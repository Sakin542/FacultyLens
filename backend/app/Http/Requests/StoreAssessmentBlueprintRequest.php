<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * STEP 37: blueprint create/update payload. Cross-entity checks (outcome belongs to course, etc.) happen in the service.
 */
class StoreAssessmentBlueprintRequest extends FormRequest
{
    /** Authorization runs before field validation so outsiders get 403, not a 422 that reveals the schema. */
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }
        $assessment = $this->route('assessment') ?? $this->route('blueprint')?->assessment;

        return $assessment !== null && app(\App\Services\CourseAccessService::class)->can($user, $assessment->course, 'edit_assessment');
    }

    public function rules(): array
    {
        $cfg = config('assessment_blueprint');

        return [
            'title' => ['nullable', 'string', 'max:200'],
            'total_marks' => ['required', 'numeric', 'gt:0', 'max:' . $cfg['max_marks']],
            'total_questions' => ['required', 'integer', 'min:1', 'max:' . $cfg['max_questions']],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'instructions' => ['nullable', 'string', 'max:5000'],

            'sections' => ['nullable', 'array', 'max:' . $cfg['max_sections']],
            'sections.*.title' => ['required', 'string', 'max:150'],
            'sections.*.section_order' => ['nullable', 'integer', 'min:1'],
            'sections.*.instructions' => ['nullable', 'string', 'max:2000'],
            'sections.*.question_type' => ['nullable', Rule::in($cfg['question_types'])],
            'sections.*.question_count' => ['required', 'integer', 'min:1', 'max:' . $cfg['max_questions']],
            'sections.*.marks_per_question' => ['required', 'numeric', 'gt:0', 'max:' . $cfg['max_marks']],
            'sections.*.difficulty_distribution' => ['nullable', 'array'],
            'sections.*.difficulty_distribution.*' => ['numeric', 'min:0', 'max:100'],
            'sections.*.cognitive_distribution' => ['nullable', 'array'],
            'sections.*.cognitive_distribution.*' => ['numeric', 'min:0', 'max:100'],

            'constraints' => ['nullable', 'array'],
            'constraints.difficulty' => ['nullable', 'array'],
            'constraints.difficulty.*.key' => ['required', Rule::in($cfg['difficulty_levels'])],
            'constraints.difficulty.*.target_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'constraints.difficulty.*.target_count' => ['nullable', 'integer', 'min:0'],
            'constraints.cognitive' => ['nullable', 'array'],
            'constraints.cognitive.*.key' => ['required', Rule::in($cfg['cognitive_levels'])],
            'constraints.cognitive.*.target_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'constraints.cognitive.*.target_count' => ['nullable', 'integer', 'min:0'],
            'constraints.learning_outcomes' => ['nullable', 'array'],
            'constraints.learning_outcomes.*.learning_outcome_id' => ['required', 'integer'],
            'constraints.learning_outcomes.*.target_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'constraints.learning_outcomes.*.target_marks' => ['nullable', 'numeric', 'min:0'],
            'constraints.learning_outcomes.*.target_count' => ['nullable', 'integer', 'min:0'],
            'constraints.program_outcomes' => ['nullable', 'array'],
            'constraints.program_outcomes.*.program_outcome_id' => ['required', 'integer'],
            'constraints.program_outcomes.*.target_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'constraints.topics' => ['nullable', 'array', 'max:50'],
            'constraints.topics.*.topic' => ['required', 'string', 'max:150'],
            'constraints.topics.*.target_count' => ['nullable', 'integer', 'min:0'],
            'constraints.topics.*.target_marks' => ['nullable', 'numeric', 'min:0'],
            'constraints.question_types' => ['nullable', 'array'],
            'constraints.question_types.*.question_type' => ['required', Rule::in($cfg['question_types'])],
            'constraints.question_types.*.target_count' => ['required', 'integer', 'min:0'],
            'constraints.question_types.*.marks_each' => ['nullable', 'numeric', 'min:0'],

            'items' => ['nullable', 'array', 'max:' . $cfg['max_items']],
            'items.*.section_order' => ['nullable', 'integer', 'min:1'],
            'items.*.topic' => ['nullable', 'string', 'max:255'],
            'items.*.learning_outcome_id' => ['nullable', 'integer'],
            'items.*.program_outcome_id' => ['nullable', 'integer'],
            'items.*.question_type' => ['nullable', Rule::in($cfg['question_types'])],
            'items.*.difficulty_level' => ['nullable', Rule::in($cfg['difficulty_levels'])],
            'items.*.cognitive_level' => ['nullable', Rule::in($cfg['cognitive_levels'])],
            'items.*.question_count' => ['required', 'integer', 'min:1'],
            'items.*.marks_each' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
