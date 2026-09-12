<?php

namespace Tests\Feature\Performance;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 43 — `analysis_reports.findings` holds the full AI payload (100 KB+ per row). Any query that sorts or lists
 * reports must not select it: on MySQL 8 with 100 reports this raised error 1038 "Out of sort memory" and broke
 * /history and the async analysis endpoint under load. sqlite does not reproduce the error, so the guard is on the SQL.
 */
class AnalysisReportWideColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_and_lifecycle_queries_never_select_findings(): void
    {
        $user = User::factory()->create();
        $course = Course::create(['user_id' => $user->id, 'course_code' => 'W-1', 'course_name' => 'Wide', 'semester' => 'Fall', 'academic_year' => '2026']);
        $assessment = Assessment::create(['course_id' => $course->id, 'title' => 'A', 'type' => 'quiz', 'total_marks' => 10, 'status' => 'completed']);
        for ($v = 1; $v <= 3; $v++) {
            AnalysisReport::create(['assessment_id' => $assessment->id, 'analysis_version' => $v, 'is_current' => $v === 3, 'overall_score' => 70 + $v, 'analysis_status' => 'completed', 'findings' => ['blob' => str_repeat('x', 5000)], 'analyzed_at' => now()->addMinutes($v)]);
        }
        Sanctum::actingAs($user);

        DB::enableQueryLog();
        $this->getJson('/api/analysis/history')->assertOk();
        $this->getJson("/api/assessments/{$assessment->id}/analysis-history")->assertOk();
        $this->getJson("/api/analysis/trends?course_id={$course->id}")->assertOk();
        AnalysisReport::beginRun($assessment->id);
        AnalysisReport::recordFailure($assessment->id, 'x');
        $sql = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $wide = $sql->filter(fn ($q) => str_contains($q, 'from `analysis_reports`') && str_contains($q, 'order by') && (str_contains($q, 'select *') || str_contains($q, '`findings`')));
        $this->assertTrue($wide->isEmpty(), "Sorted analysis_reports queries must not select findings:\n" . $wide->implode("\n"));

        // the detail endpoint still returns the findings payload
        $this->assertNotNull($this->getJson('/api/analysis/' . AnalysisReport::where('is_current', true)->value('id'))->assertOk()->json('data'));
    }
}
