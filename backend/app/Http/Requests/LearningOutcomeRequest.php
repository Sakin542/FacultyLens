<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LearningOutcomeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $rule = $isUpdate ? ['sometimes', 'required'] : ['required'];

        return [
            'code' => array_merge($rule, ['string', 'max:50']),
            'description' => array_merge($rule, ['string']),
            'cognitive_level' => [
                'nullable',
                'string',
                Rule::in(['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create']),
            ],
            'sort_order' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Learning Outcome code is required (e.g. LO1).',
            'description.required' => 'Description is required.',
            'cognitive_level.in' => 'Cognitive level must be one of: Remember, Understand, Apply, Analyze, Evaluate, Create.',
        ];
    }
}

