<?php

namespace App\Listeners\Notifications;

use App\Events\ReportGenerated;
use App\Events\ReportGenerationFailed;
use App\Notifications\NotificationType;

/**
 * STEP 47 × STEP 39: institutional report notifications go to the requesting user only. The action URL is the
 * protected report page; the download itself is re-authorized by ReportAuthorizationService.
 */
class ReportNotificationListener extends NotificationListener
{
    public function handleGenerated(ReportGenerated $event): void
    {
        $this->guard('report-generated', function () use ($event) {
            $report = $event->report->loadMissing('creator');
            if (!$report->creator) {
                return;
            }
            $type = $this->reportLabel($report->report_type);
            $this->notifications->notify($report->creator, NotificationType::REPORT_GENERATED, [
                'title' => 'Report ready',
                'message' => "Your requested {$type} report ({$report->format}) is ready to view or download.",
                'action_url' => "/reports/{$report->id}",
                'entity_type' => 'institutional_report',
                'entity_id' => $report->id,
                'expires_at' => $report->expires_at,
                'data' => ['report_id' => $report->id, 'report_type' => $report->report_type, 'format' => $report->format, 'scope_type' => $report->scope_type, 'action_label' => 'Open Report'],
            ]);
        });
    }

    public function handleFailed(ReportGenerationFailed $event): void
    {
        $this->guard('report-failed', function () use ($event) {
            $report = $event->report->loadMissing('creator');
            if (!$report->creator) {
                return;
            }
            $this->notifications->notify($report->creator, NotificationType::REPORT_GENERATION_FAILED, [
                'title' => 'Report generation failed',
                'message' => 'The requested ' . $this->reportLabel($report->report_type) . ' report could not be generated. Please try again.',
                'action_url' => "/reports/{$report->id}",
                'entity_type' => 'institutional_report',
                'entity_id' => $report->id,
                'data' => ['report_id' => $report->id, 'report_type' => $report->report_type, 'format' => $report->format, 'action_label' => 'Open Report'],
            ]);
        });
    }

    protected function reportLabel(?string $type): string
    {
        $label = config("institutional_reports.types.{$type}.label");

        return is_string($label) && $label !== '' ? $label : ucwords(strtolower(str_replace('_', ' ', (string) $type)));
    }
}
