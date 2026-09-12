<?php

namespace App\Services\Explainability\Explainers;

use App\Models\AnalysisReport;
use App\Models\Course;
use App\Models\User;
use App\Services\Explainability\AbstractExplainer;
use App\Services\Explainability\ExplanationBuilder;
use Illuminate\Database\Eloquent\Model;

/** Assessment quality (STEP 13 engine): overall score, per-dimension score/weight/status and "how this score is calculated". */
class AssessmentQualityExplainer extends AbstractExplainer
{
    protected const DIMENSIONS = [
        // key in components  => [weight key, title, analysis key]
        'topic_coverage' => ['topic', 'Topic Coverage', 'topic_analysis'],
        'learning_outcome_coverage' => ['learning_outcome', 'LO Coverage', 'learning_outcome_analysis'],
        'difficulty_balance' => ['difficulty', 'Difficulty Balance', 'difficulty_analysis'],
        'cognitive_diversity' => ['cognitive', 'Cognitive Diversity', 'cognitive_analysis'],
        'question_diversity' => ['question_diversity', 'Question Diversity', 'question_diversity_analysis'],
        'marks_distribution' => ['marks', 'Marks Distribution', 'marks_analysis'],
    ];

    public function type(): string
    {
        return 'assessment_quality';
    }

    public function find(int $id): ?Model
    {
        return AnalysisReport::with('assessment.course')->find($id);
    }

    public function course(Model $target): ?Course
    {
        return $target->assessment?->course;
    }

    public function analysisReportId(Model $target): ?int
    {
        return $target->id;
    }

    public function aiValue(Model $target): array
    {
        return ['overall_score' => $target->overall_score !== null ? (float) $target->overall_score : null, 'analysis_version' => $target->analysis_version];
    }

    public function explain(User $user, Model $target, ExplanationBuilder $b): ExplanationBuilder
    {
        /** @var AnalysisReport $r */
        $r = $target;
        $quality = $r->findings['quality'] ?? $r->findings['quality_engine'] ?? [];
        $overall = $r->overall_score !== null ? round((float) $r->overall_score, 2) : (isset($quality['overall_quality_score']) ? round((float) $quality['overall_quality_score'], 2) : null);
        $rating = $quality['rating'] ?? null;
        $weightsApplied = $quality['weights_applied'] ?? [];
        $configured = config('ai_explainability.quality_weights');
        $excluded = $quality['excluded_components'] ?? [];
        $components = $quality['components'] ?? [];

        $b->analysisContext($r->id, $r->analysis_version, $r->is_current)
            ->method('RULE_BASED', 'Deterministic quality engine: each dimension is scored from the assessment data and combined with configured weights. No generative model is involved.',
                ['Topic coverage', 'LO coverage', 'Difficulty balance vs target', 'Shannon entropy of Bloom levels', 'Shannon entropy of question formats', 'Marks concentration checks'])
            ->model(['name' => 'facultylens-assessment-quality-engine', 'version' => '1.0.0'])
            ->confidence(null, 'Deterministic calculation — no model confidence applies.')
            ->limitations($this->limitations('assessment_quality'));

        if ($overall === null) {
            return $b->result(null, null, 'Unavailable')->summary('No completed quality analysis is stored for this report.')->evidenceStatus('none')
                ->review(['actions' => []]);
        }
        if ($quality === []) {
            $b->evidenceStatus('partial', 'This historical report stores only the overall score; per-dimension details were not recorded.');
        }

        $weightLines = [];
        $available = 0;
        foreach (self::DIMENSIONS as $key => [$wKey, $title, $analysisKey]) {
            $score = $components[$key] ?? null;
            $weight = $weightsApplied[$wKey] ?? null;
            $configuredW = $configured[$wKey] ?? null;
            $analysis = $quality[$analysisKey] ?? [];
            $status = $analysis['status'] ?? ($score === null ? 'UNAVAILABLE' : 'AVAILABLE');
            $isExcluded = in_array($wKey, $excluded, true) || in_array($key, $excluded, true) || $score === null;
            if (!$isExcluded) {
                $available++;
            }
            $weightLines[] = ['dimension' => $title, 'configured_weight' => $configuredW, 'applied_weight' => $weight, 'score' => $score !== null ? round((float) $score, 2) : null, 'status' => $isExcluded ? 'EXCLUDED' : $status];
            $b->evidence([
                'type' => 'dimension', 'label' => $title, 'score' => $score !== null ? round((float) $score, 2) : null,
                'text' => $score === null ? 'Not available — excluded from the overall score.' : ($this->dimensionFinding($key, $analysis) ?? ($analysis['methodology'] ?? null)),
                'meta' => ['configured_weight' => $configuredW, 'applied_weight' => $weight, 'status' => $isExcluded ? 'EXCLUDED' : $status, 'methodology' => $analysis['methodology'] ?? null],
            ]);
        }

        $normalized = $excluded !== [] || $available < count(self::DIMENSIONS);
        $summary = "The overall quality score is {$this->fmt($overall)}" . ($rating ? " ({$this->humanize($rating)})" : '') . ', a weighted combination of the available quality dimensions.';
        if ($normalized) {
            $summary .= ' Some dimensions had no data, so their weight was redistributed across the available dimensions.';
        }

        $b->result($rating, $overall, $this->fmt($overall) . ' / 100', ['rating' => $rating])
            ->summary($summary)
            ->detail('Final score', 'Final Score = Σ (dimension score × applied weight) over the available dimensions')
            ->detail('Weights', $weightLines)
            ->detail('Weights normalized', $normalized ? 'Yes — unavailable dimensions were excluded and remaining weights rescaled to 100%.' : 'No — all six dimensions were available.');

        if (!empty($quality['difficulty_analysis']['distribution'])) {
            $rows = [];
            foreach ($quality['difficulty_analysis']['distribution'] as $row) {
                $rows[] = ['level' => strtoupper((string) ($row['level'] ?? '')), 'actual' => round((float) ($row['question_percentage'] ?? 0), 1), 'target' => round((float) ($row['target_percentage'] ?? 0), 1), 'difference' => round((float) ($row['question_percentage'] ?? 0) - (float) ($row['target_percentage'] ?? 0), 1)];
            }
            $b->detail('Difficulty target vs actual (% of questions)', $rows);
        }

        return $b->link('View assessment analysis', 'analysis', $r->assessment_id, ['analysis_report_id' => $r->id])
            ->review(['overridable' => false, 'actions' => ['REVIEWED']]);
    }

    protected function dimensionFinding(string $key, array $analysis): ?string
    {
        return match ($key) {
            'topic_coverage' => isset($analysis['covered_topics_count'], $analysis['total_topics_defined']) ? "{$analysis['covered_topics_count']} of {$analysis['total_topics_defined']} defined topics are covered." : null,
            'learning_outcome_coverage' => isset($analysis['covered_los_count'], $analysis['total_los_defined']) ? "{$analysis['covered_los_count']} of {$analysis['total_los_defined']} learning outcomes are covered." : null,
            'difficulty_balance' => $this->difficultyFinding($analysis),
            'cognitive_diversity' => isset($analysis['dominant_level']) ? "Dominant level: {$analysis['dominant_level']} (" . round((float) ($analysis['dominant_percentage'] ?? 0), 1) . '% of questions).' : null,
            'question_diversity' => isset($analysis['unique_types_count']) ? "{$analysis['unique_types_count']} distinct question format(s)" . (isset($analysis['dominant_type']) ? "; dominant: {$analysis['dominant_type']}." : '.') : null,
            'marks_distribution' => isset($analysis['high_concentration_detected']) ? ($analysis['high_concentration_detected'] ? 'A single question carries a high share of the marks (' . round((float) ($analysis['highest_single_question_share'] ?? 0), 1) . '%).' : 'No excessive concentration of marks in a single question.') : null,
            default => null,
        };
    }

    protected function difficultyFinding(array $analysis): ?string
    {
        if (empty($analysis['distribution'])) {
            return null;
        }
        $parts = [];
        $worst = null;
        foreach ($analysis['distribution'] as $row) {
            $level = strtoupper((string) ($row['level'] ?? ''));
            $actual = round((float) ($row['question_percentage'] ?? 0), 1);
            $target = round((float) ($row['target_percentage'] ?? 0), 1);
            $parts[] = "{$level} {$actual}% (target {$target}%)";
            $diff = $actual - $target;
            if ($worst === null || abs($diff) > abs($worst[1])) {
                $worst = [$level, $diff];
            }
        }
        $finding = 'Actual: ' . implode(', ', $parts) . '.';
        if ($worst && abs($worst[1]) >= 5) {
            $finding .= sprintf(' %s proportion of %s questions than target (%+.1f percentage points).', $worst[1] > 0 ? 'Higher' : 'Lower', strtolower($worst[0]), $worst[1]);
        }
        return $finding;
    }

    protected function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
