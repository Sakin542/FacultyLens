<?php

namespace Tests\Unit;

use App\Services\AssessmentBlueprintService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * STEP 42 §18 — blueprint comparison tolerance boundaries:
 * |diff| < 0.5 → MATCH, 0.5 ≤ |diff| ≤ tolerance → CLOSE, > tolerance → MISMATCH,
 * unconfigured rows → NOT_CONFIGURED, configured target with no actual data → MISMATCH.
 */
class BlueprintToleranceBoundaryTest extends TestCase
{
    protected function compare(?float $target, ?float $actual, bool $configured = true, float $tol = 5.0): string
    {
        $service = app(AssessmentBlueprintService::class);
        $m = new ReflectionMethod($service, 'compareRows');
        $rows = $m->invoke(
            $service,
            ['rows' => [['key' => 'k', 'label' => 'K', 'target_percentage' => $target, 'configured' => $configured]]],
            fn () => $actual,
            fn () => 1,
            $tol
        );

        return $rows['rows'][0]['status'];
    }

    public function test_match_close_mismatch_boundaries(): void
    {
        $this->assertSame('MATCH', $this->compare(30.0, 30.0));
        $this->assertSame('MATCH', $this->compare(30.0, 30.4));
        $this->assertSame('CLOSE', $this->compare(30.0, 30.5));
        $this->assertSame('CLOSE', $this->compare(30.0, 35.0));
        $this->assertSame('CLOSE', $this->compare(30.0, 25.0));
        $this->assertSame('MISMATCH', $this->compare(30.0, 35.1));
        $this->assertSame('MISMATCH', $this->compare(30.0, 24.9));
    }

    public function test_floating_point_noise_at_the_tolerance_edge(): void
    {
        // 35.0 - 30.0 computed from thirds: 105/3 - 30 = 5.000000000000001 → still CLOSE after 1dp rounding
        $this->assertSame('CLOSE', $this->compare(30.0, 105 / 3));
        $this->assertSame('CLOSE', $this->compare(33.3, 100 / 3 + 5));
    }

    public function test_not_configured_and_missing_actual(): void
    {
        $this->assertSame('NOT_CONFIGURED', $this->compare(null, 30.0));
        $this->assertSame('NOT_CONFIGURED', $this->compare(30.0, 30.0, configured: false));
        $this->assertSame('MISMATCH', $this->compare(30.0, null));
    }

    public function test_custom_tolerance_is_respected(): void
    {
        $this->assertSame('CLOSE', $this->compare(30.0, 40.0, tol: 10.0));
        $this->assertSame('MISMATCH', $this->compare(30.0, 40.1, tol: 10.0));
        $this->assertSame('MISMATCH', $this->compare(30.0, 31.0, tol: 0.5));
    }
}
