<?php

namespace App\Policies;

use App\Models\AnalysisReport;
use App\Models\User;

class AnalysisReportPolicy
{
    use ResolvesCourseAccess;

    public function view(User $user, AnalysisReport $report): bool
    {
        return $this->allows($user, $report->assessment?->course, 'view_analysis');
    }
}
