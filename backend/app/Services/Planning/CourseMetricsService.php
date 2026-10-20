<?php

namespace App\Services\Planning;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\CoPoMapping;
use App\Models\LearningOutcome;
use App\Models\LearningOutcomePerformanceResult;
use App\Models\PerformanceAnalysisRun;
use App\Models\ProgramOutcome;
use App\Models\Question;
use App\Services\Exam\ExamPaperBuilder;
use App\Services\Exam\ItemAnalysisService;

/**
 * Per-offering course metrics shared by quality trends, the department view and the OBE pack:
 * assessment design (Bloom/difficulty mix, CO coverage, AI quality score), student outcomes
 * (performance, CO attainment) and question quality (item discrimination). Aggregates only.
 */
class CourseMetricsService
{
    public function __construct(protected ItemAnalysisService $items) {}

    public static function semesterOrder(?string $semester): int
    {
        return match (strtolower(trim((string) $semester))) {
            'winter' => 0, 'spring' => 1, 'summer' => 2, 'fall', 'autumn' => 3, default => 4,
        };
    }

    public static function termLabel(Course $course): string
    {
        return trim(($course->semester ?: '').' '.($course->academic_year ?: '')) ?: 'Undated offering';
    }

    public function metrics(Course $course, bool $withItemAnalysis = true): array
    {
        $assessments = Assessment::query()->where('course_id', $course->id)->get(['id', 'title', 'type', 'total_marks', 'assessment_date']);
        $ids = $assessments->pluck('id');
        $questions = Question::query()->whereIn('assessment_id', $ids)->get(['id', 'assessment_id', 'marks', 'cognitive_level', 'ai_cognitive_level', 'difficulty_level', 'ai_difficulty_level', 'learning_outcome_id']);
        $totalMarks = (float) $questions->sum('marks');

        $bloom = [];
        foreach (ExamPaperBuilder::BLOOM as $level) {
            $marks = (float) $questions->filter(fn ($q) => ExamPaperBuilder::bloom($q->cognitive_level ?: $q->ai_cognitive_level) === $level)->sum('marks');
            $bloom[$level] = $totalMarks > 0 ? round($marks / $totalMarks * 100, 1) : 0.0;
        }
        $difficulty = [];
        foreach (ExamPaperBuilder::DIFFICULTY as $level) {
            $marks = (float) $questions->filter(fn ($q) => ExamPaperBuilder::difficulty($q->difficulty_level ?: $q->ai_difficulty_level) === $level)->sum('marks');
            $difficulty[$level] = $totalMarks > 0 ? round($marks / $totalMarks * 100, 1) : 0.0;
        }
        $higherOrder = round(array_sum(array_intersect_key($bloom, array_flip((array) config('obe.higher_order_levels')))), 1);

        $outcomes = LearningOutcome::query()->where('course_id', $course->id)->orderBy('sort_order')->get(['id', 'code', 'description']);
        $assessedOutcomeIds = $questions->pluck('learning_outcome_id')->filter()->unique();
        $coCoverage = $outcomes->count() > 0 ? round($outcomes->whereIn('id', $assessedOutcomeIds)->count() / $outcomes->count() * 100, 1) : null;

        $quality = AnalysisReport::query()->whereIn('assessment_id', $ids)->where('is_current', true)->whereNotNull('overall_score')->avg('overall_score');

        $runs = PerformanceAnalysisRun::query()->whereIn('assessment_id', $ids)->where('is_current', true)->get(['id', 'assessment_id', 'overall_average_percentage', 'student_count']);
        $performance = $runs->whereNotNull('overall_average_percentage')->avg('overall_average_percentage');

        $attainment = $this->coAttainment($course, $runs->pluck('id')->all());

        $discrimination = null;
        $flagged = 0;
        $analysed = 0;
        if ($withItemAnalysis) {
            $values = [];
            foreach ($assessments as $a) {
                $res = $this->items->analyze($a);
                if ($res['sufficient']) {
                    $analysed++;
                    $values[] = $res['summary']['mean_discrimination_index'];
                    $flagged += $res['summary']['flagged_items'];
                }
            }
            $discrimination = $values ? round(array_sum($values) / count($values), 3) : null;
        }

        return [
            'course' => ['id' => $course->id, 'course_code' => $course->course_code, 'course_name' => $course->course_name, 'semester' => $course->semester, 'academic_year' => $course->academic_year, 'term' => self::termLabel($course)],
            'assessments' => $assessments->count(),
            'questions' => $questions->count(),
            'total_marks' => $totalMarks,
            'bloom_percent' => $bloom,
            'higher_order_percent' => $higherOrder,
            'difficulty_percent' => $difficulty,
            'outcomes' => $outcomes->count(),
            'co_coverage_percent' => $coCoverage,
            'ai_quality_score' => $quality === null ? null : round((float) $quality, 1),
            'class_average_percent' => $performance === null ? null : round((float) $performance, 1),
            'co_attainment' => $attainment,
            // Share of the ASSESSED outcomes that were attained; null until any outcome has performance data
            'co_attained_percent' => ($assessed = collect($attainment)->where('assessed', true))->isNotEmpty()
                ? round($assessed->where('attained', true)->count() / $assessed->count() * 100, 1)
                : null,
            'mean_discrimination' => $discrimination,
            'flagged_questions' => $flagged,
            'assessments_with_item_analysis' => $analysed,
        ];
    }

    /**
     * Direct CO attainment from the current performance runs: marks-weighted class average per CO, mapped to the
     * configured 0–3 level scale.
     *
     * @return list<array{id: int, code: string, description: string, average_percent: ?float, level: ?int, attained: bool, assessed: bool}>
     */
    public function coAttainment(Course $course, ?array $runIds = null): array
    {
        if ($runIds === null) {
            $runIds = PerformanceAnalysisRun::query()->where('course_id', $course->id)->where('is_current', true)->pluck('id')->all();
        }
        $results = LearningOutcomePerformanceResult::query()->whereIn('performance_analysis_run_id', $runIds ?: [-1])->get(['learning_outcome_id', 'average_percentage', 'total_marks']);
        $target = (float) config('obe.co_target_percent', 60);

        return LearningOutcome::query()->where('course_id', $course->id)->orderBy('sort_order')->get(['id', 'code', 'description'])
            ->map(function (LearningOutcome $lo) use ($results, $target) {
                $rows = $results->where('learning_outcome_id', $lo->id)->whereNotNull('average_percentage');
                $weight = (float) $rows->sum(fn ($r) => max(1.0, (float) $r->total_marks));
                $avg = $rows->isEmpty() ? null : round($rows->sum(fn ($r) => (float) $r->average_percentage * max(1.0, (float) $r->total_marks)) / $weight, 1);

                return [
                    'id' => $lo->id,
                    'code' => $lo->code,
                    'description' => $lo->description,
                    'average_percent' => $avg,
                    'level' => $avg === null ? null : self::level($avg),
                    'attained' => $avg !== null && $avg >= $target,
                    'assessed' => $avg !== null,
                ];
            })->values()->all();
    }

    /**
     * PO attainment = Σ(CO level × mapping strength) / Σ(mapping strength) over the course's CO→PO mappings.
     *
     * @return list<array<string, mixed>>
     */
    public function poAttainment(Course $course, array $coAttainment): array
    {
        $levels = collect($coAttainment)->keyBy('id');
        $mappings = CoPoMapping::query()->where('course_id', $course->id)->get(['learning_outcome_id', 'program_outcome_id', 'mapping_level']);
        $poIds = $mappings->pluck('program_outcome_id')->unique()->filter();
        $target = (float) config('obe.po_target_level', 2.0);

        return ProgramOutcome::query()->whereIn('id', $poIds)->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'title', 'description'])
            ->map(function (ProgramOutcome $po) use ($mappings, $levels, $target) {
                $num = 0.0;
                $den = 0.0;
                $contributors = [];
                foreach ($mappings->where('program_outcome_id', $po->id) as $m) {
                    $co = $levels->get($m->learning_outcome_id);
                    $strength = (float) self::mappingStrength($m->mapping_level);
                    if ($co && $co['level'] !== null && $strength > 0) {
                        $num += $co['level'] * $strength;
                        $den += $strength;
                        $contributors[] = $co['code'];
                    }
                }
                $value = $den > 0 ? round($num / $den, 2) : null;

                return [
                    'id' => $po->id,
                    'code' => $po->code,
                    'title' => $po->title ?: $po->description,
                    'attainment' => $value,
                    'attained' => $value !== null && $value >= $target,
                    'contributing_cos' => $contributors,
                ];
            })->values()->all();
    }

    public static function level(float $percent): int
    {
        foreach ((array) config('obe.levels') as [$min, $level]) {
            if ($percent >= $min) {
                return (int) $level;
            }
        }

        return 0;
    }

    /** Mapping levels are stored as 1–3 or LOW/MEDIUM/HIGH depending on how they were entered. */
    public static function mappingStrength(mixed $level): int
    {
        if (is_numeric($level)) {
            return max(0, min(3, (int) $level));
        }

        return match (strtoupper((string) $level)) {
            'HIGH', 'STRONG', 'H', 'S' => 3,
            'MEDIUM', 'MODERATE', 'M' => 2,
            'LOW', 'WEAK', 'L', 'W' => 1,
            default => 0,
        };
    }
}
