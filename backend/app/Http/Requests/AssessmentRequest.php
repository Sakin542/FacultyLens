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
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $rule = $isUpdate ? 'sometimes|required' : 'required';

        return [
            'title' => "$rule|string|max:255",
            'type' => "$rule|string|in:quiz,midterm,final,assignment,class_test,project,other,Quiz,Midterm,Final,Assignment,Class Test,Project,Other",
            'description' => 'nullable|string',
            'assessment_date' => 'nullable|date',
            'total_marks' => "$rule|numeric|min:0",
            'duration_minutes' => 'nullable|integer|min:1|max:1440',
            'status' => "$rule|string|in:draft,published,completed,Draft,Published,Completed,Analyzed",
        ];
    }
}

