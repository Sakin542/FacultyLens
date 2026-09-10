<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * STEP 33: validation for POST /api/question-generation. Ownership of course/assessment/CO/PO/documents is
 * enforced in QuestionGenerationService (never trusted from the client).
 */
class QuestionGenerationRequestForm extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $maxQuestions = (int) config('question_generation.max_questions_per_request');
        $maxMarks = (float) config('question_generation.max_marks_per_question');
        $types = config('question_generation.question_types');
        $difficulties = config('question_generation.difficulty_levels');
        $cognitive = config('question_generation.cognitive_levels');

        return [
            'course_id' => ['required', 'integer'],
            'assessment_id' => ['nullable', 'integer'],
            'topic' => ['nullable', 'string', 'max:' . config('question_generation.max_topic_length')],
            'learning_outcome_id' => ['nullable', 'integer'],
            'program_outcome_id' => ['nullable', 'integer'],
            'question_type' => ['required', Rule::in($types)],
            'difficulty_level' => ['nullable', Rule::in($difficulties)],
            'cognitive_level' => ['nullable', Rule::in($cognitive)],
            'marks' => ['required', 'numeric', 'gt:0', "max:{$maxMarks}"],
            'number_of_questions' => ['required_without:blueprint', 'nullable', 'integer', 'min:1', "max:{$maxQuestions}"],
            'language' => ['nullable', 'string', 'max:40'],
            'include_expected_answer' => ['nullable', 'boolean'],
            'include_explanation' => ['nullable', 'boolean'],
            'document_scope' => ['nullable', 'array'],
            'document_scope.scope_type' => ['nullable', Rule::in(['COURSE', 'DOCUMENT', 'ASSESSMENT'])],
            'document_scope.document_id' => ['nullable', 'integer'],
            'document_scope.assessment_id' => ['nullable', 'integer'],
            'blueprint' => ['nullable', 'array', 'min:1', "max:{$maxQuestions}"],
            'blueprint.*.difficulty_level' => ['nullable', Rule::in($difficulties)],
            'blueprint.*.cognitive_level' => ['nullable', Rule::in($cognitive)],
            'blueprint.*.question_type' => ['nullable', Rule::in($types)],
            'blueprint.*.marks' => ['nullable', 'numeric', 'gt:0', "max:{$maxMarks}"],
            'blueprint.*.count' => ['required_with:blueprint', 'integer', 'min:1', "max:{$maxQuestions}"],
        ];
    }

    public function messages(): array
    {
        return [
            'number_of_questions.max' => 'A single request may generate at most ' . config('question_generation.max_questions_per_request') . ' questions.',
            'marks.gt' => 'Marks must be greater than zero.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('number_of_questions') && !$this->has('blueprint')) {
            $this->merge(['number_of_questions' => 1]);
        }
    }
}
