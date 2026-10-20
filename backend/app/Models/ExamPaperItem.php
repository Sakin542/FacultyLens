<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamPaperItem extends Model
{
    protected $fillable = [
        'exam_paper_id', 'sort_order', 'section_title', 'source_type', 'source_id', 'question_text', 'expected_answer',
        'question_type', 'marks', 'difficulty_level', 'cognitive_level', 'topic', 'learning_outcome_id', 'options',
        'variant_of_item_id', 'variant_meta', 'answer_needs_review',
    ];

    protected function casts(): array
    {
        return [
            'marks' => 'float',
            'options' => 'array',
            'variant_meta' => 'array',
            'answer_needs_review' => 'boolean',
        ];
    }

    public function paper(): BelongsTo
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function learningOutcome(): BelongsTo
    {
        return $this->belongsTo(LearningOutcome::class);
    }

    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'sort_order' => $this->sort_order,
            'section_title' => $this->section_title,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'question_text' => $this->question_text,
            'expected_answer' => $this->expected_answer,
            'question_type' => $this->question_type,
            'marks' => $this->marks,
            'difficulty_level' => $this->difficulty_level,
            'cognitive_level' => $this->cognitive_level,
            'topic' => $this->topic,
            'learning_outcome' => $this->learningOutcome ? ['id' => $this->learningOutcome->id, 'code' => $this->learningOutcome->code] : null,
            'options' => $this->options,
            'variant_of_item_id' => $this->variant_of_item_id,
            'variant_meta' => $this->variant_meta,
            'answer_needs_review' => $this->answer_needs_review,
        ];
    }
}
