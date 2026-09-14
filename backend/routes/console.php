<?php

use App\Services\InstitutionalReportService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// STEP 39: remove expired institutional report files from private storage (records + audit rows are kept)
Artisan::command('reports:purge-expired', function (InstitutionalReportService $reports) {
    $n = $reports->purgeExpired();
    $this->info("Purged {$n} expired report file(s).");
})->purpose('Delete expired institutional report files');

Schedule::command('reports:purge-expired')->dailyAt('02:30');
