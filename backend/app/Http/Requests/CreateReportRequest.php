<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/** STEP 39: report creation = preview inputs + export format. */
class CreateReportRequest extends PreviewReportRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'format' => ['required', 'string', Rule::in((array) config('institutional_reports.formats'))],
        ];
    }
}
