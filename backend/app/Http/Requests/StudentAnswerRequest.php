<?php

namespace App\Http\Requests;

use App\Models\StudentAnswer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * STEP 26: Create/update a student answer. Integrity rules (question ∈ assessment,
 * marks ≤ question marks) are enforced in StudentSubmissionService.
 */
class StudentAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->route('answer') === null;

        return [
            'question_id' => $isCreate ? ['required', 'integer'] : ['prohibited'],
            'answer_text' => ['nullable', 'string', 'max:20000'],
            'answer_type' => ['nullable', 'string', Rule::in(StudentAnswer::TYPES)],
            'answer_status' => ['nullable', 'string', Rule::in(StudentAnswer::STATUSES)],
            'awarded_marks' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'faculty_feedback' => ['nullable', 'string', 'max:5000'],
            'remove_file' => ['nullable', 'boolean'],
            'file' => [
                'nullable',
                'file',
                'max:' . \App\Services\StudentSubmissionService::MAX_FILE_KB,
                'mimes:pdf,docx,txt,png,jpg,jpeg',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'question_id.required' => 'A question must be selected for the answer.',
            'question_id.prohibited' => 'The question of an existing answer cannot be changed.',
            'file.max' => 'The answer file must not exceed 10MB.',
            'file.mimes' => 'Only PDF, DOCX, TXT, PNG and JPG answer files are supported.',
            'awarded_marks.min' => 'Awarded marks cannot be negative.',
        ];
    }
}
