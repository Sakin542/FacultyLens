<?php

namespace App\Http\Requests;

use App\Models\AiGradingResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * STEP 27: Faculty final grade for one answer. The upper bound (question marks)
 * is enforced in AiGradingService::validateFinalMarks from trusted database data.
 */
class FinalGradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'final_marks' => ['required', 'numeric', 'min:0', 'max:1000'],
            'faculty_feedback' => ['nullable', 'string', 'max:5000'],
            'decision' => ['nullable', 'string', Rule::in(AiGradingResult::DECISIONS)],
        ];
    }

    public function messages(): array
    {
        return [
            'final_marks.required' => 'Final marks are required.',
            'final_marks.numeric' => 'Final marks must be a number.',
            'final_marks.min' => 'Final marks cannot be negative.',
        ];
    }
}
