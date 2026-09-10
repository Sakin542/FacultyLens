<?php

namespace App\Jobs;

use App\Models\DocumentProcessing;
use App\Services\DocumentTextExtractor;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Queue job for asynchronous document text extraction.
 * Moves expensive PDF/DOCX/TXT extraction out of the HTTP upload cycle.
 */
class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [15];
    public int $timeout = 120;

    public function __construct(protected DocumentProcessing $document) {}

    public function handle(DocumentTextExtractor $extractor): void
    {
        $document = $this->document->fresh();

        if (!$document) {
            Log::warning("ProcessDocumentJob: document {$this->document->id} not found - skipping.");
            return;
        }

        if ($document->processing_status === 'completed') {
            Log::info("ProcessDocumentJob: document {$document->id} already completed - skipping.");
            return;
        }

        if (!Storage::disk('local')->exists($document->file_path)) {
            $document->update([
                'processing_status' => 'failed',
                'processing_error'  => 'Source file no longer exists on disk.',
                'processed_at'      => now(),
            ]);
            return;
        }

        $extension = strtolower(
            pathinfo($document->original_file_name, PATHINFO_EXTENSION)
            ?: pathinfo($document->file_path, PATHINFO_EXTENSION)
        );

        try {
            $absolutePath     = Storage::disk('local')->path($document->file_path);
            $extractionResult = $extractor->extract($absolutePath, $extension);

            $document->update([
                'extracted_text'    => $extractionResult['raw_text'],
                'cleaned_text'      => $extractionResult['cleaned_text'],
                'processing_status' => 'completed',
                'processing_error'  => null,
                'processed_at'      => now(),
            ]);

            Log::info("ProcessDocumentJob: completed for document {$document->id}.");
        } catch (Exception $e) {
            Log::warning("ProcessDocumentJob: failed for document {$document->id}: {$e->getMessage()}");

            $document->update([
                'processing_status' => 'failed',
                'processing_error'  => 'The uploaded document could not be processed. Please ensure the file contains readable text and is not corrupted or empty.',
                'processed_at'      => now(),
            ]);
            return;
        }

        // STEP 32: index the document for academic chat retrieval. Indexing problems are tracked on
        // indexing_status and must never flip a successful extraction to "failed".
        try {
            GenerateDocumentEmbeddingsJob::dispatch($document);
        } catch (\Throwable $e) {
            Log::warning("ProcessDocumentJob: embedding dispatch failed for document {$document->id}: {$e->getMessage()}");
        }
    }

    public function failed(Exception $exception): void
    {
        Log::error("ProcessDocumentJob: permanently failed for document {$this->document->id}: {$exception->getMessage()}");

        DocumentProcessing::where('id', $this->document->id)->update([
            'processing_status' => 'failed',
            'processing_error'  => 'Text extraction failed after retries: ' . $exception->getMessage(),
        ]);
    }
}
