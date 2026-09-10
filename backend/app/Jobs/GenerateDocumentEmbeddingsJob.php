<?php

namespace App\Jobs;

use App\Models\DocumentChunk;
use App\Models\DocumentProcessing;
use App\Services\AiService;
use App\Services\DocumentChunker;
use App\Services\DocumentTextExtractor;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * STEP 32: Chunk a processed document and store MiniLM embeddings for retrieval.
 * Skips work when the document text (and chunking/model parameters) are unchanged.
 */
class GenerateDocumentEmbeddingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [30];
    public int $timeout = 600;

    public function __construct(protected DocumentProcessing $document, protected bool $force = false) {}

    public function handle(AiService $aiService, DocumentTextExtractor $extractor): void
    {
        $document = $this->document->fresh();
        if (!$document) {
            return;
        }

        if ($document->processing_status !== 'completed' || trim((string) $document->cleaned_text) === '') {
            Log::info("GenerateDocumentEmbeddingsJob: document {$document->id} has no processed text - skipping.");
            return;
        }

        $model = (string) config('academic_chat.embedding_model');
        $version = (string) config('academic_chat.indexing_version');
        $chunker = new DocumentChunker(
            (int) config('academic_chat.chunk_size_words'),
            (int) config('academic_chat.chunk_overlap_words'),
            (int) config('academic_chat.max_chunks_per_document'),
        );

        $text = (string) $document->cleaned_text;
        $maxLength = (int) config('academic_chat.max_document_text_length');
        if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength);
        }
        $hash = $chunker->contentHash($text, $model, $version);

        if (!$this->force
            && $document->indexing_status === DocumentProcessing::INDEX_INDEXED
            && $document->index_content_hash === $hash
            && $document->chunks()->exists()) {
            Log::info("GenerateDocumentEmbeddingsJob: document {$document->id} unchanged - skipping re-embedding.");
            return;
        }

        $document->update(['indexing_status' => DocumentProcessing::INDEX_INDEXING, 'indexing_error' => null]);

        try {
            $pages = [];
            $extension = strtolower(pathinfo($document->original_file_name, PATHINFO_EXTENSION));
            if ($extension === 'pdf' && Storage::disk('local')->exists($document->file_path)) {
                $pages = $extractor->extractPdfPages(Storage::disk('local')->path($document->file_path));
            }

            $chunks = $chunker->chunk($text, $pages);
            if ($chunks === []) {
                throw new Exception('No text chunks could be produced from the document.');
            }

            // Reuse embeddings for chunks whose content is unchanged.
            $existing = $document->chunks()
                ->where('embedding_model', $model)
                ->where('embedding_version', $version)
                ->get(['content_hash', 'embedding', 'embedding_dimension'])
                ->keyBy('content_hash');

            $toEmbed = [];
            foreach ($chunks as $i => $chunk) {
                if (!$existing->has($chunk['content_hash'])) {
                    $toEmbed[$i] = $chunk['content'];
                }
            }

            $vectors = [];
            $dimension = null;
            foreach (array_chunk($toEmbed, max(1, (int) config('academic_chat.embedding_batch_size')), true) as $batch) {
                $result = $aiService->generateEmbeddings(array_values($batch));
                $dimension = $result['dimension'] ?: $dimension;
                foreach (array_keys($batch) as $offset => $chunkIndex) {
                    $vectors[$chunkIndex] = $result['embeddings'][$offset];
                }
            }

            $now = now();
            $rows = [];
            foreach ($chunks as $i => $chunk) {
                if (isset($vectors[$i])) {
                    $blob = DocumentChunk::packEmbedding($vectors[$i]);
                    $dim = count($vectors[$i]);
                } else {
                    $prev = $existing->get($chunk['content_hash']);
                    $blob = $prev->getAttribute('embedding');
                    $dim = $prev->embedding_dimension;
                }
                $rows[] = [
                    'document_processing_id' => $document->id,
                    'user_id' => $document->user_id,
                    'course_id' => $document->course_id,
                    'assessment_id' => $document->assessment_id,
                    'chunk_index' => $chunk['chunk_index'],
                    'content' => $chunk['content'],
                    'content_hash' => $chunk['content_hash'],
                    'page_number' => $chunk['page_number'],
                    'section_title' => $chunk['section_title'],
                    'word_count' => $chunk['word_count'],
                    'embedding' => $blob,
                    'embedding_dimension' => $dim,
                    'embedding_model' => $model,
                    'embedding_version' => $version,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::transaction(function () use ($document, $rows, $hash, $model, $now) {
                DocumentChunk::where('document_processing_id', $document->id)->delete();
                foreach (array_chunk($rows, 100) as $batch) {
                    DocumentChunk::insert($batch);
                }
                $document->update([
                    'indexing_status' => DocumentProcessing::INDEX_INDEXED,
                    'index_content_hash' => $hash,
                    'chunk_count' => count($rows),
                    'embedding_model' => $model,
                    'indexing_error' => null,
                    'indexed_at' => $now,
                ]);
            });

            Log::info("GenerateDocumentEmbeddingsJob: indexed document {$document->id} with " . count($rows) . ' chunks.');
        } catch (Exception $e) {
            Log::warning("GenerateDocumentEmbeddingsJob: failed for document {$document->id}: {$e->getMessage()}");
            $document->update([
                'indexing_status' => DocumentProcessing::INDEX_FAILED,
                'indexing_error' => 'Document indexing for chat failed. ' . $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(Exception $exception): void
    {
        DocumentProcessing::where('id', $this->document->id)->update([
            'indexing_status' => DocumentProcessing::INDEX_FAILED,
            'indexing_error' => 'Document indexing for chat failed after retries.',
        ]);
    }
}
