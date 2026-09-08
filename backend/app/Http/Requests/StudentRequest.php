<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $required = $isUpdate ? ['sometimes', 'required'] : ['required'];

        return [
            'student_identifier' => array_merge($required, ['string', 'max:64', 'regex:/^[\w\-\.\/]+$/u']),
            'name' => array_merge($required, ['string', 'max:255']),
            'email' => ['nullable', 'email', 'max:255'],
            'department' => ['nullable', 'string', 'max:120'],
            'program' => ['nullable', 'string', 'max:120'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'section' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'student_identifier.required' => 'Student identifier is required (e.g. STU001).',
            'student_identifier.regex' => 'Student identifier may only contain letters, numbers, dashes, dots, slashes and underscores.',
            'name.required' => 'Student name is required.',
        ];
    }
}
