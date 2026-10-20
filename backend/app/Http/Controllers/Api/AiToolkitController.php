<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunAiToolJob;
use App\Models\AiToolJob;
use App\Services\Admin\FeatureFlagService;
use App\Services\AiService;
use App\Services\AuditLogService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Faculty AI toolkit: Bangla <-> English translation (synchronous), handwriting OCR and lecture transcription
 * (queued). Jobs are private to their owner. Results are drafts for the faculty member to review.
 */
class AiToolkitController extends Controller
{
    public function __construct(protected AiService $ai, protected AuditLogService $audit, protected FeatureFlagService $features) {}

    public function index(Request $request): JsonResponse
    {
        $jobs = AiToolJob::query()->where('user_id', $request->user()->id)->latest('id')->limit(30)->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'tools' => [
                    'translation' => $this->features->enabled('ai_translation'),
                    'ocr' => $this->features->enabled('ai_ocr'),
                    'transcription' => $this->features->enabled('ai_transcription'),
                    'study_pack' => $this->features->enabled('ai_study_pack'),
                    'speech' => $this->features->enabled('ai_speech'),
                    'writing' => $this->features->enabled('ai_writing'),
                    'feedback' => $this->features->enabled('ai_feedback'),
                    'compare' => $this->features->enabled('ai_answer_compare'),
                    'paper_review' => $this->features->enabled('ai_paper_review'),
                ],
                'provider' => $this->ai->provider(),
                'limits' => [
                    'translation_chars' => 5000,
                    'ocr_mb' => 20,
                    'transcription_mb' => (int) config('services.ai.toolkit_max_upload_mb', 100),
                ],
                'jobs' => $jobs->map(fn (AiToolJob $j) => $j->toPayload())->all(),
            ],
        ]);
    }

    public function translate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:5000'],
            'source' => ['required', Rule::in(['en', 'bn'])],
            'target' => ['required', Rule::in(['en', 'bn']), 'different:source'],
        ]);
        try {
            $result = $this->ai->translate($validated['text'], $validated['source'], $validated['target']);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
        $this->audit->log('AI_TRANSLATION', 'AiToolkit', null, ['source' => $validated['source'], 'target' => $validated['target'], 'chars' => mb_strlen($validated['text']), 'provider' => $result['provider'] ?? null], $request->user());

        return response()->json(['status' => 'success', 'data' => [
            'text' => (string) ($result['text'] ?? ''),
            'model' => $result['model'] ?? null,
            'provider' => $result['provider'] ?? 'local',
            'fallback_reason' => $result['fallback_reason'] ?? null,
        ]]);
    }

    public function ocr(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:20480']]);

        return $this->queue($request, 'ocr', $request->file('file'), null);
    }

    public function transcribe(Request $request): JsonResponse
    {
        $maxKb = (int) config('services.ai.toolkit_max_upload_mb', 100) * 1024;
        $request->validate([
            'file' => ['required', 'file', 'mimes:mp3,wav,m4a,ogg,oga,webm,mp4,aac,flac', "max:{$maxKb}"],
            'language' => ['nullable', Rule::in(['en', 'bn'])],
        ]);

        return $this->queue($request, 'transcription', $request->file('file'), $request->input('language'));
    }

    /** Notes & quiz from pasted material or from a finished transcription (queued: generation takes ~30–60 s on CPU). */
    public function studyPack(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['required_without:source_job_id', 'nullable', 'string', 'min:200', 'max:60000'],
            'source_job_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:160'],
            'num_questions' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);
        $text = $validated['text'] ?? null;
        $title = $validated['title'] ?? null;
        if (!empty($validated['source_job_id'])) {
            $source = AiToolJob::query()->whereKey($validated['source_job_id'])->where('user_id', $request->user()->id)->first();
            if (!$source || $source->status !== 'completed' || trim((string) $source->result_text) === '') {
                return response()->json(['status' => 'error', 'message' => 'That transcript is not available.'], 422);
            }
            $text = (string) $source->result_text;
            $title ??= pathinfo($source->input_filename, PATHINFO_FILENAME);
            if (mb_strlen($text) < 200) {
                return response()->json(['status' => 'error', 'message' => 'The transcript is too short to build notes from.'], 422);
            }
        }
        if ($error = $this->activeLimitError($request)) {
            return $error;
        }
        $path = 'ai-toolkit/'.$request->user()->id.'/'.Str::uuid().'.txt';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, mb_substr((string) $text, 0, 60000));
        $job = AiToolJob::query()->create([
            'user_id' => $request->user()->id,
            'tool' => 'study_pack',
            'status' => 'queued',
            'input_filename' => mb_substr($title ?: 'Study notes', 0, 180),
            'input_path' => $path,
            'input_bytes' => strlen((string) $text),
            'result' => ['num_questions' => (int) ($validated['num_questions'] ?? 5)],
        ]);
        $this->audit->log('AI_TOOL_REQUESTED', $job, $job->id, ['tool' => 'study_pack', 'chars' => $job->input_bytes], $request->user());
        RunAiToolJob::dispatch($job->id);

        return response()->json(['status' => 'success', 'message' => 'Building notes and quiz. This usually takes under a minute.', 'data' => $job->fresh()->toPayload()], 202);
    }

    /** Text to speech (WAV). */
    public function speak(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $validated = $request->validate(['text' => ['required', 'string', 'max:1500'], 'language' => ['required', Rule::in(['en', 'bn'])]]);
        try {
            $audio = $this->ai->speak($validated['text'], $validated['language']);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response($audio['audio'], 200, [
            'Content-Type' => 'audio/wav',
            'Content-Disposition' => 'inline; filename="facultylens-speech.wav"',
            'X-Audio-Seconds' => (string) $audio['seconds'],
            'Cache-Control' => 'no-store',
        ]);
    }

    public function questionQuality(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'questions' => ['required', 'array', 'min:1', 'max:15'],
            'questions.*' => ['required', 'string', 'max:2000'],
            'suggest_rewrites' => ['nullable', 'boolean'],
        ]);

        return $this->proxy(fn () => $this->ai->questionQuality($validated['questions'], $request->boolean('suggest_rewrites', true)), $request, 'question_quality', ['count' => count($validated['questions'])]);
    }

    public function simplify(Request $request): JsonResponse
    {
        $validated = $request->validate(['text' => ['required', 'string', 'min:40', 'max:3000'], 'level' => ['required', Rule::in(['school', 'undergraduate', 'esl'])]]);

        return $this->proxy(fn () => $this->ai->simplify($validated['text'], $validated['level']), $request, 'simplify', ['level' => $validated['level']]);
    }

    public function bloomRewrite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:10', 'max:2000'],
            'target_level' => ['required', Rule::in(['REMEMBER', 'UNDERSTAND', 'APPLY', 'ANALYZE', 'EVALUATE', 'CREATE'])],
        ]);

        return $this->proxy(fn () => $this->ai->bloomRewrite($validated['question'], $validated['target_level']), $request, 'bloom_rewrite', ['target' => $validated['target_level']]);
    }

    /**
     * Feedback drafter. Either for a stored answer (question, approved rubric and answer text come from the database,
     * with the same access rule as grading) or for pasted question / criteria / answer. Returns text for the teacher to
     * edit; nothing is saved and no marks are produced.
     */
    public function feedbackDraft(Request $request, \App\Services\AiGradingService $grading): JsonResponse
    {
        $validated = $request->validate([
            'student_answer_id' => ['nullable', 'integer', 'exists:student_answers,id'],
            'tone' => ['nullable', Rule::in(['encouraging', 'neutral', 'concise'])],
            'question' => ['required_without:student_answer_id', 'nullable', 'string', 'min:5', 'max:5000'],
            'answer' => ['required_without:student_answer_id', 'nullable', 'string', 'max:20000'],
            'criteria' => ['required_without:student_answer_id', 'nullable', 'array', 'min:1', 'max:12'],
            'criteria.*.criterion' => ['required', 'string', 'max:255'],
            'criteria.*.description' => ['nullable', 'string', 'max:2000'],
            'criteria.*.indicators' => ['nullable', 'array', 'max:12'],
            'criteria.*.indicators.*' => ['string', 'max:255'],
        ]);
        $tone = $validated['tone'] ?? 'encouraging';

        if (!empty($validated['student_answer_id'])) {
            $answer = \App\Models\StudentAnswer::query()->with('question')->findOrFail($validated['student_answer_id']);
            if (!$request->user()->can('update', $answer)) {
                return response()->json(['status' => 'error', 'message' => 'You cannot grade this answer.'], 403);
            }
            $rubric = $answer->question
                ? \App\Models\Rubric::with('criteria')->where('question_id', $answer->question->id)->approved()->orderByDesc('version')->first()
                : null;
            if (!$rubric || $rubric->criteria->isEmpty()) {
                return response()->json(['status' => 'error', 'code' => 'NO_RUBRIC', 'message' => 'This question has no approved rubric yet. Approve one, or paste the criteria in AI Toolkit → Feedback drafter.'], 422);
            }
            try {
                $text = $grading->resolveAnswerText($answer);
            } catch (\App\Services\AiGradingException $e) {
                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
            }
            $payload = [
                'question' => (string) $answer->question->question_text,
                'answer' => mb_substr($text, 0, 20000),
                'expected_answer' => $answer->question->expected_answer ? mb_substr((string) $answer->question->expected_answer, 0, 10000) : null,
                'criteria' => $rubric->criteria->sortBy('sort_order')->map(fn ($c) => [
                    'criterion' => (string) $c->criterion,
                    'description' => $c->description ? mb_substr((string) $c->description, 0, 2000) : null,
                    'indicators' => array_values(array_slice((array) ($c->expected_indicators ?? []), 0, 12)),
                ])->values()->all(),
                'tone' => $tone,
            ];
            $meta = ['student_answer_id' => $answer->id, 'rubric_id' => $rubric->id, 'tone' => $tone];
        } else {
            $payload = [
                'question' => $validated['question'],
                'answer' => $validated['answer'],
                'criteria' => array_map(fn ($c) => [
                    'criterion' => $c['criterion'],
                    'description' => $c['description'] ?? null,
                    'indicators' => array_values($c['indicators'] ?? []),
                ], $validated['criteria']),
                'tone' => $tone,
            ];
            $meta = ['pasted' => true, 'criteria' => count($payload['criteria']), 'tone' => $tone];
        }

        return $this->proxy(fn () => $this->ai->feedbackDraft($payload), $request, 'feedback_draft', $meta);
    }

    /**
     * Compare pasted answers for unusual similarity (e.g. OCR'd handwritten scripts). Same scoring as the assessment
     * plagiarism check; nothing is stored. Stored submissions are checked on the assessment's integrity page instead.
     */
    public function compareAnswers(Request $request, \App\Services\Exam\AnswerSimilarityService $similarity): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['nullable', 'string', 'max:5000'],
            'answers' => ['required', 'array', 'min:2', 'max:40'],
            'answers.*.label' => ['nullable', 'string', 'max:80'],
            'answers.*.text' => ['required', 'string', 'max:6000'],
            'threshold' => ['nullable', 'numeric', 'min:0.3', 'max:0.95'],
        ]);
        $answers = [];
        foreach (array_values($validated['answers']) as $i => $a) {
            $label = trim((string) ($a['label'] ?? '')) ?: 'Answer '.($i + 1);
            $answers[] = ['label' => $label, 'text' => $a['text']];
        }
        $labels = array_column($answers, 'label');
        if (count($labels) !== count(array_unique($labels))) {
            return response()->json(['status' => 'error', 'message' => 'Give each answer a different label.'], 422);
        }
        $threshold = (float) ($validated['threshold'] ?? \App\Services\Exam\AnswerSimilarityService::DEFAULT_THRESHOLD);
        $result = $similarity->compareTexts($answers, (string) ($validated['question'] ?? ''), $threshold);
        $this->audit->log('AI_TOOL_USED', 'AiToolkit', null, ['tool' => 'compare_answers', 'answers' => count($answers), 'flagged' => $result['pairs_flagged']], $request->user());

        return response()->json(['status' => 'success', 'data' => $result + [
            'note' => 'High similarity is a reason to look closer, not proof of copying. Shared textbook wording, model answers and short answers can score high.',
        ]]);
    }

    /**
     * Exam paper review for pasted questions, or for a stored assessment (its questions, marks and the faculty's own
     * Bloom labels; the course outcomes serve as coverage targets when no topics are given). Nothing is changed.
     */
    public function paperReview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assessment_id' => ['nullable', 'integer', 'exists:assessments,id'],
            'questions' => ['required_without:assessment_id', 'nullable', 'array', 'min:2', 'max:60'],
            'questions.*.text' => ['required', 'string', 'max:5000'],
            'questions.*.marks' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'questions.*.number' => ['nullable', 'string', 'max:20'],
            'topics' => ['nullable', 'array', 'max:30'],
            'topics.*' => ['string', 'max:300'],
            'targets' => ['nullable', 'array'],
            'targets.*' => ['array', 'size:2'],
            'targets.*.*' => ['numeric', 'min:0', 'max:100'],
        ]);
        $topics = array_values(array_filter(array_map('trim', $validated['topics'] ?? [])));
        $meta = [];

        if (!empty($validated['assessment_id'])) {
            $assessment = \App\Models\Assessment::query()->with('course')->findOrFail($validated['assessment_id']);
            if (!app(\App\Services\CourseAccessService::class)->can($request->user(), $assessment->course, 'view_analysis')) {
                return response()->json(['status' => 'error', 'message' => 'You cannot review this assessment.'], 403);
            }
            $questions = \App\Models\Question::query()->where('assessment_id', $assessment->id)->orderBy('question_number')->orderBy('id')->limit(60)
                ->get(['id', 'question_number', 'question_text', 'marks', 'cognitive_level'])
                ->filter(fn ($q) => trim((string) $q->question_text) !== '')
                ->map(fn ($q) => [
                    'number' => (string) ($q->question_number ?: $q->id),
                    'text' => mb_substr((string) $q->question_text, 0, 5000),
                    'marks' => $q->marks !== null ? (float) $q->marks : null,
                    // The column defaults to 'Understand', so that value cannot be told apart from "never set": detect it instead
                    'bloom_level' => $q->cognitive_level && strcasecmp((string) $q->cognitive_level, 'Understand') !== 0 ? strtoupper((string) $q->cognitive_level) : null,
                ])->values()->all();
            if (count($questions) < 2) {
                return response()->json(['status' => 'error', 'message' => 'This assessment needs at least two questions with text.'], 422);
            }
            if (!$topics && $assessment->course) {
                $topics = \App\Models\LearningOutcome::query()->where('course_id', $assessment->course->id)->orderBy('sort_order')->orderBy('id')->limit(30)
                    ->get(['code', 'description'])
                    ->map(fn ($lo) => mb_substr(trim(($lo->code ? $lo->code.': ' : '').$lo->description), 0, 300))
                    ->filter()->values()->all();
            }
            $meta = ['assessment_id' => $assessment->id];
        } else {
            $questions = array_map(fn ($q) => [
                'number' => $q['number'] ?? null,
                'text' => $q['text'],
                'marks' => isset($q['marks']) ? (float) $q['marks'] : null,
            ], $validated['questions']);
        }

        $payload = ['questions' => $questions, 'topics' => $topics];
        if (!empty($validated['targets'])) {
            $payload['targets'] = $validated['targets'];
        }

        return $this->proxy(fn () => $this->ai->paperReview($payload) + ['topics_source' => !empty($validated['topics']) ? 'pasted' : (!empty($validated['assessment_id']) && $topics ? 'course_outcomes' : null)],
            $request, 'paper_review', $meta + ['questions' => count($questions), 'topics' => count($topics)]);
    }

    /**
     * OCR → grading: put reviewed OCR text into a student's answer for one question. The faculty member edits and
     * confirms the text first; the existing submission rules apply (question belongs to the assessment, one answer
     * per question, original text preserved when an existing answer is replaced).
     */
    public function attachToSubmission(Request $request, AiToolJob $job, \App\Services\StudentSubmissionService $submissions): JsonResponse
    {
        $this->authorizeOwner($request, $job);
        $validated = $request->validate([
            'submission_id' => ['required', 'integer', 'exists:student_submissions,id'],
            'question_id' => ['required', 'integer'],
            'text' => ['required', 'string', 'max:20000'],
            'replace' => ['nullable', 'boolean'],
        ]);
        if ($job->tool !== 'ocr' || $job->status !== 'completed') {
            return response()->json(['status' => 'error', 'message' => 'Only finished handwriting OCR results can be attached.'], 422);
        }
        $submission = \App\Models\StudentSubmission::query()->findOrFail($validated['submission_id']);
        if (!$request->user()->can('update', $submission)) {
            return response()->json(['status' => 'error', 'message' => 'You cannot edit this submission.'], 403);
        }
        $existing = \App\Models\StudentAnswer::query()->where('student_submission_id', $submission->id)->where('question_id', $validated['question_id'])->first();
        try {
            if ($existing) {
                if (!$request->boolean('replace')) {
                    return response()->json(['status' => 'error', 'code' => 'ANSWER_EXISTS', 'message' => 'This student already has an answer for that question. Confirm to replace its text (the original is kept).'], 409);
                }
                $answer = $submissions->updateAnswer($existing, $request->user(), ['answer_text' => $validated['text']], null);
            } else {
                $answer = $submissions->addAnswer($submission, $request->user(), ['question_id' => $validated['question_id'], 'answer_text' => $validated['text']], null);
            }
        } catch (\App\Services\SubmissionException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
        $this->audit->log('AI_OCR_ATTACHED', $answer, $answer->id, ['tool_job_id' => $job->id, 'submission_id' => $submission->id, 'question_id' => $validated['question_id'], 'replaced' => (bool) $existing], $request->user());

        return response()->json([
            'status' => 'success',
            'message' => $existing ? 'Answer text replaced. You can now run AI grading assistance on it.' : 'Answer added to the submission. You can now run AI grading assistance on it.',
            'data' => ['answer_id' => $answer->id, 'submission_id' => $submission->id, 'assessment_id' => $submission->assessment_id],
        ], $existing ? 200 : 201);
    }

    /** Save reviewed study-pack quiz questions into a course question bank (faculty choose which items). */
    public function saveToQuestionBank(Request $request, AiToolJob $job): JsonResponse
    {
        $this->authorizeOwner($request, $job);
        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'questions' => ['required', 'array', 'min:1', 'max:10'],
            'questions.*.question' => ['required', 'string', 'max:2000'],
            'questions.*.bloom_level' => ['nullable', 'string', 'max:20'],
        ]);
        if ($job->tool !== 'study_pack' || $job->status !== 'completed') {
            return response()->json(['status' => 'error', 'message' => 'Only finished study packs can be saved.'], 422);
        }
        $course = \App\Models\Course::query()->findOrFail($validated['course_id']);
        if (!app(\App\Services\CourseAccessService::class)->can($request->user(), $course, 'edit_question')) {
            return response()->json(['status' => 'error', 'message' => 'You cannot add questions to this course.'], 403);
        }
        $saved = [];
        foreach ($validated['questions'] as $q) {
            $level = strtoupper((string) ($q['bloom_level'] ?? ''));
            $saved[] = \App\Models\PreviousQuestion::query()->create([
                'user_id' => $request->user()->id,
                'course_id' => $course->id,
                'question_text' => trim($q['question']),
                'question_type' => str_starts_with(trim($q['question']), 'Fill in the blank') ? 'other' : 'short_answer',
                'difficulty_level' => 'easy',
                'cognitive_level' => in_array($level, ['REMEMBER', 'UNDERSTAND', 'APPLY', 'ANALYZE', 'EVALUATE', 'CREATE'], true) ? ucfirst(strtolower($level)) : 'Remember',
                'source' => 'question_bank',
                'source_assessment' => mb_substr('AI study pack: '.$job->input_filename, 0, 190),
            ])->id;
        }
        $this->audit->log('AI_STUDY_PACK_SAVED', $course, $course->id, ['tool_job_id' => $job->id, 'questions' => count($saved)], $request->user());

        return response()->json(['status' => 'success', 'message' => count($saved).' question(s) saved to the '.$course->course_code.' question bank.', 'data' => ['ids' => $saved]], 201);
    }

    protected function proxy(callable $call, Request $request, string $tool, array $meta): JsonResponse
    {
        try {
            $result = $call();
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
        $this->audit->log('AI_TOOL_USED', 'AiToolkit', null, ['tool' => $tool] + $meta, $request->user());
        unset($result['status']);

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    protected function activeLimitError(Request $request): ?JsonResponse
    {
        $active = AiToolJob::query()->where('user_id', $request->user()->id)->whereIn('status', ['queued', 'processing'])->count();

        return $active >= 3 ? response()->json(['status' => 'error', 'message' => 'You already have 3 jobs waiting. Let them finish first.'], 429) : null;
    }

    public function show(Request $request, AiToolJob $job): JsonResponse
    {
        $this->authorizeOwner($request, $job);

        return response()->json(['status' => 'success', 'data' => $job->toPayload(true)]);
    }

    public function download(Request $request, AiToolJob $job): StreamedResponse|JsonResponse
    {
        $this->authorizeOwner($request, $job);
        if ($job->status !== 'completed') {
            return response()->json(['status' => 'error', 'message' => 'This job has no result yet.'], 409);
        }
        $name = Str::slug(pathinfo($job->input_filename, PATHINFO_FILENAME) ?: $job->tool).'-'.$job->tool.'.txt';

        return response()->streamDownload(fn () => print($job->result_text), $name, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function destroy(Request $request, AiToolJob $job): JsonResponse
    {
        $this->authorizeOwner($request, $job);
        if ($job->status === 'processing') {
            return response()->json(['status' => 'error', 'message' => 'Wait for the job to finish before deleting it.'], 409);
        }
        if ($job->input_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($job->input_path);
        }
        $job->delete();

        return response()->json(['status' => 'success', 'message' => 'Deleted.']);
    }

    protected function queue(Request $request, string $tool, UploadedFile $file, ?string $language): JsonResponse
    {
        if ($error = $this->activeLimitError($request)) {
            return $error;
        }
        $original = mb_substr(preg_replace('/[^\pL\pN._ -]+/u', '_', (string) $file->getClientOriginalName()) ?: "upload.{$file->extension()}", 0, 180);
        $path = $file->storeAs('ai-toolkit/'.$request->user()->id, Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()), 'local');

        $job = AiToolJob::query()->create([
            'user_id' => $request->user()->id,
            'tool' => $tool,
            'status' => 'queued',
            'input_filename' => $original,
            'input_path' => $path,
            'input_bytes' => (int) $file->getSize(),
            'language' => $language,
        ]);
        $this->audit->log('AI_TOOL_REQUESTED', $job, $job->id, ['tool' => $tool, 'bytes' => $job->input_bytes], $request->user());
        RunAiToolJob::dispatch($job->id);

        return response()->json([
            'status' => 'success',
            'message' => $tool === 'ocr' ? 'Handwriting OCR started. The text appears here when it is ready.' : 'Transcription started. Long recordings can take several minutes.',
            'data' => $job->fresh()->toPayload(),
        ], 202);
    }

    protected function authorizeOwner(Request $request, AiToolJob $job): void
    {
        abort_unless($job->user_id === $request->user()->id, 404);
    }
}
