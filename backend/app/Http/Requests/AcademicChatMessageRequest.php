<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AcademicChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $max = (int) config('academic_chat.max_question_length', 5000);

        return [
            'message' => ['required', 'string', 'min:1', "max:{$max}"],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Please enter a question.',
            'message.max' => 'Questions are limited to ' . config('academic_chat.max_question_length', 5000) . ' characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => trim($this->input('message'))]);
        }
    }
}
