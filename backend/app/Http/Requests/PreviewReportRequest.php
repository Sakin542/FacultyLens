<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** STEP 39: shape validation for report preview. Authorization/scope resolution happens server-side afterwards. */
class PreviewReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'report_type' => ['required', 'string', Rule::in(array_keys((array) config('institutional_reports.types')))],
            'scope_type' => ['required', 'string', Rule::in((array) config('institutional_reports.scopes'))],
            'filters' => ['nullable', 'array'],
            'filters.course_id' => ['nullable', 'integer'],
            'filters.assessment_id' => ['nullable', 'integer'],
            'filters.assessment_version_id' => ['nullable', 'integer'],
            'filters.program_id' => ['nullable', 'integer'],
            'filters.semester' => ['nullable', 'string', 'max:50'],
            'filters.academic_year' => ['nullable', 'string', 'max:20'],
            'filters.assessment_type' => ['nullable', 'string', 'max:40'],
            'filters.department' => ['nullable', 'string', 'max:255'],
            'filters.status' => ['nullable', 'string', 'max:30'],
            'filters.start_date' => ['nullable', 'date_format:Y-m-d'],
            'filters.end_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
