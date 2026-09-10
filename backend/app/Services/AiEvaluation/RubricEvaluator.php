<?php

namespace App\Services\AiEvaluation;

use App\Models\AiEvaluationRating;
use Illuminate\Support\Collection;

/**
 * STEP 35: rubric generation (STEP 25). Example input {question, question_type?, total_marks, difficulty?, cognitive_level?,
 * learning_outcome?}; expected {ratings?: {dimension: 1-5}, decision?: ACCEPTED|REVISED|REJECTED} (faculty review of a
 * previously generated rubric is optional). Hard check: sum(criteria.max_marks) == total_marks.
 */
class RubricEvaluator extends TaskEvaluator
{
    public function task(): string
    {
        return 'RUBRIC_GENERATION';
    }

    public function headlineMetric(): string
    {
        return 'marks_validity_rate';
    }

    public function validateExample(array $input, array $expected): array
    {
        $errors = [];
        $this->requireString($input, 'question', $errors, 'question text');
        if (!isset($input['total_marks']) || !is_numeric($input['total_marks']) || (float) $input['total_marks'] <= 0) {
            $errors[] = 'Missing positive total_marks.';
        }
        foreach ((array) ($expected['ratings'] ?? []) as $dim => $v) {
            if (!is_numeric($v) || $v < 1 || $v > 5) {
                $errors[] = "Rating '{$dim}' must be between 1 and 5.";
            }
        }
        if (isset($expected['decision']) && !in_array(strtoupper($expected['decision']), AiEvaluationRating::DECISIONS, true)) {
            $errors[] = 'Invalid decision (ACCEPTED | REVISED | REJECTED).';
        }

        return $errors;
    }

    public function predict(Collection $examples): array
    {
        $out = [];
        foreach ($examples as $ex) {
            $in = $ex->input_data;
            try {
                $res = $this->ai->generateRubric([
                    'question_text' => $in['question'],
                    'question_type' => strtoupper($in['question_type'] ?? 'DESCRIPTIVE'),
                    'total_marks' => (float) $in['total_marks'],
                    'difficulty_level' => $in['difficulty'] ?? null,
                    'cognitive_level' => $in['cognitive_level'] ?? null,
                    'expected_answer' => $in['expected_answer'] ?? null,
                    'learning_outcome' => isset($in['learning_outcome']) ? ['code' => $in['learning_outcome_code'] ?? 'CO', 'description' => $in['learning_outcome']] : null,
                ]);
            } catch (\Throwable $e) {
                $out[$ex->id] = $this->inferenceError($e);
                continue;
            }
            $criteria = $res['rubric']['criteria'] ?? [];
            $sum = round(array_sum(array_map(fn ($c) => (float) ($c['max_marks'] ?? 0), $criteria)), 2);
            $valid = abs($sum - (float) $in['total_marks']) < 0.01 && count($criteria) > 0;
            $out[$ex->id] = [
                'prediction' => ['criteria_count' => count($criteria), 'criteria_marks_sum' => $sum, 'criteria' => array_map(fn ($c) => ['criterion' => $c['criterion'] ?? '', 'max_marks' => $c['max_marks'] ?? 0], $criteria),
                    'generation_method' => $res['generation_method'] ?? null, 'model' => $res['metadata']['model'] ?? null],
                'is_correct' => $valid,
                'score' => isset($ex->expected_output['ratings']) && $ex->expected_output['ratings'] ? round(array_sum($ex->expected_output['ratings']) / count($ex->expected_output['ratings']), 4) : null,
                'error_type' => $valid ? null : 'MARKS_MISMATCH',
                'metadata' => ['decision' => isset($ex->expected_output['decision']) ? strtoupper($ex->expected_output['decision']) : null, 'ratings' => $ex->expected_output['ratings'] ?? null],
            ];
        }

        return $out;
    }

    public function aggregate(Collection $examples, array $predictions): array
    {
        $generated = $valid = 0;
        $ratings = [];
        $dims = [];
        $decisions = ['ACCEPTED' => 0, 'REVISED' => 0, 'REJECTED' => 0];
        foreach ($examples as $ex) {
            $p = $predictions[$ex->id] ?? null;
            if (!$p || !$p['prediction']) {
                continue;
            }
            $generated++;
            $valid += (int) $p['is_correct'];
            if ($p['score'] !== null) {
                $ratings[] = (float) $p['score'];
            }
            foreach ((array) ($p['metadata']['ratings'] ?? []) as $dim => $v) {
                $dims[$dim][] = (float) $v;
            }
            if ($p['metadata']['decision'] ?? null) {
                $decisions[$p['metadata']['decision']]++;
            }
        }
        $rated = count($ratings);
        $decided = array_sum($decisions);
        sort($ratings);
        $mean = $rated ? array_sum($ratings) / $rated : null;
        $sd = $rated > 1 ? sqrt(array_sum(array_map(fn ($r) => ($r - $mean) ** 2, $ratings)) / ($rated - 1)) : null;

        $median = null;
        if ($rated > 0) {
            $median = ($rated % 2 !== 0)
                ? $ratings[intdiv($rated, 2)]
                : ($ratings[intdiv($rated, 2) - 1] + $ratings[intdiv($rated, 2)]) / 2;
        }

        return [
            'generated_count' => $this->row($generated),
            'valid_rubric_count' => $this->row($valid),
            'invalid_rubric_count' => $this->row($generated - $valid),
            'marks_validity_rate' => $this->row($generated ? $valid / $generated : null),
            'rated_count' => $this->row($rated),
            'mean_rating' => $this->row($mean),
            'median_rating' => $this->row($median),
            'rating_std_dev' => $this->row($sd),
            'acceptance_rate' => $this->row($decided ? $decisions['ACCEPTED'] / $decided : null),
            'revision_rate' => $this->row($decided ? $decisions['REVISED'] / $decided : null),
            'rejection_rate' => $this->row($decided ? $decisions['REJECTED'] / $decided : null),
            'dimension_means' => ['value' => null, 'metadata' => array_map(fn ($l) => round(array_sum($l) / count($l), 4), $dims)],
        ];
    }
}
