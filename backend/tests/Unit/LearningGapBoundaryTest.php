<?php

namespace Tests\Unit;

use App\Models\PerformanceAnalysisRun;
use App\Services\StudentPerformanceService;
use Tests\TestCase;

/**
 * BUG-005 regression: learning-gap bands must be exact at the boundaries
 * (< 5 none, 5–<10 MINOR, 10–<20 MODERATE, >= 20 HIGH) and immune to
 * binary floating-point noise from percentage arithmetic (e.g. 13/20*100 = 65.00000000000001).
 */
class LearningGapBoundaryTest extends TestCase
{
    protected StudentPerformanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('performance.expected_performance_percent', 70.0);
        config()->set('performance.strong_performance_percent', 80.0);
        config()->set('performance.gap_low_threshold', 5.0);
        config()->set('performance.gap_moderate_threshold', 10.0);
        config()->set('performance.gap_high_threshold', 20.0);
        config()->set('performance.min_responses_for_gap_analysis', 5);
        $this->service = app(StudentPerformanceService::class);
    }

    /** @return array<string, array{float, string}> average percentage → expected classification */
    public static function boundaryCases(): array
    {
        return [
            'gap 4.99 → on target' => [65.01, PerformanceAnalysisRun::PERF_ON_TARGET],
            'gap 5.00 → minor' => [65.00, PerformanceAnalysisRun::PERF_MINOR_GAP],
            'gap 9.99 → minor' => [60.01, PerformanceAnalysisRun::PERF_MINOR_GAP],
            'gap 10.00 → moderate' => [60.00, PerformanceAnalysisRun::PERF_MODERATE_GAP],
            'gap 19.99 → moderate' => [50.01, PerformanceAnalysisRun::PERF_MODERATE_GAP],
            'gap 20.00 → high' => [50.00, PerformanceAnalysisRun::PERF_HIGH_GAP],
            'gap 0 → on target' => [70.00, PerformanceAnalysisRun::PERF_ON_TARGET],
            'strong at 80' => [80.00, PerformanceAnalysisRun::PERF_STRONG],
            'just under strong' => [79.99, PerformanceAnalysisRun::PERF_ON_TARGET],
        ];
    }

    /** @dataProvider boundaryCases */
    public function test_exact_boundaries(float $average, string $expected): void
    {
        $this->assertSame($expected, $this->service->classify($average, 10));
    }

    public function test_percentages_computed_like_calculate_land_on_the_right_band(): void
    {
        // sum / (n * max) * 100 exactly as StudentPerformanceService::calculate() does it
        $this->assertSame(PerformanceAnalysisRun::PERF_MINOR_GAP, $this->service->classify(13 / (1 * 20) * 100, 10));      // 65%
        $this->assertSame(PerformanceAnalysisRun::PERF_MODERATE_GAP, $this->service->classify(30 / (5 * 10) * 100, 5));   // 60%
        $this->assertSame(PerformanceAnalysisRun::PERF_HIGH_GAP, $this->service->classify(7 / (7 * 2) * 100, 7));         // 50%
        $this->assertSame(PerformanceAnalysisRun::PERF_ON_TARGET, $this->service->classify(195.03 / (3 * 100) * 100, 6)); // 65.01%
    }

    public function test_reported_gap_and_classification_agree(): void
    {
        foreach ([65.004, 60.004, 50.004] as $avg) {
            $gap = round($this->service->gap($avg), 2);
            $status = $this->service->classify($avg, 10);
            $expected = $gap >= 20 ? PerformanceAnalysisRun::PERF_HIGH_GAP : ($gap >= 10 ? PerformanceAnalysisRun::PERF_MODERATE_GAP : ($gap >= 5 ? PerformanceAnalysisRun::PERF_MINOR_GAP : PerformanceAnalysisRun::PERF_ON_TARGET));
            $this->assertSame($expected, $status, "average {$avg}: reported gap {$gap} must match the band");
        }
    }

    public function test_insufficient_responses_never_classified(): void
    {
        $this->assertSame(PerformanceAnalysisRun::PERF_INSUFFICIENT, $this->service->classify(20.0, 4));
        $this->assertSame(PerformanceAnalysisRun::PERF_INSUFFICIENT, $this->service->classify(null, 100));
    }
}
