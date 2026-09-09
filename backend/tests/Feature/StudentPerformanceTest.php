<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeStudentPerformanceJob;
use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\PerformanceAnalysisRun;
use App\Models\Question;
use App\Models\QuestionLearningOutcomeAlignment;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Services\StudentPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 30: Student Performance / Gap Analysis. Uses finalized faculty marks only.
 */
class StudentPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected User $other;
    protected Course $course;
    protected Assessment $assessment;
    protected LearningOutcome $lo1;
    protected LearningOutcome $lo2;
    protected Question $q1;
    protected Question $q2;
    protected Question $q3;
    protected array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['performance.expected_performance_percent' => 70, 'performance.min_responses_for_gap_analysis' => 5]);

        $this->faculty = User::factory()->create(['email' => 'faculty@university.edu']);
        $this->other = User::factory()->create(['email' => 'other@university.edu']);
        $this->course = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026']);
        $this->lo1 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'LO1', 'description' => 'Write SQL queries.', 'cognitive_level' => 'Apply', 'sort_order' => 1]);
        $this->lo2 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'LO2', 'description' => 'Design normalized schemas.', 'cognitive_level' => 'Analyze', 'sort_order' => 2]);
        $this->assessment = Assessment::create(['course_id' => $this->course->id, 'title' => 'Midterm Examination', 'type' => 'midterm', 'total_marks' => 30, 'status' => 'draft']);

        $this->q1 = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Write a SQL join.', 'question_type' => 'problem_solving', 'marks' => 10, 'difficulty_level' => 'easy', 'cognitive_level' => 'Apply', 'learning_outcome_id' => $this->lo1->id, 'ai_topics' => ['SQL Queries']]);
        $this->q2 = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 2, 'question_text' => 'Explain normalization.', 'question_type' => 'descriptive', 'marks' => 10, 'difficulty_level' => 'medium', 'cognitive_level' => 'Understand', 'learning_outcome_id' => $this->lo2->id, 'ai_topics' => ['Normalization']]);
        $this->q3 = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 3, 'question_text' => 'Analyze transaction isolation.', 'question_type' => 'descriptive', 'marks' => 10, 'difficulty_level' => 'hard', 'cognitive_level' => 'Analyze', 'learning_outcome_id' => $this->lo1->id, 'ai_topics' => ['Transactions', 'SQL Queries']]);
    }

    /** Create a student + submission with finalized marks per question (null = unanswered). */
    protected function finalized(array $marks, string $gradingStatus = 'FACULTY_REVIEWED', string $answerStatus = 'REVIEWED'): StudentSubmission
    {
        $idx = count($this->students) + 1;
        $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => sprintf('STU%03d', $idx), 'name' => "Student {$idx}"]);
        $this->students[] = $student;
        $sub = StudentSubmission::create(['assessment_id' => $this->assessment->id, 'student_id' => $student->id, 'status' => 'GRADED', 'grading_status' => $gradingStatus, 'submitted_at' => now(), 'total_marks' => 30]);
        foreach ([$this->q1, $this->q2, $this->q3] as $i => $q) {
            if (!array_key_exists($i, $marks) || $marks[$i] === null) {
                continue;
            }
            StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $q->id, 'answer_type' => 'TEXT', 'answer_text' => 'answer', 'awarded_marks' => $marks[$i], 'answer_status' => $answerStatus]);
        }
        return $sub;
    }

    protected function seedCohort(): void
    {
        // Q1 (SQL, easy, LO1): 9,8,8,7,8  -> avg 8.0 = 80% STRONG
        // Q2 (Normalization, medium, LO2): 5,6,4,5,5 -> 5.0 = 50% HIGH_GAP (gap 20)
        // Q3 (Transactions, hard, LO1): 6,7,6,5,6 -> 6.0 = 60% MODERATE_GAP (gap 10)
        $this->finalized([9, 5, 6]);
        $this->finalized([8, 6, 7]);
        $this->finalized([8, 4, 6]);
        $this->finalized([7, 5, 5]);
        $this->finalized([8, 5, 6]);
    }

    protected function url(string $suffix = ''): string
    {
        return "/api/assessments/{$this->assessment->id}/performance{$suffix}";
    }

    // ---------------------------------------------------------- calculations

    public function test_question_metrics_from_finalized_marks(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedCohort();

        $res = $this->postJson($this->url('/analyze'))->assertStatus(200)->assertJsonPath('status', 'success');
        $q = collect($res->json('data.questions'))->keyBy('question_number');

        $this->assertSame(5, $q[1]['response_count']);
        $this->assertEquals(8.0, $q[1]['average_marks']);
        $this->assertEquals(80.0, $q[1]['average_percentage']);
        $this->assertEquals(8.0, $q[1]['median_marks']);
        $this->assertEquals(7.0, $q[1]['minimum_marks']);
        $this->assertEquals(9.0, $q[1]['max_awarded_marks']);
        $this->assertEquals(-10.0, $q[1]['performance_gap']);
        $this->assertSame('STRONG', $q[1]['performance_status']);
        $this->assertSame(5, $q[1]['submission_count']);

        $this->assertEquals(50.0, $q[2]['average_percentage']);
        $this->assertEquals(20.0, $q[2]['performance_gap']);
        $this->assertSame('HIGH_GAP', $q[2]['performance_status']);
        $this->assertEquals(5.0, $q[2]['median_marks']);

        $this->assertEquals(60.0, $q[3]['average_percentage']);
        $this->assertSame('MODERATE_GAP', $q[3]['performance_status']);
        $this->assertSame('hard', $q[3]['difficulty_level']);
        $this->assertSame('Analyze', $q[3]['cognitive_level']);
        $this->assertNotEmpty($q[3]['review_signals']);

        // Overall: (40+25+30)/(150) = 63.33
        $this->assertEquals(63.33, $res->json('data.overall_average_percentage'));
        $this->assertEquals(6.67, $res->json('data.overall_gap'));
        $this->assertSame('MINOR_GAP', $res->json('data.overall_status'));
        $this->assertSame(5, $res->json('data.student_count'));
        $this->assertSame(15, $res->json('data.finalized_answer_count'));
        $this->assertSame(70, $res->json('data.expected_performance_percent'));
    }

    public function test_topic_aggregation_only_includes_matching_questions(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedCohort();
        $topics = collect($this->postJson($this->url('/analyze'))->json('data.topics'))->keyBy('topic');

        // SQL Queries = Q1 + Q3: (40 + 30) / 100 = 70%
        $this->assertEquals(70.0, $topics['SQL Queries']['average_percentage']);
        $this->assertSame(2, $topics['SQL Queries']['question_count']);
        $this->assertSame(10, $topics['SQL Queries']['response_count']);
        $this->assertSame('ON_TARGET', $topics['SQL Queries']['performance_status']);
        // Normalization = Q2 only
        $this->assertEquals(50.0, $topics['Normalization']['average_percentage']);
        $this->assertSame('HIGH_GAP', $topics['Normalization']['performance_status']);
        $this->assertSame([$this->q2->id], $topics['Normalization']['question_ids']);
        $this->assertEquals(60.0, $topics['Transactions']['average_percentage']);
    }

    public function test_learning_outcome_weighted_aggregation(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->q3->update(['marks' => 20]); // LO1 = Q1 (10) + Q3 (20): weights differ
        $this->finalized([9, 5, 12]);
        $this->finalized([8, 6, 14]);
        $this->finalized([8, 4, 12]);
        $this->finalized([7, 5, 10]);
        $this->finalized([8, 5, 12]);

        $los = collect($this->postJson($this->url('/analyze'))->json('data.learning_outcomes'))->keyBy('lo_code');
        // LO1: Σmarks = 40 + 60 = 100 over Σmax = 50 + 100 = 150 -> 66.67
        $this->assertEquals(66.67, $los['LO1']['average_percentage']);
        $this->assertSame(2, $los['LO1']['question_count']);
        $this->assertEquals(30.0, $los['LO1']['total_marks']);
        $this->assertSame('ON_TARGET', $los['LO1']['performance_status']);
        // LO2 = Q2 only -> 50%
        $this->assertEquals(50.0, $los['LO2']['average_percentage']);
        $this->assertSame('HIGH_GAP', $los['LO2']['performance_status']);
        $this->assertEquals(20.0, $los['LO2']['performance_gap']);
    }

    public function test_strong_step11_alignment_adds_question_to_lo_but_weak_does_not(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->q3->update(['learning_outcome_id' => null]);
        $report = AnalysisReport::create(['assessment_id' => $this->assessment->id, 'analysis_status' => 'completed', 'analysis_version' => 1, 'is_current' => true]);
        QuestionLearningOutcomeAlignment::create(['analysis_report_id' => $report->id, 'question_id' => $this->q3->id, 'learning_outcome_id' => $this->lo2->id, 'similarity_score' => 0.81, 'alignment' => 'STRONG_ALIGNMENT']);
        QuestionLearningOutcomeAlignment::create(['analysis_report_id' => $report->id, 'question_id' => $this->q3->id, 'learning_outcome_id' => $this->lo1->id, 'similarity_score' => 0.45, 'alignment' => 'WEAK_ALIGNMENT']);
        $this->seedCohort();

        $los = collect($this->postJson($this->url('/analyze'))->json('data.learning_outcomes'))->keyBy('lo_code');
        $this->assertSame([$this->q1->id], $los['LO1']['question_ids']);
        $this->assertEqualsCanonicalizing([$this->q2->id, $this->q3->id], $los['LO2']['question_ids']);
        $this->assertEquals(55.0, $los['LO2']['average_percentage']); // (25+30)/100
    }

    // ------------------------------------------------------ finalized filter

    public function test_only_finalized_grades_are_included(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedCohort();
        $this->finalized([0, 0, 0], 'IN_PROGRESS');           // excluded: submission not finalized
        $this->finalized([0, 0, 0], 'AI_ASSISTED');           // excluded
        $this->finalized([0, 0, 0], 'FACULTY_REVIEWED', 'UNDER_REVIEW'); // excluded: answer not reviewed
        $this->finalized([10, 10, 10], 'FINALIZED');          // included

        $res = $this->postJson($this->url('/analyze'))->assertStatus(200);
        $q1 = collect($res->json('data.questions'))->firstWhere('question_number', 1);
        $this->assertSame(6, $q1['response_count']);
        $this->assertEquals(round(50 / 60 * 100, 2), $q1['average_percentage']);
        $this->assertSame(9, $q1['submission_count']);
        $this->assertSame(18, $res->json('data.finalized_answer_count'));
    }

    public function test_missing_answers_are_excluded_not_zero(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->finalized([10, null, 10]);
        $this->finalized([10, null, 10]);
        $this->finalized([10, 5, 10]);
        $this->finalized([10, 5, 10]);
        $this->finalized([10, 5, 10]);
        $this->finalized([10, 5, 10]);
        $this->finalized([10, 5, 10]);

        $q = collect($this->postJson($this->url('/analyze'))->json('data.questions'))->keyBy('question_number');
        $this->assertSame(5, $q[2]['response_count']);
        $this->assertEquals(50.0, $q[2]['average_percentage']);
        $this->assertSame(7, $q[2]['submission_count']);
    }

    // ---------------------------------------------------- classification/edges

    public function test_classification_bands_and_insufficient_data(): void
    {
        $service = app(StudentPerformanceService::class);
        $this->assertSame('STRONG', $service->classify(85, 10));
        $this->assertSame('ON_TARGET', $service->classify(70, 10));
        $this->assertSame('ON_TARGET', $service->classify(66, 10));      // gap 4 < 5
        $this->assertSame('MINOR_GAP', $service->classify(65, 10));      // gap 5
        $this->assertSame('MINOR_GAP', $service->classify(60.01, 10));
        $this->assertSame('MODERATE_GAP', $service->classify(60, 10));   // gap 10
        $this->assertSame('MODERATE_GAP', $service->classify(50.01, 10));
        $this->assertSame('HIGH_GAP', $service->classify(50, 10));       // gap 20
        $this->assertSame('HIGH_GAP', $service->classify(0, 10));
        $this->assertSame('INSUFFICIENT_DATA', $service->classify(20, 4));
        $this->assertSame('INSUFFICIENT_DATA', $service->classify(null, 100));
        $this->assertSame('ON_TARGET', $service->classify(79.99, 5));
        $this->assertSame('STRONG', $service->classify(80, 5));
    }

    public function test_zero_one_four_five_responses(): void
    {
        Sanctum::actingAs($this->faculty);
        $res = $this->postJson($this->url('/analyze'))->assertStatus(200);
        $this->assertSame(0, $res->json('data.finalized_answer_count'));
        $this->assertNull($res->json('data.overall_average_percentage'));
        $this->assertSame('INSUFFICIENT_DATA', $res->json('data.overall_status'));
        $this->assertSame('INSUFFICIENT_DATA', $res->json('data.questions.0.performance_status'));
        $this->assertStringContainsString('No finalized responses', $res->json('data.questions.0.review_signals.0'));

        $this->finalized([2, 2, 2]);
        $res = $this->postJson($this->url('/analyze?force=1'))->assertStatus(200);
        $this->assertSame(1, $res->json('data.questions.0.response_count'));
        $this->assertEquals(20.0, $res->json('data.questions.0.average_percentage'));
        $this->assertSame('INSUFFICIENT_DATA', $res->json('data.questions.0.performance_status'));
        $this->assertStringContainsString('Insufficient responses', $res->json('data.questions.0.review_signals.0'));

        $this->finalized([2, 2, 2]);
        $this->finalized([2, 2, 2]);
        $this->finalized([2, 2, 2]);
        $res = $this->postJson($this->url('/analyze?force=1'))->assertStatus(200);
        $this->assertSame(4, $res->json('data.questions.0.response_count'));
        $this->assertSame('INSUFFICIENT_DATA', $res->json('data.questions.0.performance_status'));
        // Question-level gaps need >= 5 responses; pooled LO/topic responses (Q1+Q3 = 8) may qualify.
        $this->assertSame([], array_filter($res->json('data.summary.gap_areas'), fn ($a) => $a['type'] === 'question'));

        $this->finalized([2, 2, 2]);
        $res = $this->postJson($this->url('/analyze?force=1'))->assertStatus(200);
        $this->assertSame(5, $res->json('data.questions.0.response_count'));
        $this->assertSame('HIGH_GAP', $res->json('data.questions.0.performance_status'));
        $this->assertNotEmpty($res->json('data.summary.gap_areas'));
    }

    public function test_full_and_zero_marks_and_decimals(): void
    {
        Sanctum::actingAs($this->faculty);
        foreach (range(1, 5) as $_) {
            $this->finalized([10, 0, 7.5]);
        }
        $q = collect($this->postJson($this->url('/analyze'))->json('data.questions'))->keyBy('question_number');
        $this->assertEquals(100.0, $q[1]['average_percentage']);
        $this->assertSame('STRONG', $q[1]['performance_status']);
        $this->assertEquals(-30.0, $q[1]['performance_gap']);
        $this->assertEquals(0.0, $q[2]['average_percentage']);
        $this->assertSame('HIGH_GAP', $q[2]['performance_status']);
        $this->assertEquals(70.0, $q[2]['performance_gap']);
        $this->assertEquals(75.0, $q[3]['average_percentage']);
        $this->assertEquals(7.5, $q[3]['median_marks']);
        $this->assertSame('ON_TARGET', $q[3]['performance_status']);
    }

    public function test_easy_question_low_performance_review_signal_is_non_punitive(): void
    {
        Sanctum::actingAs($this->faculty);
        foreach (range(1, 5) as $_) {
            $this->finalized([4, 8, 8]);
        }
        $q1 = collect($this->postJson($this->url('/analyze'))->json('data.questions'))->firstWhere('question_number', 1);
        $this->assertSame('HIGH_GAP', $q1['performance_status']);
        $signal = implode(' ', $q1['review_signals']);
        $this->assertStringContainsString('classified as easy', $signal);
        $this->assertStringNotContainsString('failed', strtolower($signal));
        $this->assertStringNotContainsString('weak student', strtolower($signal));
    }

    // ------------------------------------------------------ summary/strong/gaps

    public function test_summary_lists_gap_and_strong_areas_from_data(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedCohort();
        $summary = $this->postJson($this->url('/analyze'))->json('data.summary');

        $gapLabels = array_column($summary['gap_areas'], 'label');
        $this->assertContains('LO2', $gapLabels);
        $this->assertContains('Normalization', $gapLabels);
        $this->assertContains('Q2', $gapLabels);
        $this->assertContains('Q3', $gapLabels);
        $this->assertSame('LO2', $summary['gap_areas'][0]['label'] === 'LO2' ? 'LO2' : $summary['gap_areas'][0]['label']);
        $this->assertEquals(20.0, $summary['gap_areas'][0]['performance_gap']);

        $strongLabels = array_column($summary['strong_areas'], 'label');
        $this->assertContains('Q1', $strongLabels);
        $this->assertSame(1, $summary['los_with_gaps']);
        $this->assertSame(2, $summary['questions_with_gaps']);
        $this->assertSame(2, $summary['topics_with_gaps']);
    }

    // --------------------------------------------------------- lifecycle/api

    public function test_show_without_analysis_returns_null_and_meta(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedCohort();
        $this->getJson($this->url())->assertStatus(200)->assertJsonPath('data', null)->assertJsonPath('meta.finalized_answer_count', 15);
    }

    public function test_show_returns_snapshot_and_subresources(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedCohort();
        $this->postJson($this->url('/analyze'))->assertStatus(200);

        $this->getJson($this->url())->assertStatus(200)
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.is_stale', false)
            ->assertJsonCount(3, 'data.questions')
            ->assertJsonCount(3, 'data.topics')
            ->assertJsonCount(2, 'data.learning_outcomes');
        $this->getJson($this->url('/questions'))->assertStatus(200)->assertJsonCount(3, 'data');
        $this->getJson($this->url('/topics'))->assertStatus(200)->assertJsonCount(3, 'data');
        $this->getJson($this->url('/learning-outcomes'))->assertStatus(200)->assertJsonCount(2, 'data');
        $this->getJson($this->url('/history'))->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'PERFORMANCE_ANALYSIS_REQUESTED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'PERFORMANCE_ANALYSIS_GENERATED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'PERFORMANCE_REPORT_VIEWED']);
    }

    public function test_duplicate_analysis_409_then_stale_after_new_grade_and_regenerate_preserves_history(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedCohort();
        $first = $this->postJson($this->url('/analyze'))->assertStatus(200)->json('data.id');
        $this->postJson($this->url('/analyze'))->assertStatus(409);

        $this->finalized([10, 10, 10]);
        $this->getJson($this->url())->assertJsonPath('data.is_stale', true)->assertJsonPath('data.status', 'STALE');

        $second = $this->postJson($this->url('/analyze'))->assertStatus(200)->json('data.id');
        $this->assertNotSame($first, $second);
        $this->assertFalse(PerformanceAnalysisRun::find($first)->is_current);
        $this->assertEquals(63.33, (float) PerformanceAnalysisRun::find($first)->overall_average_percentage); // history preserved
        $this->assertSame(2, PerformanceAnalysisRun::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'PERFORMANCE_ANALYSIS_REGENERATED']);
    }

    public function test_question_change_invalidates_and_marks_stale(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedCohort();
        $this->postJson($this->url('/analyze'))->assertStatus(200);
        $this->q1->update(['marks' => 12]);
        $this->getJson($this->url())->assertJsonPath('data.is_stale', true);
    }

    public function test_large_assessment_is_queued(): void
    {
        Queue::fake();
        config(['performance.async_threshold' => 3]);
        Sanctum::actingAs($this->faculty);
        $this->seedCohort();
        $this->postJson($this->url('/analyze'))->assertStatus(202)->assertJsonPath('data.status', 'PENDING');
        Queue::assertPushed(AnalyzeStudentPerformanceJob::class, 1);
        $this->postJson($this->url('/analyze'))->assertStatus(202); // idempotent while pending
        Queue::assertPushed(AnalyzeStudentPerformanceJob::class, 1);
    }

    public function test_job_computes_pending_run(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedCohort();
        $run = PerformanceAnalysisRun::create(['assessment_id' => $this->assessment->id, 'course_id' => $this->course->id, 'expected_performance_percent' => 70, 'minimum_responses' => 5, 'status' => 'PENDING', 'is_current' => true, 'requested_by' => $this->faculty->id]);
        (new AnalyzeStudentPerformanceJob($run->id, $this->faculty->id))->handle(app(StudentPerformanceService::class));
        $this->assertSame('COMPLETED', $run->fresh()->status);
        $this->assertSame(3, $run->questionResults()->count());
    }

    public function test_grades_are_never_modified_by_analysis(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedCohort();
        $before = StudentAnswer::orderBy('id')->pluck('awarded_marks')->all();
        $this->postJson($this->url('/analyze'))->assertStatus(200);
        $this->assertSame($before, StudentAnswer::orderBy('id')->pluck('awarded_marks')->all());
        $this->assertSame(0, StudentSubmission::where('grading_status', '!=', 'FACULTY_REVIEWED')->count());
    }

    // ------------------------------------------------------------ student view

    public function test_student_performance_view_is_authorized_and_uses_finalized_only(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedCohort();
        $student = $this->students[0]; // 9,5,6
        $res = $this->getJson("/api/students/{$student->id}/assessments/{$this->assessment->id}/performance")->assertStatus(200);
        $this->assertEquals(66.67, $res->json('data.overall_percentage'));
        $this->assertEquals(20.0, $res->json('data.total_awarded_marks'));
        $this->assertSame(3, $res->json('data.finalized_question_count'));
        $areas = collect($res->json('data.areas_for_review'));
        $this->assertTrue($areas->contains(fn ($a) => $a['label'] === 'Normalization' && $a['percentage'] == 50));
        $this->assertTrue($areas->contains(fn ($a) => $a['label'] === 'LO2'));
        $this->assertFalse($areas->contains(fn ($a) => $a['label'] === 'LO1')); // 15/20 = 75% >= 70
        $this->assertStringNotContainsString('weak', strtolower(json_encode($res->json('data'))));
        $this->assertDatabaseHas('audit_logs', ['action' => 'STUDENT_PERFORMANCE_VIEWED']);

        // Unfinalized student: marks shown as not finalized, no totals
        $sub = $this->finalized([9, 9, 9], 'IN_PROGRESS');
        $res = $this->getJson("/api/students/{$sub->student_id}/assessments/{$this->assessment->id}/performance")->assertStatus(200);
        $this->assertFalse($res->json('data.has_finalized_grades'));
        $this->assertNull($res->json('data.overall_percentage'));
        $this->assertFalse($res->json('data.questions.0.is_finalized'));
    }

    public function test_cross_faculty_access_is_blocked_everywhere(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedCohort();
        $this->postJson($this->url('/analyze'))->assertStatus(200);

        Sanctum::actingAs($this->other);
        $this->getJson($this->url())->assertStatus(403);
        $this->postJson($this->url('/analyze'))->assertStatus(403);
        $this->getJson($this->url('/questions'))->assertStatus(403);
        $this->getJson($this->url('/topics'))->assertStatus(403);
        $this->getJson($this->url('/learning-outcomes'))->assertStatus(403);
        $this->getJson("/api/students/{$this->students[0]->id}/assessments/{$this->assessment->id}/performance")->assertStatus(403);

        // Faculty owning the assessment but not the student record cannot enumerate other students
        $foreign = Student::create(['created_by' => $this->other->id, 'student_identifier' => 'X1', 'name' => 'Foreign']);
        Sanctum::actingAs($this->faculty);
        $this->getJson("/api/students/{$foreign->id}/assessments/{$this->assessment->id}/performance")->assertStatus(403);
    }

    public function test_aggregate_payload_contains_no_student_identity(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedCohort();
        $json = json_encode($this->postJson($this->url('/analyze'))->json('data'));
        $this->assertStringNotContainsString('STU001', $json);
        $this->assertStringNotContainsString('Student 1', $json);
        $this->assertStringNotContainsString('student_identifier', $json);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson($this->url())->assertStatus(401);
        $this->postJson($this->url('/analyze'))->assertStatus(401);
    }
}
