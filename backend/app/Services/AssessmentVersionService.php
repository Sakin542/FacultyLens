<?php

namespace App\Services;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\AssessmentBlueprint;
use App\Models\AssessmentVersion;
use App\Models\AssessmentVersionBlueprint;
use App\Models\AssessmentVersionQuestion;
use App\Models\LearningOutcome;
use App\Models\ProgramOutcome;
use App\Models\Question;
use App\Models\Rubric;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * STEP 38: Assessment version lifecycle — create / clone / restore / edit draft / review / approve / finalize / archive.
 * Versions are historical snapshots: finalizing never rewrites the live assessment, and a finalized
 * (or student-referenced) version is never mutated. Everything here is deterministic (no AI).
 */
class AssessmentVersionService
{
    public function __construct(
        protected AuditLogService $audit,
        protected AssessmentVersionValidationService $validation,
    ) {}

    // ------------------------------------------------------------- lookup

    /** The version current dashboards/reports should use: latest FINALIZED, else latest non-archived. */
    public function currentVersion(Assessment $assessment): ?AssessmentVersion
    {
        return AssessmentVersion::where('assessment_id', $assessment->id)->where('status', AssessmentVersion::STATUS_FINALIZED)->orderByDesc('version_number')->first()
            ?? $this->workingVersion($assessment);
    }

    /** The version faculty are currently working on (latest non-archived). */
    public function workingVersion(Assessment $assessment): ?AssessmentVersion
    {
        return AssessmentVersion::where('assessment_id', $assessment->id)->where('status', '!=', AssessmentVersion::STATUS_ARCHIVED)->orderByDesc('version_number')->first();
    }

    /** Version whose snapshot equals the live question set (used to attach analyses to the right version). */
    public function versionMatchingLiveQuestions(Assessment $assessment): ?AssessmentVersion
    {
        $hash = $this->liveHash($assessment);

        return AssessmentVersion::where('assessment_id', $assessment->id)->where('content_hash', $hash)->orderByDesc('version_number')->first();
    }

    public function history(Assessment $assessment): Collection
    {
        return AssessmentVersion::where('assessment_id', $assessment->id)->with(['creator:id,name', 'basedOn:id,version_number,version_label'])->orderByDesc('version_number')->get();
    }

    // ------------------------------------------------------------- create / clone / restore

    /**
     * Create the next version. Without based_on_version_id the first version snapshots the live assessment;
     * later versions clone the latest (or the requested) version. Version numbers are always server-generated.
     */
    public function createVersion(User $user, Assessment $assessment, array $data): AssessmentVersion
    {
        $assessment->loadMissing('course');
        $source = null;
        if (!empty($data['based_on_version_id'])) {
            $source = AssessmentVersion::where('assessment_id', $assessment->id)->find((int) $data['based_on_version_id']);
            if (!$source) {
                throw new HttpException(422, 'The source version does not belong to this assessment.');
            }
        } else {
            $source = AssessmentVersion::where('assessment_id', $assessment->id)->orderByDesc('version_number')->first();
        }
        $type = strtoupper($data['version_type'] ?? AssessmentVersion::TYPE_MAJOR);
        if (!in_array($type, AssessmentVersion::TYPES, true)) {
            throw new HttpException(422, 'version_type must be MAJOR or MINOR.');
        }

        $version = DB::transaction(function () use ($user, $assessment, $data, $source, $type) {
            $number = ((int) AssessmentVersion::where('assessment_id', $assessment->id)->lockForUpdate()->max('version_number')) + 1;
            $label = $this->nextLabel($assessment, $source, $type);
            $attrs = [
                'assessment_id' => $assessment->id, 'version_number' => $number, 'version_label' => $label, 'version_type' => $type, 'status' => AssessmentVersion::STATUS_DRAFT,
                'created_by' => $user->id, 'based_on_version_id' => $source?->id, 'change_summary' => isset($data['change_summary']) ? trim((string) $data['change_summary']) : null,
            ];
            $version = $source
                ? $this->cloneVersion($source, $attrs)
                : $this->snapshotFromAssessment($assessment, $attrs);
            if (isset($data['title'])) {
                $version->update(['title' => $data['title']]);
            }
            $this->refreshDerived($version);
            $this->audit->log($data['_audit_action'] ?? 'ASSESSMENT_VERSION_CREATED', $version, $version->id, [
                'assessment_id' => $assessment->id, 'course_id' => $assessment->course_id, 'assessment_version_id' => $version->id, 'version_number' => $number, 'version_label' => $label,
                'version_type' => $type, 'based_on_version_id' => $source?->id, 'question_count' => $version->question_count,
            ], $user);

            return $version;
        });

        return $version->fresh(['questions', 'blueprint', 'creator', 'basedOn']);
    }

    /** Copy metadata, question snapshots (keeping original_question_id) and the blueprint snapshot of a source version. */
    public function cloneVersion(AssessmentVersion $source, array $attrs): AssessmentVersion
    {
        $source->loadMissing(['questions', 'blueprint']);
        $version = AssessmentVersion::create($attrs + [
            'title' => $source->title, 'description' => $source->description, 'instructions' => $source->instructions, 'assessment_type' => $source->assessment_type,
            'total_marks' => $source->total_marks, 'duration_minutes' => $source->duration_minutes, 'question_count' => $source->question_count,
        ]);
        foreach ($source->questions as $q) {
            AssessmentVersionQuestion::create([
                'assessment_version_id' => $version->id, 'original_question_id' => $q->original_question_id, 'question_number' => $q->question_number, 'section_name' => $q->section_name,
                'question_text' => $q->question_text, 'question_type' => $q->question_type, 'marks' => $q->marks, 'difficulty_level' => $q->difficulty_level, 'cognitive_level' => $q->cognitive_level,
                'topic' => $q->topic, 'learning_outcome_id' => $q->learning_outcome_id, 'program_outcome_id' => $q->program_outcome_id, 'expected_answer' => $q->expected_answer,
                'rubric_snapshot' => $q->rubric_snapshot, 'sort_order' => $q->sort_order,
            ]);
        }
        if ($source->blueprint) {
            $bp = $source->blueprint->replicate();
            $bp->assessment_version_id = $version->id;
            $bp->save();
        }

        return $version;
    }

    /** Restore never overwrites: the restored state becomes a brand-new draft version based on the source. */
    public function restoreVersionAsNew(User $user, AssessmentVersion $source, ?string $summary = null, string $type = AssessmentVersion::TYPE_MAJOR): AssessmentVersion
    {
        $source->loadMissing('assessment');
        $version = $this->createVersion($user, $source->assessment, [
            'based_on_version_id' => $source->id, 'version_type' => $type,
            'change_summary' => $summary !== null && trim($summary) !== '' ? trim($summary) : "Restored structure from {$source->version_label}",
            '_audit_action' => 'ASSESSMENT_VERSION_RESTORED',
        ]);

        return $version;
    }

    // ------------------------------------------------------------- draft editing

    /**
     * Edit a DRAFT/IN_REVIEW version (edits move it back to DRAFT). Questions are replaced wholesale when provided.
     * Options: sync_from_assessment (re-snapshot live questions), blueprint_id (re-snapshot a STEP 37 blueprint version).
     */
    public function updateDraftVersion(User $user, AssessmentVersion $version, array $data): AssessmentVersion
    {
        $this->assertEditable($version);
        $version->loadMissing('assessment.course');

        $version = DB::transaction(function () use ($user, $version, $data) {
            $meta = array_intersect_key($data, array_flip(['title', 'description', 'instructions', 'assessment_type', 'total_marks', 'duration_minutes', 'change_summary']));
            if (isset($meta['assessment_type'])) {
                $meta['assessment_type'] = strtolower($meta['assessment_type']);
            }
            $version->update($meta + ['status' => AssessmentVersion::STATUS_DRAFT, 'validation_status' => null, 'validation_result' => null, 'validated_at' => null, 'submitted_at' => null]);

            if (!empty($data['sync_from_assessment'])) {
                $version->questions()->delete();
                $this->snapshotQuestions($version, $version->assessment);
            } elseif (array_key_exists('questions', $data) && is_array($data['questions'])) {
                $this->replaceQuestions($version, $data['questions']);
            }
            if (array_key_exists('blueprint_id', $data)) {
                $bp = $data['blueprint_id'] === null ? null : AssessmentBlueprint::where('assessment_id', $version->assessment_id)->find((int) $data['blueprint_id']);
                if ($data['blueprint_id'] !== null && !$bp) {
                    throw new HttpException(422, 'The blueprint does not belong to this assessment.');
                }
                $version->blueprint()->delete();
                if ($bp) {
                    $this->snapshotBlueprint($version, $bp);
                }
            }
            $this->refreshDerived($version, !array_key_exists('total_marks', $meta) && (isset($data['questions']) || !empty($data['sync_from_assessment'])));
            $this->audit->log('ASSESSMENT_VERSION_UPDATED', $version, $version->id, [
                'assessment_id' => $version->assessment_id, 'course_id' => $version->assessment->course_id, 'assessment_version_id' => $version->id, 'version_label' => $version->version_label,
                'fields' => array_keys($meta), 'questions_replaced' => isset($data['questions']) || !empty($data['sync_from_assessment']), 'question_count' => $version->question_count,
            ], $user);

            return $version;
        });

        return $version->fresh(['questions', 'blueprint', 'creator', 'basedOn']);
    }

    protected function replaceQuestions(AssessmentVersion $version, array $rows): void
    {
        $course = $version->assessment->course;
        $loIds = LearningOutcome::where('course_id', $course->id)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $poIds = $course->program_id ? ProgramOutcome::where('program_id', $course->program_id)->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
        $liveIds = Question::where('assessment_id', $version->assessment_id)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $existingRubrics = $version->questions()->whereNotNull('original_question_id')->pluck('rubric_snapshot', 'original_question_id');

        $version->questions()->delete();
        foreach (array_values($rows) as $i => $r) {
            $lo = isset($r['learning_outcome_id']) && $r['learning_outcome_id'] !== null && $r['learning_outcome_id'] !== '' ? (int) $r['learning_outcome_id'] : null;
            $po = isset($r['program_outcome_id']) && $r['program_outcome_id'] !== null && $r['program_outcome_id'] !== '' ? (int) $r['program_outcome_id'] : null;
            $orig = isset($r['original_question_id']) && $r['original_question_id'] !== null && $r['original_question_id'] !== '' ? (int) $r['original_question_id'] : null;
            if ($lo !== null && !in_array($lo, $loIds, true)) {
                throw new HttpException(422, 'Question ' . ($i + 1) . ' references a learning outcome of another course.');
            }
            if ($po !== null && !in_array($po, $poIds, true)) {
                throw new HttpException(422, 'Question ' . ($i + 1) . " references a program outcome outside this course's program.");
            }
            if ($orig !== null && !in_array($orig, $liveIds, true)) {
                throw new HttpException(422, 'Question ' . ($i + 1) . ' references a question of another assessment.');
            }
            $rubric = $orig !== null ? ($existingRubrics->has($orig) ? $existingRubrics->get($orig) : $this->rubricSnapshot(Question::find($orig))) : null;
            AssessmentVersionQuestion::create([
                'assessment_version_id' => $version->id, 'original_question_id' => $orig, 'question_number' => (int) ($r['question_number'] ?? ($i + 1)), 'section_name' => isset($r['section_name']) ? trim((string) $r['section_name']) ?: null : null,
                'question_text' => trim((string) $r['question_text']), 'question_type' => strtolower((string) ($r['question_type'] ?? 'descriptive')), 'marks' => round((float) ($r['marks'] ?? 0), 2),
                'difficulty_level' => isset($r['difficulty_level']) && $r['difficulty_level'] !== null ? strtolower((string) $r['difficulty_level']) : null, 'cognitive_level' => $r['cognitive_level'] ?? null,
                'topic' => isset($r['topic']) ? trim((string) $r['topic']) ?: null : null, 'learning_outcome_id' => $lo, 'program_outcome_id' => $po,
                'expected_answer' => isset($r['expected_answer']) ? trim((string) $r['expected_answer']) ?: null : null, 'rubric_snapshot' => $rubric, 'sort_order' => $i + 1,
            ]);
        }
    }

    // ------------------------------------------------------------- workflow

    public function submitForReview(User $user, AssessmentVersion $version): AssessmentVersion
    {
        if ($version->status !== AssessmentVersion::STATUS_DRAFT) {
            throw new HttpException(409, "Only DRAFT versions can be submitted for review (current status: {$version->status}).");
        }
        $result = $this->validation->validateVersion($version);
        $version->update(['status' => AssessmentVersion::STATUS_IN_REVIEW, 'submitted_at' => now()] + $this->validationColumns($result));
        $this->logStatus('ASSESSMENT_VERSION_SUBMITTED', $version, $user, ['validation_status' => $result['status']]);

        return $version->fresh();
    }

    public function approveVersion(User $user, AssessmentVersion $version): AssessmentVersion
    {
        if (!in_array($version->status, [AssessmentVersion::STATUS_DRAFT, AssessmentVersion::STATUS_IN_REVIEW], true)) {
            throw new HttpException(409, "Only DRAFT or IN_REVIEW versions can be approved (current status: {$version->status}).");
        }
        $result = $this->validation->validateVersion($version);
        if ($result['status'] === AssessmentVersionValidationService::INVALID) {
            $version->update($this->validationColumns($result));
            throw new HttpException(422, 'Assessment version cannot be approved. ' . count($result['errors']) . ' critical validation error(s) remain.');
        }
        $version->update(['status' => AssessmentVersion::STATUS_APPROVED, 'approved_at' => now(), 'approved_by' => $user->id] + $this->validationColumns($result));
        $this->logStatus('ASSESSMENT_VERSION_APPROVED', $version, $user, ['validation_status' => $result['status']]);

        return $version->fresh();
    }

    /** Finalize = validate strictly, lock the snapshot, archive the previously finalized version. Never touches live questions. */
    public function finalizeVersion(User $user, AssessmentVersion $version): AssessmentVersion
    {
        if ($version->isFinalized()) {
            throw new HttpException(409, 'This version is already finalized.');
        }
        if ($version->isArchived()) {
            throw new HttpException(409, 'Archived versions are read-only. Restore it as a new version instead.');
        }
        $result = $this->validation->validateBeforeFinalization($version);
        if ($result['status'] === AssessmentVersionValidationService::INVALID) {
            $version->update($this->validationColumns($result));
            throw new HttpException(422, 'Assessment cannot be finalized. ' . count($result['errors']) . ' critical validation error(s) remain.');
        }

        DB::transaction(function () use ($user, $version, $result) {
            $superseded = AssessmentVersion::where('assessment_id', $version->assessment_id)->where('id', '!=', $version->id)->where('status', AssessmentVersion::STATUS_FINALIZED)->get();
            foreach ($superseded as $old) {
                $old->update(['status' => AssessmentVersion::STATUS_ARCHIVED, 'archived_at' => now()]);
                $this->logStatus('ASSESSMENT_VERSION_ARCHIVED', $old, $user, ['superseded_by' => $version->id, 'automatic' => true]);
            }
            $version->update(['status' => AssessmentVersion::STATUS_FINALIZED, 'finalized_at' => now(), 'finalized_by' => $user->id, 'approved_at' => $version->approved_at ?? now(), 'approved_by' => $version->approved_by ?? $user->id] + $this->validationColumns($result));
        });
        $this->logStatus('ASSESSMENT_VERSION_FINALIZED', $version, $user, ['validation_status' => $result['status'], 'warnings' => count($result['warnings'])]);

        return $version->fresh();
    }

    public function archiveVersion(User $user, AssessmentVersion $version): AssessmentVersion
    {
        if ($version->isArchived()) {
            throw new HttpException(409, 'This version is already archived.');
        }
        $version->update(['status' => AssessmentVersion::STATUS_ARCHIVED, 'archived_at' => now()]);
        $this->logStatus('ASSESSMENT_VERSION_ARCHIVED', $version, $user);

        return $version->fresh();
    }

    public function assertEditable(AssessmentVersion $version): void
    {
        if (!$version->isEditableStatus()) {
            throw new HttpException(409, "A {$version->status} version is immutable. Create a new version to make changes.");
        }
        if ($version->hasSubmissions()) {
            throw new HttpException(409, 'Student submissions already reference this version; it can no longer be modified. Create a new version instead.');
        }
    }

    // ------------------------------------------------------------- snapshots

    /** First version: snapshot the live assessment (metadata, questions, current blueprint, approved rubrics). */
    protected function snapshotFromAssessment(Assessment $assessment, array $attrs): AssessmentVersion
    {
        $version = AssessmentVersion::create($attrs + [
            'title' => $assessment->title, 'description' => $assessment->description, 'instructions' => null, 'assessment_type' => $assessment->type ?: 'other',
            'total_marks' => (float) $assessment->total_marks, 'duration_minutes' => $assessment->duration_minutes, 'question_count' => 0,
        ]);
        $this->snapshotQuestions($version, $assessment);
        $current = AssessmentBlueprint::where('assessment_id', $assessment->id)->where('is_current', true)->orderByDesc('version')->first();
        if ($current) {
            $this->snapshotBlueprint($version, $current);
        }

        return $version;
    }

    public function snapshotQuestions(AssessmentVersion $version, Assessment $assessment): void
    {
        $assessment->loadMissing('course');
        $questions = Question::where('assessment_id', $assessment->id)->orderBy('question_number')->orderBy('id')->get();
        $loToPo = $assessment->course?->program_id
            ? DB::table('co_po_mappings')->where('course_id', $assessment->course_id)->where('mapping_level', '>', 0)->orderByDesc('mapping_level')->get(['learning_outcome_id', 'program_outcome_id'])->groupBy('learning_outcome_id')
            : collect();
        foreach ($questions->values() as $i => $q) {
            $topics = (array) ($q->ai_topics ?? []);
            AssessmentVersionQuestion::create([
                'assessment_version_id' => $version->id, 'original_question_id' => $q->id, 'question_number' => (int) ($q->question_number ?: $i + 1), 'section_name' => null,
                'question_text' => (string) $q->question_text, 'question_type' => strtolower((string) ($q->question_type ?: 'descriptive')), 'marks' => round((float) $q->marks, 2),
                'difficulty_level' => strtolower((string) ($q->difficulty_level ?: $q->ai_difficulty_level)) ?: null, 'cognitive_level' => ($q->cognitive_level ?: $q->ai_cognitive_level) ?: null,
                'topic' => isset($topics[0]) ? trim((string) $topics[0]) : null, 'learning_outcome_id' => $q->learning_outcome_id,
                'program_outcome_id' => $q->learning_outcome_id ? ($loToPo->get($q->learning_outcome_id)?->first()?->program_outcome_id) : null,
                'expected_answer' => $q->expected_answer, 'rubric_snapshot' => $this->rubricSnapshot($q), 'sort_order' => $i + 1,
            ]);
        }
    }

    /** STEP 25: reference + critical criteria of the approved rubric so later rubric edits never change the historical version. */
    public function rubricSnapshot(?Question $question): ?array
    {
        if (!$question) {
            return null;
        }
        $rubric = Rubric::where('question_id', $question->id)->where('status', Rubric::STATUS_APPROVED)->orderByDesc('version')->with('criteria')->first()
            ?? Rubric::where('question_id', $question->id)->orderByDesc('version')->with('criteria')->first();
        if (!$rubric) {
            return null;
        }

        return [
            'rubric_id' => $rubric->id, 'rubric_version' => $rubric->version, 'status' => $rubric->status, 'total_marks' => (float) $rubric->total_marks,
            'criteria' => $rubric->criteria->map(fn ($c) => ['criterion' => $c->criterion, 'max_marks' => (float) $c->max_marks, 'description' => $c->description])->values()->all(),
        ];
    }

    /** STEP 37: normalized blueprint snapshot (percent distributions, sections, constraints) of the given blueprint version. */
    public function snapshotBlueprint(AssessmentVersion $version, AssessmentBlueprint $bp): AssessmentVersionBlueprint
    {
        $bp->loadMissing(['sections', 'constraints']);
        $result = $bp->validation_result ?: app(AssessmentBlueprintValidator::class)->validate($bp);
        $dist = $result['distributions'] ?? [];
        $map = function (array $d, string $pctKey = 'target_percentage'): array {
            $out = [];
            foreach ($d['rows'] ?? [] as $r) {
                if (!($r['configured'] ?? false) || ($r[$pctKey] ?? null) === null) {
                    continue;
                }
                $out[(string) $r['key']] = ['label' => (string) ($r['label'] ?? $r['key']), 'percentage' => round((float) $r[$pctKey], 2)] + (isset($r['learning_outcome_id']) ? ['learning_outcome_id' => $r['learning_outcome_id']] : []) + (isset($r['program_outcome_id']) ? ['program_outcome_id' => $r['program_outcome_id']] : []);
            }

            return $out;
        };

        return AssessmentVersionBlueprint::create([
            'assessment_version_id' => $version->id, 'blueprint_id' => $bp->id, 'blueprint_version' => $bp->version, 'blueprint_status' => $bp->status, 'validation_status' => $bp->validation_status ?? ($result['status'] ?? null),
            'total_marks' => (float) $bp->total_marks, 'question_count' => (int) $bp->total_questions, 'duration_minutes' => $bp->duration_minutes,
            'difficulty_distribution' => $map($dist['difficulty'] ?? []), 'cognitive_distribution' => $map($dist['cognitive'] ?? []), 'learning_outcome_distribution' => $map($dist['learning_outcomes'] ?? []),
            'program_outcome_distribution' => $map($dist['program_outcomes'] ?? []), 'topic_distribution' => $map($dist['topics'] ?? []), 'question_type_distribution' => $map($dist['question_types'] ?? []),
            'sections' => $bp->sections->map(fn ($s) => ['title' => $s->title, 'question_type' => $s->question_type, 'question_count' => $s->question_count, 'marks_per_question' => (float) $s->marks_per_question, 'total_marks' => (float) $s->total_marks])->values()->all(),
            'constraints' => $bp->constraints->map(fn ($c) => ['dimension' => $c->dimension, 'key' => $c->target_key, 'target_type' => $c->target_type, 'target_percentage' => $c->target_percentage, 'target_count' => $c->target_count, 'target_marks' => $c->target_marks])->values()->all(),
        ]);
    }

    /** Recompute question_count, total marks (optionally) and the content hash. */
    protected function refreshDerived(AssessmentVersion $version, bool $sumMarks = false): void
    {
        $questions = $version->questions()->get();
        $updates = ['question_count' => $questions->count(), 'content_hash' => $this->hashRows($questions->map(fn ($q) => $this->hashRow($q->question_number, $q->question_text, $q->question_type, $q->marks, $q->difficulty_level, $q->cognitive_level, $q->learning_outcome_id, $q->expected_answer))->all())];
        if ($sumMarks || (float) $version->total_marks <= 0) {
            $updates['total_marks'] = round((float) $questions->sum('marks'), 2);
        }
        $version->update($updates);
    }

    /** Hash of the live question set, computed exactly like a version snapshot so equality means "same content". */
    public function liveHash(Assessment $assessment): string
    {
        $rows = Question::where('assessment_id', $assessment->id)->orderBy('question_number')->orderBy('id')->get()->values()
            ->map(fn ($q, $i) => $this->hashRow((int) ($q->question_number ?: $i + 1), (string) $q->question_text, strtolower((string) ($q->question_type ?: 'descriptive')), (float) $q->marks,
                strtolower((string) ($q->difficulty_level ?: $q->ai_difficulty_level)) ?: null, ($q->cognitive_level ?: $q->ai_cognitive_level) ?: null, $q->learning_outcome_id, $q->expected_answer))->all();

        return $this->hashRows($rows);
    }

    protected function hashRow(int $number, string $text, string $type, float $marks, ?string $difficulty, ?string $cognitive, $loId, ?string $expected): string
    {
        return implode('|', [$number, trim(preg_replace('/\s+/', ' ', $text)), $type, number_format($marks, 2, '.', ''), $difficulty ?: '', $cognitive ?: '', $loId !== null ? (int) $loId : '', trim((string) $expected)]);
    }

    protected function hashRows(array $rows): string
    {
        sort($rows);

        return hash('sha256', implode("\n", $rows));
    }

    /** Deterministic label: MAJOR → next major (vN.0); MINOR → next minor within the source's major (vM.n). Labels are never reused. */
    protected function nextLabel(Assessment $assessment, ?AssessmentVersion $source, string $type): string
    {
        $labels = AssessmentVersion::where('assessment_id', $assessment->id)->pluck('version_label')->map(fn ($l) => $this->parseLabel($l));
        if ($labels->isEmpty()) {
            return 'v1.0';
        }
        if ($type === AssessmentVersion::TYPE_MINOR && $source) {
            [$major] = $this->parseLabel($source->version_label);
            $minor = $labels->filter(fn ($p) => $p[0] === $major)->max(fn ($p) => $p[1]);

            return "v{$major}." . ($minor + 1);
        }

        return 'v' . ($labels->max(fn ($p) => $p[0]) + 1) . '.0';
    }

    protected function parseLabel(?string $label): array
    {
        return preg_match('/^v?(\d+)(?:\.(\d+))?$/', (string) $label, $m) ? [(int) $m[1], (int) ($m[2] ?? 0)] : [0, 0];
    }

    protected function validationColumns(array $result): array
    {
        return ['validation_status' => $result['status'], 'validation_result' => $result, 'validated_at' => now()];
    }

    protected function logStatus(string $action, AssessmentVersion $version, User $user, array $extra = []): void
    {
        $version->loadMissing('assessment');
        $this->audit->log($action, $version, $version->id, ['assessment_id' => $version->assessment_id, 'course_id' => $version->assessment?->course_id, 'assessment_version_id' => $version->id,
            'version_label' => $version->version_label, 'status' => $version->status] + $extra, $user);
    }

    // ------------------------------------------------------------- integrations

    /** STEP 19: attach a freshly created analysis to the version whose snapshot matches the analyzed question set (else the working version). */
    public function linkAnalysisReport(AnalysisReport $report): void
    {
        $assessment = $report->assessment ?? Assessment::find($report->assessment_id);
        if (!$assessment) {
            return;
        }
        $hash = $this->liveHash($assessment);
        $version = AssessmentVersion::where('assessment_id', $assessment->id)->where('content_hash', $hash)->orderByDesc('version_number')->first() ?? $this->workingVersion($assessment);
        $report->forceFill(['assessment_version_id' => $version?->id, 'version_content_hash' => $hash])->saveQuietly();
    }

    /** Analyses recorded for a version; STALE when the analyzed content no longer matches the version snapshot. */
    public function analysisFor(AssessmentVersion $version): array
    {
        $reports = AnalysisReport::where('assessment_version_id', $version->id)->where('analysis_status', 'completed')->withCount('recommendations')->orderByDesc('analysis_version')->get();
        $items = $reports->map(fn ($r) => $this->presentAnalysis($r, $version))->values()->all();
        $latest = $items[0] ?? null;

        return ['assessment_version_id' => $version->id, 'version_label' => $version->version_label, 'status' => $latest ? $latest['status'] : 'NONE', 'latest' => $latest, 'reports' => $items,
            'note' => 'Analyses use the STEP 13 quality metrics. STALE means the version content changed after the analysis ran; finalized versions keep the analysis of their exact snapshot.'];
    }

    public function presentAnalysis(AnalysisReport $r, AssessmentVersion $version): array
    {
        return [
            'id' => $r->id, 'analysis_version' => $r->analysis_version, 'is_current' => (bool) $r->is_current, 'status' => $r->version_content_hash && $r->version_content_hash === $version->content_hash ? 'CURRENT' : 'STALE',
            'overall_score' => (float) $r->overall_score, 'topic_coverage_score' => (float) $r->topic_coverage_score, 'learning_outcome_alignment_score' => (float) $r->learning_outcome_alignment_score,
            'difficulty_balance_score' => (float) $r->difficulty_balance_score, 'cognitive_level_balance_score' => (float) $r->cognitive_level_balance_score, 'similarity_score' => (float) $r->similarity_score,
            'total_questions' => (int) $r->total_questions, 'similar_questions_count' => (int) $r->similar_questions_count, 'recommendations_count' => (int) ($r->recommendations_count ?? $r->recommendations()->count()),
            'analyzed_at' => $r->analyzed_at?->toISOString(),
        ];
    }

    /** STEP 18 / STEP 36: compact identity of the current version for reports and analytics (null when unversioned). */
    public function reportSection(Assessment $assessment): ?array
    {
        $v = $this->currentVersion($assessment);
        if (!$v) {
            return null;
        }
        $v->loadMissing('blueprint');
        $analysis = AnalysisReport::where('assessment_version_id', $v->id)->where('analysis_status', 'completed')->orderByDesc('analysis_version')->first();

        return ['id' => $v->id, 'version_number' => $v->version_number, 'version_label' => $v->version_label, 'status' => $v->status, 'version_type' => $v->version_type,
            'question_count' => $v->question_count, 'total_marks' => (float) $v->total_marks, 'blueprint_version' => $v->blueprint?->blueprint_version, 'analysis_version' => $analysis?->analysis_version,
            'analysis_status' => $analysis ? ($analysis->version_content_hash === $v->content_hash ? 'CURRENT' : 'STALE') : 'NONE',
            'created_at' => $v->created_at?->toISOString(), 'finalized_at' => $v->finalized_at?->toISOString(), 'total_versions' => AssessmentVersion::where('assessment_id', $assessment->id)->count()];
    }

    // ------------------------------------------------------------- presentation

    public function summary(AssessmentVersion $v): array
    {
        return [
            'id' => $v->id, 'assessment_id' => $v->assessment_id, 'version_number' => $v->version_number, 'version_label' => $v->version_label, 'version_type' => $v->version_type, 'status' => $v->status,
            'title' => $v->title, 'assessment_type' => $v->assessment_type, 'total_marks' => (float) $v->total_marks, 'duration_minutes' => $v->duration_minutes, 'question_count' => (int) $v->question_count,
            'change_summary' => $v->change_summary, 'based_on_version_id' => $v->based_on_version_id, 'based_on_version' => $v->relationLoaded('basedOn') && $v->basedOn ? ['id' => $v->basedOn->id, 'version_number' => $v->basedOn->version_number, 'version_label' => $v->basedOn->version_label] : ($v->based_on_version_id ? ['id' => $v->based_on_version_id] : null),
            'created_by' => $v->relationLoaded('creator') && $v->creator ? ['id' => $v->creator->id, 'name' => $v->creator->name] : ['id' => $v->created_by],
            'validation_status' => $v->validation_status, 'has_submissions' => $v->hasSubmissions(), 'is_editable' => $v->isEditableStatus() && !$v->hasSubmissions(),
            'created_at' => $v->created_at?->toISOString(), 'updated_at' => $v->updated_at?->toISOString(), 'submitted_at' => $v->submitted_at?->toISOString(), 'approved_at' => $v->approved_at?->toISOString(),
            'finalized_at' => $v->finalized_at?->toISOString(), 'archived_at' => $v->archived_at?->toISOString(),
        ];
    }

    public function present(AssessmentVersion $v): array
    {
        $v->loadMissing(['questions.learningOutcome:id,code', 'questions.programOutcome:id,code', 'blueprint', 'creator:id,name', 'basedOn:id,version_number,version_label', 'assessment.course:id,course_code,course_name,program_id']);

        return $this->summary($v) + [
            'description' => $v->description, 'instructions' => $v->instructions, 'content_hash' => $v->content_hash, 'validation' => $v->validation_result,
            'assessment' => ['id' => $v->assessment->id, 'title' => $v->assessment->title, 'type' => $v->assessment->type, 'status' => $v->assessment->status],
            'course' => $v->assessment->course ? ['id' => $v->assessment->course->id, 'code' => $v->assessment->course->course_code, 'name' => $v->assessment->course->course_name, 'program_id' => $v->assessment->course->program_id] : null,
            'questions' => $v->questions->map(fn ($q) => $this->presentQuestion($q))->values()->all(),
            'blueprint' => $v->blueprint ? $this->presentBlueprint($v->blueprint) : null,
        ];
    }

    public function presentQuestion(AssessmentVersionQuestion $q): array
    {
        return [
            'id' => $q->id, 'original_question_id' => $q->original_question_id, 'question_number' => $q->question_number, 'section_name' => $q->section_name, 'question_text' => $q->question_text,
            'question_type' => $q->question_type, 'marks' => (float) $q->marks, 'difficulty_level' => $q->difficulty_level, 'cognitive_level' => $q->cognitive_level, 'topic' => $q->topic,
            'learning_outcome_id' => $q->learning_outcome_id, 'learning_outcome_code' => $q->relationLoaded('learningOutcome') ? $q->learningOutcome?->code : null,
            'program_outcome_id' => $q->program_outcome_id, 'program_outcome_code' => $q->relationLoaded('programOutcome') ? $q->programOutcome?->code : null,
            'expected_answer' => $q->expected_answer, 'rubric_snapshot' => $q->rubric_snapshot, 'sort_order' => $q->sort_order,
        ];
    }

    public function presentBlueprint(AssessmentVersionBlueprint $b): array
    {
        return [
            'id' => $b->id, 'blueprint_id' => $b->blueprint_id, 'blueprint_version' => $b->blueprint_version, 'blueprint_status' => $b->blueprint_status, 'validation_status' => $b->validation_status,
            'total_marks' => (float) $b->total_marks, 'question_count' => (int) $b->question_count, 'duration_minutes' => $b->duration_minutes,
            'difficulty_distribution' => $b->difficulty_distribution ?? [], 'cognitive_distribution' => $b->cognitive_distribution ?? [], 'learning_outcome_distribution' => $b->learning_outcome_distribution ?? [],
            'program_outcome_distribution' => $b->program_outcome_distribution ?? [], 'topic_distribution' => $b->topic_distribution ?? [], 'question_type_distribution' => $b->question_type_distribution ?? [],
            'sections' => $b->sections ?? [], 'constraints' => $b->constraints ?? [],
        ];
    }
}
