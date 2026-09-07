<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentProcessing extends Model
{
    use HasFactory;

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
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'processed_at' => 'datetime',
        ];
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

