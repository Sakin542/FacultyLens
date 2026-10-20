<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Question;
use App\Models\StudentAnswer;
use App\Services\CourseAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Grade one question across all students (the mobile/tablet grading view). Marks are saved through the existing
 * POST /student-answers/{answer}/finalize-grade endpoint, so every rule and audit of normal grading applies.
 */
class GradingQueueController extends Controller
{
    public function __invoke(Request $request, Assessment $assessment, CourseAccessService $access): JsonResponse
    {
        $assessment->loadMissing('course');
        abort_unless($access->can($request->user(), $assessment->course, 'view_student_data'), 403, 'Unauthorized access to this assessment.');
        $validated = $request->validate([
            'question_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['all', 'ungraded', 'graded'])],
        ]);

        $questions = Question::query()->where('assessment_id', $assessment->id)->orderBy('id')
            ->with(['approvedRubric.criteria'])->get(['id', 'assessment_id', 'question_number', 'question_text', 'marks', 'expected_answer']);
        if ($questions->isEmpty()) {
            return response()->json(['status' => 'success', 'data' => ['questions' => [], 'question' => null, 'answers' => []]]);
        }
        $question = $questions->firstWhere('id', (int) ($validated['question_id'] ?? 0)) ?? $questions->first();

        $counts = StudentAnswer::query()->whereIn('question_id', $questions->pluck('id'))
            ->whereHas('submission', fn ($q) => $q->where('assessment_id', $assessment->id))
            ->selectRaw('question_id, COUNT(*) as total, SUM(CASE WHEN awarded_marks IS NULL THEN 1 ELSE 0 END) as ungraded')
            ->groupBy('question_id')->get()->keyBy('question_id');

        $status = $validated['status'] ?? 'all';
        $answers = StudentAnswer::query()->where('question_id', $question->id)
            ->whereHas('submission', fn ($q) => $q->where('assessment_id', $assessment->id))
            ->when($status === 'ungraded', fn ($q) => $q->whereNull('awarded_marks'))
            ->when($status === 'graded', fn ($q) => $q->whereNotNull('awarded_marks'))
            ->with(['submission.student:id,student_identifier,name', 'currentAiGrading'])
            ->orderBy('student_submission_id')->get();

        return response()->json(['status' => 'success', 'data' => [
            'assessment' => ['id' => $assessment->id, 'title' => $assessment->title, 'course_code' => $assessment->course->course_code],
            'questions' => $questions->map(fn (Question $q) => [
                'id' => $q->id,
                'question_number' => $q->question_number,
                'marks' => (float) $q->marks,
                'total' => (int) ($counts[$q->id]->total ?? 0),
                'ungraded' => (int) ($counts[$q->id]->ungraded ?? 0),
            ])->values(),
            'question' => [
                'id' => $question->id,
                'question_number' => $question->question_number,
                'question_text' => $question->question_text,
                'marks' => (float) $question->marks,
                'expected_answer' => $question->expected_answer,
                'rubric' => $question->approvedRubric ? $question->approvedRubric->criteria->map(fn ($c) => ['criterion' => $c->criterion, 'max_marks' => (float) $c->max_marks, 'description' => $c->description])->values() : [],
            ],
            'answers' => $answers->map(fn (StudentAnswer $a) => [
                'id' => $a->id,
                'student' => $a->submission?->student ? ['name' => $a->submission->student->name, 'student_identifier' => $a->submission->student->student_identifier] : null,
                'answer_text' => $a->answer_text,
                'has_file' => $a->hasFile(),
                'awarded_marks' => $a->awarded_marks !== null ? (float) $a->awarded_marks : null,
                'faculty_feedback' => $a->faculty_feedback,
                'ai_suggested_marks' => $a->currentAiGrading?->suggested_marks !== null ? (float) $a->currentAiGrading->suggested_marks : null,
                'submission_status' => $a->submission?->status,
            ])->values(),
        ]]);
    }
}
