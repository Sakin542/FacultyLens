<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CourseMaterialRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'file' => [
                'required',
                'file',
                'max:25600', // 25 MB max
                'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,txt',
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
            'title.required' => 'Material title is required.',
            'file.required' => 'Please select a document to upload.',
            'file.max' => 'The file size must not exceed 25MB.',
            'file.mimes' => 'Allowed file types: PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, TXT.',
        ];
    }
}

