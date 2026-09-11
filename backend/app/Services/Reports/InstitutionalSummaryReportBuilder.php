<?php

namespace App\Services\Reports;

use App\Models\Course;
use App\Models\User;
use App\Services\Analytics\AcademicAnalyticsService;
use App\Services\Analytics\AiAnalyticsService;
use Illuminate\Support\Facades\DB;

/**
 * Institutional Summary — institution-level aggregates for authorized institutional roles only.
 * Per-department and per-program rows are counts and averages; no faculty-private or student data.
 */
class InstitutionalSummaryReportBuilder extends AbstractReportBuilder
{
    public function __construct(protected AcademicAnalyticsService $analytics, protected AiAnalyticsService $ai) {}

    public function build(ReportContext $ctx): array
    {
        $a = $this->analytics->overviewForScope($ctx->user, $ctx->filters, $ctx->courseIds, $ctx->assessmentIds);
        $courses = Course::whereIn('courses.id', $ctx->courseIds ?: [-1])->join('users', 'users.id', '=', 'courses.user_id')->leftJoin('programs', 'programs.id', '=', 'courses.program_id')
            ->get(['courses.id', 'courses.course_code', 'courses.semester', 'courses.academic_year', 'courses.program_id', 'users.department', 'programs.code AS program_code', 'programs.name AS program_name']);
        $rows = collect($a['assessments'])->keyBy('assessment_id');
        $byCourse = $rows->groupBy(fn ($r) => $r['course']['id'] ?? 0);

        $departments = $courses->groupBy(fn ($c) => $c->department ?: 'Unspecified')->map(function ($group, $dept) use ($byCourse) {
            $ass = $group->flatMap(fn ($c) => $byCourse->get($c->id, collect()));
            $quality = $ass->pluck('quality_score')->filter(fn ($v) => $v !== null);
            $perf = $ass->pluck('performance_percentage')->filter(fn ($v) => $v !== null);

            return ['department' => $dept, 'courses' => $group->count(), 'faculty' => User::where('department', $dept === 'Unspecified' ? null : $dept)->count(), 'assessments' => $ass->count(), 'questions' => (int) $ass->sum('questions'),
                'analyzed_assessments' => $quality->count(), 'average_quality' => $quality->isEmpty() ? null : round($quality->avg(), 2), 'average_performance' => $perf->isEmpty() ? null : round($perf->avg(), 2), 'high_gaps' => (int) $ass->sum(fn ($r) => (int) ($r['high_gaps'] ?? 0))];
        })->sortBy('department')->values()->all();

        $programs = $courses->whereNotNull('program_id')->groupBy('program_id')->map(function ($group) use ($byCourse) {
            $ass = $group->flatMap(fn ($c) => $byCourse->get($c->id, collect()));
            $quality = $ass->pluck('quality_score')->filter(fn ($v) => $v !== null);

            return ['program' => $group->first()->program_code.' — '.$group->first()->program_name, 'courses' => $group->count(), 'assessments' => $ass->count(), 'questions' => (int) $ass->sum('questions'), 'average_quality' => $quality->isEmpty() ? null : round($quality->avg(), 2)];
        })->values()->all();

        $terms = $courses->groupBy(fn ($c) => trim($c->academic_year.' '.$c->semester))->map(function ($group, $term) use ($byCourse) {
            $ass = $group->flatMap(fn ($c) => $byCourse->get($c->id, collect()));
            $quality = $ass->pluck('quality_score')->filter(fn ($v) => $v !== null);
            $perf = $ass->pluck('performance_percentage')->filter(fn ($v) => $v !== null);

            return ['term' => $term ?: 'Unspecified', 'courses' => $group->count(), 'assessments' => $ass->count(), 'average_quality' => $quality->isEmpty() ? null : round($quality->avg(), 2), 'average_performance' => $perf->isEmpty() ? null : round($perf->avg(), 2)];
        })->sortByDesc('term')->values()->all();

        $aiRuns = (int) DB::table('analysis_reports')->whereIn('assessment_id', $ctx->assessmentIds ?: [-1])->where('analysis_status', 'completed')->count();
        $perf = $a['performance'];
        $summary = [
            $this->kv('Courses', count($ctx->courseIds)),
            $this->kv('Faculty (course owners)', Course::whereIn('id', $ctx->courseIds ?: [-1])->distinct()->count('user_id')),
            $this->kv('Departments', count($departments)),
            $this->kv('Programs', count($programs)),
            $this->kv('Assessments', count($ctx->assessmentIds)),
            $this->kv('Questions', $a['kpis']['questions']['value']),
            $this->kv('Average Assessment Quality', $this->na($a['assessment_quality']['average_score'], '', 'Not analyzed')),
            $this->kv('CO Coverage', $this->na($a['learning_outcomes']['coverage_percentage'], '%', 'No outcomes')),
            $this->kv('Average Student Performance', $perf['available'] ? $perf['average_percentage'].'%' : 'No finalized grades'),
            $this->kv('Open Learning Gaps', $a['learning_gaps']['analyzed_assessments'] ? $a['learning_gaps']['open_gaps'] : 'No analysis'),
            $this->kv('AI Analysis Runs (completed)', $aiRuns),
            $this->kv('AI Evaluation Status', $a['ai_evaluation']['overall_status']),
        ];
        $warnings = [];
        if ($ctx->courseIds === []) {
            $warnings[] = 'No courses match the selected filters.';
        }

        $doc = $this->document($ctx, $summary, [$this->section('note', 'Scope', [], 'Institution-wide aggregated evidence generated for authorized institutional roles. Figures are counts, averages and distributions; no individual faculty or student records are included, and no accreditation claim is made.')], [
            $this->table('departments', 'Departments', ['department' => 'Department', 'faculty' => 'Faculty', 'courses' => 'Courses', 'assessments' => 'Assessments', 'questions' => 'Questions', 'analyzed_assessments' => 'Analyzed', 'average_quality' => 'Avg Quality', 'average_performance' => 'Avg Performance %', 'high_gaps' => 'High Gaps'], $departments),
            $this->table('programs', 'Programs', ['program' => 'Program', 'courses' => 'Courses', 'assessments' => 'Assessments', 'questions' => 'Questions', 'average_quality' => 'Avg Quality'], $programs),
            $this->table('terms', 'Academic Terms', ['term' => 'Term', 'courses' => 'Courses', 'assessments' => 'Assessments', 'average_quality' => 'Avg Quality', 'average_performance' => 'Avg Performance %'], $terms),
            $this->table('quality', 'Assessment Quality Distribution', ['rating' => 'Rating', 'assessments' => 'Assessments'], array_map(fn ($r, $c) => ['rating' => $r, 'assessments' => $c], array_keys($a['assessment_quality']['counts']), $a['assessment_quality']['counts'])),
            $this->table('learning_gaps', 'Learning Gap Classification', ['status' => 'Status', 'outcomes' => 'Outcome Results'], array_map(fn ($s, $c) => ['status' => $s, 'outcomes' => $c], array_keys($a['learning_gaps']['counts']), $a['learning_gaps']['counts'])),
            $this->table('ai_evaluation', 'AI Evaluation Status', ['task' => 'Task', 'headline_metric' => 'Headline Metric', 'value' => 'Value', 'gate_status' => 'Gate'], array_map(fn ($t) => ['task' => $t['task'], 'headline_metric' => $t['headline_metric'], 'value' => $t['evaluated'] ? $t['headline_value'] : 'Not evaluated', 'gate_status' => $t['gate_status'] ?? 'Not evaluated'], $a['ai_evaluation']['tasks'])),
        ], $warnings, $a['meta']['generated_at']);
        $doc['has_data'] = $ctx->courseIds !== [];

        return $doc;
    }
}
