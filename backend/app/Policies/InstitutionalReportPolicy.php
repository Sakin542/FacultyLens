<?php

namespace App\Policies;

use App\Models\InstitutionalReport;
use App\Models\User;

/** STEP 39: a report belongs to the user who requested it; admins may view/download/delete any report. */
class InstitutionalReportPolicy
{
    public function view(User $user, InstitutionalReport $report): bool
    {
        return $user->isAdmin() || $report->created_by === $user->id;
    }

    public function download(User $user, InstitutionalReport $report): bool
    {
        return $this->view($user, $report);
    }

    public function delete(User $user, InstitutionalReport $report): bool
    {
        return $this->view($user, $report) && $report->status !== InstitutionalReport::STATUS_PROCESSING;
    }
}
