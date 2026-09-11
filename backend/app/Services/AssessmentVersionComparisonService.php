<?php

namespace App\Services;

use App\Models\AnalysisReport;
use App\Models\AssessmentVersion;
use App\Models\AssessmentVersionQuestion;
use Illuminate\Support\Collection;

/**
 * STEP 38: deterministic comparison of two assessment versions (metadata, questions, marks, blueprint, mappings, analysis).
 * No AI is involved: questions are matched by original_question_id, then by question number.
 */
class AssessmentVersionComparisonService
{
    public function __construct(protected AssessmentVersionValidationService $validation, protected AssessmentVersionService $versions) {}

    public function compare(AssessmentVersion $from, AssessmentVersion $to): array
    {
        $from->loadMissing(['questions.learningOutcome:id,code', 'questions.programOutcome:id,code', 'blueprint', 'creator:id,name', 'basedOn:id,version_number,version_label']);
        $to->loadMissing(['questions.learningOutcome:id,code', 'questions.programOutcome:id,code', 'blueprint', 'creator:id,name', 'basedOn:id,version_number,version_label']);

        $metadata = $this->compareMetadata($from, $to);
        $questions = $this->compareQuestions($from, $to);
        $marks = $this->compareMarks($from, $to, $questions);
        $blueprint = $this->compareBlueprint($from, $to);
        $mappings = $this->compareMappings($from, $to, $questions);
        $analysis = $this->compareAnalysis($from, $to);

        $structural = config('assessment_versioning.structural_fields');
        $hasStructural = $questions['summary']['added'] > 0 || $questions['summary']['removed'] > 0
            || collect($questions['items'])->contains(fn ($i) => $i['status'] === 'MODIFIED' && array_intersect(array_column($i['changes'], 'field'), $structural) !== [])
            || collect($metadata)->contains(fn ($m) => $m['changed'] && in_array($m['field'], ['total_marks', 'assessment_type', 'question_count'], true));
        $anyChange = $hasStructural || $questions['summary']['modified'] > 0 || collect($metadata)->contains(fn ($m) => $m['changed']) || $blueprint['changed'];

        return [
            'from' => $this->versions->summary($from), 'to' => $this->versions->summary($to),
            'metadata' => $metadata, 'questions' => $questions, 'marks' => $marks, 'blueprint' => $blueprint, 'mappings' => $mappings, 'analysis' => $analysis,
            'summary' => $questions['summary'] + ['metadata_changes' => collect($metadata)->where('changed', true)->count(), 'marks_difference' => $marks['total']['difference'],
                'question_count_difference' => $to->question_count - $from->question_count, 'blueprint_changed' => $blueprint['changed'],
                'detected_change_type' => !$anyChange ? 'NONE' : ($hasStructural ? AssessmentVersion::TYPE_MAJOR : AssessmentVersion::TYPE_MINOR)],
            'compared_at' => now()->toISOString(),
        ];
    }

    public function compareMetadata(AssessmentVersion $a, AssessmentVersion $b): array
    {
        $rows = [];
        foreach (['title' => 'Title', 'assessment_type' => 'Assessment type', 'description' => 'Description', 'instructions' => 'Instructions', 'total_marks' => 'Total marks', 'duration_minutes' => 'Duration (minutes)', 'question_count' => 'Question count'] as $field => $label) {
            $x = $a->{$field};
            $y = $b->{$field};
            if (in_array($field, ['total_marks'], true)) {
                $x = (float) $x;
                $y = (float) $y;
            }
            $rows[] = ['field' => $field, 'label' => $label, 'from' => $x, 'to' => $y, 'changed' => $this->differs($x, $y)];
        }

        return $rows;
    }

    /** Match by original_question_id, then by question_number; report ADDED / REMOVED / MODIFIED / UNCHANGED with field-level changes. */
    public function compareQuestions(AssessmentVersion $a, AssessmentVersion $b): array
    {
        $fields = config('assessment_versioning.compared_question_fields');
        $fromQs = $a->questions->values();
        $toQs = $b->questions->values();
        $pairs = [];
        $usedTo = [];
        foreach ($fromQs as $q) {
            if ($q->original_question_id === null) {
                continue;
            }
            $match = $toQs->first(fn ($t) => $t->original_question_id !== null && (int) $t->original_question_id === (int) $q->original_question_id && !isset($usedTo[$t->id]));
            if ($match) {
                $pairs[] = [$q, $match];
                $usedTo[$match->id] = true;
            }
        }
        $pairedFrom = collect($pairs)->map(fn ($p) => $p[0]->id)->all();
        // Leftovers sharing a question number are the same slot: a different original id means the question was replaced.
        foreach ($fromQs as $q) {
            if (in_array($q->id, $pairedFrom, true)) {
                continue;
            }
            $match = $toQs->first(fn ($t) => !isset($usedTo[$t->id]) && (int) $t->question_number === (int) $q->question_number);
            if ($match) {
                $pairs[] = [$q, $match];
                $usedTo[$match->id] = true;
                $pairedFrom[] = $q->id;
            }
        }

        $items = [];
        foreach ($pairs as [$x, $y]) {
            $changes = [];
            foreach ($fields as $f) {
                $vx = $f === 'marks' ? (float) $x->{$f} : $x->{$f};
                $vy = $f === 'marks' ? (float) $y->{$f} : $y->{$f};
                if ($this->differs($vx, $vy)) {
                    $changes[] = ['field' => $f, 'from' => $vx, 'to' => $vy] + ($f === 'learning_outcome_id' ? ['from_label' => $x->learningOutcome?->code, 'to_label' => $y->learningOutcome?->code] : [])
                        + ($f === 'program_outcome_id' ? ['from_label' => $x->programOutcome?->code, 'to_label' => $y->programOutcome?->code] : []);
                }
            }
            $replaced = (int) ($x->original_question_id ?? 0) !== (int) ($y->original_question_id ?? 0);
            $items[] = ['status' => $changes || $replaced ? 'MODIFIED' : 'UNCHANGED', 'replaced' => $replaced, 'question_number' => $y->question_number, 'from' => $this->versions->presentQuestion($x), 'to' => $this->versions->presentQuestion($y), 'changes' => $changes];
        }
        foreach ($fromQs as $q) {
            if (!in_array($q->id, $pairedFrom, true)) {
                $items[] = ['status' => 'REMOVED', 'replaced' => false, 'question_number' => $q->question_number, 'from' => $this->versions->presentQuestion($q), 'to' => null, 'changes' => []];
            }
        }
        foreach ($toQs as $t) {
            if (!isset($usedTo[$t->id])) {
                $items[] = ['status' => 'ADDED', 'replaced' => false, 'question_number' => $t->question_number, 'from' => null, 'to' => $this->versions->presentQuestion($t), 'changes' => []];
            }
        }
        usort($items, fn ($p, $q) => [$p['question_number'], $p['status']] <=> [$q['question_number'], $q['status']]);
        $counts = collect($items)->countBy('status');

        return ['items' => $items, 'summary' => ['added' => (int) $counts->get('ADDED', 0), 'removed' => (int) $counts->get('REMOVED', 0), 'modified' => (int) $counts->get('MODIFIED', 0), 'unchanged' => (int) $counts->get('UNCHANGED', 0), 'total_from' => $fromQs->count(), 'total_to' => $toQs->count()]];
    }

    public function compareMarks(AssessmentVersion $a, AssessmentVersion $b, ?array $questions = null): array
    {
        $questions ??= $this->compareQuestions($a, $b);
        $items = [];
        foreach ($questions['items'] as $i) {
            $fromMarks = $i['from']['marks'] ?? null;
            $toMarks = $i['to']['marks'] ?? null;
            if ($i['status'] === 'UNCHANGED' || ($i['status'] === 'MODIFIED' && !$this->differs($fromMarks, $toMarks))) {
                continue;
            }
            $items[] = ['question_number' => $i['question_number'], 'status' => $i['status'], 'from' => $fromMarks, 'to' => $toMarks, 'difference' => round((float) ($toMarks ?? 0) - (float) ($fromMarks ?? 0), 2)];
        }
        $fromTotal = (float) $a->total_marks;
        $toTotal = (float) $b->total_marks;

        return [
            'total' => ['from' => $fromTotal, 'to' => $toTotal, 'difference' => round($toTotal - $fromTotal, 2)],
            'questions_sum' => ['from' => round((float) $a->questions->sum('marks'), 2), 'to' => round((float) $b->questions->sum('marks'), 2)],
            'items' => $items,
        ];
    }

    /** Planned (blueprint snapshot) and actual (question profile) distributions side by side, in percentage points. */
    public function compareBlueprint(AssessmentVersion $a, AssessmentVersion $b): array
    {
        $threshold = (float) config('assessment_versioning.distribution_change_threshold', 0.5);
        $pa = $this->validation->profile($a);
        $pb = $this->validation->profile($b);
        $dims = ['difficulty' => 'Difficulty', 'cognitive' => 'Bloom level', 'learning_outcomes' => 'Course outcomes', 'program_outcomes' => 'Program outcomes', 'topics' => 'Topics', 'question_types' => 'Question types'];
        $profile = [];
        foreach ($dims as $k => $label) {
            $profile[$k] = ['label' => $label, 'rows' => $this->diffRows($pa[$k], $pb[$k], $threshold)];
        }
        $profile['structure'] = ['label' => 'Structure', 'rows' => [
            ['key' => 'question_count', 'label' => 'Question count', 'from' => $pa['question_count'], 'to' => $pb['question_count'], 'difference' => $pb['question_count'] - $pa['question_count'], 'changed' => $pa['question_count'] !== $pb['question_count']],
            ['key' => 'total_marks', 'label' => 'Total marks', 'from' => (float) $a->total_marks, 'to' => (float) $b->total_marks, 'difference' => round((float) $b->total_marks - (float) $a->total_marks, 2), 'changed' => $this->differs((float) $a->total_marks, (float) $b->total_marks)],
        ]];

        $ba = $a->blueprint;
        $bb = $b->blueprint;
        $planned = ['configured' => $ba !== null || $bb !== null, 'from' => $ba ? ['blueprint_id' => $ba->blueprint_id, 'blueprint_version' => $ba->blueprint_version, 'status' => $ba->blueprint_status] : null,
            'to' => $bb ? ['blueprint_id' => $bb->blueprint_id, 'blueprint_version' => $bb->blueprint_version, 'status' => $bb->blueprint_status] : null, 'dimensions' => []];
        $plannedChanged = false;
        if ($ba || $bb) {
            $cols = ['difficulty' => 'difficulty_distribution', 'cognitive' => 'cognitive_distribution', 'learning_outcomes' => 'learning_outcome_distribution', 'program_outcomes' => 'program_outcome_distribution', 'topics' => 'topic_distribution', 'question_types' => 'question_type_distribution'];
            foreach ($cols as $k => $col) {
                $rows = $this->diffRows($ba?->{$col} ?? [], $bb?->{$col} ?? [], $threshold);
                $planned['dimensions'][$k] = ['label' => $dims[$k], 'rows' => $rows];
                $plannedChanged = $plannedChanged || collect($rows)->contains('changed', true);
            }
            $planned['structure'] = ['rows' => [
                ['key' => 'question_count', 'label' => 'Planned questions', 'from' => $ba?->question_count, 'to' => $bb?->question_count, 'difference' => (int) ($bb?->question_count ?? 0) - (int) ($ba?->question_count ?? 0), 'changed' => $this->differs($ba?->question_count, $bb?->question_count)],
                ['key' => 'total_marks', 'label' => 'Planned marks', 'from' => $ba ? (float) $ba->total_marks : null, 'to' => $bb ? (float) $bb->total_marks : null, 'difference' => round((float) ($bb?->total_marks ?? 0) - (float) ($ba?->total_marks ?? 0), 2), 'changed' => $this->differs($ba ? (float) $ba->total_marks : null, $bb ? (float) $bb->total_marks : null)],
            ]];
            $plannedChanged = $plannedChanged || ($ba?->blueprint_id !== $bb?->blueprint_id) || ($ba?->blueprint_version !== $bb?->blueprint_version) || collect($planned['structure']['rows'])->contains('changed', true);
        }
        $profileChanged = collect($profile)->contains(fn ($d) => collect($d['rows'])->contains('changed', true));

        return ['changed' => $plannedChanged || $profileChanged, 'threshold_pp' => $threshold, 'profile' => $profile, 'planned' => $planned + ['changed' => $plannedChanged]];
    }

    /** CO / PO coverage changes and per-question mapping changes. */
    public function compareMappings(AssessmentVersion $a, AssessmentVersion $b, ?array $questions = null): array
    {
        $questions ??= $this->compareQuestions($a, $b);
        $set = fn (Collection $qs, string $field, string $rel) => $qs->filter(fn ($q) => $q->{$field} !== null)->mapWithKeys(fn (AssessmentVersionQuestion $q) => [(int) $q->{$field} => $q->{$rel}?->code ?? ('#' . $q->{$field})])->all();
        $coverage = function (array $x, array $y): array {
            return [
                'from' => array_values($x), 'to' => array_values($y),
                'added' => array_values(array_intersect_key($y, array_diff_key($y, $x))), 'removed' => array_values(array_intersect_key($x, array_diff_key($x, $y))),
                'unchanged' => array_values(array_intersect_key($x, $y)),
            ];
        };
        $items = collect($questions['items'])->filter(fn ($i) => $i['status'] === 'MODIFIED' && collect($i['changes'])->contains(fn ($c) => in_array($c['field'], ['learning_outcome_id', 'program_outcome_id'], true)))
            ->map(fn ($i) => ['question_number' => $i['question_number'], 'changes' => array_values(array_filter($i['changes'], fn ($c) => in_array($c['field'], ['learning_outcome_id', 'program_outcome_id'], true)))])->values()->all();

        return [
            'learning_outcomes' => $coverage($set($a->questions, 'learning_outcome_id', 'learningOutcome'), $set($b->questions, 'learning_outcome_id', 'learningOutcome')),
            'program_outcomes' => $coverage($set($a->questions, 'program_outcome_id', 'programOutcome'), $set($b->questions, 'program_outcome_id', 'programOutcome')),
            'items' => $items,
        ];
    }

    /** Latest completed STEP 13 analysis of each version, metric by metric (no new metric is computed). */
    public function compareAnalysis(AssessmentVersion $a, AssessmentVersion $b): array
    {
        $ra = AnalysisReport::where('assessment_version_id', $a->id)->where('analysis_status', 'completed')->withCount('recommendations')->orderByDesc('analysis_version')->first();
        $rb = AnalysisReport::where('assessment_version_id', $b->id)->where('analysis_status', 'completed')->withCount('recommendations')->orderByDesc('analysis_version')->first();
        $pa = $ra ? $this->versions->presentAnalysis($ra, $a) : null;
        $pb = $rb ? $this->versions->presentAnalysis($rb, $b) : null;
        $metrics = [];
        foreach (['overall_score' => 'Assessment quality', 'topic_coverage_score' => 'Topic coverage', 'learning_outcome_alignment_score' => 'LO coverage', 'difficulty_balance_score' => 'Difficulty balance',
            'cognitive_level_balance_score' => 'Cognitive diversity', 'similarity_score' => 'Similarity score', 'similar_questions_count' => 'Similar questions', 'recommendations_count' => 'Recommendations', 'total_questions' => 'Analyzed questions'] as $k => $label) {
            $x = $pa[$k] ?? null;
            $y = $pb[$k] ?? null;
            $metrics[] = ['key' => $k, 'label' => $label, 'from' => $x, 'to' => $y, 'difference' => $x !== null && $y !== null ? round($y - $x, 2) : null];
        }

        return ['available' => $pa !== null && $pb !== null, 'from' => $pa, 'to' => $pb, 'metrics' => $metrics,
            'note' => $pa === null || $pb === null ? 'Both versions need a completed analysis to compare quality metrics.' : 'Metrics come from the existing STEP 13 quality engine; STALE analyses describe an earlier state of a draft.'];
    }

    // ------------------------------------------------------------- helpers

    protected function diffRows(array $x, array $y, float $threshold): array
    {
        $keys = array_values(array_unique(array_merge(array_keys($x), array_keys($y))));
        sort($keys);
        $rows = [];
        foreach ($keys as $k) {
            $from = isset($x[$k]) ? (float) $x[$k]['percentage'] : null;
            $to = isset($y[$k]) ? (float) $y[$k]['percentage'] : null;
            $diff = round((float) ($to ?? 0) - (float) ($from ?? 0), 1);
            $rows[] = ['key' => (string) $k, 'label' => (string) ($y[$k]['label'] ?? $x[$k]['label'] ?? $k), 'from' => $from, 'to' => $to, 'difference' => $diff, 'changed' => abs($diff) >= $threshold || ($from === null) !== ($to === null)];
        }

        return $rows;
    }

    protected function differs(mixed $x, mixed $y): bool
    {
        if (is_float($x) || is_float($y)) {
            return $x === null || $y === null ? $x !== $y : abs((float) $x - (float) $y) > 0.001;
        }
        if (is_string($x) || is_string($y)) {
            return trim((string) $x) !== trim((string) $y);
        }

        return $x != $y;
    }
}
