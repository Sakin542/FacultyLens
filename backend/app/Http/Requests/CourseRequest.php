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
        return [
            'course_code' => ['required', 'string', 'max:50'],
            'course_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'semester' => ['required', 'string', 'max:50'],
            'academic_year' => ['required', 'string', 'max:20'],
            'credits' => ['required', 'integer', 'min:1', 'max:30'],
            'status' => ['required', Rule::in(['active', 'archived'])],
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

