<?php

namespace App\Policies;

use App\Models\AnalysisReport;
use App\Models\User;

class AnalysisReportPolicy
{
    public function view(User $user, AnalysisReport $report): bool
    {
        return $user->isAdmin() || $report->assessment?->course?->user_id === $user->id;
    }
}

