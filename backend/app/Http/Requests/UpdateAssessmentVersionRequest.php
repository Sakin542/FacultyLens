<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * STEP 38: PUT /api/assessment-versions/{version} — draft edits only (state checks happen in the service).
 * Cross-entity checks (LO belongs to course, PO to program, original question to assessment) happen in the service.
 */
class UpdateAssessmentVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $version = $this->route('version');

        return $user !== null && $version !== null && app(\App\Services\CourseAccessService::class)->can($user, $version->assessment?->course, 'edit_assessment');
    }

    public function rules(): array
    {
        $cfg = config('assessment_versioning');

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'assessment_type' => ['sometimes', Rule::in($cfg['assessment_types'])],
            'total_marks' => ['sometimes', 'numeric', 'gt:0', 'max:' . $cfg['max_marks']],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
            'change_summary' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'sync_from_assessment' => ['sometimes', 'boolean'],
            'blueprint_id' => ['sometimes', 'nullable', 'integer'],

            'questions' => ['sometimes', 'array', 'max:' . $cfg['max_questions']],
            'questions.*.original_question_id' => ['nullable', 'integer'],
            'questions.*.question_number' => ['nullable', 'integer', 'min:1'],
            'questions.*.section_name' => ['nullable', 'string', 'max:150'],
            'questions.*.question_text' => ['required', 'string', 'max:10000'],
            'questions.*.question_type' => ['nullable', Rule::in($cfg['question_types'])],
            'questions.*.marks' => ['required', 'numeric', 'gt:0', 'max:' . $cfg['max_marks']],
            'questions.*.difficulty_level' => ['nullable', Rule::in($cfg['difficulty_levels'])],
            'questions.*.cognitive_level' => ['nullable', Rule::in($cfg['cognitive_levels'])],
            'questions.*.topic' => ['nullable', 'string', 'max:255'],
            'questions.*.learning_outcome_id' => ['nullable', 'integer'],
            'questions.*.program_outcome_id' => ['nullable', 'integer'],
            'questions.*.expected_answer' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
