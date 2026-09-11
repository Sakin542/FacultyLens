<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Services\AiGradingService;
use App\Services\RubricAlignmentService;
use App\Services\StudentSubmissionService;
use App\Services\SubmissionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * STEP 26: Student submission management for faculty.
 * Ownership chain: User -> Course -> Assessment -> Submission.
 */
class StudentSubmissionController extends Controller
{
    public function __construct(protected StudentSubmissionService $service) {}

    /**
     * GET /api/assessments/{assessment}/submissions — paginated summary rows (no answer bodies).
     */
    public function index(Request $request, Assessment $assessment): JsonResponse
    {
        if (!$this->ownsAssessment($request, $assessment)) {
            return $this->forbidden('Unauthorized access to assessment submissions.');
        }

        $query = StudentSubmission::query()
            ->where('assessment_id', $assessment->id)
            ->with('student:id,student_identifier,name,section,program')
            ->withCount([
                'answers',
                'answers as reviewed_answers_count' => fn ($q) => $q->where('answer_status', StudentAnswer::STATUS_REVIEWED),
            ]);

        if ($request->filled('status')) {
            $query->where('status', strtoupper((string) $request->status));
        }
        if ($request->filled('grading_status')) {
            $query->where('grading_status', strtoupper((string) $request->grading_status));
        }
        if ($request->filled('submitted_from')) {
            $query->whereDate('submitted_at', '>=', $request->submitted_from);
        }
        if ($request->filled('submitted_to')) {
            $query->whereDate('submitted_at', '<=', $request->submitted_to);
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('submission_identifier', 'like', "%{$search}%")
                    ->orWhereHas('student', function ($s) use ($search) {
                        $s->where('student_identifier', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%");
                    });
            });
        }

        $sort = in_array($request->get('sort'), ['submitted_at', 'created_at', 'status', 'grading_status', 'awarded_marks'], true)
            ? $request->get('sort')
            : 'created_at';
        $direction = strtolower((string) $request->get('direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
        $paginated = $query->orderBy($sort, $direction)->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => collect($paginated->items())->map(fn (StudentSubmission $s) => $this->presentSummary($s))->values(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * GET /api/assessments/{assessment}/submissions/summary — real counts for the assessment page.
     */
    public function summary(Request $request, Assessment $assessment): JsonResponse
    {
        if (!$this->ownsAssessment($request, $assessment)) {
            return $this->forbidden('Unauthorized access to assessment submissions.');
        }

        $byStatus = StudentSubmission::where('assessment_id', $assessment->id)
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $byGrading = StudentSubmission::where('assessment_id', $assessment->id)
            ->selectRaw('grading_status, COUNT(*) as c')->groupBy('grading_status')->pluck('c', 'grading_status');
        $answersByStatus = StudentAnswer::whereHas('submission', fn ($q) => $q->where('assessment_id', $assessment->id))
            ->selectRaw('answer_status, COUNT(*) as c')->groupBy('answer_status')->pluck('c', 'answer_status');

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_submissions' => (int) $byStatus->sum(),
                'by_status' => collect(StudentSubmission::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($byStatus[$s] ?? 0)]),
                'by_grading_status' => collect(StudentSubmission::GRADING_STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($byGrading[$s] ?? 0)]),
                'total_answers' => (int) $answersByStatus->sum(),
                'answers_by_status' => collect(StudentAnswer::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($answersByStatus[$s] ?? 0)]),
                'questions_count' => $assessment->questions()->count(),
            ],
        ]);
    }

    /**
     * POST /api/assessments/{assessment}/submissions
     */
    public function store(Request $request, Assessment $assessment): JsonResponse
    {
        if (!$this->ownsAssessment($request, $assessment)) {
            return $this->forbidden('Unauthorized access to this assessment.');
        }

        $validated = $request->validate([
            'student_id' => ['required', 'integer'],
            'submission_identifier' => ['nullable', 'string', 'max:64'],
            'submitted_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string', Rule::in([StudentSubmission::STATUS_DRAFT, StudentSubmission::STATUS_SUBMITTED])],
        ]);

        try {
            $submission = $this->service->createSubmission($assessment, $request->user(), $validated);
        } catch (SubmissionException $e) {
            return $this->error($e);
        }

        $submission->load('student')->loadCount('answers');

        return response()->json([
            'status' => 'success',
            'message' => 'Submission created.',
            'data' => $this->presentSummary($submission),
        ], 201);
    }

    /**
     * GET /api/submissions/{submission}
     */
    public function show(Request $request, StudentSubmission $submission): JsonResponse
    {
        if (!$request->user()->can('view', $submission)) {
            return $this->forbidden('Unauthorized access to this submission.');
        }

        $submission->load([
            'student',
            'assessment:id,course_id,title,type,total_marks,assessment_date',
            'assessment.course:id,course_code,course_name',
            'assessment.questions.approvedRubric',
            'assessmentVersion.questions',
            'answers.currentAiGrading.criterionResults',
            'answers.currentRubricAlignment.criterionAlignments',
        ]);

        $answersByQuestion = $submission->answers->keyBy('question_id');
        // STEP 38: the student answered the version snapshot — show that historical wording, never the later live edit.
        $snapshots = $submission->assessmentVersion ? $submission->assessmentVersion->questions->whereNotNull('original_question_id')->keyBy('original_question_id') : collect();

        $questions = $submission->assessment->questions->map(function ($q) use ($answersByQuestion, $snapshots) {
            $answer = $answersByQuestion->get($q->id);
            $snap = $snapshots->get($q->id);
            return [
                'id' => $q->id,
                'question_number' => $snap ? $snap->question_number : $q->question_number,
                'question_text' => $snap ? $snap->question_text : $q->question_text,
                'question_type' => $snap ? $snap->question_type : $q->question_type,
                'marks' => (float) ($snap ? $snap->marks : $q->marks),
                'current_question_text' => $q->question_text,
                'version_question' => $snap ? ['id' => $snap->id, 'question_number' => $snap->question_number, 'question_text' => $snap->question_text, 'marks' => (float) $snap->marks, 'question_type' => $snap->question_type] : null,
                'approved_rubric' => $q->approvedRubric ? [
                    'id' => $q->approvedRubric->id,
                    'title' => $q->approvedRubric->title,
                    'status' => $q->approvedRubric->status,
                    'version' => $q->approvedRubric->version,
                ] : null,
                'answer' => $answer ? $this->presentAnswer($answer) : null,
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $submission->id,
                'assessment_id' => $submission->assessment_id,
                'student_id' => $submission->student_id,
                'submission_identifier' => $submission->submission_identifier,
                'submitted_at' => $submission->submitted_at?->toISOString(),
                'status' => $submission->status,
                'grading_status' => $submission->grading_status,
                'total_marks' => $submission->total_marks !== null ? (float) $submission->total_marks : null,
                'awarded_marks' => $submission->awarded_marks !== null ? (float) $submission->awarded_marks : null,
                'allowed_transitions' => StudentSubmission::TRANSITIONS[$submission->status] ?? [],
                'student' => $submission->student,
                'assessment_version_id' => $submission->assessment_version_id,
                'assessment_version' => $submission->assessmentVersion ? [
                    'id' => $submission->assessmentVersion->id,
                    'version_number' => $submission->assessmentVersion->version_number,
                    'version_label' => $submission->assessmentVersion->version_label,
                    'status' => $submission->assessmentVersion->status,
                ] : null,
                'assessment' => [
                    'id' => $submission->assessment->id,
                    'title' => $submission->assessment->title,
                    'type' => $submission->assessment->type,
                    'total_marks' => $submission->assessment->total_marks !== null ? (float) $submission->assessment->total_marks : null,
                    'course' => $submission->assessment->course,
                ],
                'questions' => $questions,
                'answers_count' => $submission->answers->count(),
                'created_at' => $submission->created_at?->toISOString(),
                'updated_at' => $submission->updated_at?->toISOString(),
            ],
        ]);
    }

    /**
     * PATCH /api/submissions/{submission}/status
     */
    public function updateStatus(Request $request, StudentSubmission $submission): JsonResponse
    {
        if (!$request->user()->can('update', $submission)) {
            return $this->forbidden('Unauthorized access to this submission.');
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(StudentSubmission::STATUSES)],
        ]);

        try {
            $updated = $this->service->changeStatus($submission, $request->user(), $validated['status']);
        } catch (SubmissionException $e) {
            return $this->error($e);
        }

        $updated->load('student')->loadCount('answers');

        return response()->json([
            'status' => 'success',
            'message' => "Submission status updated to {$updated->status}.",
            'data' => $this->presentSummary($updated) + ['allowed_transitions' => StudentSubmission::TRANSITIONS[$updated->status] ?? []],
        ]);
    }

    /**
     * DELETE /api/submissions/{submission}
     */
    public function destroy(Request $request, StudentSubmission $submission): JsonResponse
    {
        if (!$request->user()->can('delete', $submission)) {
            return $this->forbidden('Unauthorized access to this submission.');
        }

        $this->service->deleteSubmission($submission, $request->user());

        return response()->json(['status' => 'success', 'message' => 'Submission deleted.']);
    }

    /**
     * POST /api/assessments/{assessment}/submissions/import — CSV of text answers.
     */
    public function import(Request $request, Assessment $assessment): JsonResponse
    {
        if (!$this->ownsAssessment($request, $assessment)) {
            return $this->forbidden('Unauthorized access to this assessment.');
        }

        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
        ], [
            'file.mimes' => 'The import file must be a CSV.',
            'file.max' => 'The CSV must not exceed 5MB.',
        ]);

        try {
            $result = $this->service->importCsv($assessment, $request->user(), $request->file('file'));
        } catch (SubmissionException $e) {
            return $this->error($e);
        }

        return response()->json([
            'status' => 'success',
            'message' => "{$result['answers_created']} answers imported across {$result['submissions_created']} new submissions.",
            'data' => $result,
        ], 201);
    }

    // ---------------------------------------------------------------- helpers

    /** STEP 34: student submissions are private academic data — OWNER/EDITOR (view_student_data) only. */
    protected function ownsAssessment(Request $request, Assessment $assessment): bool
    {
        $assessment->loadMissing('course');

        return app(\App\Services\CourseAccessService::class)->can($request->user(), $assessment->course, 'view_student_data');
    }

    protected function presentSummary(StudentSubmission $s): array
    {
        return [
            'id' => $s->id,
            'assessment_id' => $s->assessment_id,
            'student_id' => $s->student_id,
            'submission_identifier' => $s->submission_identifier,
            'submitted_at' => $s->submitted_at?->toISOString(),
            'status' => $s->status,
            'grading_status' => $s->grading_status,
            'total_marks' => $s->total_marks !== null ? (float) $s->total_marks : null,
            'awarded_marks' => $s->awarded_marks !== null ? (float) $s->awarded_marks : null,
            'answers_count' => (int) ($s->answers_count ?? 0),
            'reviewed_answers_count' => (int) ($s->reviewed_answers_count ?? 0),
            'student' => $s->student ? [
                'id' => $s->student->id,
                'student_identifier' => $s->student->student_identifier,
                'name' => $s->student->name,
                'section' => $s->student->section,
                'program' => $s->student->program,
            ] : null,
            'created_at' => $s->created_at?->toISOString(),
            'updated_at' => $s->updated_at?->toISOString(),
        ];
    }

    public static function presentAnswer(StudentAnswer $a): array
    {
        $aiGrading = null;
        if ($a->relationLoaded('currentAiGrading') && $a->currentAiGrading) {
            $aiGrading = app(AiGradingService::class)->present($a->currentAiGrading, $a);
        }
        $rubricAlignment = null;
        if ($a->relationLoaded('currentRubricAlignment') && $a->currentRubricAlignment) {
            $rubricAlignment = app(RubricAlignmentService::class)->present($a->currentRubricAlignment, $a);
        }

        return [
            'id' => $a->id,
            'student_submission_id' => $a->student_submission_id,
            'question_id' => $a->question_id,
            'answer_type' => $a->answer_type,
            'answer_text' => $a->answer_text,
            'original_answer_text' => $a->is_faculty_edited ? $a->original_answer_text : null,
            'is_faculty_edited' => (bool) $a->is_faculty_edited,
            'has_file' => $a->hasFile(),
            'answer_file_name' => $a->answer_file_name,
            'answer_file_type' => $a->answer_file_type,
            'answer_file_size' => $a->answer_file_size,
            'awarded_marks' => $a->awarded_marks !== null ? (float) $a->awarded_marks : null,
            'faculty_feedback' => $a->faculty_feedback,
            'answer_status' => $a->answer_status,
            'ai_grading' => $aiGrading,
            'rubric_alignment' => $rubricAlignment,
            'created_at' => $a->created_at?->toISOString(),
            'updated_at' => $a->updated_at?->toISOString(),
        ];
    }

    protected function forbidden(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 403);
    }

    protected function error(SubmissionException $e): JsonResponse
    {
        $body = ['status' => 'error', 'message' => $e->getMessage()];
        if ($e->getDetails()) {
            $body['errors'] = $e->getDetails();
        }

        return response()->json($body, $e->getStatus());
    }
}
