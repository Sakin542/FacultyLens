<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeAssessmentJob;
use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\Recommendation;
use App\Models\Rubric;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * STEP 41 — controlled failure of the AI service and of the queue worker.
 *
 * The FastAPI service is simulated (503 / timeout / connection refused) so that failure paths are deterministic;
 * the recovery scenario runs a REAL database queue worker (`queue:work --once`) instead of the sync driver.
 */
class AiFailureAndQueueTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;

    protected Assessment $assessment;

    protected Question $question;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faculty = User::factory()->create(['role' => 'FACULTY', 'department' => 'CSE']);
        $course = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE-401', 'course_name' => 'Artificial Intelligence', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        $lo = LearningOutcome::create(['course_id' => $course->id, 'code' => 'LO1', 'description' => 'Explain search strategies.', 'sort_order' => 1]);
        $this->assessment = Assessment::create(['course_id' => $course->id, 'title' => 'Midterm', 'type' => 'midterm', 'total_marks' => 20, 'status' => 'draft']);
        $this->question = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Explain the difference between BFS and DFS.', 'question_type' => 'descriptive', 'marks' => 10, 'difficulty_level' => 'easy', 'cognitive_level' => 'Understand', 'learning_outcome_id' => $lo->id]);
        Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 2, 'question_text' => 'When is A* optimal?', 'question_type' => 'descriptive', 'marks' => 10, 'difficulty_level' => 'medium', 'cognitive_level' => 'Understand', 'learning_outcome_id' => $lo->id]);
        Sanctum::actingAs($this->faculty);
    }

    // ------------------------------------------------------------------ AI service failures (synchronous HTTP)

    public function test_ai_service_outage_returns_a_controlled_502_and_marks_the_analysis_failed(): void
    {
        Http::fake(['*/api/v1/analyze-assessment' => Http::response(['detail' => 'Service Unavailable'], 503)]);

        $res = $this->postJson('/api/ai/analyze-assessment', ['assessment_id' => $this->assessment->id]);
        $res->assertStatus(502)->assertJsonPath('status', 'error');
        $message = $res->json('message');
        $this->assertNotEmpty($message);
        foreach (['Traceback', 'Exception', 'Stack', '127.0.0.1', 'ai-service:8001', 'Guzzle', 'cURL'] as $leak) {
            $this->assertStringNotContainsString($leak, $message, 'Error message must be safe for end users');
        }

        $report = AnalysisReport::where('assessment_id', $this->assessment->id)->first();
        $this->assertNotNull($report);
        $this->assertSame('failed', $report->analysis_status);
        $this->assertSame(0, Recommendation::count(), 'A failed analysis must not create partial recommendations');
        $this->assertSame(0, Question::where('assessment_id', $this->assessment->id)->where('ai_analysis_status', 'completed')->count());

        $status = $this->getJson("/api/ai/assessments/{$this->assessment->id}/analysis-status")->assertOk();
        $this->assertSame('failed', $status->json('analysis_status'));
    }

    public function test_ai_service_connection_refused_and_timeout_map_to_502_and_504_with_safe_messages(): void
    {
        // Http::fake() stubs are cumulative (first match wins), so both failure modes are queued as one sequence
        Http::fake(['*/api/v1/analyze-assessment' => Http::sequence()
            ->pushFailedConnection('cURL error 7: Failed to connect to ai-service port 8001: Connection refused')
            ->pushFailedConnection('cURL error 28: Operation timed out after 120000 milliseconds')]);
        $down = $this->postJson('/api/ai/analyze-assessment', ['assessment_id' => $this->assessment->id]);
        $down->assertStatus(502);
        $this->assertSame('AI Service is currently unavailable while performing unified assessment analysis.', $down->json('message'));
        $this->assertStringNotContainsString('cURL', $down->json('message'));

        $slow = $this->postJson('/api/ai/analyze-assessment', ['assessment_id' => $this->assessment->id]);
        $slow->assertStatus(504);
        $this->assertSame('AI Service request timed out while performing unified assessment analysis.', $slow->json('message'));

        // Rubric generation fails the same controlled way and never persists a half rubric
        Http::fake(['*/api/v1/generate-rubric' => Http::response(null, 500)]);
        $rubric = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate");
        $this->assertContains($rubric->status(), [502, 503, 504, 422]);
        $this->assertSame(0, Rubric::count());
    }

    public function test_analysis_recovers_after_the_ai_service_comes_back(): void
    {
        Http::fake(['*/api/v1/analyze-assessment' => Http::sequence()
            ->push(['detail' => 'Service Unavailable'], 503)
            ->push($this->successfulAnalysisPayload(), 200)]);

        $this->postJson('/api/ai/analyze-assessment', ['assessment_id' => $this->assessment->id])->assertStatus(502);
        $this->assertSame('failed', AnalysisReport::where('assessment_id', $this->assessment->id)->value('analysis_status'));

        $this->postJson('/api/ai/analyze-assessment', ['assessment_id' => $this->assessment->id])->assertOk();
        $report = AnalysisReport::where('assessment_id', $this->assessment->id)->where('is_current', true)->firstOrFail();
        $this->assertSame('completed', $report->analysis_status);
        $this->assertNull($report->processing_error);
        $this->assertEqualsWithDelta(81.5, (float) $report->overall_score, 0.01);
        $this->assertSame(1, Recommendation::where('analysis_report_id', $report->id)->count());
        $this->assertSame(2, Question::where('assessment_id', $this->assessment->id)->where('ai_analysis_status', 'completed')->count());
    }

    public function test_readiness_probe_reports_degraded_not_ready_when_only_the_ai_service_is_down(): void
    {
        Http::fake(['*/health' => Http::response(null, 503)]);
        Storage::fake('local');
        $data = $this->getJson('/api/health/ready')->assertOk()->json();
        $this->assertSame('degraded', $data['status']);
        $this->assertSame('error', $data['components']['ai_service']);
        // Core, non-AI reads keep working during an AI outage
        $this->getJson("/api/assessments/{$this->assessment->id}")->assertOk();
    }

    // ------------------------------------------------------------------ queue worker: failure → retry → success

    public function test_queued_analysis_job_fails_is_retried_by_the_worker_and_then_succeeds(): void
    {
        config(['queue.default' => 'database']);
        Http::fake(['*/api/v1/analyze-assessment' => Http::sequence()
            ->push(['detail' => 'Service Unavailable'], 503)
            ->push($this->successfulAnalysisPayload(), 200)]);

        $res = $this->postJson("/api/ai/assessments/{$this->assessment->id}/analyze?async=1");
        $res->assertStatus(202);
        $this->assertSame(1, DB::table('jobs')->count(), 'Async analysis must be queued, not executed inline');
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // Attempt 1: AI down → job throws, is released back to the queue (attempt counter incremented, not failed)
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
        $this->assertSame('failed', AnalysisReport::where('assessment_id', $this->assessment->id)->value('analysis_status'), 'Transient failure must be visible while a retry is pending');
        $job = DB::table('jobs')->first();
        $this->assertNotNull($job, 'The job must remain queued for a retry (tries=3)');
        $this->assertSame(1, (int) $job->attempts);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertGreaterThan(now()->timestamp, (int) $job->available_at, 'Retry must use back-off, not a hot loop');

        // Attempt 2 (back-off elapsed): AI healthy → completes
        DB::table('jobs')->update(['available_at' => now()->subSecond()->timestamp]);
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $report = AnalysisReport::where('assessment_id', $this->assessment->id)->where('is_current', true)->firstOrFail();
        $this->assertSame('completed', $report->analysis_status);
        $this->assertEqualsWithDelta(81.5, (float) $report->overall_score, 0.01);
        $this->assertSame(1, Recommendation::where('analysis_report_id', $report->id)->count());
    }

    public function test_job_that_exhausts_its_retries_lands_in_failed_jobs_with_a_failed_status(): void
    {
        config(['queue.default' => 'database']);
        Http::fake(['*/api/v1/analyze-assessment' => Http::response(null, 503)]);
        $this->postJson("/api/ai/assessments/{$this->assessment->id}/analyze?async=1")->assertStatus(202);

        $tries = (new AnalyzeAssessmentJob($this->assessment, $this->faculty->id))->tries;
        for ($i = 0; $i < $tries; $i++) {
            DB::table('jobs')->update(['available_at' => now()->subSecond()->timestamp]);
            Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0]);
        }
        $this->assertSame(0, DB::table('jobs')->count(), 'Job must not be retried forever');
        $this->assertSame(1, DB::table('failed_jobs')->count(), 'Exhausted job must be recorded in failed_jobs for operators');
        $report = AnalysisReport::where('assessment_id', $this->assessment->id)->firstOrFail();
        $this->assertSame('failed', $report->analysis_status);
        $this->assertStringContainsString('maximum retry attempts', $report->processing_error);
        Http::assertSentCount($tries);
    }

    public function test_every_queued_job_declares_finite_retries_a_timeout_and_a_failed_handler(): void
    {
        $finder = (new Finder)->files()->in(app_path('Jobs'))->name('*.php');
        $this->assertGreaterThanOrEqual(9, iterator_count($finder));
        foreach ($finder as $file) {
            $class = 'App\\Jobs\\'.$file->getBasename('.php');
            $ref = new \ReflectionClass($class);
            $this->assertTrue($ref->implementsInterface(ShouldQueue::class), $class);
            $defaults = $ref->getDefaultProperties();
            $this->assertArrayHasKey('tries', $defaults, "{$class} must declare \$tries");
            $this->assertGreaterThanOrEqual(1, $defaults['tries'], $class);
            $this->assertLessThanOrEqual(5, $defaults['tries'], "{$class} must not retry excessively");
            $this->assertArrayHasKey('timeout', $defaults, "{$class} must declare \$timeout");
            $this->assertGreaterThan(0, $defaults['timeout'], $class);
            $this->assertTrue($ref->hasMethod('failed'), "{$class} must implement failed() so a permanently failed job leaves a visible status");
        }
    }

    public function test_sync_request_path_does_not_queue_and_async_path_does(): void
    {
        Queue::fake();
        Http::fake(['*/api/v1/analyze-assessment' => Http::response($this->successfulAnalysisPayload(), 200)]);
        $this->postJson('/api/ai/analyze-assessment', ['assessment_id' => $this->assessment->id])->assertOk();
        Queue::assertNothingPushed();
        $this->postJson("/api/ai/assessments/{$this->assessment->id}/analyze?async=1")->assertStatus(202);
        Queue::assertPushed(AnalyzeAssessmentJob::class, 1);
    }

    // ------------------------------------------------------------------ fixtures

    /** Minimal but complete unified-analysis payload in the shape the FastAPI service returns. */
    protected function successfulAnalysisPayload(): array
    {
        $qs = Question::where('assessment_id', $this->assessment->id)->orderBy('question_number')->get();

        return [
            'status' => 'success',
            'quality_analysis' => [
                'overall_quality_score' => 81.5,
                'quality_rating' => 'GOOD',
                'components' => [
                    'topic_coverage' => ['score' => 80], 'learning_outcome_alignment' => ['score' => 75],
                    'difficulty_balance' => ['score' => 90], 'cognitive_level_balance' => ['score' => 70], 'similarity' => ['score' => 100],
                ],
                'difficulty_distribution' => ['easy' => 1, 'medium' => 1, 'hard' => 0],
                'cognitive_distribution' => ['Understand' => 2],
            ],
            'questions_analysis' => ['questions' => $qs->map(fn ($q) => [
                'id' => $q->id, 'number' => $q->question_number,
                'classification' => ['question_type' => 'DESCRIPTIVE', 'confidence' => 0.9],
                'difficulty' => ['level' => strtoupper($q->difficulty_level), 'confidence' => 0.8],
                'cognitive_level' => ['level' => 'UNDERSTAND', 'confidence' => 0.8],
                'topics' => ['Search'],
            ])->all()],
            'alignment_analysis' => ['question_alignment' => [], 'unaligned_questions' => [], 'uncovered_learning_outcomes' => []],
            'similarity_analysis' => ['matches' => [], 'potential_duplicates_count' => 0, 'highly_similar_count' => 0],
            'recommendations' => ['recommendations' => [[
                'category' => 'difficulty', 'priority' => 'MEDIUM', 'problem' => 'No hard questions',
                'recommendation' => 'Add at least one hard question.', 'explanation' => 'Difficulty distribution lacks the hard band.', 'source_metric' => 'difficulty_distribution',
            ]]],
            'metadata' => ['model' => 'test-fixture'],
        ];
    }
}
