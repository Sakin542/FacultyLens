<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssessmentRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'type' => 'required|string|in:quiz,midterm,final,assignment,class_test,project,other',
            'description' => 'nullable|string',
            'assessment_date' => 'nullable|date',
            'total_marks' => 'required|numeric|min:0',
            'duration_minutes' => 'nullable|integer|min:1|max:1440',
            'status' => 'required|string|in:draft,published,completed',
        ];
    }
}

