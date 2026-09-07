<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviousQuestionRequest extends FormRequest
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

        return [
            'question_text' => $isUpdate ? 'nullable|string|max:5000' : 'nullable|string|required_without:file|max:5000',
            'question_type' => 'nullable|string|in:mcq,short_answer,descriptive,problem_solving,true_false,other,MCQ,SHORT_ANSWER,DESCRIPTIVE,PROBLEM_SOLVING,TRUE_FALSE,OTHER',
            'marks' => 'nullable|numeric|min:0|max:1000',
            'difficulty_level' => 'nullable|string|in:easy,medium,hard,Easy,Medium,Hard,EASY,MEDIUM,HARD',
            'cognitive_level' => 'nullable|string',
            'source' => 'nullable|string|in:previous_exam,question_bank,uploaded_document,manual,other',
            'source_year' => 'nullable|string|max:50',
            'source_assessment' => 'nullable|string|max:255',
            'file' => 'nullable|file|mimes:pdf,doc,docx,txt|max:20480',
        ];
    }
}

