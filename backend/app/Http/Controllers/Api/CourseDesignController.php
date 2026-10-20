<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicEvent;
use App\Models\CoPoMapping;
use App\Models\Course;
use App\Models\CoursePlan;
use App\Models\LearningOutcome;
use App\Models\ProgramOutcome;
use App\Services\AiService;
use App\Services\AuditLogService;
use App\Services\CourseAccessService;
use App\Services\Exam\ExamPaperBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** AI-assisted syllabus design (COs + CO→PO mapping) and lesson planning. Drafts are saved; applying is explicit. */
class CourseDesignController extends Controller
{
    public function __construct(protected AiService $ai, protected CourseAccessService $access, protected AuditLogService $audit) {}

    public function plans(Request $request, Course $course): JsonResponse
    {
        $this->authorizeCourse($request, $course, 'view');
        $kind = $request->validate(['kind' => ['nullable', Rule::in(CoursePlan::KINDS)]])['kind'] ?? null;
        $plans = CoursePlan::query()->where('course_id', $course->id)->when($kind, fn ($q) => $q->where('kind', $kind))
            ->with('creator:id,name')->latest('id')->limit(20)->get();

        return response()->json(['status' => 'success', 'data' => $plans->map(fn (CoursePlan $p) => $p->toPayload())->values()]);
    }

    public function draftSyllabus(Request $request, Course $course): JsonResponse
    {
        $this->authorizeCourse($request, $course, 'edit_course');
        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:8000'],
            'weeks' => ['nullable', 'integer', 'min:6', 'max:20'],
            'outcome_count' => ['nullable', 'integer', 'min:3', 'max:6'],
            'topics' => ['nullable', 'array', 'max:40'],
            'topics.*' => ['string', 'max:120'],
        ]);
        [$pos, $poSource] = $this->programOutcomes($course);

        $inputs = [
            'course_code' => (string) $course->course_code,
            'course_name' => (string) $course->course_name,
            'description' => (string) ($validated['description'] ?? $course->description ?? ''),
            'credits' => (float) ($course->credits ?: 3),
            'weeks' => (int) ($validated['weeks'] ?? 14),
            'outcome_count' => $validated['outcome_count'] ?? null,
            'topics' => $validated['topics'] ?? null,
            'program_outcomes' => $pos->map(fn ($p) => ['code' => $p->code, 'title' => (string) $p->title, 'description' => (string) $p->description])->values()->all(),
        ];
        if (trim($inputs['description']) === '' && empty($inputs['topics'])) {
            return response()->json(['status' => 'error', 'message' => 'Add a course description or a list of topics so the draft has something to work from.'], 422);
        }

        try {
            $draft = $this->ai->draftSyllabus($inputs);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 503);
        }
        $draft['program_outcome_source'] = $poSource;
        $draft['existing_outcomes'] = LearningOutcome::query()->where('course_id', $course->id)->orderBy('sort_order')->get(['code', 'description', 'cognitive_level']);

        $plan = CoursePlan::query()->create(['course_id' => $course->id, 'created_by' => $request->user()->id, 'kind' => 'syllabus', 'inputs' => $inputs, 'content' => $draft]);
        $this->audit->log('SYLLABUS_DRAFTED', $plan, $plan->id, ['engine' => $draft['engine'] ?? null], $request->user());

        return response()->json(['status' => 'success', 'message' => 'Syllabus draft ready. Review it, then apply the outcomes you want.', 'data' => $plan->load('creator:id,name')->toPayload()], 201);
    }

    /** Create the reviewed course outcomes and CO→PO links (existing outcome codes are updated only when asked). */
    public function applySyllabus(Request $request, Course $course, CoursePlan $plan): JsonResponse
    {
        $this->authorizeCourse($request, $course, 'edit_course');
        abort_unless($plan->course_id === $course->id && $plan->kind === 'syllabus', 404);
        $validated = $request->validate([
            'outcomes' => ['required', 'array', 'min:1', 'max:12'],
            'outcomes.*.code' => ['required', 'string', 'max:20'],
            'outcomes.*.statement' => ['required', 'string', 'max:1000'],
            'outcomes.*.cognitive_level' => ['nullable', 'string', 'max:16'],
            'links' => ['nullable', 'array', 'max:80'],
            'links.*.co' => ['required', 'string', 'max:20'],
            'links.*.po' => ['required', 'string', 'max:32'],
            'links.*.level' => ['required', 'integer', Rule::in([1, 2, 3])],
            'update_existing' => ['nullable', 'boolean'],
        ]);
        [$pos] = $this->programOutcomes($course);
        $poByCode = $pos->keyBy(fn ($p) => strtoupper($p->code));
        $update = (bool) ($validated['update_existing'] ?? false);

        $result = DB::transaction(function () use ($validated, $course, $poByCode, $update, $request) {
            $created = $updated = $skipped = $links = 0;
            $byCode = [];
            $sort = (int) LearningOutcome::query()->where('course_id', $course->id)->max('sort_order');
            foreach ($validated['outcomes'] as $o) {
                $existing = LearningOutcome::query()->where('course_id', $course->id)->whereRaw('UPPER(code) = ?', [strtoupper($o['code'])])->first();
                $level = ExamPaperBuilder::bloom($o['cognitive_level'] ?? null);
                if ($existing && !$update) {
                    $skipped++;
                    $byCode[strtoupper($o['code'])] = $existing;
                    continue;
                }
                if ($existing) {
                    $existing->fill(['description' => $o['statement'], 'cognitive_level' => $level ?? $existing->cognitive_level])->save();
                    $updated++;
                    $byCode[strtoupper($o['code'])] = $existing;
                } else {
                    $byCode[strtoupper($o['code'])] = LearningOutcome::query()->create(['course_id' => $course->id, 'code' => strtoupper($o['code']), 'description' => $o['statement'], 'cognitive_level' => $level, 'sort_order' => ++$sort]);
                    $created++;
                }
            }
            foreach ($validated['links'] ?? [] as $l) {
                $lo = $byCode[strtoupper($l['co'])] ?? null;
                $po = $poByCode[strtoupper($l['po'])] ?? null;
                if ($lo && $po) {
                    CoPoMapping::query()->updateOrCreate(
                        ['course_id' => $course->id, 'learning_outcome_id' => $lo->id, 'program_outcome_id' => $po->id],
                        ['mapping_level' => (int) $l['level'], 'justification' => 'Accepted from AI syllabus draft', 'created_by' => $request->user()->id],
                    );
                    $links++;
                }
            }

            return compact('created', 'updated', 'skipped', 'links');
        });

        $plan->forceFill(['applied_at' => now()])->save();
        $this->audit->log('SYLLABUS_APPLIED', $plan, $plan->id, $result, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => "Saved: {$result['created']} new outcome(s), {$result['updated']} updated, {$result['skipped']} kept as they were, {$result['links']} CO→PO link(s).",
            'data' => $result,
        ]);
    }

    public function lessonPlan(Request $request, Course $course): JsonResponse
    {
        $this->authorizeCourse($request, $course, 'edit_course');
        $validated = $request->validate([
            'weeks' => ['nullable', 'integer', 'min:6', 'max:20'],
            'sessions_per_week' => ['nullable', 'integer', 'min:1', 'max:5'],
            'session_minutes' => ['nullable', 'integer', 'min:30', 'max:240'],
            'topics' => ['nullable', 'array', 'max:40'],
            'topics.*' => ['string', 'max:120'],
        ]);
        $outcomes = LearningOutcome::query()->where('course_id', $course->id)->orderBy('sort_order')->get(['code', 'description', 'cognitive_level']);
        if ($outcomes->isEmpty()) {
            return response()->json(['status' => 'error', 'message' => 'Add course outcomes first (or draft them with the syllabus assistant).'], 422);
        }
        $topics = $validated['topics'] ?? null;
        if (!$topics) {
            // Reuse the latest syllabus draft's weekly topics when there is one
            $syllabus = CoursePlan::query()->where('course_id', $course->id)->where('kind', 'syllabus')->latest('id')->first();
            $topics = $syllabus ? array_values(array_filter((array) ($syllabus->content['topics'] ?? []))) : null;
        }

        $inputs = [
            'course_name' => (string) $course->course_name,
            'outcomes' => $outcomes->map(fn ($o) => ['code' => $o->code, 'description' => $o->description, 'cognitive_level' => $o->cognitive_level])->values()->all(),
            'weeks' => (int) ($validated['weeks'] ?? 14),
            'sessions_per_week' => (int) ($validated['sessions_per_week'] ?? 2),
            'session_minutes' => (int) ($validated['session_minutes'] ?? 75),
            'topics' => $topics,
        ];
        try {
            $content = $this->ai->lessonPlan($inputs);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 503);
        }

        $plan = CoursePlan::query()->create(['course_id' => $course->id, 'created_by' => $request->user()->id, 'kind' => 'lesson_plan', 'inputs' => $inputs, 'content' => $content]);
        $this->audit->log('LESSON_PLAN_GENERATED', $plan, $plan->id, null, $request->user());

        return response()->json(['status' => 'success', 'message' => 'Lesson plan ready.', 'data' => $plan->load('creator:id,name')->toPayload()], 201);
    }

    /** Put a lesson plan's assessments on the calendar for a semester that starts on the given Monday. */
    public function scheduleLessonPlan(Request $request, CoursePlan $plan): JsonResponse
    {
        $plan->loadMissing('course');
        $this->authorizeCourse($request, $plan->course, 'edit_course');
        abort_unless($plan->kind === 'lesson_plan', 404);
        $validated = $request->validate(['start_date' => ['required', 'date']]);
        $start = Carbon::parse($validated['start_date'])->startOfDay();

        $titles = ['quiz' => 'Quiz', 'midterm' => 'Midterm examination', 'final' => 'Final examination', 'assignment_release' => 'Project brief released', 'assignment_due' => 'Project due'];
        $types = ['quiz' => 'quiz', 'midterm' => 'exam', 'final' => 'exam', 'assignment_release' => 'other', 'assignment_due' => 'assignment_deadline'];
        $created = 0;
        foreach ((array) ($plan->content['assessments'] ?? []) as $a) {
            $date = $start->copy()->addWeeks(max(0, (int) $a['week'] - 1))->addDays(in_array($a['type'], ['assignment_due', 'final'], true) ? 4 : 2)->setTime(10, 0);
            $title = "{$plan->course->course_code} ".($titles[$a['type']] ?? Str::headline($a['type']));
            AcademicEvent::query()->firstOrCreate(
                ['user_id' => $request->user()->id, 'course_id' => $plan->course_id, 'title' => $title, 'starts_at' => $date],
                ['type' => $types[$a['type']] ?? 'other', 'notes' => 'From lesson plan #'.$plan->id.' (week '.$a['week'].', '.implode(', ', (array) ($a['outcomes'] ?? [])).')', 'reminder_offsets' => in_array($a['type'], ['midterm', 'final'], true) ? [10080, 1440] : [1440]],
            );
            $created++;
        }

        return response()->json(['status' => 'success', 'message' => "{$created} assessment date(s) added to your calendar."]);
    }

    public function export(Request $request, CoursePlan $plan): Response
    {
        $plan->loadMissing('course');
        $this->authorizeCourse($request, $plan->course, 'view');
        $view = $plan->kind === 'syllabus' ? 'course-design.syllabus-pdf' : 'course-design.lesson-plan-pdf';
        $bytes = Pdf::loadView($view, ['plan' => $plan, 'course' => $plan->course, 'c' => $plan->content])->setPaper('a4')->output();
        $name = Str::slug($plan->course->course_code.' '.str_replace('_', ' ', $plan->kind)).'.pdf';

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"', 'Cache-Control' => 'no-store']);
    }

    /** @return array{0: \Illuminate\Support\Collection, 1: string} */
    protected function programOutcomes(Course $course): array
    {
        if ($course->program_id) {
            $pos = ProgramOutcome::query()->where('program_id', $course->program_id)->orderBy('sort_order')->get();
            if ($pos->isNotEmpty()) {
                return [$pos, 'course_program'];
            }
        }

        return [ProgramOutcome::query()->orderBy('program_id')->orderBy('sort_order')->limit(30)->get(), 'all_programs'];
    }

    protected function authorizeCourse(Request $request, Course $course, string $ability): void
    {
        abort_unless($this->access->can($request->user(), $course, $ability), 403, 'Unauthorized access to this course.');
    }
}
