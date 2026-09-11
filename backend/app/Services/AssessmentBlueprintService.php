<?php

namespace App\Services;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\AssessmentBlueprint;
use App\Models\AssessmentBlueprintConstraint;
use App\Models\AssessmentBlueprintItem;
use App\Models\AssessmentBlueprintSection;
use App\Models\LearningOutcome;
use App\Models\PreviousQuestion;
use App\Models\ProgramOutcome;
use App\Models\Question;
use App\Models\QuestionGenerationRequest;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * STEP 37: Assessment Blueprint lifecycle — create / version / validate / finalize / coverage / comparison / generation hand-off.
 * The blueprint is a planning layer: it never changes questions, marks, mappings or the assessment status.
 */
class AssessmentBlueprintService
{
    public function __construct(
        protected AssessmentBlueprintValidator $validator,
        protected AuditLogService $audit,
        protected CourseAccessService $access,
    ) {}

    // ------------------------------------------------------------- lookup

    public function current(Assessment $assessment): ?AssessmentBlueprint
    {
        return AssessmentBlueprint::where('assessment_id', $assessment->id)->where('is_current', true)->orderByDesc('version')->first();
    }

    public function versions(Assessment $assessment): array
    {
        return AssessmentBlueprint::where('assessment_id', $assessment->id)->orderByDesc('version')->get()
            ->map(fn ($b) => ['id' => $b->id, 'version' => $b->version, 'status' => $b->status, 'is_current' => $b->is_current, 'total_marks' => $b->total_marks, 'total_questions' => $b->total_questions,
                'validation_status' => $b->validation_status, 'blueprint_completeness' => $b->blueprint_completeness, 'finalized_at' => $b->finalized_at?->toISOString(), 'created_at' => $b->created_at?->toISOString()])->values()->all();
    }

    // ------------------------------------------------------------- create / update / version

    public function create(User $user, Assessment $assessment, array $data): AssessmentBlueprint
    {
        $current = $this->current($assessment);
        if ($current && !$current->isFinalized()) {
            throw new HttpException(409, 'A draft blueprint already exists for this assessment. Edit it instead of creating another.');
        }

        return DB::transaction(function () use ($user, $assessment, $data, $current) {
            $version = ((int) AssessmentBlueprint::where('assessment_id', $assessment->id)->max('version')) + 1;
            if ($current) {
                $current->update(['is_current' => false, 'status' => AssessmentBlueprint::STATUS_ARCHIVED]);
            }
            $bp = AssessmentBlueprint::create([
                'assessment_id' => $assessment->id, 'created_by' => $user->id, 'version' => $version, 'status' => AssessmentBlueprint::STATUS_DRAFT, 'is_current' => true,
                'title' => $data['title'] ?? $assessment->title, 'total_marks' => $data['total_marks'], 'total_questions' => $data['total_questions'],
                'duration_minutes' => $data['duration_minutes'] ?? $assessment->duration_minutes, 'instructions' => $data['instructions'] ?? null,
            ]);
            $this->syncChildren($bp, $data);
            $this->audit->log($version > 1 ? 'BLUEPRINT_VERSION_CREATED' : 'BLUEPRINT_CREATED', $bp, $bp->id, ['assessment_id' => $assessment->id, 'course_id' => $assessment->course_id, 'version' => $version], $user);

            return $bp->fresh(['sections', 'constraints', 'items']);
        });
    }

    /** Editing a FINALIZED blueprint never mutates it: a new version is created instead. */
    public function update(User $user, AssessmentBlueprint $bp, array $data): AssessmentBlueprint
    {
        if ($bp->isFinalized()) {
            $bp->loadMissing('assessment');

            return $this->create($user, $bp->assessment, $data);
        }
        if ($bp->status === AssessmentBlueprint::STATUS_ARCHIVED) {
            throw new HttpException(409, 'Archived blueprint versions are read-only.');
        }

        return DB::transaction(function () use ($user, $bp, $data) {
            $bp->update([
                'title' => $data['title'] ?? $bp->title, 'total_marks' => $data['total_marks'], 'total_questions' => $data['total_questions'],
                'duration_minutes' => $data['duration_minutes'] ?? $bp->duration_minutes, 'instructions' => $data['instructions'] ?? null,
                'status' => AssessmentBlueprint::STATUS_DRAFT, 'validation_status' => null, 'validation_result' => null, 'validated_at' => null,
            ]);
            $bp->sections()->delete();
            $bp->constraints()->delete();
            $bp->items()->delete();
            $this->syncChildren($bp, $data);
            $this->audit->log('BLUEPRINT_UPDATED', $bp, $bp->id, ['assessment_id' => $bp->assessment_id, 'version' => $bp->version], $user);

            return $bp->fresh(['sections', 'constraints', 'items']);
        });
    }

    public function delete(User $user, AssessmentBlueprint $bp): void
    {
        if ($bp->isFinalized()) {
            throw new HttpException(409, 'A finalized blueprint cannot be deleted. Create a new version instead.');
        }
        $assessmentId = $bp->assessment_id;
        DB::transaction(function () use ($bp, $assessmentId) {
            $bp->delete();
            // Promote the latest remaining version so the assessment keeps a current blueprint
            $latest = AssessmentBlueprint::where('assessment_id', $assessmentId)->orderByDesc('version')->first();
            if ($latest && !$latest->is_current) {
                $latest->update(['is_current' => true, 'status' => $latest->isFinalized() ? AssessmentBlueprint::STATUS_FINALIZED : $latest->status]);
            }
        });
        $this->audit->log('BLUEPRINT_DELETED', 'AssessmentBlueprint', $bp->id, ['assessment_id' => $assessmentId], $user);
    }

    protected function syncChildren(AssessmentBlueprint $bp, array $data): void
    {
        $bp->loadMissing('assessment.course');
        $course = $bp->assessment->course;
        $courseLoIds = LearningOutcome::where('course_id', $course->id)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $poIds = $course->program_id ? ProgramOutcome::where('program_id', $course->program_id)->pluck('id')->map(fn ($i) => (int) $i)->all() : [];

        $sectionsByOrder = [];
        foreach (array_values((array) ($data['sections'] ?? [])) as $i => $s) {
            $order = (int) ($s['section_order'] ?? ($i + 1));
            $section = AssessmentBlueprintSection::create([
                'blueprint_id' => $bp->id, 'title' => $s['title'], 'section_order' => $order, 'instructions' => $s['instructions'] ?? null, 'question_type' => $s['question_type'] ?? null,
                'question_count' => (int) $s['question_count'], 'marks_per_question' => (float) $s['marks_per_question'], 'total_marks' => round((float) $s['marks_per_question'] * (int) $s['question_count'], 2),
                'difficulty_distribution' => $s['difficulty_distribution'] ?? null, 'cognitive_distribution' => $s['cognitive_distribution'] ?? null,
            ]);
            $sectionsByOrder[$order] = $section->id;
        }

        $c = (array) ($data['constraints'] ?? []);
        foreach ((array) ($c['difficulty'] ?? []) as $row) {
            $this->constraint($bp, 'DIFFICULTY', strtolower($row['key']), ['target_percentage' => $row['target_percentage'] ?? null, 'target_count' => $row['target_count'] ?? null]);
        }
        foreach ((array) ($c['cognitive'] ?? []) as $row) {
            $this->constraint($bp, 'COGNITIVE_LEVEL', $row['key'], ['target_percentage' => $row['target_percentage'] ?? null, 'target_count' => $row['target_count'] ?? null]);
        }
        foreach ((array) ($c['learning_outcomes'] ?? []) as $row) {
            if (!in_array((int) $row['learning_outcome_id'], $courseLoIds, true)) {
                throw new HttpException(422, 'Learning outcome targets must reference outcomes of this course.');
            }
            $this->constraint($bp, 'LEARNING_OUTCOME', 'lo:' . $row['learning_outcome_id'], ['learning_outcome_id' => (int) $row['learning_outcome_id'], 'target_percentage' => $row['target_percentage'] ?? null, 'target_marks' => $row['target_marks'] ?? null, 'target_count' => $row['target_count'] ?? null]);
        }
        foreach ((array) ($c['program_outcomes'] ?? []) as $row) {
            if (!in_array((int) $row['program_outcome_id'], $poIds, true)) {
                throw new HttpException(422, "Program outcome targets must reference outcomes of this course's program.");
            }
            $this->constraint($bp, 'PROGRAM_OUTCOME', 'po:' . $row['program_outcome_id'], ['program_outcome_id' => (int) $row['program_outcome_id'], 'target_percentage' => $row['target_percentage']]);
        }
        foreach ((array) ($c['topics'] ?? []) as $row) {
            $this->constraint($bp, 'TOPIC', trim($row['topic']), ['target_count' => $row['target_count'] ?? null, 'target_marks' => $row['target_marks'] ?? null, 'target_type' => isset($row['target_marks']) ? 'marks' : 'count']);
        }
        foreach ((array) ($c['question_types'] ?? []) as $row) {
            $each = isset($row['marks_each']) ? (float) $row['marks_each'] : null;
            $this->constraint($bp, 'QUESTION_TYPE', $row['question_type'], ['target_count' => (int) $row['target_count'], 'target_marks' => $each !== null ? round($each * (int) $row['target_count'], 2) : null, 'target_type' => 'count', 'metadata' => ['marks_each' => $each]]);
        }

        foreach (array_values((array) ($data['items'] ?? [])) as $i => $it) {
            if (!empty($it['learning_outcome_id']) && !in_array((int) $it['learning_outcome_id'], $courseLoIds, true)) {
                throw new HttpException(422, 'Question plan rows must reference outcomes of this course.');
            }
            if (!empty($it['program_outcome_id']) && !in_array((int) $it['program_outcome_id'], $poIds, true)) {
                throw new HttpException(422, "Question plan rows must reference program outcomes of this course's program.");
            }
            AssessmentBlueprintItem::create([
                'blueprint_id' => $bp->id, 'section_id' => isset($it['section_order']) ? ($sectionsByOrder[(int) $it['section_order']] ?? null) : null, 'topic' => isset($it['topic']) ? trim($it['topic']) : null,
                'learning_outcome_id' => $it['learning_outcome_id'] ?? null, 'program_outcome_id' => $it['program_outcome_id'] ?? null, 'question_type' => $it['question_type'] ?? null,
                'difficulty_level' => isset($it['difficulty_level']) ? strtolower($it['difficulty_level']) : null, 'cognitive_level' => $it['cognitive_level'] ?? null,
                'question_count' => (int) $it['question_count'], 'marks_each' => (float) $it['marks_each'], 'total_marks' => round((float) $it['marks_each'] * (int) $it['question_count'], 2), 'sort_order' => $i + 1,
            ]);
        }
    }

    protected function constraint(AssessmentBlueprint $bp, string $dimension, string $key, array $attrs): void
    {
        if (AssessmentBlueprintConstraint::where('blueprint_id', $bp->id)->where('dimension', $dimension)->where('target_key', $key)->exists()) {
            throw new HttpException(422, "Duplicate {$dimension} target for \"{$key}\".");
        }
        AssessmentBlueprintConstraint::create(['blueprint_id' => $bp->id, 'dimension' => $dimension, 'target_key' => $key, 'target_type' => $attrs['target_type'] ?? (isset($attrs['target_percentage']) && $attrs['target_percentage'] !== null ? 'percentage' : (isset($attrs['target_marks']) && $attrs['target_marks'] !== null ? 'marks' : 'count'))] + $attrs);
    }

    // ------------------------------------------------------------- validate / finalize

    public function validate(User $user, AssessmentBlueprint $bp, bool $audit = true): array
    {
        $result = $this->validator->validate($bp);
        $updates = ['validation_status' => $result['status'], 'validation_result' => $result, 'blueprint_completeness' => $result['completeness']['score'], 'validated_at' => now()];
        if (!$bp->isFinalized() && $bp->status !== AssessmentBlueprint::STATUS_ARCHIVED) {
            $updates['status'] = $result['status'] === AssessmentBlueprint::INVALID ? AssessmentBlueprint::STATUS_DRAFT : AssessmentBlueprint::STATUS_VALIDATED;
        }
        $bp->update($updates);
        if ($audit) {
            $this->audit->log('BLUEPRINT_VALIDATED', $bp, $bp->id, ['assessment_id' => $bp->assessment_id, 'status' => $result['status'], 'errors' => count($result['errors']), 'warnings' => count($result['warnings'])], $user);
        }

        return $result;
    }

    public function finalize(User $user, AssessmentBlueprint $bp): AssessmentBlueprint
    {
        if ($bp->isFinalized()) {
            throw new HttpException(409, 'This blueprint is already finalized.');
        }
        $result = $this->validate($user, $bp, false);
        if ($result['status'] === AssessmentBlueprint::INVALID) {
            throw new HttpException(422, 'Only a valid blueprint can be finalized. Resolve the validation errors first.');
        }
        $bp->update(['status' => AssessmentBlueprint::STATUS_FINALIZED, 'finalized_at' => now(), 'finalized_by' => $user->id]);
        $this->audit->log('BLUEPRINT_FINALIZED', $bp, $bp->id, ['assessment_id' => $bp->assessment_id, 'version' => $bp->version, 'validation_status' => $result['status']], $user);

        return $bp->fresh();
    }

    // ------------------------------------------------------------- coverage / comparison

    /** Target coverage per dimension (from the stored or freshly computed validation). */
    public function coverage(AssessmentBlueprint $bp): array
    {
        $result = $bp->validation_result ?: $this->validator->validate($bp);

        return ['distributions' => $result['distributions'], 'matrices' => $result['matrices'], 'totals' => $result['totals'], 'completeness' => $result['completeness'], 'time_indicator' => $result['time_indicator']];
    }

    /** Actual question metadata of the assessment (faculty value first, AI value as fallback). */
    public function actualProfile(Assessment $assessment): array
    {
        $questions = Question::where('assessment_id', $assessment->id)->get(['id', 'question_number', 'question_text', 'question_type', 'marks', 'difficulty_level', 'ai_difficulty_level', 'cognitive_level', 'ai_cognitive_level', 'learning_outcome_id', 'ai_topics']);
        $n = $questions->count();
        $marks = (float) $questions->sum('marks');
        $confirmed = DB::table('question_co_mappings')->whereIn('question_id', $questions->pluck('id'))->where('status', 'CONFIRMED')->get(['question_id', 'learning_outcome_id'])->groupBy('question_id');
        $course = $assessment->course;
        $loToPo = $course->program_id ? DB::table('co_po_mappings')->where('course_id', $course->id)->where('mapping_level', '>', 0)->get(['learning_outcome_id', 'program_outcome_id'])->groupBy('learning_outcome_id') : collect();

        $difficulty = [];
        $cognitive = [];
        $types = [];
        $los = [];
        $pos = [];
        $topics = [];
        $rows = [];
        foreach ($questions as $q) {
            $d = strtolower((string) ($q->difficulty_level ?: $q->ai_difficulty_level));
            $c = (string) ($q->cognitive_level ?: $q->ai_cognitive_level);
            $difficulty[$d] = ($difficulty[$d] ?? 0) + 1;
            $cognitive[strtolower($c)] = ($cognitive[strtolower($c)] ?? 0) + 1;
            $types[$q->question_type] = ($types[$q->question_type] ?? 0) + 1;
            $loIds = array_values(array_unique(array_filter(array_merge([$q->learning_outcome_id], $confirmed->get($q->id, collect())->pluck('learning_outcome_id')->all()))));
            foreach ($loIds as $lo) {
                $los[$lo] = ($los[$lo] ?? 0) + (float) $q->marks;
                foreach ($loToPo->get($lo, collect()) as $m) {
                    $pos[$m->program_outcome_id] = ($pos[$m->program_outcome_id] ?? 0) + (float) $q->marks;
                }
            }
            foreach ((array) ($q->ai_topics ?? []) as $t) {
                $topics[strtolower(trim((string) $t))] = ($topics[strtolower(trim((string) $t))] ?? 0) + (float) $q->marks;
            }
            $rows[] = ['id' => $q->id, 'number' => $q->question_number, 'marks' => (float) $q->marks, 'type' => $q->question_type, 'difficulty' => $d ?: null, 'cognitive' => $c ?: null, 'learning_outcome_ids' => $loIds, 'topics' => array_map('strtolower', (array) ($q->ai_topics ?? [])), 'text' => mb_substr((string) $q->question_text, 0, 160)];
        }

        return ['question_count' => $n, 'total_marks' => $marks, 'difficulty' => $difficulty, 'cognitive' => $cognitive, 'question_types' => $types, 'learning_outcome_marks' => $los, 'program_outcome_marks' => $pos, 'topic_marks' => $topics, 'questions' => $rows];
    }

    /** Blueprint targets vs actual question set → MATCH / CLOSE / MISMATCH / NOT_CONFIGURED with configurable tolerance. */
    public function comparison(AssessmentBlueprint $bp): array
    {
        $bp->loadMissing('assessment.course');
        $result = $bp->validation_result ?: $this->validator->validate($bp);
        $actual = $this->actualProfile($bp->assessment);
        $tol = (float) config('assessment_blueprint.percentage_tolerance', 5);
        $n = $actual['question_count'];
        $marks = $actual['total_marks'];
        $dims = [];

        $dims['difficulty'] = $this->compareRows($result['distributions']['difficulty'], fn ($r) => $n ? round(($actual['difficulty'][$r['key']] ?? 0) / $n * 100, 1) : null, fn ($r) => $actual['difficulty'][$r['key']] ?? 0, $tol);
        $dims['cognitive'] = $this->compareRows($result['distributions']['cognitive'], fn ($r) => $n ? round(($actual['cognitive'][strtolower($r['key'])] ?? 0) / $n * 100, 1) : null, fn ($r) => $actual['cognitive'][strtolower($r['key'])] ?? 0, $tol);
        $dims['learning_outcomes'] = $this->compareRows($result['distributions']['learning_outcomes'], fn ($r) => $marks ? round(($actual['learning_outcome_marks'][$r['learning_outcome_id']] ?? 0) / $marks * 100, 1) : null, fn ($r) => $actual['learning_outcome_marks'][$r['learning_outcome_id']] ?? 0, $tol, 'marks');
        $dims['program_outcomes'] = ($result['distributions']['program_outcomes']['available'] ?? false)
            ? $this->compareRows($result['distributions']['program_outcomes'], fn ($r) => $marks ? round(($actual['program_outcome_marks'][$r['program_outcome_id']] ?? 0) / $marks * 100, 1) : null, fn ($r) => $actual['program_outcome_marks'][$r['program_outcome_id']] ?? 0, $tol, 'marks')
            : ['configured' => false, 'rows' => [], 'message' => $result['distributions']['program_outcomes']['message'] ?? 'PO blueprint is not configured for this course.'];
        $dims['topics'] = $this->compareRows($result['distributions']['topics'], fn ($r) => $marks ? round(($actual['topic_marks'][strtolower($r['key'])] ?? 0) / $marks * 100, 1) : null, fn ($r) => $actual['topic_marks'][strtolower($r['key'])] ?? 0, $tol, 'marks');
        $dims['question_types'] = $this->compareRows($result['distributions']['question_types'], fn ($r) => $n ? round(($actual['question_types'][$r['key']] ?? 0) / $n * 100, 1) : null, fn ($r) => $actual['question_types'][$r['key']] ?? 0, $tol);

        $structure = [
            ['dimension' => 'question_count', 'label' => 'Questions', 'target' => (int) $bp->total_questions, 'actual' => $n, 'difference' => $n - (int) $bp->total_questions, 'status' => $n === (int) $bp->total_questions ? 'MATCH' : 'MISMATCH'],
            ['dimension' => 'total_marks', 'label' => 'Total marks', 'target' => (float) $bp->total_marks, 'actual' => $marks, 'difference' => round($marks - (float) $bp->total_marks, 2), 'status' => abs($marks - (float) $bp->total_marks) < 0.01 ? 'MATCH' : 'MISMATCH'],
        ];

        $configuredRows = collect($dims)->flatMap(fn ($d) => $d['rows'] ?? [])->filter(fn ($r) => $r['status'] !== 'NOT_CONFIGURED');
        $ok = $configuredRows->whereIn('status', ['MATCH', 'CLOSE'])->count();
        $compliance = $configuredRows->count() ? round($ok / $configuredRows->count() * 100, 1) : null;
        $summary = [];
        foreach ($dims as $k => $d) {
            $rows = collect($d['rows'] ?? [])->where('status', '!=', 'NOT_CONFIGURED');
            $summary[$k] = !($d['configured'] ?? false) || $rows->isEmpty() ? 'NOT_CONFIGURED' : ($rows->contains('status', 'MISMATCH') ? 'MISMATCH' : ($rows->contains('status', 'CLOSE') ? 'CLOSE' : 'MATCH'));
        }

        return [
            'blueprint_id' => $bp->id, 'version' => $bp->version, 'blueprint_status' => $bp->status, 'tolerance_percent' => $tol,
            'actual' => ['question_count' => $n, 'total_marks' => $marks, 'has_questions' => $n > 0],
            'structure' => $structure, 'dimensions' => $dims, 'summary' => $summary, 'compliance_percent' => $n > 0 ? $compliance : null,
            'note' => 'Comparison uses the actual question metadata of this assessment (faculty values first, AI values as fallback). Nothing is changed automatically; review deviations before finalizing the assessment.',
            'compared_at' => now()->toISOString(),
        ];
    }

    protected function compareRows(array $dist, callable $actualPct, callable $actualRaw, float $tol, string $basis = 'count'): array
    {
        $rows = [];
        foreach ($dist['rows'] ?? [] as $r) {
            $target = $r['target_percentage'] ?? null;
            $actual = $actualPct($r);
            $raw = $actualRaw($r);
            if (!($r['configured'] ?? false) || $target === null) {
                $rows[] = ['key' => $r['key'], 'label' => $r['label'], 'target_percentage' => $target, 'actual_percentage' => $actual, 'actual_raw' => $raw, 'difference' => null, 'status' => 'NOT_CONFIGURED'];
                continue;
            }
            if ($actual === null) {
                $rows[] = ['key' => $r['key'], 'label' => $r['label'], 'target_percentage' => $target, 'actual_percentage' => null, 'actual_raw' => 0, 'difference' => null, 'status' => 'MISMATCH'];
                continue;
            }
            $diff = round($actual - $target, 1);
            $status = abs($diff) < 0.5 ? 'MATCH' : (abs($diff) <= $tol ? 'CLOSE' : 'MISMATCH');
            $rows[] = ['key' => $r['key'], 'label' => $r['label'], 'target_percentage' => $target, 'actual_percentage' => $actual, 'actual_raw' => $raw, 'difference' => $diff, 'status' => $status];
        }

        return ['configured' => (bool) ($dist['configured'] ?? false), 'basis' => $basis, 'rows' => $rows];
    }

    /** Check candidate questions (assessment questions or question bank) against the blueprint plan rows. */
    public function validateQuestions(AssessmentBlueprint $bp, array $questionIds, array $previousQuestionIds = []): array
    {
        $bp->loadMissing(['items', 'assessment.course']);
        $courseId = $bp->assessment->course_id;
        $items = $bp->items;
        $candidates = [];
        foreach (Question::whereIn('id', $questionIds ?: [-1])->whereHas('assessment', fn ($q) => $q->where('course_id', $courseId))->get() as $q) {
            $candidates[] = ['source' => 'question', 'id' => $q->id, 'text' => mb_substr((string) $q->question_text, 0, 160), 'type' => $q->question_type, 'difficulty' => strtolower((string) ($q->difficulty_level ?: $q->ai_difficulty_level)) ?: null,
                'cognitive' => (string) ($q->cognitive_level ?: $q->ai_cognitive_level) ?: null, 'learning_outcome_id' => $q->learning_outcome_id, 'marks' => (float) $q->marks, 'topics' => array_map('strtolower', (array) ($q->ai_topics ?? []))];
        }
        foreach (PreviousQuestion::whereIn('id', $previousQuestionIds ?: [-1])->where('course_id', $courseId)->get() as $q) {
            $candidates[] = ['source' => 'previous_question', 'id' => $q->id, 'text' => mb_substr((string) $q->question_text, 0, 160), 'type' => $q->question_type, 'difficulty' => $q->difficulty_level ? strtolower($q->difficulty_level) : null,
                'cognitive' => $q->cognitive_level ?: null, 'learning_outcome_id' => null, 'marks' => (float) $q->marks, 'topics' => []];
        }
        $results = [];
        foreach ($candidates as $c) {
            $best = null;
            foreach ($items as $it) {
                $checks = [];
                if ($it->question_type) {
                    $checks['question_type'] = ['target' => $it->question_type, 'actual' => $c['type'], 'ok' => $c['type'] === $it->question_type];
                }
                if ($it->difficulty_level) {
                    $checks['difficulty'] = ['target' => $it->difficulty_level, 'actual' => $c['difficulty'], 'ok' => $c['difficulty'] === $it->difficulty_level];
                }
                if ($it->cognitive_level) {
                    $checks['cognitive_level'] = ['target' => $it->cognitive_level, 'actual' => $c['cognitive'], 'ok' => strcasecmp((string) $c['cognitive'], $it->cognitive_level) === 0];
                }
                if ($it->learning_outcome_id) {
                    $checks['learning_outcome'] = ['target' => $it->learning_outcome_id, 'actual' => $c['learning_outcome_id'], 'ok' => (int) $c['learning_outcome_id'] === (int) $it->learning_outcome_id];
                }
                $checks['marks'] = ['target' => (float) $it->marks_each, 'actual' => $c['marks'], 'ok' => abs($c['marks'] - (float) $it->marks_each) < 0.01];
                if ($it->topic) {
                    $checks['topic'] = ['target' => $it->topic, 'actual' => $c['topics'], 'ok' => in_array(strtolower($it->topic), $c['topics'], true)];
                }
                $score = count(array_filter($checks, fn ($x) => $x['ok']));
                if (!$best || $score > $best['score']) {
                    $best = ['item_id' => $it->id, 'sort_order' => $it->sort_order, 'score' => $score, 'checks' => $checks];
                }
            }
            $failed = $best ? array_keys(array_filter($best['checks'], fn ($x) => !$x['ok'])) : [];
            $results[] = ['source' => $c['source'], 'id' => $c['id'], 'text' => $c['text'], 'metadata' => ['type' => $c['type'], 'difficulty' => $c['difficulty'], 'cognitive' => $c['cognitive'], 'learning_outcome_id' => $c['learning_outcome_id'], 'marks' => $c['marks']],
                'status' => $items->isEmpty() ? 'NO_PLAN' : ($failed ? 'CONSTRAINT_MISMATCH' : 'MATCH'), 'closest_item' => $best ? ['id' => $best['item_id'], 'row' => $best['sort_order']] : null, 'checks' => $best['checks'] ?? [], 'failed_constraints' => $failed];
        }

        return ['blueprint_id' => $bp->id, 'plan_rows' => $items->count(), 'evaluated' => count($results), 'matched' => count(array_filter($results, fn ($r) => $r['status'] === 'MATCH')), 'results' => $results,
            'note' => $items->isEmpty() ? 'This blueprint has no cross-dimension question plan; add plan rows to validate individual questions.' : 'Constraint checks are advisory; faculty decide which questions to use.'];
    }

    // ------------------------------------------------------------- STEP 33 hand-off

    /** Create STEP 33 generation requests from the blueprint (one per CO/topic group). Drafts stay DRAFT until faculty approve. */
    public function generateQuestions(User $user, AssessmentBlueprint $bp, array $options = []): array
    {
        if (!$bp->isFinalized() && !($options['allow_draft'] ?? false)) {
            throw new HttpException(422, 'Finalize the blueprint before generating questions from it.');
        }
        $bp->loadMissing(['items', 'sections', 'constraints', 'assessment.course']);
        $course = $bp->assessment->course;
        if (!$this->access->can($user, $course, 'generate_questions')) {
            throw new HttpException(403, 'You are not allowed to generate questions for this course.');
        }
        $groups = $this->generationGroups($bp);
        if ($groups === []) {
            throw new HttpException(422, 'The blueprint has no sections or plan rows to generate from.');
        }
        $gen = app(QuestionGenerationService::class);
        $requests = [];
        foreach ($groups as $g) {
            $req = $gen->createRequest($user, [
                'course_id' => $course->id, 'assessment_id' => $bp->assessment_id, 'topic' => $g['topic'], 'learning_outcome_id' => $g['learning_outcome_id'], 'program_outcome_id' => $g['program_outcome_id'],
                'question_type' => $g['question_type'], 'difficulty_level' => $g['difficulty_level'], 'cognitive_level' => $g['cognitive_level'], 'marks' => $g['marks'], 'number_of_questions' => $g['count'],
                'language' => $options['language'] ?? 'English', 'document_scope' => $options['document_scope'] ?? null, 'blueprint' => $g['slots'], 'include_expected_answer' => true,
            ]);
            $requests[] = ['id' => $req->id, 'topic' => $req->topic, 'learning_outcome_id' => $req->learning_outcome_id, 'question_type' => $req->question_type, 'number_of_questions' => $req->number_of_questions, 'generation_status' => $req->generation_status, 'warnings' => $req->warnings ?? []];
        }
        $this->audit->log('BLUEPRINT_QUESTION_GENERATION_STARTED', $bp, $bp->id, ['assessment_id' => $bp->assessment_id, 'requests' => count($requests), 'request_ids' => array_column($requests, 'id')], $user);

        return ['blueprint_id' => $bp->id, 'requests' => $requests, 'note' => 'Generated questions remain drafts until faculty review and approve them; nothing is added to the assessment automatically.'];
    }

    /** Group plan rows by CO/PO/topic; each group becomes a STEP 33 request with per-slot difficulty/Bloom/type/marks. */
    public function generationGroups(AssessmentBlueprint $bp): array
    {
        $groups = [];
        if ($bp->items->isNotEmpty()) {
            foreach ($bp->items as $it) {
                $key = implode('|', [$it->learning_outcome_id, $it->program_outcome_id, strtolower((string) $it->topic)]);
                $groups[$key] ??= ['topic' => $it->topic, 'learning_outcome_id' => $it->learning_outcome_id, 'program_outcome_id' => $it->program_outcome_id, 'question_type' => $it->question_type ?: 'descriptive', 'difficulty_level' => null, 'cognitive_level' => null, 'marks' => (float) $it->marks_each, 'count' => 0, 'slots' => []];
                $groups[$key]['count'] += $it->question_count;
                $groups[$key]['slots'][] = ['difficulty_level' => $it->difficulty_level, 'cognitive_level' => $it->cognitive_level, 'question_type' => $it->question_type ?: $groups[$key]['question_type'], 'marks' => (float) $it->marks_each, 'count' => $it->question_count];
            }

            return array_values($groups);
        }
        $difficulty = $bp->constraints->where('dimension', 'DIFFICULTY');
        $alloc = null;
        if ($difficulty->isNotEmpty()) {
            $alloc = $this->validator->allocate($difficulty->mapWithKeys(fn ($c) => [$c->target_key => $c->target_percentage ?? ($bp->total_questions ? $c->target_count / $bp->total_questions * 100 : 0)])->all(), (int) $bp->total_questions)['allocation'];
        }
        $sections = $bp->sections->isNotEmpty() ? $bp->sections : collect([(object) ['question_type' => 'descriptive', 'question_count' => $bp->total_questions, 'marks_per_question' => $bp->total_questions ? round($bp->total_marks / $bp->total_questions, 2) : $bp->total_marks, 'difficulty_distribution' => null, 'title' => null]]);
        $remaining = $alloc;
        foreach ($sections as $s) {
            $slots = [];
            $dist = $s->difficulty_distribution ? $this->validator->allocate($s->difficulty_distribution, (int) $s->question_count)['allocation'] : null;
            if (!$dist && $remaining) {
                $dist = [];
                $left = (int) $s->question_count;
                foreach ($remaining as $k => $v) {
                    $take = min($v, $left);
                    if ($take > 0) {
                        $dist[$k] = $take;
                        $remaining[$k] -= $take;
                        $left -= $take;
                    }
                }
            }
            foreach ($dist ?? [] as $level => $count) {
                if ($count > 0) {
                    $slots[] = ['difficulty_level' => $level, 'cognitive_level' => null, 'question_type' => $s->question_type ?: 'descriptive', 'marks' => (float) $s->marks_per_question, 'count' => (int) $count];
                }
            }
            $groups[] = ['topic' => $s->title, 'learning_outcome_id' => null, 'program_outcome_id' => null, 'question_type' => $s->question_type ?: 'descriptive', 'difficulty_level' => null, 'cognitive_level' => null,
                'marks' => (float) $s->marks_per_question, 'count' => (int) $s->question_count, 'slots' => $slots ?: null];
        }

        return $groups;
    }

    // ------------------------------------------------------------- integrations

    /** STEP 14: surface blueprint mismatches through the existing recommendation infrastructure (deduplicated by title). */
    public function syncRecommendations(AssessmentBlueprint $bp, array $comparison): array
    {
        $report = AnalysisReport::where('assessment_id', $bp->assessment_id)->where('is_current', true)->orderByDesc('id')->first();
        if (!$report) {
            return ['created' => 0, 'skipped' => 0, 'note' => 'No analysis report exists yet; recommendations will be attached after the assessment is analyzed.'];
        }
        $categoryMap = ['difficulty' => 'difficulty', 'cognitive' => 'cognitive_level', 'learning_outcomes' => 'learning_outcome', 'program_outcomes' => 'learning_outcome', 'topics' => 'topic_coverage', 'question_types' => 'assessment_quality'];
        $created = 0;
        $skipped = 0;
        foreach ($comparison['dimensions'] as $dim => $d) {
            foreach ($d['rows'] ?? [] as $r) {
                if ($r['status'] !== 'MISMATCH') {
                    continue;
                }
                $title = "Blueprint deviation: {$r['label']} planned at {$r['target_percentage']}% (v{$bp->version})";
                $exists = Recommendation::where('analysis_report_id', $report->id)->where('title', $title)->whereIn('status', ['pending', 'reviewed'])->exists();
                if ($exists) {
                    $skipped++;
                    continue;
                }
                Recommendation::create([
                    'analysis_report_id' => $report->id, 'category' => $categoryMap[$dim] ?? 'assessment_quality', 'title' => $title,
                    'problem' => "{$r['label']} is planned at {$r['target_percentage']}% but the current question set contains " . ($r['actual_percentage'] ?? 0) . '%.',
                    'description' => "{$r['label']} is planned at {$r['target_percentage']}% in blueprint v{$bp->version}, but the current question set contains " . ($r['actual_percentage'] ?? 0) . "% ({$d['basis']}-based).",
                    'recommendation' => 'Review the question allocation before finalizing the assessment.', 'explanation' => 'Blueprint-versus-question comparison (STEP 37) with tolerance ±' . $comparison['tolerance_percent'] . '%.',
                    'priority' => abs((float) ($r['difference'] ?? 0)) >= 2 * $comparison['tolerance_percent'] ? 'high' : 'medium', 'status' => 'pending', 'source_metric' => 'Assessment Blueprint',
                    'evidence' => ['blueprint_id' => $bp->id, 'dimension' => $dim, 'key' => $r['key'], 'target_percentage' => $r['target_percentage'], 'actual_percentage' => $r['actual_percentage'], 'difference' => $r['difference']],
                ]);
                $created++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'analysis_report_id' => $report->id];
    }

    /** STEP 36 analytics card / STEP 18 report: compliance of the current blueprint (null when none). */
    public function compliance(Assessment $assessment): ?array
    {
        $bp = $this->current($assessment);
        if (!$bp) {
            return null;
        }
        $cmp = $this->comparison($bp);

        return ['blueprint_id' => $bp->id, 'version' => $bp->version, 'status' => $bp->status, 'validation_status' => $bp->validation_status, 'completeness' => $bp->blueprint_completeness,
            'compliance_percent' => $cmp['compliance_percent'], 'summary' => $cmp['summary'], 'structure' => $cmp['structure'], 'has_questions' => $cmp['actual']['has_questions']];
    }

    public function reportSection(Assessment $assessment): ?array
    {
        $bp = $this->current($assessment);
        if (!$bp) {
            return null;
        }
        $bp->load(['sections', 'constraints', 'items']);
        $cmp = $this->comparison($bp);
        $result = $bp->validation_result ?: $this->validator->validate($bp);

        return ['version' => $bp->version, 'status' => $bp->status, 'validation_status' => $bp->validation_status, 'total_marks' => $bp->total_marks, 'total_questions' => $bp->total_questions, 'duration_minutes' => $bp->duration_minutes,
            'sections' => $bp->sections->map(fn ($s) => ['title' => $s->title, 'question_type' => $s->question_type, 'question_count' => $s->question_count, 'marks_per_question' => $s->marks_per_question, 'total_marks' => $s->total_marks])->values()->all(),
            'distributions' => $result['distributions'], 'comparison' => $cmp, 'warnings' => $result['warnings'], 'completeness' => $result['completeness']['score']];
    }

    // ------------------------------------------------------------- presentation

    public function present(AssessmentBlueprint $bp, bool $withValidation = true): array
    {
        $bp->loadMissing(['sections', 'constraints.learningOutcome', 'constraints.programOutcome', 'items', 'assessment.course']);
        $byDim = $bp->constraints->groupBy('dimension');
        $data = [
            'id' => $bp->id, 'assessment_id' => $bp->assessment_id, 'version' => $bp->version, 'status' => $bp->status, 'is_current' => $bp->is_current, 'title' => $bp->title,
            'total_marks' => (float) $bp->total_marks, 'total_questions' => (int) $bp->total_questions, 'duration_minutes' => $bp->duration_minutes, 'instructions' => $bp->instructions,
            'validation_status' => $bp->validation_status, 'blueprint_completeness' => $bp->blueprint_completeness, 'validated_at' => $bp->validated_at?->toISOString(), 'finalized_at' => $bp->finalized_at?->toISOString(),
            'created_by' => $bp->created_by, 'created_at' => $bp->created_at?->toISOString(), 'updated_at' => $bp->updated_at?->toISOString(),
            'course' => $bp->assessment->course ? ['id' => $bp->assessment->course->id, 'code' => $bp->assessment->course->course_code, 'name' => $bp->assessment->course->course_name, 'program_id' => $bp->assessment->course->program_id] : null,
            'assessment' => ['id' => $bp->assessment->id, 'title' => $bp->assessment->title, 'type' => $bp->assessment->type, 'total_marks' => (float) $bp->assessment->total_marks, 'duration_minutes' => $bp->assessment->duration_minutes, 'status' => $bp->assessment->status],
            'sections' => $bp->sections->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'section_order' => $s->section_order, 'instructions' => $s->instructions, 'question_type' => $s->question_type, 'question_count' => $s->question_count,
                'marks_per_question' => (float) $s->marks_per_question, 'total_marks' => (float) $s->total_marks, 'difficulty_distribution' => $s->difficulty_distribution, 'cognitive_distribution' => $s->cognitive_distribution])->values()->all(),
            'constraints' => [
                'difficulty' => $byDim->get('DIFFICULTY', collect())->map(fn ($c) => ['key' => $c->target_key, 'target_percentage' => $c->target_percentage, 'target_count' => $c->target_count])->values()->all(),
                'cognitive' => $byDim->get('COGNITIVE_LEVEL', collect())->map(fn ($c) => ['key' => $c->target_key, 'target_percentage' => $c->target_percentage, 'target_count' => $c->target_count])->values()->all(),
                'learning_outcomes' => $byDim->get('LEARNING_OUTCOME', collect())->map(fn ($c) => ['learning_outcome_id' => $c->learning_outcome_id, 'code' => $c->learningOutcome?->code, 'target_percentage' => $c->target_percentage, 'target_marks' => $c->target_marks, 'target_count' => $c->target_count])->values()->all(),
                'program_outcomes' => $byDim->get('PROGRAM_OUTCOME', collect())->map(fn ($c) => ['program_outcome_id' => $c->program_outcome_id, 'code' => $c->programOutcome?->code, 'target_percentage' => $c->target_percentage])->values()->all(),
                'topics' => $byDim->get('TOPIC', collect())->map(fn ($c) => ['topic' => $c->target_key, 'target_count' => $c->target_count, 'target_marks' => $c->target_marks])->values()->all(),
                'question_types' => $byDim->get('QUESTION_TYPE', collect())->map(fn ($c) => ['question_type' => $c->target_key, 'target_count' => $c->target_count, 'marks_each' => $c->metadata['marks_each'] ?? null, 'target_marks' => $c->target_marks])->values()->all(),
            ],
            'items' => $bp->items->map(fn ($i) => ['id' => $i->id, 'section_id' => $i->section_id, 'section_order' => $i->section_id ? $bp->sections->firstWhere('id', $i->section_id)?->section_order : null, 'topic' => $i->topic, 'learning_outcome_id' => $i->learning_outcome_id, 'program_outcome_id' => $i->program_outcome_id,
                'question_type' => $i->question_type, 'difficulty_level' => $i->difficulty_level, 'cognitive_level' => $i->cognitive_level, 'question_count' => $i->question_count, 'marks_each' => (float) $i->marks_each, 'total_marks' => (float) $i->total_marks, 'sort_order' => $i->sort_order])->values()->all(),
        ];
        if ($withValidation) {
            $data['validation'] = $bp->validation_result;
        }

        return $data;
    }
}
