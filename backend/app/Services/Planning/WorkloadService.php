<?php

namespace App\Services\Planning;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\CourseCollaborator;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Services\CourseAccessService;

/**
 * A faculty member's teaching load in one place: active courses and weekly contact hours, the grading backlog,
 * upcoming exams and deadlines, and a transparent estimate of hours needed this week.
 */
class WorkloadService
{
    public function __construct(protected CourseAccessService $access, protected CalendarService $calendar) {}

    public function forUser(User $user): array
    {
        // Teaching load counts courses the user owns or edits (reviewers/viewers are not teaching them)
        $courses = Course::query()->accessibleBy($user)->get()
            ->filter(fn (Course $c) => in_array($this->access->roleFor($user, $c), [CourseCollaborator::ROLE_OWNER, CourseCollaborator::ROLE_EDITOR], true))
            ->values();
        $active = $courses->filter(fn (Course $c) => strtolower((string) ($c->status ?: 'active')) === 'active')->values();

        $courseRows = $active->map(function (Course $c) {
            $assessmentIds = Assessment::query()->where('course_id', $c->id)->pluck('id');
            $ungraded = $this->ungradedAnswers($assessmentIds->all());

            return [
                'id' => $c->id,
                'course_code' => $c->course_code,
                'course_name' => $c->course_name,
                'term' => CourseMetricsService::termLabel($c),
                'credits' => $c->credits !== null ? (float) $c->credits : null,
                'contact_hours_per_week' => $this->contactHours($c),
                'contact_hours_is_estimate' => $c->contact_hours_per_week === null,
                'assessments' => $assessmentIds->count(),
                'draft_assessments' => Assessment::query()->where('course_id', $c->id)->where('status', 'draft')->count(),
                'ungraded_answers' => $ungraded,
                'submissions_awaiting_grading' => $this->submissionsAwaiting($assessmentIds->all()),
            ];
        })->values();

        $upcoming = collect($this->calendar->events($user, now(), now()->addDays(14)))
            ->filter(fn ($e) => in_array($e['course']['id'] ?? null, $active->pluck('id')->all(), true) || ($e['course'] ?? null) === null)
            ->values();

        $contact = round((float) $courseRows->sum('contact_hours_per_week'), 1);
        $ungradedTotal = (int) $courseRows->sum('ungraded_answers');
        $minutesPerAnswer = (int) config('obe.workload.minutes_per_ungraded_answer', 4);
        $grading = round($ungradedTotal * $minutesPerAnswer / 60, 1);
        $examsThisWeek = collect($this->calendar->events($user, now(), now()->addDays(7)))->whereIn('type', ['exam', 'quiz'])->count();
        $prep = round($examsThisWeek * (float) config('obe.workload.prep_hours_per_upcoming_exam', 3), 1);

        return [
            'courses' => $courseRows->all(),
            'totals' => [
                'active_courses' => $active->count(),
                'contact_hours_per_week' => $contact,
                'ungraded_answers' => $ungradedTotal,
                'submissions_awaiting_grading' => (int) $courseRows->sum('submissions_awaiting_grading'),
                'draft_assessments' => (int) $courseRows->sum('draft_assessments'),
                'upcoming_14d' => $upcoming->count(),
            ],
            'estimate_hours_this_week' => [
                'teaching' => $contact,
                'grading' => $grading,
                'exam_preparation' => $prep,
                'total' => round($contact + $grading + $prep, 1),
                'formula' => "contact hours + ungraded answers × {$minutesPerAnswer} min + exams/quizzes in the next 7 days × ".config('obe.workload.prep_hours_per_upcoming_exam', 3).' h',
            ],
            'upcoming' => $upcoming->take(12)->all(),
        ];
    }

    public function contactHours(Course $c): float
    {
        return (float) ($c->contact_hours_per_week ?? $c->credits ?? 0);
    }

    protected function ungradedAnswers(array $assessmentIds): int
    {
        if ($assessmentIds === []) {
            return 0;
        }

        return StudentAnswer::query()->whereNull('awarded_marks')
            ->whereHas('submission', fn ($q) => $q->whereIn('assessment_id', $assessmentIds))->count();
    }

    protected function submissionsAwaiting(array $assessmentIds): int
    {
        if ($assessmentIds === []) {
            return 0;
        }

        return StudentSubmission::query()->whereIn('assessment_id', $assessmentIds)
            ->whereHas('answers', fn ($q) => $q->whereNull('awarded_marks'))->count();
    }
}
