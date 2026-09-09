<?php

namespace App\Services;

use App\Jobs\AnalyzeStudentPerformanceJob;
use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\LearningOutcomePerformanceResult;
use App\Models\PerformanceAnalysisRun;
use App\Models\Question;
use App\Models\QuestionLearningOutcomeAlignment;
use App\Models\QuestionPerformanceResult;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\TopicPerformanceResult;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * STEP 30: Student Performance / Gap Analysis.
 *
 * Deterministic aggregation of FINALIZED faculty marks (never AI suggestions) into question,
 * topic and learning-outcome performance with configurable benchmark, gap bands and a minimum
 * sample size. Results are review signals for faculty; nothing here changes grades or makes
 * academic decisions, and no causal claims are produced.
 *
 * Methodology: average_percentage = Σ(final_marks) / Σ(maximum_marks) × 100 (mark-weighted).
 * For a single question with a fixed maximum this equals the mean of per-response percentages.
 */
class StudentPerformanceService
{
    public const STATUS_CONFLICT = 409;
    public const STATUS_VALIDATION = 422;

    public function __construct(protected AuditLogService $auditLogService) {}

    // ----------------------------------------------------------------- config

    public function expected(): float
    {
        return (float) config('performance.expected_performance_percent', 70);
    }

    public function minResponses(): int
    {
        return max(1, (int) config('performance.min_responses_for_gap_analysis', 5));
    }

    public function thresholds(): array
    {
        return [
            'expected_performance_percent' => $this->expected(),
            'strong_performance_percent' => (float) config('performance.strong_performance_percent', 80),
            'gap_low_threshold' => (float) config('performance.gap_low_threshold', 5),
            'gap_moderate_threshold' => (float) config('performance.gap_moderate_threshold', 10),
            'gap_high_threshold' => (float) config('performance.gap_high_threshold', 20),
            'min_responses_for_gap_analysis' => $this->minResponses(),
            'finalized_grading_statuses' => (array) config('performance.finalized_grading_statuses', ['FACULTY_REVIEWED', 'FINALIZED']),
        ];
    }

    // ---------------------------------------------------------------- request

    /**
     * Create a new analysis run. Small assessments are computed inline; large ones are queued.
     *
     * @return array{run: PerformanceAnalysisRun, created: bool}
     * @throws PerformanceAnalysisException
     */
    public function request(Assessment $assessment, User $user, bool $force = false): array
    {
        $active = PerformanceAnalysisRun::where('assessment_id', $assessment->id)->whereIn('status', PerformanceAnalysisRun::ACTIVE_STATUSES)->orderByDesc('id')->first();
        if ($active) {
            return ['run' => $active, 'created' => false];
        }

        $current = $this->currentRun($assessment);
        if ($current && !$force && $current->isCompleted() && empty($this->staleReasons($current))) {
            throw new PerformanceAnalysisException(
                'A performance analysis for the current finalized grades already exists. Use regenerate to run it again.',
                self::STATUS_CONFLICT
            );
        }

        $finalizedCount = $this->finalizedAnswersQuery($assessment)->count();

        $run = DB::transaction(function () use ($assessment, $user) {
            PerformanceAnalysisRun::where('assessment_id', $assessment->id)->where('is_current', true)->update(['is_current' => false]);

            return PerformanceAnalysisRun::create([
                'assessment_id' => $assessment->id,
                'course_id' => $assessment->course_id,
                'expected_performance_percent' => $this->expected(),
                'minimum_responses' => $this->minResponses(),
                'thresholds' => $this->thresholds(),
                'status' => PerformanceAnalysisRun::STATUS_PENDING,
                'is_current' => true,
                'requested_by' => $user->id,
            ]);
        });

        $this->auditLogService->log($current ? 'PERFORMANCE_ANALYSIS_REGENERATED' : 'PERFORMANCE_ANALYSIS_REQUESTED', $run, $run->id, [
            'assessment_id' => $assessment->id,
            'previous_run_id' => $current?->id,
            'finalized_answers' => $finalizedCount,
        ], $user);

        if ($finalizedCount > (int) config('performance.async_threshold', 500)) {
            AnalyzeStudentPerformanceJob::dispatch($run->id, $user->id);
        } else {
            $this->compute($run);
        }

        return ['run' => $run->fresh(), 'created' => true];
    }

    public function currentRun(Assessment $assessment): ?PerformanceAnalysisRun
    {
        return PerformanceAnalysisRun::where('assessment_id', $assessment->id)->current()->first();
    }

    // ---------------------------------------------------------------- compute

    /**
     * Calculate and persist the snapshot for a PENDING run.
     */
    public function compute(PerformanceAnalysisRun $run): PerformanceAnalysisRun
    {
        if (!in_array($run->status, [PerformanceAnalysisRun::STATUS_PENDING, PerformanceAnalysisRun::STATUS_PROCESSING], true)) {
            return $run;
        }
        $run->update(['status' => PerformanceAnalysisRun::STATUS_PROCESSING, 'error_message' => null]);

        try {
            $assessment = Assessment::with('course')->findOrFail($run->assessment_id);
            $metrics = $this->calculate($assessment);

            DB::transaction(function () use ($run, $metrics) {
                $run->questionResults()->delete();
                $run->topicResults()->delete();
                $run->learningOutcomeResults()->delete();

                foreach ($metrics['questions'] as $q) {
                    QuestionPerformanceResult::create(['performance_analysis_run_id' => $run->id] + $q);
                }
                foreach ($metrics['topics'] as $t) {
                    TopicPerformanceResult::create(['performance_analysis_run_id' => $run->id] + $t);
                }
                foreach ($metrics['learning_outcomes'] as $lo) {
                    LearningOutcomePerformanceResult::create(['performance_analysis_run_id' => $run->id] + $lo);
                }

                $run->update([
                    'status' => PerformanceAnalysisRun::STATUS_COMPLETED,
                    'grading_fingerprint' => $metrics['grading_fingerprint'],
                    'student_count' => $metrics['overall']['student_count'],
                    'submission_count' => $metrics['overall']['submission_count'],
                    'finalized_answer_count' => $metrics['overall']['finalized_answer_count'],
                    'question_count' => $metrics['overall']['question_count'],
                    'overall_average_percentage' => $metrics['overall']['average_percentage'],
                    'overall_gap' => $metrics['overall']['performance_gap'],
                    'overall_status' => $metrics['overall']['performance_status'],
                    'summary' => $metrics['summary'],
                    'analyzed_at' => now(),
                ]);
            });

            self::invalidateCache($run->assessment_id);

            $this->auditLogService->log('PERFORMANCE_ANALYSIS_GENERATED', $run, $run->id, [
                'assessment_id' => $run->assessment_id,
                'finalized_answers' => $metrics['overall']['finalized_answer_count'],
                'overall_average_percentage' => $metrics['overall']['average_percentage'],
                'overall_status' => $metrics['overall']['performance_status'],
                'gap_areas' => count($metrics['summary']['gap_areas']),
                'strong_areas' => count($metrics['summary']['strong_areas']),
            ], $run->requester);
        } catch (\Throwable $e) {
            Log::error('Performance analysis failed for run ' . $run->id . ': ' . get_class($e) . ' ' . $e->getMessage());
            $run->update([
                'status' => PerformanceAnalysisRun::STATUS_FAILED,
                'error_message' => 'The performance analysis could not be completed. Please try again.',
            ]);
        }

        return $run->fresh();
    }

    /**
     * Pure calculation of all metrics for an assessment from finalized marks.
     */
    public function calculate(Assessment $assessment): array
    {
        $questions = Question::where('assessment_id', $assessment->id)->orderBy('question_number')->get();
        $answers = $this->loadFinalizedAnswers($assessment);
        $submissionCount = StudentSubmission::where('assessment_id', $assessment->id)->count();
        $byQuestion = $answers->groupBy('question_id');

        $context = $this->contextByQuestion($answers);
        $questionResults = [];
        $questionStats = [];

        foreach ($questions as $q) {
            $rows = $byQuestion->get($q->id, collect());
            $marks = $rows->pluck('awarded_marks')->map(fn ($m) => (float) $m)->values();
            $max = round((float) $q->marks, 2);
            $n = $marks->count();
            $sum = (float) $marks->sum();
            $avgPct = ($n > 0 && $max > 0) ? $sum / ($n * $max) * 100 : null;
            $status = $this->classify($avgPct, $n);
            $gap = $avgPct !== null ? $this->gap($avgPct) : null;
            $difficulty = $q->difficulty_level ?: $q->ai_difficulty_level;
            $cognitive = $q->cognitive_level ?: $q->ai_cognitive_level;
            $topics = $this->topicsOf($q);
            $ctx = $context[$q->id] ?? ['ai_avg' => null, 'alignment_avg' => null];

            $questionStats[$q->id] = ['sum' => $sum, 'max_total' => $n * $max, 'n' => $n, 'max' => $max];

            $questionResults[] = [
                'question_id' => $q->id,
                'question_number' => $q->question_number,
                'question_text_excerpt' => Str::limit((string) $q->question_text, 250, '…'),
                'maximum_marks' => $max,
                'response_count' => $n,
                'submission_count' => $submissionCount,
                'average_marks' => $n ? round($sum / $n, 2) : null,
                'average_percentage' => $avgPct !== null ? round($avgPct, 2) : null,
                'median_marks' => $n ? round($this->median($marks->all()), 2) : null,
                'minimum_marks' => $n ? round((float) $marks->min(), 2) : null,
                'max_awarded_marks' => $n ? round((float) $marks->max(), 2) : null,
                'performance_gap' => $gap !== null ? round($gap, 2) : null,
                'performance_status' => $status,
                'difficulty_level' => $difficulty ? strtolower((string) $difficulty) : null,
                'cognitive_level' => $cognitive ?: null,
                'topics' => $topics,
                'ai_suggested_average_percentage' => $ctx['ai_avg'],
                'rubric_alignment_average' => $ctx['alignment_avg'],
                'review_signals' => $this->reviewSignals($status, $gap, $difficulty, $cognitive, $ctx['alignment_avg'], $n),
            ];
        }

        // Topics (STEP 10 ai_topics). A question contributes to each of its topics.
        $topicBuckets = [];
        foreach ($questions as $q) {
            foreach ($this->topicsOf($q) as $topic) {
                $key = mb_strtolower($topic);
                $topicBuckets[$key] ??= ['topic' => $topic, 'question_ids' => []];
                $topicBuckets[$key]['question_ids'][] = $q->id;
            }
        }
        $topicResults = [];
        foreach ($topicBuckets as $bucket) {
            $topicResults[] = ['topic' => $bucket['topic']] + $this->aggregate($bucket['question_ids'], $questionStats);
        }
        usort($topicResults, fn ($a, $b) => ($a['average_percentage'] ?? 101) <=> ($b['average_percentage'] ?? 101));

        // Learning outcomes: faculty-assigned LO (authoritative) + STEP 11 strong alignments.
        $loBuckets = [];
        $los = $assessment->course->learningOutcomes()->orderBy('sort_order')->get()->keyBy('id');
        foreach ($questions as $q) {
            if ($q->learning_outcome_id && $los->has($q->learning_outcome_id)) {
                $loBuckets[$q->learning_outcome_id]['question_ids'][$q->id] = 'faculty';
            }
        }
        foreach ($this->alignedQuestionLos($assessment) as $qid => $loIds) {
            foreach ($loIds as $loId) {
                if ($los->has($loId) && !isset($loBuckets[$loId]['question_ids'][$qid])) {
                    $loBuckets[$loId]['question_ids'][$qid] = 'alignment';
                }
            }
        }
        $loResults = [];
        foreach ($los as $lo) {
            $qids = array_keys($loBuckets[$lo->id]['question_ids'] ?? []);
            $loResults[] = [
                'learning_outcome_id' => $lo->id,
                'lo_code' => $lo->code,
                'lo_description' => Str::limit((string) $lo->description, 480, '…'),
            ] + $this->aggregate($qids, $questionStats);
        }

        // Overall
        $totalSum = array_sum(array_column($questionStats, 'sum'));
        $totalMax = array_sum(array_column($questionStats, 'max_total'));
        $studentCount = $answers->pluck('student_id')->unique()->count();
        $overallPct = $totalMax > 0 ? $totalSum / $totalMax * 100 : null;
        $overall = [
            'student_count' => $studentCount,
            'submission_count' => $submissionCount,
            'finalized_answer_count' => $answers->count(),
            'question_count' => $questions->count(),
            'average_percentage' => $overallPct !== null ? round($overallPct, 2) : null,
            'expected_performance_percent' => $this->expected(),
            'performance_gap' => $overallPct !== null ? round($this->gap($overallPct), 2) : null,
            'performance_status' => $this->classify($overallPct, $studentCount),
        ];

        return [
            'overall' => $overall,
            'questions' => $questionResults,
            'topics' => $topicResults,
            'learning_outcomes' => $loResults,
            'summary' => $this->summarize($questionResults, $topicResults, $loResults),
            'grading_fingerprint' => $this->gradingFingerprint($assessment, $answers, $questions),
        ];
    }

    // -------------------------------------------------------- student-level

    /**
     * One student's performance in an assessment (authorized faculty only). Areas for review,
     * never labels of ability. Unfinalized marks are shown as such and excluded from totals.
     */
    public function studentPerformance(Student $student, Assessment $assessment): array
    {
        $submission = StudentSubmission::where('assessment_id', $assessment->id)->where('student_id', $student->id)->with('answers')->first();
        $questions = Question::where('assessment_id', $assessment->id)->orderBy('question_number')->get();
        $finalStatuses = (array) config('performance.finalized_grading_statuses');
        $submissionFinal = $submission && in_array($submission->grading_status, $finalStatuses, true);
        $answersByQ = $submission ? $submission->answers->keyBy('question_id') : collect();

        $rows = [];
        $sum = 0.0;
        $max = 0.0;
        $topicAgg = [];
        $loAgg = [];
        $los = $assessment->course->learningOutcomes()->get()->keyBy('id');

        foreach ($questions as $q) {
            $a = $answersByQ->get($q->id);
            $finalized = $a && $submissionFinal && $a->awarded_marks !== null && $a->answer_status === StudentAnswer::STATUS_REVIEWED;
            $marks = $finalized ? round((float) $a->awarded_marks, 2) : null;
            $qMax = round((float) $q->marks, 2);
            $pct = ($finalized && $qMax > 0) ? round($marks / $qMax * 100, 2) : null;
            if ($finalized) {
                $sum += $marks;
                $max += $qMax;
                foreach ($this->topicsOf($q) as $t) {
                    $topicAgg[mb_strtolower($t)] ??= ['label' => $t, 'sum' => 0.0, 'max' => 0.0];
                    $topicAgg[mb_strtolower($t)]['sum'] += $marks;
                    $topicAgg[mb_strtolower($t)]['max'] += $qMax;
                }
                if ($q->learning_outcome_id && $los->has($q->learning_outcome_id)) {
                    $lo = $los->get($q->learning_outcome_id);
                    $loAgg[$lo->id] ??= ['label' => $lo->code, 'description' => $lo->description, 'sum' => 0.0, 'max' => 0.0];
                    $loAgg[$lo->id]['sum'] += $marks;
                    $loAgg[$lo->id]['max'] += $qMax;
                }
            }
            $rows[] = [
                'question_id' => $q->id,
                'question_number' => $q->question_number,
                'question_text_excerpt' => Str::limit((string) $q->question_text, 160, '…'),
                'maximum_marks' => $qMax,
                'answered' => $a !== null,
                'awarded_marks' => $a && $a->awarded_marks !== null ? round((float) $a->awarded_marks, 2) : null,
                'is_finalized' => $finalized,
                'percentage' => $pct,
                'topics' => $this->topicsOf($q),
                'learning_outcome_code' => $q->learning_outcome_id && $los->has($q->learning_outcome_id) ? $los->get($q->learning_outcome_id)->code : null,
            ];
        }

        $expected = $this->expected();
        $areas = [];
        foreach ([['topic', $topicAgg], ['learning_outcome', $loAgg]] as [$kind, $agg]) {
            foreach ($agg as $item) {
                if ($item['max'] <= 0) {
                    continue;
                }
                $pct = round($item['sum'] / $item['max'] * 100, 2);
                if ($pct < $expected) {
                    $areas[] = ['type' => $kind, 'label' => $item['label'], 'description' => $item['description'] ?? null, 'percentage' => $pct, 'gap' => round($expected - $pct, 2)];
                }
            }
        }
        usort($areas, fn ($a, $b) => $b['gap'] <=> $a['gap']);

        return [
            'student' => ['id' => $student->id, 'student_identifier' => $student->student_identifier, 'name' => $student->name],
            'assessment' => ['id' => $assessment->id, 'title' => $assessment->title],
            'submission_id' => $submission?->id,
            'submission_status' => $submission?->status,
            'grading_status' => $submission?->grading_status,
            'has_finalized_grades' => $max > 0,
            'finalized_question_count' => count(array_filter($rows, fn ($r) => $r['is_finalized'])),
            'question_count' => $questions->count(),
            'total_awarded_marks' => $max > 0 ? round($sum, 2) : null,
            'total_maximum_marks' => $max > 0 ? round($max, 2) : null,
            'overall_percentage' => $max > 0 ? round($sum / $max * 100, 2) : null,
            'expected_performance_percent' => $expected,
            'questions' => $rows,
            'areas_for_review' => $areas,
            'note' => 'Individual results are shown for authorized faculty review only. They describe performance on this assessment, not student ability.',
        ];
    }

    // ---------------------------------------------------------- presentation

    public function present(PerformanceAnalysisRun $run, bool $withDetails = true): array
    {
        $stale = $this->staleReasons($run);
        if ($stale && $run->status === PerformanceAnalysisRun::STATUS_COMPLETED) {
            $run->update(['status' => PerformanceAnalysisRun::STATUS_STALE]);
        }

        $data = [
            'id' => $run->id,
            'assessment_id' => $run->assessment_id,
            'course_id' => $run->course_id,
            'status' => $run->status,
            'is_current' => (bool) $run->is_current,
            'is_stale' => count($stale) > 0,
            'stale_reasons' => $stale,
            'expected_performance_percent' => (float) $run->expected_performance_percent,
            'minimum_responses' => (int) $run->minimum_responses,
            'thresholds' => $run->thresholds,
            'student_count' => (int) $run->student_count,
            'submission_count' => (int) $run->submission_count,
            'finalized_answer_count' => (int) $run->finalized_answer_count,
            'question_count' => (int) $run->question_count,
            'overall_average_percentage' => $run->overall_average_percentage !== null ? (float) $run->overall_average_percentage : null,
            'overall_gap' => $run->overall_gap !== null ? (float) $run->overall_gap : null,
            'overall_status' => $run->overall_status,
            'summary' => $run->summary ?? ['gap_areas' => [], 'strong_areas' => [], 'status_counts' => []],
            'error_message' => $run->error_message,
            'analyzed_at' => $run->analyzed_at?->toISOString(),
            'created_at' => $run->created_at?->toISOString(),
            'limitations' => 'Performance analysis is based on available finalized grading data. Aggregate results may be affected by sample size, missing responses, assessment design, grading variation, and question characteristics. Identified gaps are signals for faculty review rather than definitive conclusions about student learning.',
        ];

        if ($withDetails) {
            $run->loadMissing(['questionResults', 'topicResults', 'learningOutcomeResults']);
            $data['questions'] = $this->presentQuestions($run);
            $data['topics'] = $this->presentTopics($run);
            $data['learning_outcomes'] = $this->presentLearningOutcomes($run);
        }

        return $data;
    }

    public function presentQuestions(PerformanceAnalysisRun $run): array
    {
        return $run->questionResults->map(fn (QuestionPerformanceResult $r) => [
            'id' => $r->id,
            'question_id' => $r->question_id,
            'question_number' => $r->question_number,
            'question_text_excerpt' => $r->question_text_excerpt,
            'maximum_marks' => (float) $r->maximum_marks,
            'response_count' => (int) $r->response_count,
            'submission_count' => (int) $r->submission_count,
            'average_marks' => $this->num($r->average_marks),
            'average_percentage' => $this->num($r->average_percentage),
            'median_marks' => $this->num($r->median_marks),
            'minimum_marks' => $this->num($r->minimum_marks),
            'max_awarded_marks' => $this->num($r->max_awarded_marks),
            'performance_gap' => $this->num($r->performance_gap),
            'performance_status' => $r->performance_status,
            'difficulty_level' => $r->difficulty_level,
            'cognitive_level' => $r->cognitive_level,
            'topics' => $r->topics ?? [],
            'ai_suggested_average_percentage' => $this->num($r->ai_suggested_average_percentage),
            'rubric_alignment_average' => $this->num($r->rubric_alignment_average),
            'review_signals' => $r->review_signals ?? [],
        ])->values()->all();
    }

    public function presentTopics(PerformanceAnalysisRun $run): array
    {
        return $run->topicResults->map(fn (TopicPerformanceResult $r) => [
            'id' => $r->id,
            'topic' => $r->topic,
            'question_count' => (int) $r->question_count,
            'question_ids' => $r->question_ids ?? [],
            'response_count' => (int) $r->response_count,
            'total_marks' => $this->num($r->total_marks),
            'average_percentage' => $this->num($r->average_percentage),
            'performance_gap' => $this->num($r->performance_gap),
            'performance_status' => $r->performance_status,
        ])->values()->all();
    }

    public function presentLearningOutcomes(PerformanceAnalysisRun $run): array
    {
        return $run->learningOutcomeResults->map(fn (LearningOutcomePerformanceResult $r) => [
            'id' => $r->id,
            'learning_outcome_id' => $r->learning_outcome_id,
            'lo_code' => $r->lo_code,
            'lo_description' => $r->lo_description,
            'question_count' => (int) $r->question_count,
            'question_ids' => $r->question_ids ?? [],
            'response_count' => (int) $r->response_count,
            'total_marks' => $this->num($r->total_marks),
            'average_percentage' => $this->num($r->average_percentage),
            'performance_gap' => $this->num($r->performance_gap),
            'performance_status' => $r->performance_status,
        ])->values()->all();
    }

    /** @return string[] */
    public function staleReasons(PerformanceAnalysisRun $run): array
    {
        if (!$run->isCompleted() || !$run->grading_fingerprint) {
            return [];
        }
        $assessment = Assessment::find($run->assessment_id);
        if (!$assessment) {
            return ['The assessment no longer exists.'];
        }
        $current = $this->gradingFingerprint($assessment);
        return $current === $run->grading_fingerprint ? [] : ['Finalized grades, questions or outcome mappings changed after this analysis.'];
    }

    // ------------------------------------------------------------------ cache

    public static function cacheKey(int $assessmentId, string $part): string
    {
        return "assessment:{$assessmentId}:performance:{$part}";
    }

    public static function invalidateCache(int $assessmentId): void
    {
        foreach (['summary', 'questions', 'topics', 'learning-outcomes'] as $part) {
            Cache::forget(self::cacheKey($assessmentId, $part));
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Finalized = awarded marks present, answer REVIEWED, submission grading_status in the configured final set.
     */
    public function finalizedAnswersQuery(Assessment $assessment)
    {
        return StudentAnswer::query()
            ->join('student_submissions', 'student_submissions.id', '=', 'student_answers.student_submission_id')
            ->where('student_submissions.assessment_id', $assessment->id)
            ->whereIn('student_submissions.grading_status', (array) config('performance.finalized_grading_statuses'))
            ->where('student_answers.answer_status', StudentAnswer::STATUS_REVIEWED)
            ->whereNotNull('student_answers.awarded_marks');
    }

    protected function loadFinalizedAnswers(Assessment $assessment): Collection
    {
        return $this->finalizedAnswersQuery($assessment)
            ->orderBy('student_answers.id')
            ->get([
                'student_answers.id as id',
                'student_answers.question_id',
                'student_answers.student_submission_id',
                'student_submissions.student_id',
                'student_answers.awarded_marks',
                'student_answers.updated_at',
            ]);
    }

    /** STEP 27 / STEP 28 context per question (supporting information only). */
    protected function contextByQuestion(Collection $answers): array
    {
        if ($answers->isEmpty()) {
            return [];
        }
        $ids = $answers->pluck('id')->all();
        $out = [];

        $ai = DB::table('ai_grading_results')
            ->whereIn('student_answer_id', $ids)->where('is_current', true)
            ->whereIn('grading_status', ['COMPLETED', 'REVIEWED', 'FINALIZED'])
            ->whereNotNull('suggested_marks')->where('maximum_marks', '>', 0)
            ->select('question_id', DB::raw('SUM(suggested_marks) as s'), DB::raw('SUM(maximum_marks) as m'))
            ->groupBy('question_id')->get();
        foreach ($ai as $row) {
            $out[$row->question_id]['ai_avg'] = (float) $row->m > 0 ? round((float) $row->s / (float) $row->m * 100, 2) : null;
        }

        $align = DB::table('answer_rubric_alignments')
            ->whereIn('student_answer_id', $ids)->where('is_current', true)
            ->whereIn('analysis_status', ['COMPLETED', 'REVIEWED', 'STALE'])
            ->whereNotNull('overall_alignment_score')
            ->select('question_id', DB::raw('AVG(overall_alignment_score) as a'))
            ->groupBy('question_id')->get();
        foreach ($align as $row) {
            $out[$row->question_id]['alignment_avg'] = round((float) $row->a, 2);
        }

        foreach ($out as $qid => $ctx) {
            $out[$qid] = ['ai_avg' => $ctx['ai_avg'] ?? null, 'alignment_avg' => $ctx['alignment_avg'] ?? null];
        }
        return $out;
    }

    /** question_id => [learning_outcome_id, ...] from the current STEP 11 report, strong alignments only. */
    protected function alignedQuestionLos(Assessment $assessment): array
    {
        $report = AnalysisReport::where('assessment_id', $assessment->id)->where('analysis_status', 'completed')->orderByDesc('id')->first();
        if (!$report) {
            return [];
        }
        $levels = (array) config('performance.lo_alignment_levels', ['STRONG_ALIGNMENT']);
        $map = [];
        QuestionLearningOutcomeAlignment::where('analysis_report_id', $report->id)
            ->whereIn('alignment', $levels)
            ->get(['question_id', 'learning_outcome_id'])
            ->each(function ($a) use (&$map) {
                $map[$a->question_id][] = $a->learning_outcome_id;
            });
        return $map;
    }

    /** Mark-weighted aggregate over a set of questions using per-question stats. */
    protected function aggregate(array $questionIds, array $stats): array
    {
        $sum = 0.0;
        $maxTotal = 0.0;
        $n = 0;
        $marks = 0.0;
        foreach ($questionIds as $qid) {
            if (!isset($stats[$qid])) {
                continue;
            }
            $sum += $stats[$qid]['sum'];
            $maxTotal += $stats[$qid]['max_total'];
            $n += $stats[$qid]['n'];
            $marks += $stats[$qid]['max'];
        }
        $pct = $maxTotal > 0 ? $sum / $maxTotal * 100 : null;
        return [
            'question_count' => count($questionIds),
            'question_ids' => array_values($questionIds),
            'response_count' => $n,
            'total_marks' => round($marks, 2),
            'average_percentage' => $pct !== null ? round($pct, 2) : null,
            'performance_gap' => $pct !== null ? round($this->gap($pct), 2) : null,
            'performance_status' => $this->classify($pct, $n),
        ];
    }

    public function gap(float $averagePercentage): float
    {
        return $this->expected() - $averagePercentage;
    }

    public function classify(?float $averagePercentage, int $responses): string
    {
        if ($averagePercentage === null || $responses < $this->minResponses()) {
            return PerformanceAnalysisRun::PERF_INSUFFICIENT;
        }
        $t = $this->thresholds();
        $gap = $this->gap($averagePercentage);
        if ($gap >= $t['gap_high_threshold']) {
            return PerformanceAnalysisRun::PERF_HIGH_GAP;
        }
        if ($gap >= $t['gap_moderate_threshold']) {
            return PerformanceAnalysisRun::PERF_MODERATE_GAP;
        }
        if ($gap >= $t['gap_low_threshold']) {
            return PerformanceAnalysisRun::PERF_MINOR_GAP;
        }
        if ($averagePercentage >= $t['strong_performance_percent']) {
            return PerformanceAnalysisRun::PERF_STRONG;
        }
        return PerformanceAnalysisRun::PERF_ON_TARGET;
    }

    protected function reviewSignals(string $status, ?float $gap, ?string $difficulty, ?string $cognitive, ?float $alignmentAvg, int $n): array
    {
        $signals = [];
        if ($status === PerformanceAnalysisRun::PERF_INSUFFICIENT) {
            $signals[] = $n === 0
                ? 'No finalized responses are available for this question yet.'
                : 'Insufficient responses for a reliable aggregate analysis.';
            return $signals;
        }
        $d = strtolower((string) $difficulty);
        $isGap = in_array($status, [PerformanceAnalysisRun::PERF_MODERATE_GAP, PerformanceAnalysisRun::PERF_HIGH_GAP], true);
        if ($isGap && $d === 'easy') {
            $signals[] = 'Low performance was observed on a question classified as easy. Review of question wording, instructional coverage, and grading criteria may be useful.';
        } elseif ($status === PerformanceAnalysisRun::PERF_HIGH_GAP && $d === 'hard') {
            $signals[] = 'Students performed substantially below the configured benchmark on a hard question.';
        } elseif ($isGap) {
            $signals[] = 'Performance is below the configured benchmark. Review may be useful.';
        }
        if ($isGap && $cognitive && in_array(strtolower($cognitive), ['analyze', 'evaluate', 'create'], true)) {
            $signals[] = 'This question targets a higher cognitive level (' . ucfirst(strtolower($cognitive)) . '); consider whether the assessment format supports it.';
        }
        if ($isGap && $alignmentAvg !== null && $alignmentAvg < $this->expected()) {
            $signals[] = 'Answer–rubric alignment is also low for this question; reviewing both the rubric and the question may be useful.';
        }
        if ($status === PerformanceAnalysisRun::PERF_STRONG) {
            $signals[] = 'High observed performance on this question.';
        }
        return $signals;
    }

    protected function summarize(array $questions, array $topics, array $los): array
    {
        $gapAreas = [];
        $strongAreas = [];
        $statusCounts = array_fill_keys(PerformanceAnalysisRun::PERFORMANCE_STATUSES, 0);

        foreach ($los as $lo) {
            $this->collectArea($gapAreas, $strongAreas, 'learning_outcome', $lo['lo_code'], $lo['lo_description'], $lo['average_percentage'], $lo['performance_gap'], $lo['performance_status'], $lo['learning_outcome_id']);
        }
        foreach ($topics as $t) {
            $this->collectArea($gapAreas, $strongAreas, 'topic', $t['topic'], null, $t['average_percentage'], $t['performance_gap'], $t['performance_status'], null);
        }
        foreach ($questions as $q) {
            $statusCounts[$q['performance_status']] = ($statusCounts[$q['performance_status']] ?? 0) + 1;
            $this->collectArea($gapAreas, $strongAreas, 'question', 'Q' . $q['question_number'], $q['question_text_excerpt'], $q['average_percentage'], $q['performance_gap'], $q['performance_status'], $q['question_id']);
        }
        usort($gapAreas, fn ($a, $b) => $b['performance_gap'] <=> $a['performance_gap']);
        usort($strongAreas, fn ($a, $b) => $b['average_percentage'] <=> $a['average_percentage']);

        return [
            'status_counts' => $statusCounts,
            'gap_areas' => array_slice($gapAreas, 0, 10),
            'strong_areas' => array_slice($strongAreas, 0, 10),
            'los_with_gaps' => count(array_filter($los, fn ($lo) => in_array($lo['performance_status'], PerformanceAnalysisRun::GAP_STATUSES, true))),
            'topics_with_gaps' => count(array_filter($topics, fn ($t) => in_array($t['performance_status'], PerformanceAnalysisRun::GAP_STATUSES, true))),
            'questions_with_gaps' => count(array_filter($questions, fn ($q) => in_array($q['performance_status'], PerformanceAnalysisRun::GAP_STATUSES, true))),
            'insufficient_data_count' => count(array_filter($questions, fn ($q) => $q['performance_status'] === PerformanceAnalysisRun::PERF_INSUFFICIENT)),
        ];
    }

    protected function collectArea(array &$gaps, array &$strong, string $type, string $label, ?string $description, ?float $pct, ?float $gap, string $status, ?int $refId): void
    {
        $entry = ['type' => $type, 'label' => $label, 'description' => $description, 'average_percentage' => $pct, 'performance_gap' => $gap, 'performance_status' => $status, 'reference_id' => $refId];
        if (in_array($status, PerformanceAnalysisRun::GAP_STATUSES, true)) {
            $gaps[] = $entry;
        } elseif ($status === PerformanceAnalysisRun::PERF_STRONG) {
            $strong[] = $entry;
        }
    }

    protected function topicsOf(Question $q): array
    {
        $out = [];
        foreach ((array) ($q->ai_topics ?? []) as $t) {
            $name = is_array($t) ? ($t['topic'] ?? $t['name'] ?? null) : $t;
            $name = trim((string) $name);
            if ($name !== '' && !in_array($name, $out, true)) {
                $out[] = mb_substr($name, 0, 255);
            }
        }
        return $out;
    }

    protected function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);
        return $n % 2 ? (float) $values[$mid] : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
    }

    public function gradingFingerprint(Assessment $assessment, ?Collection $answers = null, ?Collection $questions = null): string
    {
        $answers ??= $this->loadFinalizedAnswers($assessment);
        $questions ??= Question::where('assessment_id', $assessment->id)->orderBy('id')->get();
        $parts = [(string) $this->expected(), (string) $this->minResponses()];
        foreach ($questions as $q) {
            $parts[] = implode(':', [$q->id, (string) round((float) $q->marks, 2), (string) $q->learning_outcome_id, json_encode($this->topicsOf($q))]);
        }
        foreach ($answers->sortBy('id') as $a) {
            $parts[] = $a->id . ':' . round((float) $a->awarded_marks, 2);
        }
        return hash('sha256', implode('|', $parts));
    }

    protected function num($value): ?float
    {
        return $value !== null ? (float) $value : null;
    }
}
