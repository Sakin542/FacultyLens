<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentProcessing extends Model
{
    use HasFactory;

    public const INDEX_NOT_INDEXED = 'NOT_INDEXED';
    public const INDEX_INDEXING = 'INDEXING';
    public const INDEX_INDEXED = 'INDEXED';
    public const INDEX_FAILED = 'FAILED';
    public const INDEX_STALE = 'STALE';

    protected $fillable = [
        'user_id',
        'course_id',
        'assessment_id',
        'document_type',
        'original_file_name',
        'stored_file_name',
        'file_path',
        'mime_type',
        'file_size',
        'extracted_text',
        'cleaned_text',
        'processing_status',
        'processing_error',
        'processed_at',
        'indexing_status',
        'index_content_hash',
        'chunk_count',
        'embedding_model',
        'indexing_error',
        'indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'chunk_count' => 'integer',
            'processed_at' => 'datetime',
            'indexed_at' => 'datetime',
        ];
    }

    /**
     * Retrieval chunks generated for this document (STEP 32).
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    /**
     * The faculty user who owns/uploaded this document.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The course associated with this document.
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * The assessment associated with this document (if applicable).
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }
}

