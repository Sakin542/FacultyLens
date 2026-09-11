<?php

namespace App\Jobs;

use App\Models\InstitutionalReport;
use App\Services\InstitutionalReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * STEP 39: generates a large / institution-wide report off the HTTP cycle
 * (PENDING → PROCESSING → COMPLETED|FAILED). Works on the project's queue worker (database/Redis/Horizon).
 */
class GenerateInstitutionalReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [30];
    public int $timeout = 900;

    public function __construct(public int $reportId) {}

    public function handle(InstitutionalReportService $service): void
    {
        $report = InstitutionalReport::find($this->reportId);
        if (!$report || $report->status === InstitutionalReport::STATUS_COMPLETED || $report->status === InstitutionalReport::STATUS_CANCELLED) {
            return;
        }
        $service->generate($report);
    }

    public function failed(\Throwable $e): void
    {
        InstitutionalReport::where('id', $this->reportId)->whereIn('status', [InstitutionalReport::STATUS_PENDING, InstitutionalReport::STATUS_PROCESSING])
            ->update(['status' => InstitutionalReport::STATUS_FAILED, 'error_message' => 'Report generation failed. Please try again.']);
    }
}
