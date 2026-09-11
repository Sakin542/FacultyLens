<?php

namespace App\Services;

use App\Models\AssessmentBlueprint;
use App\Models\AssessmentBlueprintConstraint;
use App\Models\LearningOutcome;
use App\Models\ProgramOutcome;

/**
 * STEP 37: deterministic blueprint validation (marks, counts, distributions, sections, cross-dimension plan, time).
 * Never modifies the blueprint; suggestions require faculty confirmation.
 */
class AssessmentBlueprintValidator
{
    protected array $errors = [];
    protected array $warnings = [];
    protected array $recommendations = [];

    public function validate(AssessmentBlueprint $bp): array
    {
        $this->errors = $this->warnings = $this->recommendations = [];
        $bp->loadMissing(['sections', 'constraints', 'items', 'assessment.course.learningOutcomes']);
        $course = $bp->assessment->course;
        $total = (float) $bp->total_marks;
        $n = (int) $bp->total_questions;
        $cfg = config('assessment_blueprint');

        if ($total <= 0) {
            $this->error('MARKS', 'Total marks must be greater than zero.');
        }
        if ($n <= 0) {
            $this->error('QUESTION_COUNT', 'Number of questions must be greater than zero.');
        }
        if ((float) $bp->assessment->total_marks > 0 && abs((float) $bp->assessment->total_marks - $total) > $cfg['marks_tolerance']) {
            $this->warn('MARKS', "Blueprint total ({$total}) differs from the assessment's total marks ({$bp->assessment->total_marks}).");
        }

        $sections = $this->validateSections($bp, $total, $n, $cfg);
        $byDim = $bp->constraints->groupBy('dimension');
        $difficulty = $this->distribution($byDim->get('DIFFICULTY', collect()), $cfg['difficulty_levels'], 'DIFFICULTY', $n, $total, 'count', $cfg);
        $cognitive = $this->distribution($byDim->get('COGNITIVE_LEVEL', collect()), $cfg['cognitive_levels'], 'COGNITIVE_LEVEL', $n, $total, 'count', $cfg);
        $los = $this->outcomeDistribution($byDim->get('LEARNING_OUTCOME', collect()), $course->learningOutcomes, $total, $n, $cfg);
        $pos = $this->programOutcomeDistribution($byDim->get('PROGRAM_OUTCOME', collect()), $course, $total, $cfg);
        $topics = $this->topicDistribution($byDim->get('TOPIC', collect()), $total, $n, $cfg);
        $types = $this->typeDistribution($byDim->get('QUESTION_TYPE', collect()), $total, $n, $cfg);
        $items = $this->validateItems($bp, $total, $n, $difficulty, $cognitive, $los, $cfg);
        $this->difficultyWarnings($difficulty, $cfg);
        $this->cognitiveWarnings($cognitive);
        $time = $this->timeIndicator($bp, $total, $n, $cfg);
        $completeness = $this->completeness($bp, $difficulty, $cognitive, $los, $topics, $types, $cfg);

        $status = $this->errors ? AssessmentBlueprint::INVALID : ($this->warnings ? AssessmentBlueprint::VALID_WITH_WARNINGS : AssessmentBlueprint::VALID);

        return [
            'status' => $status,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'recommendations' => $this->recommendations,
            'completeness' => $completeness,
            'totals' => $sections['totals'] + $items['totals'] + ['total_marks' => $total, 'total_questions' => $n],
            'distributions' => ['difficulty' => $difficulty, 'cognitive' => $cognitive, 'learning_outcomes' => $los, 'program_outcomes' => $pos, 'topics' => $topics, 'question_types' => $types],
            'matrices' => $items['matrices'],
            'time_indicator' => $time,
            'validated_at' => now()->toISOString(),
        ];
    }

    // ------------------------------------------------------------- sections

    protected function validateSections(AssessmentBlueprint $bp, float $total, int $n, array $cfg): array
    {
        $marks = 0.0;
        $count = 0;
        foreach ($bp->sections as $s) {
            $expected = round((float) $s->marks_per_question * (int) $s->question_count, 2);
            if (abs($expected - (float) $s->total_marks) > $cfg['marks_tolerance']) {
                $this->error('SECTION', "Section \"{$s->title}\": {$s->question_count} × {$s->marks_per_question} = {$expected}, but total is {$s->total_marks}.");
            }
            foreach (['difficulty_distribution' => 'difficulty', 'cognitive_distribution' => 'cognitive'] as $field => $label) {
                $dist = $s->{$field};
                if (is_array($dist) && $dist !== []) {
                    $sum = array_sum(array_map('floatval', $dist));
                    if (abs($sum - 100) > $cfg['total_rounding_tolerance']) {
                        $this->error('SECTION', "Section \"{$s->title}\" {$label} distribution totals {$sum}% (must be 100%).");
                    }
                }
            }
            $marks += (float) $s->total_marks;
            $count += (int) $s->question_count;
        }
        if ($bp->sections->isNotEmpty()) {
            if (abs($marks - $total) > $cfg['marks_tolerance']) {
                $diff = round($marks - $total, 2);
                $this->error('MARKS', "Allocated section marks: {$marks}. Required marks: {$total}. Difference: " . ($diff > 0 ? '+' : '') . "{$diff}.");
            }
            if ($count !== $n) {
                $this->error('QUESTION_COUNT', "Question count mismatch: sections plan {$count} question(s) but the blueprint specifies {$n}.");
            }
        }

        return ['totals' => ['section_marks' => round($marks, 2), 'section_questions' => $count, 'sections' => $bp->sections->count()]];
    }

    // -------------------------------------------------------- distributions

    /** Difficulty / Bloom: percentages refer to question count; counts must add up to the blueprint question count. */
    protected function distribution($constraints, array $keys, string $dimension, int $n, float $total, string $basis, array $cfg): array
    {
        $rows = [];
        $configured = $constraints->isNotEmpty();
        $pctSum = 0.0;
        $countSum = 0;
        $anyPct = false;
        $anyCount = false;
        $map = $constraints->keyBy(fn ($c) => strtolower($c->target_key));
        foreach ($keys as $key) {
            $c = $map->get(strtolower($key));
            $pct = $c?->target_percentage;
            $cnt = $c?->target_count;
            if ($c && $pct === null && $cnt !== null && $n > 0) {
                $pct = round($cnt / $n * 100, 2);
            }
            $anyPct = $anyPct || ($c && $c->target_percentage !== null);
            $anyCount = $anyCount || ($c && $c->target_count !== null);
            $pctSum += (float) ($pct ?? 0);
            $countSum += (int) ($cnt ?? 0);
            $rows[] = ['key' => $key, 'label' => ucfirst($key), 'target_percentage' => $pct !== null ? round((float) $pct, 2) : null, 'target_count' => $cnt, 'configured' => (bool) $c];
        }
        $suggestion = null;
        if ($configured) {
            if ($anyPct && abs($pctSum - 100) > $cfg['total_rounding_tolerance']) {
                $this->error($dimension, ucfirst(strtolower(str_replace('_', ' ', $dimension))) . " distribution totals {$pctSum}% (must be 100%). Blueprint requires correction.");
            }
            if ($anyCount && !$anyPct && $countSum !== $n && $n > 0) {
                $this->error($dimension, ucfirst(strtolower(str_replace('_', ' ', $dimension))) . " counts total {$countSum} but the blueprint has {$n} questions.");
            }
            if ($anyPct && $n > 0) {
                $suggestion = $this->allocate(array_column($rows, 'target_percentage', 'key'), $n);
                foreach ($rows as &$r) {
                    $r['derived_count'] = $suggestion['allocation'][$r['key']] ?? null;
                }
                unset($r);
                if (!$suggestion['exact']) {
                    $this->warn($dimension, "{$n} questions cannot exactly represent the " . strtolower(str_replace('_', ' ', $dimension)) . ' percentages. Suggested allocation: ' . $this->fmtAlloc($suggestion['allocation']) . ' (requires your confirmation).');
                }
                if ($anyCount) {
                    foreach ($rows as $r) {
                        if ($r['target_count'] !== null && $r['derived_count'] !== null && $r['target_count'] !== $r['derived_count']) {
                            $this->warn($dimension, "{$r['label']}: {$r['target_percentage']}% of {$n} questions ≈ {$r['derived_count']}, but the planned count is {$r['target_count']}.");
                        }
                    }
                }
            }
        }

        return ['configured' => $configured, 'basis' => $basis, 'rows' => $rows, 'percentage_total' => round($pctSum, 2), 'count_total' => $countSum, 'allocation' => $suggestion];
    }

    /** Learning outcomes: percentages/marks refer to marks; only outcomes of the blueprint's course are allowed. */
    protected function outcomeDistribution($constraints, $courseLos, float $total, int $n, array $cfg): array
    {
        $configured = $constraints->isNotEmpty();
        $rows = [];
        $pctSum = 0.0;
        $marksSum = 0.0;
        $anyPct = false;
        $byLo = $constraints->keyBy('learning_outcome_id');
        foreach ($courseLos as $lo) {
            $c = $byLo->get($lo->id);
            $pct = $c?->target_percentage;
            $marks = $c?->target_marks;
            if ($c && $pct === null && $marks !== null && $total > 0) {
                $pct = round($marks / $total * 100, 2);
            }
            if ($c && $marks === null && $pct !== null) {
                $marks = round($pct / 100 * $total, 2);
            }
            $anyPct = $anyPct || ($c && $c->target_percentage !== null);
            $pctSum += (float) ($pct ?? 0);
            $marksSum += (float) ($marks ?? 0);
            $rows[] = ['key' => 'lo:' . $lo->id, 'learning_outcome_id' => $lo->id, 'code' => $lo->code, 'label' => $lo->code, 'description' => $lo->description,
                'target_percentage' => $pct !== null ? round((float) $pct, 2) : null, 'target_marks' => $marks !== null ? round((float) $marks, 2) : null, 'target_count' => $c?->target_count, 'configured' => (bool) $c];
            if ($configured && (!$c || (float) ($pct ?? 0) <= 0)) {
                $this->warn('LEARNING_OUTCOME', "{$lo->code} has no planned questions.");
            } elseif ($configured && $pct !== null && $pct < $cfg['low_coverage_percent']) {
                $this->warn('LEARNING_OUTCOME', "{$lo->code} has limited planned coverage ({$pct}% of marks).");
                $this->recommend('LEARNING_OUTCOME', "{$lo->code} is planned at {$pct}% of marks", "Consider reviewing whether this reflects the intended assessment emphasis for {$lo->code}.");
            }
        }
        foreach ($constraints as $c) {
            if (!$courseLos->contains('id', $c->learning_outcome_id)) {
                $this->error('LEARNING_OUTCOME', 'A learning-outcome target references an outcome that does not belong to this course.');
            }
        }
        if ($configured) {
            if ($anyPct && abs($pctSum - 100) > $cfg['total_rounding_tolerance']) {
                $this->error('LEARNING_OUTCOME', "Learning-outcome coverage totals {$pctSum}% (must be 100%).");
            }
            if (!$anyPct && abs($marksSum - $total) > $cfg['marks_tolerance']) {
                $this->error('LEARNING_OUTCOME', "Learning-outcome marks total {$marksSum} but the blueprint total is {$total}.");
            }
        }

        return ['configured' => $configured, 'basis' => 'marks', 'rows' => $rows, 'percentage_total' => round($pctSum, 2), 'marks_total' => round($marksSum, 2)];
    }

    protected function programOutcomeDistribution($constraints, $course, float $total, array $cfg): array
    {
        if (!$course->program_id) {
            if ($constraints->isNotEmpty()) {
                $this->error('PROGRAM_OUTCOME', 'Program-outcome targets are set but the course is not linked to a program.');
            }

            return ['configured' => false, 'available' => false, 'message' => 'PO blueprint is not configured for this course.', 'rows' => [], 'percentage_total' => 0];
        }
        $pos = ProgramOutcome::where('program_id', $course->program_id)->where('status', 'ACTIVE')->orderBy('sort_order')->get();
        $byPo = $constraints->keyBy('program_outcome_id');
        $rows = [];
        $sum = 0.0;
        foreach ($pos as $po) {
            $c = $byPo->get($po->id);
            $sum += (float) ($c?->target_percentage ?? 0);
            $rows[] = ['key' => 'po:' . $po->id, 'program_outcome_id' => $po->id, 'code' => $po->code, 'label' => $po->code, 'description' => $po->title, 'target_percentage' => $c?->target_percentage, 'configured' => (bool) $c];
        }
        foreach ($constraints as $c) {
            if (!$pos->contains('id', $c->program_outcome_id)) {
                $this->error('PROGRAM_OUTCOME', "A program-outcome target references an outcome outside this course's program.");
            }
        }
        if ($constraints->isNotEmpty() && abs($sum - 100) > $cfg['total_rounding_tolerance']) {
            $this->error('PROGRAM_OUTCOME', "Program-outcome coverage totals {$sum}% (must be 100%).");
        }

        return ['configured' => $constraints->isNotEmpty(), 'available' => true, 'rows' => $rows, 'percentage_total' => round($sum, 2)];
    }

    protected function topicDistribution($constraints, float $total, int $n, array $cfg): array
    {
        $rows = [];
        $marks = 0.0;
        $count = 0;
        foreach ($constraints as $c) {
            $marks += (float) ($c->target_marks ?? 0);
            $count += (int) ($c->target_count ?? 0);
            $pct = $c->target_percentage ?? ($c->target_marks !== null && $total > 0 ? round($c->target_marks / $total * 100, 2) : null);
            $rows[] = ['key' => $c->target_key, 'label' => $c->target_key, 'target_count' => $c->target_count, 'target_marks' => $c->target_marks, 'target_percentage' => $pct, 'configured' => true];
            if ($pct !== null && $pct < $cfg['low_coverage_percent']) {
                $this->warn('TOPIC', "Topic \"{$c->target_key}\" has insufficient planned coverage ({$pct}% of marks).");
            }
        }
        if ($marks - $total > $cfg['marks_tolerance']) {
            $this->error('TOPIC', "Topic marks total {$marks}, exceeding the blueprint total of {$total}.");
        }
        if ($count > $n && $n > 0) {
            $this->error('TOPIC', "Topics plan {$count} questions, exceeding the blueprint question count of {$n}.");
        }

        return ['configured' => $constraints->isNotEmpty(), 'basis' => 'marks', 'rows' => $rows, 'marks_total' => round($marks, 2), 'count_total' => $count];
    }

    protected function typeDistribution($constraints, float $total, int $n, array $cfg): array
    {
        $rows = [];
        $marks = 0.0;
        $count = 0;
        foreach ($constraints as $c) {
            $each = (float) ($c->metadata['marks_each'] ?? 0);
            $rowMarks = $c->target_marks !== null ? (float) $c->target_marks : round($each * (int) $c->target_count, 2);
            if ($each > 0 && $c->target_count !== null && abs($each * $c->target_count - $rowMarks) > $cfg['marks_tolerance']) {
                $this->error('QUESTION_TYPE', ucfirst(str_replace('_', ' ', $c->target_key)) . ": {$c->target_count} × {$each} ≠ {$rowMarks}.");
            }
            $marks += $rowMarks;
            $count += (int) $c->target_count;
            $rows[] = ['key' => $c->target_key, 'label' => ucfirst(str_replace('_', ' ', $c->target_key)), 'target_count' => $c->target_count, 'marks_each' => $each ?: null, 'target_marks' => round($rowMarks, 2),
                'target_percentage' => $n > 0 && $c->target_count !== null ? round($c->target_count / $n * 100, 2) : null, 'configured' => true];
        }
        if ($constraints->isNotEmpty()) {
            if ($count !== $n) {
                $this->error('QUESTION_TYPE', "Question types plan {$count} question(s) but the blueprint specifies {$n}.");
            }
            if (abs($marks - $total) > $cfg['marks_tolerance']) {
                $this->error('QUESTION_TYPE', "Question-type marks total {$marks} but the blueprint total is {$total}.");
            }
            if (count($rows) < 2 && $n >= 5) {
                $this->warn('QUESTION_TYPE', 'Only one question type is planned; consider question diversity.');
                $this->recommend('QUESTION_DIVERSITY', 'Single question type planned', 'Consider whether a single question type reflects the intended assessment design.');
            }
        }

        return ['configured' => $constraints->isNotEmpty(), 'basis' => 'count', 'rows' => $rows, 'marks_total' => round($marks, 2), 'count_total' => $count];
    }

    // -------------------------------------------------------- item plan + matrices

    protected function validateItems(AssessmentBlueprint $bp, float $total, int $n, array $difficulty, array $cognitive, array $los, array $cfg): array
    {
        $items = $bp->items;
        $marks = 0.0;
        $count = 0;
        $seen = [];
        $courseLoIds = $bp->assessment->course->learningOutcomes->pluck('id')->all();
        $byDifficulty = [];
        $byCognitive = [];
        $matrix = ['co_x_difficulty' => [], 'co_x_bloom' => [], 'topic_x_difficulty' => [], 'topic_x_bloom' => []];
        foreach ($items as $it) {
            $expected = round((float) $it->marks_each * (int) $it->question_count, 2);
            if (abs($expected - (float) $it->total_marks) > $cfg['marks_tolerance']) {
                $this->error('ITEM', "Plan row #{$it->sort_order}: {$it->question_count} × {$it->marks_each} = {$expected}, but total is {$it->total_marks}.");
            }
            if ($it->learning_outcome_id && !in_array($it->learning_outcome_id, $courseLoIds, true)) {
                $this->error('ITEM', "Plan row #{$it->sort_order} references a learning outcome from another course.");
            }
            $sig = implode('|', [$it->section_id, $it->topic, $it->learning_outcome_id, $it->program_outcome_id, $it->question_type, $it->difficulty_level, $it->cognitive_level, $it->marks_each]);
            if (isset($seen[$sig])) {
                $this->warn('ITEM', "Plan rows #{$seen[$sig]} and #{$it->sort_order} define the same combination; merge them to avoid double counting.");
            }
            $seen[$sig] = $it->sort_order;
            $marks += (float) $it->total_marks;
            $count += (int) $it->question_count;
            if ($it->difficulty_level) {
                $byDifficulty[strtolower($it->difficulty_level)] = ($byDifficulty[strtolower($it->difficulty_level)] ?? 0) + $it->question_count;
            }
            if ($it->cognitive_level) {
                $byCognitive[strtolower($it->cognitive_level)] = ($byCognitive[strtolower($it->cognitive_level)] ?? 0) + $it->question_count;
            }
            $co = $it->learning_outcome_id ? ($bp->assessment->course->learningOutcomes->firstWhere('id', $it->learning_outcome_id)?->code ?? "LO{$it->learning_outcome_id}") : 'Unassigned';
            $topic = $it->topic ?: 'Unassigned';
            $d = $it->difficulty_level ? strtolower($it->difficulty_level) : 'unspecified';
            $b = $it->cognitive_level ?: 'Unspecified';
            $matrix['co_x_difficulty'][$co][$d] = ($matrix['co_x_difficulty'][$co][$d] ?? 0) + $it->question_count;
            $matrix['co_x_bloom'][$co][$b] = ($matrix['co_x_bloom'][$co][$b] ?? 0) + $it->question_count;
            $matrix['topic_x_difficulty'][$topic][$d] = ($matrix['topic_x_difficulty'][$topic][$d] ?? 0) + $it->question_count;
            $matrix['topic_x_bloom'][$topic][$b] = ($matrix['topic_x_bloom'][$topic][$b] ?? 0) + $it->question_count;
        }
        if ($items->isNotEmpty()) {
            if ($count > $n) {
                $this->error('ITEM', "The question plan contains {$count} questions, exceeding the blueprint count of {$n}.");
            } elseif ($count < $n) {
                $this->warn('ITEM', "The question plan covers {$count} of {$n} questions.");
            }
            if ($marks - $total > $cfg['marks_tolerance']) {
                $this->error('ITEM', "The question plan allocates {$marks} marks, exceeding the blueprint total of {$total}.");
            } elseif ($total - $marks > $cfg['marks_tolerance'] && $count >= $n) {
                $this->error('ITEM', "The question plan allocates {$marks} marks but the blueprint total is {$total}.");
            }
            foreach ($difficulty['rows'] as $r) {
                $planned = $byDifficulty[$r['key']] ?? 0;
                $target = $r['target_count'] ?? $r['derived_count'] ?? null;
                if ($r['configured'] && $target !== null && $count >= $n && $planned !== $target) {
                    $this->warn('ITEM', "Plan has {$planned} {$r['label']} question(s) but the difficulty target is {$target}.");
                }
            }
            foreach ($cognitive['rows'] as $r) {
                $planned = $byCognitive[strtolower($r['key'])] ?? 0;
                $target = $r['target_count'] ?? $r['derived_count'] ?? null;
                if ($r['configured'] && $target !== null && $count >= $n && $planned !== $target) {
                    $this->warn('ITEM', "Plan has {$planned} {$r['label']} question(s) but the Bloom target is {$target}.");
                }
            }
            foreach ($los['rows'] as $r) {
                if ($r['configured'] && ($r['target_marks'] ?? 0) > 0 && $count >= $n) {
                    $planned = $items->where('learning_outcome_id', $r['learning_outcome_id'])->sum('total_marks');
                    if (abs($planned - $r['target_marks']) > $cfg['marks_tolerance']) {
                        $this->warn('ITEM', "Plan allocates {$planned} marks to {$r['code']} but the target is {$r['target_marks']}.");
                    }
                }
            }
        }

        return ['totals' => ['item_marks' => round($marks, 2), 'item_questions' => $count, 'items' => $items->count()], 'matrices' => array_map([$this, 'presentMatrix'], $matrix)];
    }

    protected function presentMatrix(array $cells): array
    {
        $cols = [];
        foreach ($cells as $row) {
            foreach (array_keys($row) as $c) {
                $cols[$c] = true;
            }
        }
        $cols = array_keys($cols);
        $order = ['easy' => 0, 'medium' => 1, 'hard' => 2, 'unspecified' => 9, 'Remember' => 0, 'Understand' => 1, 'Apply' => 2, 'Analyze' => 3, 'Evaluate' => 4, 'Create' => 5, 'Unspecified' => 9];
        usort($cols, fn ($a, $b) => ($order[$a] ?? 5) <=> ($order[$b] ?? 5) ?: strcmp($a, $b));
        $rows = [];
        $colTotals = array_fill_keys($cols, 0);
        foreach ($cells as $label => $row) {
            $vals = [];
            $sum = 0;
            foreach ($cols as $c) {
                $v = (int) ($row[$c] ?? 0);
                $vals[$c] = $v;
                $sum += $v;
                $colTotals[$c] += $v;
            }
            $rows[] = ['label' => $label, 'cells' => $vals, 'total' => $sum];
        }

        return ['columns' => $cols, 'rows' => $rows, 'column_totals' => $colTotals, 'total' => array_sum($colTotals)];
    }

    // ------------------------------------------------------------- warnings

    protected function difficultyWarnings(array $difficulty, array $cfg): void
    {
        if (!$difficulty['configured']) {
            return;
        }
        $targets = (array) config('assessment_blueprint.difficulty_targets');
        foreach ($difficulty['rows'] as $r) {
            if ($r['target_percentage'] === null) {
                continue;
            }
            $dev = $r['target_percentage'] - ($targets[$r['key']] ?? 0);
            if (abs($dev) > $cfg['difficulty_warning_deviation']) {
                $dir = $dev < 0 ? 'below' : 'above';
                $this->warn('DIFFICULTY', "{$r['label']} questions ({$r['target_percentage']}%) are {$dir} the configured target ({$targets[$r['key']]}%).");
                $this->recommend('DIFFICULTY', "Difficulty imbalance: {$r['label']} {$r['target_percentage']}% vs target {$targets[$r['key']]}%", 'Consider whether the planned difficulty profile reflects the intended assessment design.');
            }
        }
    }

    protected function cognitiveWarnings(array $cognitive): void
    {
        if (!$cognitive['configured']) {
            return;
        }
        $analyze = collect($cognitive['rows'])->firstWhere('key', 'Analyze');
        $higher = collect($cognitive['rows'])->whereIn('key', ['Analyze', 'Evaluate', 'Create'])->sum(fn ($r) => (float) ($r['target_percentage'] ?? 0));
        if ($analyze && (float) ($analyze['target_percentage'] ?? 0) <= 0) {
            $this->warn('COGNITIVE_LEVEL', 'No Analyze-level questions planned.');
        }
        if ($higher <= 0) {
            $this->recommend('COGNITIVE_LEVEL', 'No higher-order (Analyze/Evaluate/Create) questions planned', 'Consider whether the blueprint should include higher-order cognitive demands.');
        }
    }

    protected function timeIndicator(AssessmentBlueprint $bp, float $total, int $n, array $cfg): array
    {
        $d = $bp->duration_minutes ?: $bp->assessment->duration_minutes;
        if (!$d || $total <= 0) {
            return ['available' => false, 'note' => 'Planning indicator: set an assessment duration to see time per mark.'];
        }
        $perMark = round($d / $total, 2);
        $perQuestion = $n > 0 ? round($d / $n, 1) : null;
        $band = $perMark < $cfg['time']['minutes_per_mark_low'] ? 'TIGHT' : ($perMark > $cfg['time']['minutes_per_mark_high'] ? 'GENEROUS' : 'TYPICAL');
        if ($band === 'TIGHT') {
            $this->warn('TIME', "Planning indicator: {$perMark} minutes per mark is below the configured lower band ({$cfg['time']['minutes_per_mark_low']}).");
        }
        $expected = null;
        $perType = (array) ($cfg['time']['expected_minutes_per_question'] ?? []);
        if ($perType && $bp->sections->isNotEmpty()) {
            $expected = 0.0;
            foreach ($bp->sections as $s) {
                $expected += (float) ($perType[$s->question_type] ?? 0) * $s->question_count;
            }
            if ($expected > $d) {
                $this->warn('TIME', "Planning indicator: configured expected time per question type totals {$expected} minutes, exceeding the {$d}-minute duration.");
            }
        }

        return ['available' => true, 'duration_minutes' => (int) $d, 'minutes_per_mark' => $perMark, 'minutes_per_question' => $perQuestion, 'band' => $band, 'expected_minutes' => $expected,
            'note' => 'Planning indicator only — not an official duration recommendation.'];
    }

    protected function completeness(AssessmentBlueprint $bp, array $difficulty, array $cognitive, array $los, array $topics, array $types, array $cfg): array
    {
        $w = (array) $cfg['completeness_weights'];
        $dims = [
            'basics' => $bp->total_marks > 0 && $bp->total_questions > 0 && (bool) ($bp->duration_minutes ?: $bp->assessment->duration_minutes),
            'sections' => $bp->sections->isNotEmpty(),
            'difficulty' => $difficulty['configured'],
            'cognitive' => $cognitive['configured'],
            'learning_outcomes' => $los['configured'],
            'topics' => $topics['configured'],
            'question_types' => $types['configured'] || $bp->sections->whereNotNull('question_type')->isNotEmpty(),
            'items' => $bp->items->isNotEmpty(),
        ];
        $score = 0;
        foreach ($dims as $k => $ok) {
            $score += $ok ? ($w[$k] ?? 0) : 0;
        }
        $max = array_sum($w) ?: 1;

        return ['score' => round($score / $max * 100, 1), 'dimensions' => $dims, 'note' => 'Blueprint Completeness measures configured planning dimensions; it is not an assessment quality score.'];
    }

    // -------------------------------------------------------------- helpers

    /** Largest-remainder allocation of percentages into an integer question count. */
    public function allocate(array $percentages, int $n): array
    {
        $raw = [];
        $alloc = [];
        $exact = true;
        foreach ($percentages as $k => $p) {
            $v = (float) ($p ?? 0) / 100 * $n;
            $raw[$k] = $v;
            $alloc[$k] = (int) floor($v + 1e-9);
            if (abs($v - round($v)) > 1e-6) {
                $exact = false;
            }
        }
        $remaining = $n - array_sum($alloc);
        $fractions = array_map(fn ($k) => [$k, $raw[$k] - floor($raw[$k] + 1e-9)], array_keys($raw));
        usort($fractions, fn ($a, $b) => $b[1] <=> $a[1]);
        for ($i = 0; $i < $remaining && $i < count($fractions); $i++) {
            $alloc[$fractions[$i][0]]++;
        }

        return ['exact' => $exact, 'allocation' => $alloc];
    }

    protected function fmtAlloc(array $alloc): string
    {
        return implode(' / ', array_map(fn ($k, $v) => "{$v} " . ucfirst($k), array_keys($alloc), $alloc));
    }

    protected function error(string $dimension, string $message): void
    {
        $this->errors[] = ['dimension' => $dimension, 'message' => $message];
    }

    protected function warn(string $dimension, string $message): void
    {
        $this->warnings[] = ['dimension' => $dimension, 'message' => $message];
    }

    protected function recommend(string $category, string $title, string $message): void
    {
        $this->recommendations[] = ['category' => $category, 'title' => $title, 'message' => $message];
    }
}
