<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicChatSource extends Model
{
    protected $fillable = [
        'academic_chat_message_id',
        'document_processing_id',
        'document_chunk_id',
        'document_name',
        'document_type',
        'similarity_score',
        'page_number',
        'section_title',
        'excerpt',
        'source_order',
    ];

    protected function casts(): array
    {
        return [
            'similarity_score' => 'float',
            'page_number' => 'integer',
            'source_order' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AcademicChatMessage::class, 'academic_chat_message_id');
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(DocumentChunk::class, 'document_chunk_id');
    }
}
