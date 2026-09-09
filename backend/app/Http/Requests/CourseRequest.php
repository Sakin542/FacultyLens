<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourseRequest extends FormRequest
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
            'course_code' => array_merge($rule, ['string', 'max:50']),
            'course_name' => array_merge($rule, ['string', 'max:255']),
            'description' => ['nullable', 'string'],
            'semester' => array_merge($rule, ['string', 'max:50']),
            'academic_year' => array_merge($rule, ['string', 'max:20']),
            'credits' => array_merge($rule, ['integer', 'min:1', 'max:30']),
            'status' => array_merge($isUpdate ? ['sometimes'] : ['required'], [Rule::in(['active', 'archived'])]),
            // STEP 31: program must belong to the requesting faculty (or be admin-visible).
            'program_id' => ['nullable', 'integer', Rule::exists('programs', 'id')->where(function ($q) {
                $user = $this->user();
                if ($user && !$user->isAdmin()) {
                    $q->where('created_by', $user->id);
                }
            })],
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
            'course_code.required' => 'Course code is required (e.g. CSE-3200).',
            'course_name.required' => 'Course name is required.',
            'semester.required' => 'Semester is required (e.g. Fall / Spring).',
            'academic_year.required' => 'Academic year is required.',
            'credits.required' => 'Credits value is required.',
            'credits.min' => 'Credits must be at least 1.',
            'status.required' => 'Course status must be specified (active or archived).',
            'status.in' => 'Status must be either active or archived.',
        ];
    }
}

