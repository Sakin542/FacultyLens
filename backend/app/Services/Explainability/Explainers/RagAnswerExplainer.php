<?php

namespace App\Services\Explainability\Explainers;

use App\Models\AcademicChatMessage;
use App\Models\Course;
use App\Models\User;
use App\Services\Explainability\AbstractExplainer;
use App\Services\Explainability\ExplanationBuilder;
use Illuminate\Database\Eloquent\Model;

/** RAG answer: answer → sources → retrieved evidence → citation → retrieval method → model → limitations (STEP 45 §22–24). */
class RagAnswerExplainer extends AbstractExplainer
{
    public function type(): string
    {
        return 'rag_answer';
    }

    public function find(int $id): ?Model
    {
        return AcademicChatMessage::with(['session.course', 'sources'])->where('role', AcademicChatMessage::ROLE_ASSISTANT)->find($id);
    }

    public function course(Model $target): ?Course
    {
        return $target->session?->course;
    }

    /** Chat sessions are private to the user who created them. */
    public function canView(User $user, Model $target): bool
    {
        $session = $target->session;
        return $session !== null && ($user->isAdmin() || (int) $session->user_id === (int) $user->id);
    }

    public function canReview(User $user, Model $target): bool
    {
        return $this->canView($user, $target);
    }

    public function aiValue(Model $target): array
    {
        return ['grounded' => (bool) $target->grounded, 'generation_method' => $target->generation_method, 'source_count' => $target->sources->count()];
    }

    public function explain(User $user, Model $target, ExplanationBuilder $b): ExplanationBuilder
    {
        /** @var AcademicChatMessage $m */
        $m = $target;
        $meta = $m->retrieval_metadata ?? [];
        $sources = $m->sources;
        $method = (string) $m->generation_method;
        $state = match (true) {
            $method === 'insufficient_evidence' || (!$m->grounded && $sources->isEmpty()) => 'INSUFFICIENT_EVIDENCE',
            $m->grounded && $sources->count() >= 2 => 'WELL_SUPPORTED',
            $m->grounded => 'PARTIALLY_SUPPORTED',
            default => 'INSUFFICIENT_EVIDENCE',
        };
        $summary = match ($state) {
            'WELL_SUPPORTED' => sprintf('This answer was produced from %d passage(s) retrieved from your authorized course documents; every cited passage met the configured relevance threshold.', $sources->count()),
            'PARTIALLY_SUPPORTED' => 'This answer is grounded in a single retrieved passage; treat it as partially supported and verify against the source.',
            default => 'FacultyLens could not find sufficient supporting evidence in the selected documents, so no confident answer was generated.',
        };

        $b->result($state, null, $this->humanize($state), ['grounded' => (bool) $m->grounded, 'generation_method' => $method])
            ->summary($summary)
            ->detail('Retrieval', sprintf('Top-%s passages by embedding similarity; minimum relevance %s; %s retrieved, %d cited.',
                $meta['top_k'] ?? config('academic_chat.top_k', 5), isset($meta['min_relevance_score']) ? number_format((float) $meta['min_relevance_score'], 2) : number_format((float) config('academic_chat.min_relevance_score', 0.35), 2),
                $meta['retrieved_chunk_count'] ?? 'n/a', $sources->count()))
            ->detail('Answer generation', $method === 'generative' ? 'Configured generation model, constrained to the retrieved passages.' : ($method === 'extractive' ? 'Extractive — relevant sentences quoted from the documents (no generation model).' : 'No answer generated — insufficient evidence.'));

        foreach ($sources as $s) {
            $loc = array_filter([$s->page_number ? "Page {$s->page_number}" : null, $s->section_title ? "Section: {$s->section_title}" : null]);
            $b->evidence(['type' => 'document_chunk', 'label' => 'Source ' . ($s->source_order ?? '') . ': ' . ($s->document_name ?? 'Document'),
                'text' => $loc ? implode(' · ', $loc) : 'Source location unavailable', 'score' => $s->similarity_score !== null ? round((float) $s->similarity_score, 4) : null,
                'source_type' => 'document', 'source_id' => $s->document_processing_id, 'document_id' => $s->document_processing_id, 'document_page' => $s->page_number, 'chunk_id' => $s->document_chunk_id,
                'meta' => ['excerpt' => $this->excerpt($s->excerpt, 300), 'relevance' => $s->similarity_score !== null ? round((float) $s->similarity_score, 4) : null]]);
        }
        if ($sources->isEmpty()) {
            $b->evidenceStatus('none', 'No document passage met the relevance threshold.');
        }

        return $b->method($method === 'generative' ? 'GENERATIVE' : 'EMBEDDING_BASED', 'Embedding-based retrieval over authorized document chunks (treated as untrusted data), followed by ' . ($method === 'generative' ? 'grounded generation' : 'extractive quotation') . '. Retrieved text can never act as instructions.',
                ['Embedding retrieval', 'Relevance threshold', $method === 'generative' ? 'Grounded generation model' : 'Extractive sentences', 'Citation of retrieved passages'])
            ->model(['name' => $m->generation_model, 'embedding_model' => $m->embedding_model, 'prompt_version' => $m->prompt_version])
            ->confidence(null, 'Retrieval relevance scores are shown per source; they are not an answer-level confidence.')
            ->limitations($this->limitations('rag_answer'))
            ->link('Open chat session', 'academic_chat', $m->academic_chat_session_id, ['course_id' => $m->session?->course_id])
            ->review(['overridable' => false, 'actions' => ['ACCEPTED', 'REJECTED'], 'reject_label' => 'Not helpful / incorrect']);
    }
}
