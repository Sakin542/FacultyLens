<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A retrieval chunk of an academic document with its MiniLM embedding (STEP 32).
 * The embedding is stored as a packed little-endian float32 BLOB; use {@see embeddingVector()} to decode.
 */
class DocumentChunk extends Model
{
    protected $fillable = [
        'document_processing_id',
        'user_id',
        'course_id',
        'assessment_id',
        'chunk_index',
        'content',
        'content_hash',
        'page_number',
        'section_title',
        'word_count',
        'embedding',
        'embedding_dimension',
        'embedding_model',
        'embedding_version',
    ];

    protected $hidden = ['embedding'];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'page_number' => 'integer',
            'word_count' => 'integer',
            'embedding_dimension' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DocumentProcessing::class, 'document_processing_id');
    }

    /**
     * @param float[] $vector
     */
    public static function packEmbedding(array $vector): string
    {
        return pack('g*', ...array_map('floatval', $vector));
    }

    /**
     * @return float[]
     */
    public static function unpackEmbedding(?string $blob): array
    {
        if ($blob === null || $blob === '') {
            return [];
        }

        return array_values(unpack('g*', $blob) ?: []);
    }

    /**
     * @return float[]
     */
    public function embeddingVector(): array
    {
        return self::unpackEmbedding($this->getAttribute('embedding'));
    }
}
