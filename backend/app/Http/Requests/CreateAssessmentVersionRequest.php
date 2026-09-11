<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * STEP 38: POST /api/assessments/{assessment}/versions — version numbers/labels are never accepted from the client.
 */
class CreateAssessmentVersionRequest extends FormRequest
{
    /** Authorization runs before field validation so outsiders get 403, not a 422 that reveals the schema. */
    public function authorize(): bool
    {
        $user = $this->user();
        $assessment = $this->route('assessment');

        return $user !== null && $assessment !== null && app(\App\Services\CourseAccessService::class)->can($user, $assessment->course, 'edit_assessment');
    }

    public function rules(): array
    {
        $hasVersions = $this->route('assessment')?->versions()->exists() ?? false;

        return [
            'based_on_version_id' => ['nullable', 'integer'],
            'version_type' => ['nullable', Rule::in(['MAJOR', 'MINOR', 'major', 'minor'])],
            'change_summary' => [$hasVersions && config('assessment_versioning.require_change_summary') ? 'required' : 'nullable', 'string', 'min:3', 'max:2000'],
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['change_summary.required' => 'A change summary is required when creating a new version.'];
    }
}
