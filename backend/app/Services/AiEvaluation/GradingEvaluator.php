<?php

namespace App\Services\AiEvaluation;

use Illuminate\Support\Collection;

/**
 * STEP 35: AI grading assistance (STEP 27) vs finalized faculty marks.
 * Example: input {ai_marks (offline, from a recorded suggestion) | answer+question+rubric (live)}, max_marks,
 *          optional metadata {question_type, difficulty, cognitive_level, course, assessment} for grouped error analysis,
 *          expected {faculty_marks}. Closeness is a distance, not correctness.
 */
class GradingEvaluator extends TaskEvaluator
{
    public function task(): string
    {
        return 'GRADING_ASSISTANCE';
    }

    public function headlineMetric(): string
    {
        return 'mae';
    }

    public function validateExample(array $input, array $expected): array
    {
        $errors = [];
        if (!isset($expected['faculty_marks']) || !is_numeric($expected['faculty_marks'])) {
            $errors[] = 'Missing numeric faculty_marks (finalized faculty grade).';
        }
        if (!isset($input['max_marks']) || !is_numeric($input['max_marks']) || (float) $input['max_marks'] <= 0) {
            $errors[] = 'Missing positive max_marks.';
        }
        if (!isset($input['ai_marks'])) {
            $this->requireString($input, 'answer', $errors, 'student answer text (or provide ai_marks for offline evaluation)');
            $this->requireString($input, 'question', $errors, 'question text');
            if (!is_array($input['rubric']['criteria'] ?? null) || $input['rubric']['criteria'] === []) {
                $errors[] = 'Missing rubric.criteria for live grading.';
            }
        } elseif (!is_numeric($input['ai_marks'])) {
            $errors[] = 'ai_marks must be numeric.';
        }
        if (isset($expected['faculty_marks'], $input['max_marks']) && is_numeric($expected['faculty_marks']) && (float) $expected['faculty_marks'] > (float) $input['max_marks']) {
            $errors[] = 'faculty_marks exceeds max_marks.';
        }

        return $errors;
    }

    public function predict(Collection $examples): array
    {
        $tolerances = (array) config('ai_evaluation.grading_tolerances');
        $large = (float) config('ai_evaluation.grading_large_error', 2.0);
        $out = [];
        foreach ($examples as $ex) {
            $in = $ex->input_data;
            $faculty = (float) $ex->expected_output['faculty_marks'];
            try {
                if (isset($in['ai_marks'])) {
                    $ai = (float) $in['ai_marks'];
                    $method = 'offline_recorded';
                } else {
                    $criteria = array_values(array_map(fn ($c, $i) => ['id' => (int) ($c['id'] ?? $i + 1), 'criterion' => $c['criterion'] ?? 'Criterion ' . ($i + 1), 'description' => $c['description'] ?? '',
                        'max_marks' => (float) ($c['max_marks'] ?? 0), 'scoring_guidance' => $c['scoring_guidance'] ?? null, 'expected_indicators' => $c['expected_indicators'] ?? []], $in['rubric']['criteria'], array_keys($in['rubric']['criteria'])));
                    $total = (float) ($in['rubric']['total_marks'] ?? $in['max_marks']);
                    $res = $this->ai->gradeAnswer([
                        'student_answer' => ['text' => $in['answer']],
                        'question' => ['text' => $in['question'], 'total_marks' => $total, 'question_type' => strtoupper($in['question_type'] ?? 'DESCRIPTIVE'), 'expected_answer' => $in['expected_answer'] ?? null],
                        'rubric' => ['total_marks' => $total, 'criteria' => $criteria],
                    ]);
                    $ai = (float) $res['suggested_marks'];
                    $method = 'live';
                }
            } catch (\Throwable $e) {
                $out[$ex->id] = $this->inferenceError($e);
                continue;
            }
            $err = $ai - $faculty;
            $abs = abs($err);
            $out[$ex->id] = [
                'prediction' => ['ai_marks' => round($ai, 2), 'faculty_marks' => $faculty, 'max_marks' => (float) $in['max_marks'], 'signed_error' => round($err, 4), 'method' => $method],
                'is_correct' => $abs < 0.005,
                'score' => round($abs, 4),
                'error_type' => $abs >= $large ? 'LARGE_ERROR' : null,
                'metadata' => ['within' => array_values(array_map(fn ($t) => $abs <= $t + 1e-9, $tolerances)), 'group' => array_intersect_key($ex->metadata ?? [], array_flip(['question_type', 'difficulty', 'cognitive_level', 'course', 'assessment', 'rubric']))],
            ];
        }

        return $out;
    }

    public function aggregate(Collection $examples, array $predictions): array
    {
        $tolerances = (array) config('ai_evaluation.grading_tolerances');
        $errs = $abs = $sq = $pct = [];
        $exact = 0;
        $within = array_fill(0, count($tolerances), 0);
        $aiSum = $facSum = 0.0;
        $groups = [];
        foreach ($examples as $ex) {
            $p = $predictions[$ex->id] ?? null;
            if (!$p || !$p['prediction']) {
                continue;
            }
            $e = (float) $p['prediction']['signed_error'];
            $errs[] = $e;
            $abs[] = abs($e);
            $sq[] = $e * $e;
            if ($p['prediction']['faculty_marks'] > 0) {
                $pct[] = abs($e) / $p['prediction']['faculty_marks'];
            }
            $exact += (int) $p['is_correct'];
            foreach ($p['metadata']['within'] as $i => $ok) {
                $within[$i] += (int) $ok;
            }
            $aiSum += $p['prediction']['ai_marks'];
            $facSum += $p['prediction']['faculty_marks'];
            foreach ($p['metadata']['group'] ?? [] as $dim => $val) {
                $groups[$dim][(string) $val][] = $e;
            }
        }
        $n = count($errs);
        $rows = [
            'mae' => $this->row($n ? array_sum($abs) / $n : null),
            'rmse' => $this->row($n ? sqrt(array_sum($sq) / $n) : null),
            'mape' => $this->row($pct ? array_sum($pct) / count($pct) : null),
            'mean_signed_error' => $this->row($n ? array_sum($errs) / $n : null),
            'exact_agreement_rate' => $this->row($n ? $exact / $n : null),
            'ai_mean_marks' => $this->row($n ? $aiSum / $n : null),
            'faculty_mean_marks' => $this->row($n ? $facSum / $n : null),
            'compared_answers' => $this->row($n),
        ];
        foreach ($tolerances as $i => $t) {
            $rows['within_' . str_replace('.', '_', (string) $t)] = $this->row($n ? $within[$i] / $n : null);
        }
        $bias = [];
        foreach ($groups as $dim => $vals) {
            foreach ($vals as $val => $list) {
                $m = count($list);
                $bias[] = ['dimension' => $dim, 'group' => $val, 'count' => $m, 'mae' => round(array_sum(array_map('abs', $list)) / $m, 4), 'mean_signed_error' => round(array_sum($list) / $m, 4)];
            }
        }
        $rows['error_by_group'] = ['value' => null, 'metadata' => $bias];
        $rows['tolerances'] = ['value' => null, 'metadata' => $tolerances];

        return $rows;
    }
}
