<?php

namespace App\Services;

use App\Models\AcademicChatSession;
use App\Models\DocumentChunk;
use App\Models\DocumentProcessing;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * STEP 32: Authorization-first retrieval over a user's indexed document chunks.
 *
 * Retrieval is always constrained to documents the user owns (and the session scope) BEFORE any
 * similarity is computed, so a chunk from another user/course can never be a candidate.
 * Similarity is cosine over MiniLM embeddings, computed in PHP (MySQL 8.0 has no vector type).
 */
class AcademicDocumentRetrievalService
{
    public function __construct(protected AiService $aiService) {}

    /**
     * Base query for chunks visible to this session. Reused by retrieval and by "is anything indexed?" checks.
     */
    public function scopedChunksQuery(User $user, AcademicChatSession $session): Builder
    {
        $query = DocumentChunk::query()
            ->where('document_chunks.user_id', $user->id)
            ->whereNotNull('document_chunks.embedding')
            ->whereHas('document', function (Builder $q) use ($user) {
                $q->where('user_id', $user->id)
                    ->where('processing_status', 'completed')
                    ->where('indexing_status', DocumentProcessing::INDEX_INDEXED);
            });

        switch ($session->scope_type) {
            case AcademicChatSession::SCOPE_DOCUMENT:
                $query->where('document_chunks.document_processing_id', $session->document_processing_id);
                break;
            case AcademicChatSession::SCOPE_ASSESSMENT:
                $query->where('document_chunks.course_id', $session->course_id)
                    ->where('document_chunks.assessment_id', $session->assessment_id);
                break;
            case AcademicChatSession::SCOPE_COURSE:
            default:
                $query->where('document_chunks.course_id', $session->course_id);
                break;
        }

        return $query;
    }

    /**
     * Documents in scope with their indexing state (for UI / empty states).
     *
     * @return array{total:int, indexed:int, indexing:int, failed:int, documents: array<int, array<string, mixed>>}
     */
    public function scopeIndexSummary(User $user, AcademicChatSession $session): array
    {
        $query = DocumentProcessing::query()->where('user_id', $user->id);
        switch ($session->scope_type) {
            case AcademicChatSession::SCOPE_DOCUMENT:
                $query->where('id', $session->document_processing_id);
                break;
            case AcademicChatSession::SCOPE_ASSESSMENT:
                $query->where('course_id', $session->course_id)->where('assessment_id', $session->assessment_id);
                break;
            default:
                $query->where('course_id', $session->course_id);
        }

        $docs = $query->get(['id', 'original_file_name', 'document_type', 'processing_status', 'indexing_status', 'chunk_count', 'indexed_at']);

        return [
            'total' => $docs->count(),
            'indexed' => $docs->where('indexing_status', DocumentProcessing::INDEX_INDEXED)->count(),
            'indexing' => $docs->whereIn('indexing_status', [DocumentProcessing::INDEX_INDEXING, DocumentProcessing::INDEX_NOT_INDEXED, DocumentProcessing::INDEX_STALE])
                ->where('processing_status', '!=', 'failed')->count(),
            'failed' => $docs->where('indexing_status', DocumentProcessing::INDEX_FAILED)->count(),
            'documents' => $docs->map(fn ($d) => [
                'id' => $d->id,
                'name' => $d->original_file_name,
                'document_type' => $d->document_type,
                'processing_status' => $d->processing_status,
                'indexing_status' => $d->indexing_status,
                'chunk_count' => $d->chunk_count,
            ])->values()->all(),
        ];
    }

    /**
     * STEP 33: retrieve for an ad-hoc scope without persisting a chat session.
     *
     * @param array{scope_type?:string, course_id?:int|null, document_id?:int|null, assessment_id?:int|null} $scope
     */
    public function retrieveForScope(User $user, array $scope, string $query, ?int $topK = null, ?float $minScore = null): array
    {
        $session = new AcademicChatSession([
            'user_id' => $user->id,
            'scope_type' => $scope['scope_type'] ?? AcademicChatSession::SCOPE_COURSE,
            'course_id' => $scope['course_id'] ?? null,
            'document_processing_id' => $scope['document_id'] ?? null,
            'assessment_id' => $scope['assessment_id'] ?? null,
        ]);

        return $this->retrieve($user, $session, $query, $topK, $minScore);
    }

    /**
     * Retrieve the top-K most relevant chunks for a query, restricted to the session scope.
     *
     * @return array{chunks: array<int, array<string, mixed>>, candidates: int, embedding_model: string, threshold: float}
     */
    public function retrieve(User $user, AcademicChatSession $session, string $query, ?int $topK = null, ?float $minScore = null): array
    {
        $topK = max(1, $topK ?? (int) config('academic_chat.top_k'));
        $minScore = $minScore ?? (float) config('academic_chat.min_relevance_score');
        $model = (string) config('academic_chat.embedding_model');

        $candidates = $this->scopedChunksQuery($user, $session)
            ->with('document:id,original_file_name,document_type')
            ->get();

        if ($candidates->isEmpty()) {
            return ['chunks' => [], 'candidates' => 0, 'embedding_model' => $model, 'threshold' => $minScore];
        }

        $queryVector = $this->embedQuery($query, $model);
        if ($queryVector === []) {
            return ['chunks' => [], 'candidates' => $candidates->count(), 'embedding_model' => $model, 'threshold' => $minScore];
        }

        $scored = [];
        foreach ($candidates as $chunk) {
            $vector = $chunk->embeddingVector();
            if (count($vector) !== count($queryVector)) {
                continue;
            }
            $score = self::cosine($queryVector, $vector);
            if ($score < $minScore) {
                continue;
            }
            $scored[] = [$score, $chunk];
        }

        usort($scored, fn ($a, $b) => $b[0] <=> $a[0] ?: $a[1]->id <=> $b[1]->id);
        $scored = array_slice($scored, 0, $topK);

        $chunks = [];
        foreach ($scored as [$score, $chunk]) {
            /** @var DocumentChunk $chunk */
            $chunks[] = [
                'chunk_id' => $chunk->id,
                'document_id' => $chunk->document_processing_id,
                'document_name' => $chunk->document?->original_file_name ?? 'Document',
                'document_type' => $chunk->document?->document_type,
                'content' => $chunk->content,
                'similarity_score' => round($score, 4),
                'page_number' => $chunk->page_number,
                'section_title' => $chunk->section_title,
            ];
        }

        return [
            'chunks' => $chunks,
            'candidates' => $candidates->count(),
            'embedding_model' => $model,
            'threshold' => $minScore,
        ];
    }

    /**
     * @return float[]
     */
    protected function embedQuery(string $query, string $model): array
    {
        $key = 'academic_chat:query_embedding:' . sha1($model . '|' . $query);
        $ttl = (int) config('academic_chat.query_embedding_cache_ttl', 3600);

        return Cache::remember($key, $ttl, function () use ($query) {
            $result = $this->aiService->generateEmbeddings([$query]);
            $vector = $result['embeddings'][0] ?? [];

            return is_array($vector) ? array_map('floatval', $vector) : [];
        });
    }

    /**
     * @param float[] $a
     * @param float[] $b
     */
    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        $n = count($a);
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na == 0.0 || $nb == 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($na) * sqrt($nb));
    }
}
