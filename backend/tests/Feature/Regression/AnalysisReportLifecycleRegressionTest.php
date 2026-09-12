<?php

namespace Tests\Feature\Regression;

use App\Jobs\AnalyzeAssessmentJob;
use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUG-001 / BUG-002 regression: re-running (or failing) an AI analysis must never
 * overwrite or corrupt a previously completed analysis report.
 */
class AnalysisReportLifecycleRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected Assessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculty = User::factory()->create();
        $course = Course::create([
            'user_id' => $this->faculty->id,
            'course_code' => 'REG-101',
            'course_name' => 'Regression Course',
            'semester' => 'Fall',
            'academic_year' => '2026',
        ]);
        $this->assessment = Assessment::create([
            'course_id' => $course->id,
            'title' => 'Midterm',
            'type' => 'midterm',
            'total_marks' => 20,
            'status' => 'draft',
        ]);
        foreach ([1, 2] as $n) {
            Question::create([
                'assessment_id' => $this->assessment->id,
                'question_number' => $n,
                'question_text' => "Question number {$n} about database indexing structures.",
                'marks' => 10,
                'question_type' => 'descriptive',
            ]);
        }
    }

    protected function aiSuccess(float $score): array
    {
        return [
            'status' => 'success',
            'questions_analysis' => ['questions' => []],
            'alignment_analysis' => ['overall_alignment_score' => 80.0, 'question_alignment' => []],
            'similarity_analysis' => ['matches' => []],
            'quality_analysis' => ['overall_quality_score' => $score, 'rating' => 'GOOD', 'components' => []],
            'recommendations' => ['recommendations' => []],
            'summary' => ['overall_quality_score' => $score],
        ];
    }

    public function test_rerunning_sync_analysis_creates_a_new_version_and_keeps_history(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/analyze-assessment' => Http::sequence()
            ->push($this->aiSuccess(70.0))
            ->push($this->aiSuccess(90.0))]);

        $this->postJson('/api/ai/analyze-assessment', ['assessment_id' => $this->assessment->id])->assertOk();
        $this->postJson('/api/ai/analyze-assessment', ['assessment_id' => $this->assessment->id])->assertOk();

        $reports = AnalysisReport::where('assessment_id', $this->assessment->id)->orderBy('analysis_version')->get();
        $this->assertCount(2, $reports, 'A re-run must add a new analysis version, not overwrite the previous one');
        $this->assertSame([1, 2], $reports->pluck('analysis_version')->all());
        $this->assertEquals(70.0, (float) $reports[0]->overall_score, 'Version 1 must retain its original score');
        $this->assertSame('completed', $reports[0]->analysis_status, 'Version 1 must remain completed');
        $this->assertFalse((bool) $reports[0]->is_current);
        $this->assertEquals(90.0, (float) $reports[1]->overall_score);
        $this->assertTrue((bool) $reports[1]->is_current);
        $this->assertSame(1, AnalysisReport::where('assessment_id', $this->assessment->id)->where('is_current', true)->count());
    }

    public function test_failed_rerun_does_not_corrupt_the_completed_report(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/analyze-assessment' => Http::sequence()
            ->push($this->aiSuccess(70.0))
            ->push(['status' => 'error', 'message' => 'model unavailable'], 503)]);

        $this->postJson('/api/ai/analyze-assessment', ['assessment_id' => $this->assessment->id])->assertOk();
        $this->postJson('/api/ai/analyze-assessment', ['assessment_id' => $this->assessment->id])->assertStatus(502);

        $v1 = AnalysisReport::where('assessment_id', $this->assessment->id)->where('analysis_version', 1)->firstOrFail();
        $this->assertSame('completed', $v1->analysis_status, 'A failed re-run must not flip the completed report to failed');
        $this->assertNull($v1->processing_error);
        $this->assertEquals(70.0, (float) $v1->overall_score);

        $failed = AnalysisReport::where('assessment_id', $this->assessment->id)->where('analysis_status', 'failed')->first();
        $this->assertNotNull($failed, 'The failed attempt must be recorded');
        $this->assertNotSame($v1->id, $failed->id);

        // Status endpoint must expose the failure without hiding the last completed result.
        $status = $this->getJson("/api/ai/assessments/{$this->assessment->id}/analysis-status")->assertOk()->json();
        $this->assertSame('failed', $status['analysis_status']);
        $this->assertSame($failed->id, $status['analysis_id']);

        // History still lists the completed version as current.
        $history = $this->getJson("/api/assessments/{$this->assessment->id}/analysis-history")->assertOk()->json('data');
        $this->assertSame(1, $history['current_analysis_version']);
        $this->assertSame('completed', collect($history['history'])->firstWhere('version', 1)['analysis_status']);
    }

    public function test_async_rerun_after_completed_analysis_actually_runs_and_versions(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/analyze-assessment' => Http::sequence()
            ->push($this->aiSuccess(60.0))
            ->push($this->aiSuccess(95.0))]);

        $this->postJson('/api/ai/analyze-assessment', ['assessment_id' => $this->assessment->id])->assertOk();

        Queue::fake();
        $this->postJson("/api/ai/assessments/{$this->assessment->id}/analyze?async=1")->assertStatus(202);
        Queue::assertPushed(AnalyzeAssessmentJob::class, 1);

        // Run the job inline as the worker would.
        (new AnalyzeAssessmentJob($this->assessment->fresh(), $this->faculty->id))
            ->handle(app(\App\Services\AiService::class), app(\App\Services\AuditLogService::class));

        $reports = AnalysisReport::where('assessment_id', $this->assessment->id)->orderBy('analysis_version')->get();
        $this->assertCount(2, $reports);
        $this->assertEquals(60.0, (float) $reports[0]->overall_score);
        $this->assertSame('completed', $reports[0]->analysis_status);
        $this->assertEquals(95.0, (float) $reports[1]->overall_score);
        $this->assertTrue((bool) $reports[1]->is_current);
        $this->assertFalse((bool) $reports[0]->is_current);
    }

    public function test_async_job_permanent_failure_marks_only_the_running_attempt_failed(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/analyze-assessment' => Http::sequence()
            ->push($this->aiSuccess(60.0))
            ->push(['status' => 'error'], 503)]);

        $this->postJson('/api/ai/analyze-assessment', ['assessment_id' => $this->assessment->id])->assertOk();

        Queue::fake();
        $this->postJson("/api/ai/assessments/{$this->assessment->id}/analyze?async=1")->assertStatus(202);

        $job = new AnalyzeAssessmentJob($this->assessment->fresh(), $this->faculty->id);
        try {
            $job->handle(app(\App\Services\AiService::class), app(\App\Services\AuditLogService::class));
            $this->fail('Job should throw on AI failure so the queue can retry');
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        $v1 = AnalysisReport::where('assessment_id', $this->assessment->id)->where('analysis_version', 1)->firstOrFail();
        $this->assertSame('completed', $v1->analysis_status);
        $this->assertEquals(60.0, (float) $v1->overall_score);
        $this->assertSame(1, AnalysisReport::where('assessment_id', $this->assessment->id)->where('analysis_status', 'failed')->count());
    }
}
