<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Question;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * STEP 26: Student submission & answer management.
 * Storage and faculty-side management only — no AI grading is performed here.
 */
class StudentSubmissionService
{
    public const DISK = 'local';
    public const MAX_FILE_KB = 10240;
    public const MAX_CSV_ROWS = 5000;

    /** extension => accepted MIME types (verified against actual file contents) */
    public const ALLOWED_FILES = [
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'txt' => ['text/plain'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
    ];

    public const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg'];

    public function __construct(protected AuditLogService $auditLogService) {}

    /** STEP 38: new submissions record the assessment version in force (latest finalized, else the working version). */
    protected function currentVersionId(Assessment $assessment): ?int
    {
        return app(AssessmentVersionService::class)->currentVersion($assessment)?->id;
    }

    /** STEP 38: link the answer to the question snapshot of the submission's version so later edits never change what was answered. */
    protected function versionQuestionId(StudentSubmission $submission, Question $question): ?int
    {
        if (!$submission->assessment_version_id) {
            return null;
        }

        return \App\Models\AssessmentVersionQuestion::where('assessment_version_id', $submission->assessment_version_id)->where('original_question_id', $question->id)->value('id');
    }

    // ------------------------------------------------------------ submissions

    public function createSubmission(Assessment $assessment, User $user, array $data): StudentSubmission
    {
        $student = Student::find($data['student_id']);
        if (!$student || $student->created_by !== $user->id) {
            throw new SubmissionException('The selected student is not registered under your account.', 422);
        }

        if (StudentSubmission::where('assessment_id', $assessment->id)->where('student_id', $student->id)->exists()) {
            throw new SubmissionException('This student already has a submission for this assessment.', 422);
        }

        $status = strtoupper($data['status'] ?? StudentSubmission::STATUS_SUBMITTED);
        if (!in_array($status, [StudentSubmission::STATUS_DRAFT, StudentSubmission::STATUS_SUBMITTED], true)) {
            throw new SubmissionException('New submissions may only be created as DRAFT or SUBMITTED.', 422);
        }

        $submission = StudentSubmission::create([
            'assessment_id' => $assessment->id,
            'assessment_version_id' => $this->currentVersionId($assessment),
            'student_id' => $student->id,
            'submission_identifier' => isset($data['submission_identifier']) ? Str::limit(trim($data['submission_identifier']), 64, '') : null,
            'submitted_at' => $data['submitted_at'] ?? ($status === StudentSubmission::STATUS_SUBMITTED ? now() : null),
            'status' => $status,
            'grading_status' => StudentSubmission::GRADING_NOT_STARTED,
            'total_marks' => $this->assessmentTotalMarks($assessment),
            'awarded_marks' => null,
        ]);

        $this->auditLogService->log('SUBMISSION_CREATED', $submission, $submission->id, [
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'status' => $status,
        ], $user);

        return $submission;
    }

    public function changeStatus(StudentSubmission $submission, User $user, string $status): StudentSubmission
    {
        $status = strtoupper($status);
        if (!in_array($status, StudentSubmission::STATUSES, true)) {
            throw new SubmissionException('Unknown submission status.', 422);
        }
        if ($status === $submission->status) {
            return $submission;
        }
        if (!$submission->canTransitionTo($status)) {
            throw new SubmissionException(
                "A submission cannot move from {$submission->status} to {$status}.",
                422
            );
        }

        $from = $submission->status;
        $attributes = ['status' => $status];

        // STEP 41 integrity guard: a submission is only "graded" when every recorded answer carries a faculty grade.
        if ($status === StudentSubmission::STATUS_GRADED) {
            $ungraded = StudentAnswer::where('student_submission_id', $submission->id)
                ->where('answer_status', '!=', StudentAnswer::STATUS_REVIEWED)->count();
            if ($ungraded > 0) {
                throw new SubmissionException(
                    "{$ungraded} answer(s) still need a faculty grade before this submission can be marked as graded.",
                    422
                );
            }
        }

        if ($status === StudentSubmission::STATUS_SUBMITTED && !$submission->submitted_at) {
            $attributes['submitted_at'] = now();
        }
        // Faculty-driven grading milestones only; AI_ASSISTED is reserved for a later step.
        if ($status === StudentSubmission::STATUS_GRADED) {
            $attributes['grading_status'] = StudentSubmission::GRADING_FACULTY_REVIEWED;
        } elseif ($status === StudentSubmission::STATUS_RETURNED) {
            $attributes['grading_status'] = StudentSubmission::GRADING_FINALIZED;
        } elseif ($status === StudentSubmission::STATUS_UNDER_REVIEW && $submission->grading_status === StudentSubmission::GRADING_NOT_STARTED) {
            $attributes['grading_status'] = StudentSubmission::GRADING_IN_PROGRESS;
        }

        $submission->update($attributes);
        StudentPerformanceService::invalidateCache((int) $submission->assessment_id);

        $this->auditLogService->log('SUBMISSION_STATUS_CHANGED', $submission, $submission->id, [
            'from' => $from,
            'to' => $status,
            'grading_status' => $submission->grading_status,
        ], $user);

        return $submission->fresh();
    }

    public function deleteSubmission(StudentSubmission $submission, User $user): void
    {
        $submission->load('answers');
        $paths = $submission->answers->pluck('answer_file_path')->filter()->all();

        DB::transaction(function () use ($submission, $user) {
            $this->auditLogService->log('SUBMISSION_DELETED', $submission, $submission->id, [
                'assessment_id' => $submission->assessment_id,
                'student_id' => $submission->student_id,
                'answers_count' => $submission->answers->count(),
            ], $user);
            $submission->answers()->delete();
            $submission->delete();
        });

        foreach ($paths as $path) {
            $this->deleteFileQuietly($path);
        }
    }

    // ---------------------------------------------------------------- answers

    public function addAnswer(StudentSubmission $submission, User $user, array $data, ?UploadedFile $file = null): StudentAnswer
    {
        $question = Question::find($data['question_id']);
        if (!$question) {
            throw new SubmissionException('The selected question does not exist.', 422);
        }
        // Integrity: the answer's question must belong to the submission's assessment.
        if ((int) $question->assessment_id !== (int) $submission->assessment_id) {
            throw new SubmissionException('The question does not belong to this submission\'s assessment.', 422);
        }
        if (StudentAnswer::where('student_submission_id', $submission->id)->where('question_id', $question->id)->exists()) {
            throw new SubmissionException('This submission already has an answer for that question.', 422);
        }

        $text = isset($data['answer_text']) ? trim((string) $data['answer_text']) : '';
        if ($text === '' && !$file) {
            throw new SubmissionException('Provide an answer text or an answer file.', 422);
        }

        $marks = $this->validateMarks($data['awarded_marks'] ?? null, $question);
        $fileMeta = $file ? $this->storeAnswerFile($file, $submission, $user) : null;

        try {
            $answer = DB::transaction(function () use ($submission, $question, $data, $text, $marks, $fileMeta, $user) {
                $answerType = $this->resolveAnswerType($data['answer_type'] ?? null, $fileMeta, $text);
                $status = $this->resolveAnswerStatus($data['answer_status'] ?? null, $marks, $data['faculty_feedback'] ?? null);

                $answer = StudentAnswer::create([
                    'student_submission_id' => $submission->id,
                    'question_id' => $question->id,
                    'assessment_version_question_id' => $this->versionQuestionId($submission, $question),
                    'answer_type' => $answerType,
                    'answer_text' => $text !== '' ? $text : null,
                    'original_answer_text' => $text !== '' ? $text : null,
                    'is_faculty_edited' => false,
                    'answer_file_path' => $fileMeta['path'] ?? null,
                    'answer_file_name' => $fileMeta['name'] ?? null,
                    'answer_file_type' => $fileMeta['mime'] ?? null,
                    'answer_file_size' => $fileMeta['size'] ?? null,
                    'awarded_marks' => $marks,
                    'faculty_feedback' => isset($data['faculty_feedback']) ? Str::limit(trim((string) $data['faculty_feedback']), 5000, '') : null,
                    'answer_status' => $status,
                ]);

                $this->recalculateSubmission($submission);

                $this->auditLogService->log('ANSWER_ADDED', $answer, $answer->id, [
                    'submission_id' => $submission->id,
                    'question_id' => $question->id,
                    'answer_type' => $answerType,
                    'has_file' => $fileMeta !== null,
                    'file_size' => $fileMeta['size'] ?? null,
                ], $user);

                return $answer;
            });
        } catch (SubmissionException $e) {
            $this->deleteFileQuietly($fileMeta['path'] ?? null);
            throw $e;
        } catch (\Throwable $e) {
            $this->deleteFileQuietly($fileMeta['path'] ?? null);
            Log::error('Student answer persistence failed for submission ' . $submission->id . ': ' . get_class($e));
            throw new SubmissionException('The answer could not be saved. Please try again.', 500);
        }

        return $answer;
    }

    public function updateAnswer(StudentAnswer $answer, User $user, array $data, ?UploadedFile $file = null): StudentAnswer
    {
        $answer->loadMissing(['question', 'submission']);
        $question = $answer->question;

        $attributes = [];
        $changed = [];

        if (array_key_exists('answer_text', $data)) {
            $newText = $data['answer_text'] !== null ? trim((string) $data['answer_text']) : '';
            $newText = $newText !== '' ? $newText : null;
            if ($newText !== $answer->answer_text) {
                if ($answer->original_answer_text === null && $answer->answer_text !== null) {
                    $attributes['original_answer_text'] = $answer->answer_text;
                }
                $attributes['answer_text'] = $newText;
                $attributes['is_faculty_edited'] = true;
                $changed[] = 'answer_text';
            }
        }

        if (array_key_exists('awarded_marks', $data)) {
            $attributes['awarded_marks'] = $this->validateMarks($data['awarded_marks'], $question);
            $changed[] = 'awarded_marks';
        }

        if (array_key_exists('faculty_feedback', $data)) {
            $attributes['faculty_feedback'] = $data['faculty_feedback'] !== null
                ? Str::limit(trim((string) $data['faculty_feedback']), 5000, '')
                : null;
            $changed[] = 'faculty_feedback';
        }

        if (array_key_exists('answer_status', $data) && $data['answer_status'] !== null) {
            $status = strtoupper((string) $data['answer_status']);
            if (!in_array($status, StudentAnswer::STATUSES, true)) {
                throw new SubmissionException('Unknown answer status.', 422);
            }
            $attributes['answer_status'] = $status;
            $changed[] = 'answer_status';
        } elseif (in_array('awarded_marks', $changed, true) && ($attributes['awarded_marks'] ?? null) !== null && $answer->answer_status === StudentAnswer::STATUS_NOT_REVIEWED) {
            $attributes['answer_status'] = StudentAnswer::STATUS_REVIEWED;
        }

        if (array_key_exists('answer_type', $data) && $data['answer_type'] !== null) {
            $type = strtoupper((string) $data['answer_type']);
            if (!in_array($type, StudentAnswer::TYPES, true)) {
                throw new SubmissionException('Unknown answer type.', 422);
            }
            $attributes['answer_type'] = $type;
        }

        $fileMeta = null;
        $oldPath = null;
        if ($file) {
            $fileMeta = $this->storeAnswerFile($file, $answer->submission, $user);
            $oldPath = $answer->answer_file_path;
            $attributes['answer_file_path'] = $fileMeta['path'];
            $attributes['answer_file_name'] = $fileMeta['name'];
            $attributes['answer_file_type'] = $fileMeta['mime'];
            $attributes['answer_file_size'] = $fileMeta['size'];
            if (!array_key_exists('answer_type', $attributes)) {
                $attributes['answer_type'] = $fileMeta['is_image'] ? StudentAnswer::TYPE_IMAGE : StudentAnswer::TYPE_FILE;
            }
            $changed[] = 'answer_file';
        } elseif (!empty($data['remove_file']) && $answer->hasFile()) {
            $oldPath = $answer->answer_file_path;
            $attributes['answer_file_path'] = null;
            $attributes['answer_file_name'] = null;
            $attributes['answer_file_type'] = null;
            $attributes['answer_file_size'] = null;
            $changed[] = 'answer_file_removed';
            if (($attributes['answer_text'] ?? $answer->answer_text) === null) {
                throw new SubmissionException('An answer must keep either text or a file.', 422);
            }
        }

        try {
            DB::transaction(function () use ($answer, $attributes, $changed, $user) {
                if ($attributes) {
                    $answer->update($attributes);
                }
                $this->recalculateSubmission($answer->submission);

                $this->auditLogService->log('ANSWER_UPDATED', $answer, $answer->id, [
                    'submission_id' => $answer->student_submission_id,
                    'question_id' => $answer->question_id,
                    'changed_fields' => $changed,
                    'awarded_marks' => $attributes['awarded_marks'] ?? null,
                ], $user);
            });
        } catch (\Throwable $e) {
            $this->deleteFileQuietly($fileMeta['path'] ?? null);
            Log::error('Student answer update failed for answer ' . $answer->id . ': ' . get_class($e));
            throw new SubmissionException('The answer could not be updated. Please try again.', 500);
        }

        if ($oldPath) {
            $this->deleteFileQuietly($oldPath);
        }

        return $answer->fresh(['question']);
    }

    public function deleteAnswer(StudentAnswer $answer, User $user): void
    {
        $path = $answer->answer_file_path;
        $submission = $answer->submission;

        DB::transaction(function () use ($answer, $submission, $user) {
            $this->auditLogService->log('ANSWER_DELETED', $answer, $answer->id, [
                'submission_id' => $answer->student_submission_id,
                'question_id' => $answer->question_id,
                'had_file' => $answer->hasFile(),
            ], $user);
            $answer->delete();
            $this->recalculateSubmission($submission);
        });

        $this->deleteFileQuietly($path);
    }

    // ------------------------------------------------------------ CSV import

    /**
     * Import text answers from CSV (student_identifier, question_number, answer_text).
     * All rows are validated first; nothing is written if any row is invalid.
     *
     * @return array{submissions_created:int, answers_created:int, rows:int}
     */
    public function importCsv(Assessment $assessment, User $user, UploadedFile $csv): array
    {
        $handle = fopen($csv->getRealPath(), 'r');
        if (!$handle) {
            throw new SubmissionException('The CSV file could not be read.', 422);
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            throw new SubmissionException('The CSV file is empty.', 422);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $h))), $header);
        $required = ['student_identifier', 'question_number', 'answer_text'];
        $missing = array_diff($required, $header);
        if ($missing) {
            fclose($handle);
            throw new SubmissionException('CSV is missing required columns: ' . implode(', ', $missing) . '.', 422);
        }
        $idx = array_flip($header);

        $questionsByNumber = $assessment->questions()->get()->keyBy(fn ($q) => (string) (int) $q->question_number);
        $students = Student::where('created_by', $user->id)->get()->keyBy(fn ($s) => strtolower($s->student_identifier));
        $existingSubs = StudentSubmission::where('assessment_id', $assessment->id)->get()->keyBy('student_id');
        $existingAnswers = StudentAnswer::whereIn('student_submission_id', $existingSubs->pluck('id'))
            ->get(['student_submission_id', 'question_id'])
            ->map(fn ($a) => $a->student_submission_id . ':' . $a->question_id)
            ->flip();

        $rows = [];
        $errors = [];
        $seen = [];
        $lineNo = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNo++;
            if ($lineNo - 1 > self::MAX_CSV_ROWS) {
                $errors[] = "Row {$lineNo}: CSV exceeds the maximum of " . self::MAX_CSV_ROWS . ' rows.';
                break;
            }
            if (count(array_filter($row, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }

            $identifier = trim((string) ($row[$idx['student_identifier']] ?? ''));
            $qNumber = trim((string) ($row[$idx['question_number']] ?? ''));
            $text = (string) ($row[$idx['answer_text']] ?? '');
            if (!mb_check_encoding($text, 'UTF-8')) {
                $errors[] = "Row {$lineNo}: answer text is not valid UTF-8.";
                continue;
            }
            $text = trim($text);

            if ($identifier === '') {
                $errors[] = "Row {$lineNo}: student_identifier is required.";
                continue;
            }
            $student = $students->get(strtolower($identifier));
            if (!$student) {
                $errors[] = "Row {$lineNo}: student '{$identifier}' is not registered under your account.";
                continue;
            }
            if (!ctype_digit($qNumber) || !$questionsByNumber->has((string) (int) $qNumber)) {
                $errors[] = "Row {$lineNo}: question number '{$qNumber}' does not exist in this assessment.";
                continue;
            }
            $question = $questionsByNumber->get((string) (int) $qNumber);
            if ($text === '') {
                $errors[] = "Row {$lineNo}: answer_text is empty.";
                continue;
            }
            if (mb_strlen($text) > 20000) {
                $errors[] = "Row {$lineNo}: answer_text exceeds 20,000 characters.";
                continue;
            }

            $key = $student->id . ':' . $question->id;
            if (isset($seen[$key])) {
                $errors[] = "Row {$lineNo}: duplicate answer for student '{$identifier}' question {$qNumber} within the file.";
                continue;
            }
            $seen[$key] = true;
            $existingSub = $existingSubs->get($student->id);
            if ($existingSub && $existingAnswers->has($existingSub->id . ':' . $question->id)) {
                $errors[] = "Row {$lineNo}: student '{$identifier}' already has an answer for question {$qNumber}.";
                continue;
            }

            $rows[] = ['student' => $student, 'question' => $question, 'text' => $text];
        }
        fclose($handle);

        if ($errors) {
            throw new SubmissionException('The CSV contains invalid rows. No answers were imported.', 422, array_slice($errors, 0, 50));
        }
        if (!$rows) {
            throw new SubmissionException('The CSV contains no answer rows.', 422);
        }

        $totalMarks = $this->assessmentTotalMarks($assessment);
        $versionId = $this->currentVersionId($assessment);
        $created = ['submissions' => 0, 'answers' => 0];

        DB::transaction(function () use ($rows, $assessment, $user, $existingSubs, $totalMarks, $versionId, &$created) {
            $subCache = $existingSubs->all();
            $touched = [];
            foreach ($rows as $r) {
                $sub = $subCache[$r['student']->id] ?? null;
                if (!$sub) {
                    $sub = StudentSubmission::create([
                        'assessment_id' => $assessment->id,
                        'assessment_version_id' => $versionId,
                        'student_id' => $r['student']->id,
                        'submitted_at' => now(),
                        'status' => StudentSubmission::STATUS_SUBMITTED,
                        'grading_status' => StudentSubmission::GRADING_NOT_STARTED,
                        'total_marks' => $totalMarks,
                    ]);
                    $subCache[$r['student']->id] = $sub;
                    $created['submissions']++;
                }
                StudentAnswer::create([
                    'student_submission_id' => $sub->id,
                    'question_id' => $r['question']->id,
                    'assessment_version_question_id' => $this->versionQuestionId($sub, $r['question']),
                    'answer_type' => StudentAnswer::TYPE_TEXT,
                    'answer_text' => $r['text'],
                    'original_answer_text' => $r['text'],
                    'answer_status' => StudentAnswer::STATUS_NOT_REVIEWED,
                ]);
                $created['answers']++;
                $touched[$sub->id] = $sub;
            }
            foreach ($touched as $sub) {
                $this->recalculateSubmission($sub);
            }

            $this->auditLogService->log('ANSWERS_IMPORTED', $assessment, $assessment->id, [
                'submissions_created' => $created['submissions'],
                'answers_created' => $created['answers'],
            ], $user);
        });

        return [
            'submissions_created' => $created['submissions'],
            'answers_created' => $created['answers'],
            'rows' => count($rows),
        ];
    }

    // ------------------------------------------------------------------ files

    /**
     * @return array{path:string,name:string,mime:string,size:int,is_image:bool}
     */
    public function storeAnswerFile(UploadedFile $file, StudentSubmission $submission, User $user): array
    {
        if (!$file->isValid()) {
            throw new SubmissionException('The uploaded file is invalid.', 422);
        }
        if ($file->getSize() > self::MAX_FILE_KB * 1024) {
            throw new SubmissionException('The answer file must not exceed 10MB.', 422);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());
        if (!isset(self::ALLOWED_FILES[$extension])) {
            throw new SubmissionException('Unsupported file type. Allowed: PDF, DOCX, TXT, PNG, JPG.', 422);
        }

        // Verify real content type, not the client-declared one.
        $actualMime = strtolower((string) $file->getMimeType());
        if (!in_array($actualMime, self::ALLOWED_FILES[$extension], true)) {
            throw new SubmissionException('The file contents do not match its extension.', 422);
        }

        $safeName = Str::limit(preg_replace('/[^\w.\- ]+/u', '_', basename($file->getClientOriginalName())) ?: 'answer', 200, '');
        $directory = sprintf('student-answers/user_%d/assessment_%d/submission_%d', $user->id, $submission->assessment_id, $submission->id);
        $storedName = Str::random(40) . '.' . $extension;

        $path = $file->storeAs($directory, $storedName, self::DISK);
        if (!$path) {
            throw new SubmissionException('The answer file could not be stored.', 500);
        }

        return [
            'path' => $path,
            'name' => $safeName,
            'mime' => $actualMime,
            'size' => (int) $file->getSize(),
            'is_image' => in_array($extension, self::IMAGE_EXTENSIONS, true),
        ];
    }

    public function fileExists(StudentAnswer $answer): bool
    {
        return $answer->hasFile() && Storage::disk(self::DISK)->exists($answer->answer_file_path);
    }

    protected function deleteFileQuietly(?string $path): void
    {
        if ($path && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Manual marks must satisfy 0 <= marks <= question.marks.
     */
    public function validateMarks(mixed $marks, Question $question): ?float
    {
        if ($marks === null || $marks === '') {
            return null;
        }
        if (!is_numeric($marks)) {
            throw new SubmissionException('Awarded marks must be a number.', 422);
        }
        $value = round((float) $marks, 2);
        $max = round((float) $question->marks, 2);
        if ($value < 0 || $value > $max) {
            throw new SubmissionException("Awarded marks must be between 0 and {$max} for this question.", 422);
        }

        return $value;
    }

    /**
     * Recompute submission totals from its answers (faculty-entered marks only).
     */
    public function recalculateSubmission(StudentSubmission $submission): void
    {
        $answers = StudentAnswer::where('student_submission_id', $submission->id)->get(['awarded_marks']);
        $graded = $answers->whereNotNull('awarded_marks');

        $attributes = [
            'awarded_marks' => $graded->isEmpty() ? null : round((float) $graded->sum('awarded_marks'), 2),
        ];
        if ($graded->isNotEmpty() && $submission->grading_status === StudentSubmission::GRADING_NOT_STARTED) {
            $attributes['grading_status'] = StudentSubmission::GRADING_IN_PROGRESS;
        }

        $submission->update($attributes);
        StudentPerformanceService::invalidateCache((int) $submission->assessment_id);
        CoPoMappingValidatorService::invalidateCacheForAssessment((int) $submission->assessment_id);
    }

    public function assessmentTotalMarks(Assessment $assessment): ?float
    {
        if ($assessment->total_marks !== null) {
            return round((float) $assessment->total_marks, 2);
        }
        $sum = $assessment->questions()->sum('marks');

        return $sum ? round((float) $sum, 2) : null;
    }

    protected function resolveAnswerType(?string $requested, ?array $fileMeta, string $text): string
    {
        if ($requested) {
            $type = strtoupper($requested);
            if (!in_array($type, StudentAnswer::TYPES, true)) {
                throw new SubmissionException('Unknown answer type.', 422);
            }
            return $type;
        }
        if ($fileMeta) {
            return $fileMeta['is_image'] ? StudentAnswer::TYPE_IMAGE : StudentAnswer::TYPE_FILE;
        }

        return StudentAnswer::TYPE_TEXT;
    }

    protected function resolveAnswerStatus(?string $requested, ?float $marks, ?string $feedback): string
    {
        if ($requested) {
            $status = strtoupper($requested);
            if (!in_array($status, StudentAnswer::STATUSES, true)) {
                throw new SubmissionException('Unknown answer status.', 422);
            }
            return $status;
        }

        return $marks !== null ? StudentAnswer::STATUS_REVIEWED : StudentAnswer::STATUS_NOT_REVIEWED;
    }
}
