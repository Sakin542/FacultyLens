<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * STEP 25: Faculty edit of a rubric draft. Marks integrity is enforced in RubricService.
 */
class RubricUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'general_guidance' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'criteria' => ['sometimes', 'required', 'array', 'min:1', 'max:12'],
            'criteria.*.criterion' => ['required', 'string', 'max:255'],
            'criteria.*.description' => ['required', 'string', 'max:2000'],
            'criteria.*.max_marks' => ['required', 'numeric', 'min:0', 'max:1000'],
            'criteria.*.scoring_guidance' => ['nullable', 'string', 'max:2000'],
            'criteria.*.expected_indicators' => ['nullable', 'array', 'max:12'],
            'criteria.*.expected_indicators.*' => ['string', 'max:255'],
            'criteria.*.sort_order' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'criteria.required' => 'A rubric must contain at least one criterion.',
            'criteria.min' => 'A rubric must contain at least one criterion.',
            'criteria.max' => 'A rubric may contain at most 12 criteria.',
            'criteria.*.criterion.required' => 'Each criterion needs a name.',
            'criteria.*.description.required' => 'Each criterion needs a description.',
            'criteria.*.max_marks.required' => 'Each criterion needs a marks value.',
            'criteria.*.max_marks.min' => 'Criterion marks cannot be negative.',
        ];
    }
}
