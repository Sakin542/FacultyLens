<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DocumentUploadRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'max:10240', // 10 MB max
                'mimes:pdf,docx,txt',
            ],
            'course_id' => [
                'required',
                'integer',
                'exists:courses,id',
            ],
            'assessment_id' => [
                'nullable',
                'integer',
                'exists:assessments,id',
            ],
            'document_type' => [
                'required',
                'string',
                'in:syllabus,question_paper,assignment,previous_exam,other',
            ],
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
            'file.required' => 'Please select a document to upload.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.max' => 'The file size must not exceed 10MB.',
            'file.mimes' => 'Only PDF (.pdf), Word Document (.docx), and Plain Text (.txt) files are supported.',
            'course_id.required' => 'The course ID is required.',
            'course_id.exists' => 'The selected course does not exist.',
            'assessment_id.exists' => 'The selected assessment does not exist.',
            'document_type.required' => 'The document type is required.',
            'document_type.in' => 'Invalid document type selected.',
        ];
    }
}

