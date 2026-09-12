<?php

namespace App\Services\Explainability\Explainers;

use App\Models\Course;
use App\Models\DocumentChunk;
use App\Models\GeneratedQuestion;
use App\Models\User;
use App\Services\Explainability\AbstractExplainer;
use App\Services\Explainability\ExplanationBuilder;
use Illuminate\Database\Eloquent\Model;

/** Generated question: constraints → source context → generated question → validation (STEP 45 §20–21). */
class GeneratedQuestionExplainer extends AbstractExplainer
{
    protected const CONSTRAINT_LABELS = [
        'topic' => 'Topic constraint', 'question_type' => 'Question-type constraint', 'difficulty' => 'Difficulty constraint',
        'cognitive_level' => 'Bloom constraint', 'co_alignment' => 'CO alignment', 'similarity' => 'Similarity restriction', 'marks' => 'Marks constraint',
    ];

    public function type(): string
    {
        return 'generated_question';
    }

    public function find(int $id): ?Model
    {
        return GeneratedQuestion::with(['request.course', 'request.assessment', 'learningOutcome', 'programOutcome'])->find($id);
    }

    public function course(Model $target): ?Course
    {
        return $target->request?->course;
    }

    public function viewAbility(): string
    {
        return 'generate_questions';
    }

    public function reviewAbility(): ?string
    {
        return 'approve_generated_question';
    }

    public function aiValue(Model $target): array
    {
        return ['validation_status' => $target->validation_status, 'review_status' => $target->review_status, 'version' => $target->version];
    }

    public function explain(User $user, Model $target, ExplanationBuilder $b): ExplanationBuilder
    {
        /** @var GeneratedQuestion $g */
        $g = $target;
        $req = $g->request;
        $v = $g->validation ?? [];
        $checks = $v['constraints'] ?? [];
        $generative = $req && $req->generation_method === 'generative';
        $passed = array_filter($checks, fn ($c) => $c === true);
        $failed = array_filter($checks, fn ($c) => $c === false);

        $b->result($g->validation_status, null, $this->humanize($g->validation_status), ['review_status' => $g->review_status, 'version' => $g->version])
            ->summary(sprintf(
                'FacultyLens drafted this question under the requested constraints%s and validated it with the same analyzers used for question analysis: %d constraint check(s) passed%s. Passing constraints does not make the question academically correct — faculty review is required.',
                $generative ? ' using the configured generation model' : ' using the deterministic template engine',
                count($passed),
                $failed ? ', ' . count($failed) . ' failed' : ''
            ))
            ->detail('Requested', array_filter([
                'Topic' => $req?->topic ?? $g->topic, 'LO/CO' => $g->learningOutcome?->code, 'PO' => $g->programOutcome?->code,
                'Question type' => $g->question_type, 'Difficulty' => $g->difficulty_level, 'Bloom level' => $g->cognitive_level, 'Marks' => $g->marks,
                'Language' => $req?->language, 'Document scope' => $req && is_array($req->document_scope) ? count($req->document_scope) . ' document(s)' : 'None',
                'Similarity restriction' => 'Configured similarity thresholds against existing questions',
            ], fn ($x) => $x !== null && $x !== ''));

        $validationRows = [];
        foreach (self::CONSTRAINT_LABELS as $key => $label) {
            if (!array_key_exists($key, $checks)) {
                continue;
            }
            $state = $checks[$key] === null ? 'NOT_CHECKED' : ($checks[$key] ? 'PASS' : 'FAIL');
            $validationRows[] = ['check' => $label, 'status' => $state];
            $b->evidence(['type' => 'constraint', 'label' => $label, 'text' => $state, 'meta' => ['status' => $state]]);
        }
        $b->detail('Validation', $validationRows)
            ->detail('Detected by analyzers', array_filter(['Type' => $v['detected_question_type'] ?? null, 'Difficulty' => $v['detected_difficulty'] ?? null, 'Bloom' => $v['detected_cognitive_level'] ?? null,
                'CO alignment' => isset($v['co_alignment_score']) ? $this->fmtScore((float) $v['co_alignment_score']) . ' (' . ($v['co_alignment_status'] ?? '') . ')' : null,
                'Max similarity' => isset($v['max_similarity_score']) ? $this->fmtScore((float) $v['max_similarity_score']) . ' (' . ($v['similarity_status'] ?? '') . ')' : null]));
        foreach ($v['warnings'] ?? [] as $w) {
            $b->evidence(['type' => 'warning', 'label' => 'Validation warning', 'text' => $this->excerpt($w, 300)]);
        }
        foreach (array_slice($v['similar_questions'] ?? [], 0, 3) as $s) {
            $b->evidence(['type' => 'similar_question', 'label' => 'Similar existing question (' . ($s['status'] ?? '') . ')', 'score' => $s['similarity_score'] ?? null, 'text' => $this->excerpt($s['text'] ?? null), 'source_type' => $s['source'] ?? null, 'source_id' => $s['existing_id'] ?? null]);
        }

        $this->sourceEvidence($user, $g, $b);
        if ($g->explanation) {
            $b->evidence(['type' => 'generation_note', 'label' => 'Draft rationale (validated against metadata)', 'text' => $this->excerpt($g->explanation, 400)]);
        }

        return $b->method($generative ? 'GENERATIVE' : 'RULE_BASED', $generative
                ? 'Configured generation model drafted the question under constraints; every draft was validated by the rule-based analyzers, embedding-based CO alignment and similarity checks.'
                : 'Deterministic constraint-driven template engine drafted the question; validated by the rule-based analyzers, embedding-based CO alignment and similarity checks.',
                ['Constraint slots', 'Retrieved document sentences (if in scope)', 'STEP 10 analyzers', 'CO alignment (embedding)', 'Similarity check (embedding)'])
            ->model(['name' => $req?->generation_model, 'version' => $req?->generation_model_version, 'prompt_version' => $req?->prompt_version, 'embedding_model' => $req?->embedding_model])
            ->confidence(null, 'Question generation does not produce a confidence value.')
            ->limitations($this->limitations('generated_question'))
            ->link('View generation request', 'question_generation', $req?->id, ['course_id' => $req?->course_id])
            ->review(['overridable' => false, 'actions' => ['REVIEWED'], 'note' => 'Approve, edit or reject this draft from the question generator.', 'faculty_value' => $g->review_status]);
    }

    protected function sourceEvidence(User $user, GeneratedQuestion $g, ExplanationBuilder $b): void
    {
        $ids = is_array($g->source_chunk_ids) ? array_filter($g->source_chunk_ids, 'is_numeric') : [];
        if ($ids === []) {
            $b->evidence(['type' => 'source', 'label' => 'Source documents', 'text' => 'Not generated from documents — no source support to verify.']);
            return;
        }
        $chunks = DocumentChunk::with('document')->whereIn('id', $ids)->get();
        $shown = 0;
        foreach ($chunks as $chunk) {
            $doc = $chunk->document;
            if (!$doc || !$user->can('view', $doc)) {
                continue;
            }
            $shown++;
            $b->evidence(['type' => 'document_chunk', 'label' => 'Source: ' . ($doc->original_file_name ?? 'Document'), 'text' => $this->location($chunk->page_number, $chunk->section_title),
                'source_type' => 'document', 'source_id' => $doc->id, 'document_id' => $doc->id, 'document_page' => $chunk->page_number, 'chunk_id' => $chunk->id]);
        }
        if ($shown === 0) {
            $b->evidence(['type' => 'source', 'label' => 'Source documents', 'text' => 'Source support not verified — the source documents are not available in your authorization scope.']);
        }
    }

    protected function location(?int $page, ?string $section): string
    {
        $parts = array_filter([$page ? "Page {$page}" : null, $section ? "Section: {$section}" : null]);
        return $parts ? implode(' · ', $parts) : 'Source location unavailable';
    }
}
