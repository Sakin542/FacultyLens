<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnswerSimilarityMatch;
use App\Models\AnswerSimilarityRun;
use App\Models\Assessment;
use App\Models\StudentAnswer;
use App\Services\AuditLogService;
use App\Services\CourseAccessService;
use App\Services\Exam\AnswerSimilarityService;
use App\Services\Exam\ItemAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Post-exam tools: item analysis (aggregate, no identities) and the answer plagiarism check (student data). */
class AssessmentIntegrityController extends Controller
{
    public function __construct(protected CourseAccessService $access, protected AuditLogService $audit) {}

    public function itemAnalysis(Request $request, Assessment $assessment, ItemAnalysisService $service): JsonResponse
    {
        $this->authorize($request, $assessment, 'view_analysis');

        return response()->json(['status' => 'success', 'data' => $service->analyze($assessment)]);
    }

    public function latestSimilarity(Request $request, Assessment $assessment): JsonResponse
    {
        $this->authorize($request, $assessment, 'view_student_data');
        $run = AnswerSimilarityRun::query()->where('assessment_id', $assessment->id)->latest('id')->first();

        return response()->json(['status' => 'success', 'data' => $run ? $this->runPayload($run) : null]);
    }

    public function runSimilarity(Request $request, Assessment $assessment, AnswerSimilarityService $service): JsonResponse
    {
        $this->authorize($request, $assessment, 'view_student_data');
        $validated = $request->validate([
            'threshold' => ['nullable', 'numeric', 'min:0.3', 'max:0.95'],
            'use_semantic' => ['nullable', 'boolean'],
        ]);
        $run = $service->run(
            $assessment,
            $request->user(),
            (float) ($validated['threshold'] ?? AnswerSimilarityService::DEFAULT_THRESHOLD),
            filter_var($validated['use_semantic'] ?? true, FILTER_VALIDATE_BOOL),
        );
        $this->audit->log('PLAGIARISM_CHECK_RUN', $assessment, $assessment->id, ['run_id' => $run->id, 'flagged' => $run->pairs_flagged], $request->user());

        return response()->json([
            'status' => 'success',
            'message' => $run->pairs_flagged ? "{$run->pairs_flagged} pair(s) of answers need review." : 'No suspiciously similar answers were found.',
            'data' => $this->runPayload($run),
        ], 201);
    }

    public function reviewMatch(Request $request, AnswerSimilarityMatch $match): JsonResponse
    {
        $match->loadMissing('run.assessment.course');
        $this->authorize($request, $match->run->assessment, 'view_student_data');
        $validated = $request->validate([
            'review_status' => ['required', Rule::in(['pending', 'confirmed', 'dismissed'])],
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);
        $match->forceFill([
            'review_status' => $validated['review_status'],
            'review_note' => $validated['review_note'] ?? $match->review_note,
            'reviewed_by' => $validated['review_status'] === 'pending' ? null : $request->user()->id,
            'reviewed_at' => $validated['review_status'] === 'pending' ? null : now(),
        ])->save();
        $this->audit->log('PLAGIARISM_MATCH_REVIEWED', $match, $match->id, ['status' => $validated['review_status']], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Review saved.', 'data' => $this->matchPayload($match->fresh())]);
    }

    protected function runPayload(AnswerSimilarityRun $run): array
    {
        $run->loadMissing(['runner:id,name', 'matches']);

        return [
            'id' => $run->id,
            'method' => $run->method,
            'threshold' => $run->threshold,
            'answers_compared' => $run->answers_compared,
            'pairs_compared' => $run->pairs_compared,
            'pairs_flagged' => $run->pairs_flagged,
            'summary' => $run->summary,
            'run_by' => $run->runner?->only(['id', 'name']),
            'created_at' => $run->created_at?->toIso8601String(),
            'matches' => $run->matches->map(fn ($m) => $this->matchPayload($m))->values(),
        ];
    }

    protected function matchPayload(AnswerSimilarityMatch $m): array
    {
        $m->loadMissing(['question:id,question_number,question_text', 'answerA.submission.student', 'answerB.submission.student', 'answerA.submission', 'answerB.submission']);
        $side = fn (?StudentAnswer $a) => $a ? [
            'answer_id' => $a->id,
            'submission_id' => $a->student_submission_id,
            'student' => $a->submission?->student ? ['id' => $a->submission->student->id, 'name' => $a->submission->student->name, 'student_identifier' => $a->submission->student->student_identifier] : null,
            'answer_text' => $a->answer_text,
            'awarded_marks' => $a->awarded_marks,
        ] : null;

        return [
            'id' => $m->id,
            'question' => $m->question ? ['id' => $m->question->id, 'question_number' => $m->question->question_number, 'excerpt' => mb_strimwidth((string) $m->question->question_text, 0, 140, '…')] : null,
            'lexical_score' => $m->lexical_score,
            'semantic_score' => $m->semantic_score,
            'combined_score' => $m->combined_score,
            'shared_excerpt' => $m->shared_excerpt,
            'review_status' => $m->review_status,
            'review_note' => $m->review_note,
            'reviewed_at' => $m->reviewed_at?->toIso8601String(),
            'a' => $side($m->answerA),
            'b' => $side($m->answerB),
        ];
    }

    protected function authorize(Request $request, Assessment $assessment, string $ability): void
    {
        $assessment->loadMissing('course');
        abort_unless($this->access->can($request->user(), $assessment->course, $ability), 403, 'Unauthorized access to this assessment.');
    }
}
