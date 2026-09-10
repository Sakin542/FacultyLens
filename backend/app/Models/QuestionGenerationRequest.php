<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuestionGenerationRequest extends Model
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'user_id', 'course_id', 'assessment_id', 'topic', 'learning_outcome_id', 'program_outcome_id',
        'question_type', 'difficulty_level', 'cognitive_level', 'marks', 'number_of_questions', 'language',
        'document_scope', 'blueprint', 'include_expected_answer', 'include_explanation',
        'generation_status', 'generation_method', 'generation_model', 'generation_model_version', 'embedding_model',
        'prompt_version', 'regeneration_count', 'feedback', 'warnings', 'set_summary', 'blueprint_summary',
        'retrieved_chunks', 'existing_questions_count', 'error_message', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'marks' => 'float',
            'number_of_questions' => 'integer',
            'document_scope' => 'array',
            'blueprint' => 'array',
            'include_expected_answer' => 'boolean',
            'include_explanation' => 'boolean',
            'regeneration_count' => 'integer',
            'feedback' => 'array',
            'warnings' => 'array',
            'set_summary' => 'array',
            'blueprint_summary' => 'array',
            'retrieved_chunks' => 'integer',
            'existing_questions_count' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    public function learningOutcome(): BelongsTo
    {
        return $this->belongsTo(LearningOutcome::class);
    }

    public function programOutcome(): BelongsTo
    {
        return $this->belongsTo(ProgramOutcome::class);
    }

    public function generatedQuestions(): HasMany
    {
        return $this->hasMany(GeneratedQuestion::class, 'generation_request_id')->orderBy('sequence')->orderBy('id');
    }
}
