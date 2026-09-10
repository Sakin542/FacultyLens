<?php

namespace App\Services\AiEvaluation;

use Illuminate\Support\Collection;

/**
 * STEP 35: constrained question generation (STEP 33). Example input = generation constraints
 * {topic, question_type, difficulty_level?, cognitive_level?, marks, number_of_questions, learning_outcome?{code,description},
 *  document_context?[], existing_questions?[]}; expected {min_constraint_satisfaction?: 0..1}.
 * Constraint satisfaction = share of generated drafts whose validation has no failed constraint.
 */
class QuestionGenerationEvaluator extends TaskEvaluator
{
    public function task(): string
    {
        return 'QUESTION_GENERATION';
    }

    public function headlineMetric(): string
    {
        return 'constraint_satisfaction_rate';
    }

    public function validateExample(array $input, array $expected): array
    {
        $errors = [];
        $this->requireLabel($input, 'question_type', $this->labelSet('QUESTION_CLASSIFICATION'), $errors, 'question_type');
        if (isset($input['difficulty_level'])) {
            $this->requireLabel($input, 'difficulty_level', $this->labelSet('DIFFICULTY_CLASSIFICATION'), $errors, 'difficulty_level');
        }
        if (isset($input['cognitive_level'])) {
            $this->requireLabel($input, 'cognitive_level', $this->labelSet('BLOOM_CLASSIFICATION'), $errors, 'cognitive_level');
        }
        if (!isset($input['marks']) || !is_numeric($input['marks']) || $input['marks'] <= 0) {
            $errors[] = 'Missing positive marks.';
        }
        $n = $input['number_of_questions'] ?? 1;
        if (!is_int($n) || $n < 1 || $n > 20) {
            $errors[] = 'number_of_questions must be an integer between 1 and 20.';
        }
        if (empty($input['topic']) && empty($input['learning_outcome']['description'])) {
            $errors[] = 'Provide a topic or a learning_outcome so grounding can be checked.';
        }

        return $errors;
    }

    public function predict(Collection $examples): array
    {
        $out = [];
        foreach ($examples as $ex) {
            $in = $ex->input_data;
            try {
                $res = $this->ai->generateQuestions([
                    'course_context' => $in['course_context'] ?? ['course_code' => 'EVAL', 'course_name' => 'Evaluation'],
                    'learning_outcome' => $in['learning_outcome'] ?? null,
                    'program_outcome' => $in['program_outcome'] ?? null,
                    'topic' => $in['topic'] ?? null,
                    'question_type' => strtoupper($in['question_type']),
                    'difficulty_level' => isset($in['difficulty_level']) ? strtoupper($in['difficulty_level']) : null,
                    'cognitive_level' => isset($in['cognitive_level']) ? strtoupper($in['cognitive_level']) : null,
                    'marks' => (float) $in['marks'],
                    'number_of_questions' => (int) ($in['number_of_questions'] ?? 1),
                    'include_expected_answer' => false,
                    'document_context' => array_values(array_map(fn ($d, $i) => ['chunk_id' => $i + 1, 'document_id' => $i + 1, 'document_name' => $d['name'] ?? 'Document', 'content' => $d['content']], $in['document_context'] ?? [], array_keys($in['document_context'] ?? []))),
                    'existing_question_context' => array_values(array_map(fn ($q, $i) => ['id' => $i + 1, 'text' => is_array($q) ? $q['text'] : $q, 'source' => 'question_bank'], $in['existing_questions'] ?? [], array_keys($in['existing_questions'] ?? []))),
                ]);
            } catch (\Throwable $e) {
                $out[$ex->id] = $this->inferenceError($e);
                continue;
            }
            $questions = $res['questions'] ?? [];
            $perConstraint = [];
            $full = $warn = $fail = 0;
            foreach ($questions as $q) {
                $v = $q['validation'] ?? [];
                $constraints = $v['constraints'] ?? [];
                $violated = false;
                foreach ($constraints as $name => $ok) {
                    if ($ok === null) {
                        continue;
                    }
                    $perConstraint[$name]['total'] = ($perConstraint[$name]['total'] ?? 0) + 1;
                    $perConstraint[$name]['satisfied'] = ($perConstraint[$name]['satisfied'] ?? 0) + (int) $ok;
                    $violated = $violated || !$ok;
                }
                if (($v['overall_status'] ?? '') === 'FAILED' || $violated && ($v['overall_status'] ?? '') === 'FAILED') {
                    $fail++;
                } elseif (($v['overall_status'] ?? '') === 'PASSED_WITH_WARNINGS' || $violated) {
                    $warn++;
                } else {
                    $full++;
                }
            }
            $generated = count($questions);
            $requested = (int) ($in['number_of_questions'] ?? 1);
            $csr = $generated ? $full / $generated : 0.0;
            $min = (float) ($ex->expected_output['min_constraint_satisfaction'] ?? 0);
            $ok = $generated === $requested && $csr >= $min && $fail === 0;
            $out[$ex->id] = [
                'prediction' => ['generated' => $generated, 'requested' => $requested, 'fully_valid' => $full, 'warnings' => $warn, 'failed' => $fail, 'constraint_satisfaction' => round($csr, 4),
                    'generation_method' => $res['generation_method'] ?? null, 'model' => $res['model'] ?? null, 'prompt_version' => $res['prompt_version'] ?? null,
                    'drafts' => array_map(fn ($q) => ['text' => mb_substr($q['question_text'], 0, 300), 'status' => $q['validation']['overall_status'] ?? null, 'warnings' => $q['validation']['warnings'] ?? []], $questions)],
                'is_correct' => $ok, 'score' => round($csr, 4), 'error_type' => $ok ? null : 'CONSTRAINT_VIOLATION',
                'metadata' => ['per_constraint' => $perConstraint],
            ];
        }

        return $out;
    }

    public function aggregate(Collection $examples, array $predictions): array
    {
        $generated = $full = $warn = $fail = $requested = 0;
        $per = [];
        foreach ($examples as $ex) {
            $p = $predictions[$ex->id] ?? null;
            if (!$p || !$p['prediction']) {
                continue;
            }
            $generated += $p['prediction']['generated'];
            $requested += $p['prediction']['requested'];
            $full += $p['prediction']['fully_valid'];
            $warn += $p['prediction']['warnings'];
            $fail += $p['prediction']['failed'];
            foreach ($p['metadata']['per_constraint'] ?? [] as $name => $c) {
                $per[$name]['total'] = ($per[$name]['total'] ?? 0) + $c['total'];
                $per[$name]['satisfied'] = ($per[$name]['satisfied'] ?? 0) + $c['satisfied'];
            }
        }
        $perRows = [];
        foreach ($per as $name => $c) {
            $perRows[] = ['constraint' => $name, 'satisfied' => $c['satisfied'], 'total' => $c['total'], 'rate' => $c['total'] ? round($c['satisfied'] / $c['total'], 4) : null];
        }

        return [
            'requested_questions' => $this->row($requested),
            'generated_questions' => $this->row($generated),
            'fully_valid' => $this->row($full),
            'with_warnings' => $this->row($warn),
            'failed' => $this->row($fail),
            'constraint_satisfaction_rate' => $this->row($generated ? $full / $generated : null),
            'generation_completeness' => $this->row($requested ? $generated / $requested : null),
            'per_constraint' => ['value' => null, 'metadata' => $perRows],
        ];
    }
}
