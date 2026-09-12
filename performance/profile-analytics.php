<?php
// STEP 43 — profile the SQL behind /analytics/overview for one user on the current database (read-only).
// Run inside the app container: php artisan tinker --execute "require 'performance/profile-analytics.php';"
use App\Models\User;
use Illuminate\Support\Facades\DB;

$user = User::where('email', 'perf.user.001@example.com')->firstOrFail();
$scope = app(\App\Services\Analytics\AnalyticsScopeService::class);
$service = app(\App\Services\Analytics\AcademicAnalyticsService::class);
$log = [];
DB::listen(function ($q) use (&$log) { $log[] = [$q->time, $q->sql]; });
\Illuminate\Support\Facades\Cache::flush();
$t0 = microtime(true);
$service->overview($user, []);
$total = (microtime(true) - $t0) * 1000;
usort($log, fn ($a, $b) => $b[0] <=> $a[0]);
printf("overview total %.0f ms, %d queries, sql time %.0f ms\n", $total, count($log), array_sum(array_column($log, 0)));
foreach (array_slice($log, 0, 12) as [$ms, $sql]) {
    printf("%7.1f ms  %s\n", $ms, mb_substr(preg_replace('/\s+/', ' ', $sql), 0, 230));
}
