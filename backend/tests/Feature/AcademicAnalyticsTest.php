<?php

namespace Tests\Feature;

use App\Models\AiEvaluationDataset;
use App\Models\AiEvaluationRun;
use App\Models\AiGradingResult;
use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\CollaborationComment;
use App\Models\Course;
use App\Models\CourseCollaborator;
use App\Models\LearningOutcome;
use App\Models\LearningOutcomePerformanceResult;
use App\Models\PerformanceAnalysisRun;
use App\Models\PreviousQuestion;
use App\Models\Question;
use App\Models\QuestionLearningOutcomeAlignment;
use App\Models\QuestionSimilarityMatch;
use App\Models\Recommendation;
use App\Models\RecommendationFeedback;
use App\Models\Rubric;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 36: Academic Analytics Dashboard — calculations are verified against hand-computed values from controlled fixtures.
 */
class AcademicAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected User $other;
    protected User $reviewer;
    protected Course $course;
    protected Course $spring;
    protected Course $otherCourse;
    protected Assessment $midterm;
    protected Assessment $final;
    protected LearningOutcome $lo1;
    protected LearningOutcome $lo2;
    protected LearningOutcome $lo3;
    /** @var Question[] */
    protected array $questions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->faculty = User::factory()->create(['role' => 'FACULTY']);
        $this->other = User::factory()->create(['role' => 'FACULTY']);
        $this->reviewer = User::factory()->create(['role' => 'FACULTY']);

        $this->course = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        $this->spring = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Spring', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        $this->otherCourse = Course::create(['user_id' => $this->other->id, 'course_code' => 'EEE201', 'course_name' => 'Circuits', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        CourseCollaborator::create(['course_id' => $this->course->id, 'user_id' => $this->reviewer->id, 'invited_by' => $this->faculty->id, 'role' => 'REVIEWER', 'status' => 'ACTIVE', 'accepted_at' => now()]);

        $this->lo1 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO1', 'description' => 'Explain relational concepts.', 'sort_order' => 1]);
        $this->lo2 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO2', 'description' => 'Apply normalization.', 'sort_order' => 2]);
        $this->lo3 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO3', 'description' => 'Optimize queries.', 'sort_order' => 3]);

        $this->midterm = Assessment::create(['course_id' => $this->course->id, 'title' => 'Midterm', 'type' => 'midterm', 'total_marks' => 100, 'status' => 'published', 'assessment_date' => '2026-10-01']);
        $this->final = Assessment::create(['course_id' => $this->course->id, 'title' => 'Final', 'type' => 'final', 'total_marks' => 100, 'status' => 'draft', 'assessment_date' => '2026-12-01']);

        // 10 questions on the midterm: Easy 3, Medium 5, Hard 2; Bloom: Remember 2, Understand 3, Apply 5
        $spec = [['easy', 'Remember'], ['easy', 'Remember'], ['easy', 'Understand'], ['medium', 'Understand'], ['medium', 'Understand'], ['medium', 'Apply'], ['medium', 'Apply'], ['medium', 'Apply'], ['hard', 'Apply'], ['hard', 'Apply']];
        foreach ($spec as $i => [$d, $c]) {
            $this->questions[] = Question::create(['assessment_id' => $this->midterm->id, 'question_number' => $i + 1, 'question_text' => "Question " . ($i + 1), 'question_type' => 'descriptive', 'marks' => 10,
                'difficulty_level' => $d, 'cognitive_level' => $c, 'learning_outcome_id' => $i < 5 ? $this->lo1->id : $this->lo2->id]);
        }
        // Other faculty's private data must never leak into the aggregates
        $oa = Assessment::create(['course_id' => $this->otherCourse->id, 'title' => 'Other exam', 'type' => 'final', 'total_marks' => 10, 'status' => 'draft']);
        Question::create(['assessment_id' => $oa->id, 'question_number' => 1, 'question_text' => 'Other', 'question_type' => 'mcq', 'marks' => 10, 'difficulty_level' => 'hard', 'cognitive_level' => 'Create']);
        AnalysisReport::create(['assessment_id' => $oa->id, 'analysis_version' => 1, 'is_current' => true, 'overall_score' => 10, 'analysis_status' => 'completed', 'analyzed_at' => now()]);
    }

    protected function seedAnalysis(): AnalysisReport
    {
        $report = AnalysisReport::create(['assessment_id' => $this->midterm->id, 'analysis_version' => 1, 'is_current' => true, 'overall_score' => 84.0, 'learning_outcome_alignment_score' => 70, 'difficulty_balance_score' => 90, 'cognitive_level_balance_score' => 60, 'analysis_status' => 'completed', 'analyzed_at' => now()]);
        AnalysisReport::create(['assessment_id' => $this->midterm->id, 'analysis_version' => 0, 'is_current' => false, 'overall_score' => 40.0, 'analysis_status' => 'completed', 'analyzed_at' => now()->subDay()]);
        AnalysisReport::create(['assessment_id' => $this->final->id, 'analysis_version' => 1, 'is_current' => true, 'overall_score' => 58.0, 'analysis_status' => 'completed', 'analyzed_at' => now()]);
        // CO1: 3 strong, 1 weak; CO2: 1 weak, 1 not aligned; CO3: none
        foreach ([0, 1, 2] as $i) {
            QuestionLearningOutcomeAlignment::create(['analysis_report_id' => $report->id, 'question_id' => $this->questions[$i]->id, 'learning_outcome_id' => $this->lo1->id, 'similarity_score' => 0.8, 'alignment' => 'STRONG_ALIGNMENT']);
        }
        QuestionLearningOutcomeAlignment::create(['analysis_report_id' => $report->id, 'question_id' => $this->questions[3]->id, 'learning_outcome_id' => $this->lo1->id, 'similarity_score' => 0.55, 'alignment' => 'WEAK_ALIGNMENT']);
        QuestionLearningOutcomeAlignment::create(['analysis_report_id' => $report->id, 'question_id' => $this->questions[5]->id, 'learning_outcome_id' => $this->lo2->id, 'similarity_score' => 0.55, 'alignment' => 'WEAK_ALIGNMENT']);
        QuestionLearningOutcomeAlignment::create(['analysis_report_id' => $report->id, 'question_id' => $this->questions[6]->id, 'learning_outcome_id' => $this->lo2->id, 'similarity_score' => 0.2, 'alignment' => 'NOT_ALIGNED']);
        $prev = PreviousQuestion::create(['user_id' => $this->faculty->id, 'course_id' => $this->course->id, 'question_text' => 'Old question', 'source' => 'previous_exam', 'difficulty_level' => 'medium']);
        QuestionSimilarityMatch::create(['analysis_report_id' => $report->id, 'current_question_id' => $this->questions[0]->id, 'previous_question_id' => $prev->id, 'similarity_score' => 0.91, 'similarity_status' => 'POTENTIAL_DUPLICATE']);
        QuestionSimilarityMatch::create(['analysis_report_id' => $report->id, 'current_question_id' => $this->questions[1]->id, 'previous_question_id' => $prev->id, 'similarity_score' => 0.75, 'similarity_status' => 'HIGHLY_SIMILAR']);
        QuestionSimilarityMatch::create(['analysis_report_id' => $report->id, 'current_question_id' => $this->questions[1]->id, 'previous_question_id' => $prev->id, 'similarity_score' => 0.72, 'similarity_status' => 'HIGHLY_SIMILAR']);
        foreach ([['pending', 'high'], ['pending', 'low'], ['accepted', 'medium'], ['dismissed', 'low'], ['reviewed', 'low']] as [$s, $p]) {
            $rec = Recommendation::create(['analysis_report_id' => $report->id, 'category' => 'difficulty', 'problem' => 'p', 'title' => 't', 'description' => 'd', 'recommendation' => 'r', 'explanation' => 'e', 'priority' => $p, 'status' => $s, 'source_metric' => 'x']);
            if ($s === 'accepted') {
                RecommendationFeedback::create(['recommendation_id' => $rec->id, 'user_id' => $this->faculty->id, 'decision' => 'ACCEPTED', 'usefulness_rating' => 5]);
            }
            if ($s === 'dismissed') {
                RecommendationFeedback::create(['recommendation_id' => $rec->id, 'user_id' => $this->faculty->id, 'decision' => 'DISMISSED', 'usefulness_rating' => 2]);
            }
        }

        return $report;
    }

    /** 4 finalized submissions with 8/10, 7/10, 6/10, 9/10 on Q1 (+ an unfinalized one that must be ignored). */
    protected function seedGrades(): void
    {
        foreach ([8, 7, 6, 9] as $i => $m) {
            $st = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => "S{$i}", 'name' => "Student {$i}"]);
            $sub = StudentSubmission::create(['assessment_id' => $this->midterm->id, 'student_id' => $st->id, 'status' => 'GRADED', 'grading_status' => 'FINALIZED', 'submitted_at' => now(), 'total_marks' => 10]);
            $ans = StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $this->questions[0]->id, 'answer_type' => 'TEXT', 'answer_text' => 'a', 'awarded_marks' => $m, 'answer_status' => 'REVIEWED']);
            AiGradingResult::create(['student_answer_id' => $ans->id, 'student_submission_id' => $sub->id, 'question_id' => $this->questions[0]->id, 'suggested_marks' => $m + ($i === 0 ? 1 : 0), 'maximum_marks' => 10, 'grading_status' => 'COMPLETED', 'is_current' => true,
                'faculty_decision' => $i === 0 ? 'MODIFIED' : 'ACCEPTED', 'requested_by' => $this->faculty->id, 'answer_fingerprint' => "f{$i}", 'context_fingerprint' => "c{$i}", 'model_name' => 'engine', 'generation_method' => 'rule_based']);
        }
        $st = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'S9', 'name' => 'Pending']);
        $sub = StudentSubmission::create(['assessment_id' => $this->midterm->id, 'student_id' => $st->id, 'status' => 'SUBMITTED', 'grading_status' => 'IN_PROGRESS', 'submitted_at' => now(), 'total_marks' => 10]);
        StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $this->questions[0]->id, 'answer_type' => 'TEXT', 'answer_text' => 'a', 'awarded_marks' => 1, 'answer_status' => 'REVIEWED']);
    }

    protected function seedPerformanceRun(): PerformanceAnalysisRun
    {
        $run = PerformanceAnalysisRun::create(['assessment_id' => $this->midterm->id, 'course_id' => $this->course->id, 'expected_performance_percent' => 70, 'minimum_responses' => 5, 'status' => 'COMPLETED', 'is_current' => true,
            'requested_by' => $this->faculty->id, 'finalized_answer_count' => 4, 'overall_average_percentage' => 75, 'overall_status' => 'ON_TARGET', 'analyzed_at' => now()]);
        LearningOutcomePerformanceResult::create(['performance_analysis_run_id' => $run->id, 'learning_outcome_id' => $this->lo3->id, 'lo_code' => 'CO3', 'lo_description' => 'Optimize queries.', 'question_count' => 2, 'question_ids' => [1, 2], 'response_count' => 12, 'total_marks' => 20, 'average_percentage' => 51, 'performance_gap' => 19, 'performance_status' => 'MODERATE_GAP']);
        LearningOutcomePerformanceResult::create(['performance_analysis_run_id' => $run->id, 'learning_outcome_id' => $this->lo1->id, 'lo_code' => 'CO1', 'lo_description' => 'Explain.', 'question_count' => 5, 'question_ids' => [1], 'response_count' => 12, 'total_marks' => 50, 'average_percentage' => 85, 'performance_gap' => -15, 'performance_status' => 'STRONG']);
        LearningOutcomePerformanceResult::create(['performance_analysis_run_id' => $run->id, 'learning_outcome_id' => $this->lo2->id, 'lo_code' => 'CO2', 'lo_description' => 'Apply.', 'question_count' => 3, 'question_ids' => [6], 'response_count' => 3, 'total_marks' => 30, 'average_percentage' => 40, 'performance_gap' => 30, 'performance_status' => 'INSUFFICIENT_DATA']);

        return $run;
    }

    public function test_requires_authentication_and_returns_empty_state_without_data(): void
    {
        $this->getJson('/api/analytics/overview')->assertStatus(401);
        Sanctum::actingAs($this->faculty);
        $res = $this->getJson('/api/analytics/overview')->assertOk()->assertJsonPath('status', 'success');
        $this->assertEquals(2, $res->json('data.kpis.courses.value'));
        $this->assertEquals(2, $res->json('data.kpis.assessments.value'));
        $this->assertEquals(10, $res->json('data.kpis.questions.value'));
        $this->assertNull($res->json('data.kpis.average_quality.value'));
        $this->assertNull($res->json('data.kpis.student_performance.value'));
        $this->assertNull($res->json('data.kpis.open_gaps.value'));
        $this->assertFalse($res->json('data.performance.available'));
        $this->assertSame('INSUFFICIENT_DATA', $res->json('data.performance.status'));
        $this->assertEquals(0, $res->json('data.assessment_quality.analyzed_assessments'));
        $this->assertFalse($res->json('data.program_outcomes.configured'));
        $this->assertFalse($res->json('data.inter_grader.available'));
        $this->assertSame([], $res->json('data.attention_areas'));
        $this->assertNotNull($res->json('data.meta.generated_at'));
    }

    public function test_difficulty_and_cognitive_distributions_match_manual_percentages(): void
    {
        Sanctum::actingAs($this->faculty);
        $d = $this->getJson('/api/analytics/overview')->json('data.difficulty');
        $this->assertEquals(10, $d['total_questions']);
        $byLevel = collect($d['distribution'])->keyBy('level');
        $this->assertEquals(30.0, $byLevel['easy']['percentage']);
        $this->assertEquals(50.0, $byLevel['medium']['percentage']);
        $this->assertEquals(20.0, $byLevel['hard']['percentage']);
        $this->assertEquals(0.0, $byLevel['easy']['difference']);
        $this->assertSame('BALANCED', $d['balance_status']);
        $this->assertEquals(0.0, $d['total_deviation']);

        $c = collect($this->getJson('/api/analytics/overview')->json('data.cognitive.distribution'))->keyBy('level');
        $this->assertEquals(20.0, $c['Remember']['percentage']);
        $this->assertEquals(30.0, $c['Understand']['percentage']);
        $this->assertEquals(50.0, $c['Apply']['percentage']);
        $this->assertEquals(0, $c['Create']['count']);
    }

    public function test_changing_a_source_record_changes_the_analytics(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->assertEquals(20.0, collect($this->getJson('/api/analytics/overview')->json('data.difficulty.distribution'))->firstWhere('level', 'hard')['percentage']);
        $this->questions[3]->update(['difficulty_level' => 'hard']); // Medium → Hard
        $d = $this->getJson('/api/analytics/overview')->json('data.difficulty');
        $byLevel = collect($d['distribution'])->keyBy('level');
        $this->assertEquals(30.0, $byLevel['hard']['percentage']);
        $this->assertEquals(40.0, $byLevel['medium']['percentage']);
        $this->assertEquals(10.0, $byLevel['hard']['difference']);
        $this->assertSame('SLIGHTLY_UNBALANCED', $d['balance_status']); // 0 + 10 + 10 = 20 total deviation
    }

    public function test_quality_outcome_similarity_and_recommendation_analytics(): void
    {
        $this->seedAnalysis();
        Sanctum::actingAs($this->faculty);
        $data = $this->getJson('/api/analytics/overview')->json('data');

        $q = $data['assessment_quality'];
        $this->assertEquals(2, $q['analyzed_assessments']);
        $this->assertEquals(71.0, $q['average_score']); // (84 + 58) / 2, superseded version ignored, other faculty ignored
        $this->assertEquals(1, $q['counts']['GOOD']);
        $this->assertEquals(1, $q['counts']['REQUIRES_ATTENTION']);
        $this->assertSame(['2026-10-01', '2026-12-01'], array_column($q['trend'], 'date'));
        $this->assertEquals(71.0, $data['kpis']['average_quality']['value']);
        $this->assertEquals(2, $data['kpis']['ai_analysis_runs']['value']);

        $lo = $data['learning_outcomes'];
        $this->assertEquals(3, $lo['total_outcomes']);
        $this->assertEquals(1, $lo['covered_outcomes']);
        $this->assertEqualsWithDelta(33.3, $lo['coverage_percentage'], 0.05);
        $rows = collect($lo['outcomes'])->keyBy('code');
        $this->assertEquals([4, 3, 1, 0, 75.0, 'COVERED'], [$rows['CO1']['questions'], $rows['CO1']['strong'], $rows['CO1']['weak'], $rows['CO1']['not_aligned'], $rows['CO1']['coverage_percentage'], $rows['CO1']['status']]);
        $this->assertEquals([2, 0, 1, 1, 0.0, 'NOT_ALIGNED'], [$rows['CO2']['questions'], $rows['CO2']['strong'], $rows['CO2']['weak'], $rows['CO2']['not_aligned'], $rows['CO2']['coverage_percentage'], $rows['CO2']['status']]);
        $this->assertSame('NOT_ASSESSED', $rows['CO3']['status']);
        $this->assertNull($rows['CO3']['coverage_percentage']);

        $sim = $data['similarity']['by_status'];
        $this->assertSame(['matches' => 1, 'questions' => 1], $sim['POTENTIAL_DUPLICATE']);
        $this->assertSame(['matches' => 2, 'questions' => 1], $sim['HIGHLY_SIMILAR']);
        $this->assertEquals(1, $data['question_bank']['bank_questions']);
        $this->assertEquals(1, $data['question_bank']['bank_previously_matched']);

        $rec = $data['recommendations'];
        $this->assertEquals([5, 2, 1, 1, 1], [$rec['total'], $rec['active'], $rec['accepted'], $rec['dismissed'], $rec['under_review']]);
        $this->assertEquals(1, $rec['active_by_priority']['high']);
        $this->assertEquals(50.0, $rec['feedback']['accepted_percent']);
        $this->assertSame('Faculty Interaction Signal', $rec['feedback']['label']);

        $types = array_column($data['attention_areas'], 'type');
        $this->assertContains('QUALITY', $types); // Final rated REQUIRES_ATTENTION
        $this->assertContains('CO_COVERAGE', $types);
        $this->assertContains('SIMILARITY', $types);
        $this->assertSame('HIGH', $data['attention_areas'][0]['severity']);
    }

    public function test_student_performance_uses_only_finalized_grades_and_matches_step30(): void
    {
        $this->seedGrades();
        $this->seedPerformanceRun();
        Sanctum::actingAs($this->faculty);
        $data = $this->getJson('/api/analytics/overview')->json('data');
        $p = $data['performance'];
        $this->assertTrue($p['available']);
        $this->assertEquals(75.0, $p['average_percentage']); // (8+7+6+9)/40; the IN_PROGRESS submission is excluded
        $this->assertEquals(75.0, $p['median_percentage']);
        $this->assertEquals(60.0, $p['minimum_percentage']);
        $this->assertEquals(90.0, $p['maximum_percentage']);
        $this->assertEquals(4, $p['submissions']);
        $this->assertEquals(4, $p['responses']);
        $this->assertEquals(-5.0, $p['gap']);
        $this->assertSame('INSUFFICIENT_DATA', $p['status']); // 4 responses < min 5 → never classified as a gap
        $this->assertEquals(75.0, $data['kpis']['student_performance']['value']);
        $this->assertCount(1, $p['trend']);
        $this->assertEquals(75.0, $p['trend'][0]['average_percentage']);

        $g = $data['learning_gaps'];
        $this->assertEquals(1, $g['counts']['MODERATE_GAP']);
        $this->assertEquals(1, $g['counts']['STRONG']);
        $this->assertEquals(1, $g['counts']['INSUFFICIENT_DATA']);
        $this->assertEquals(1, $g['open_gaps']);
        $this->assertEquals(1, $data['kpis']['open_gaps']['value']);
        $this->assertSame('CO3', $g['top_gaps'][0]['code']);
        $this->assertEquals(19.0, $g['top_gaps'][0]['gap']);
        $this->assertSame('LEARNING_GAP', $data['attention_areas'][0]['type']);
        $this->assertStringContainsString('CO3 performance is below benchmark', $data['attention_areas'][0]['title']);

        $gr = $data['grading'];
        $this->assertEquals(4, $gr['ai_assisted_answers']);
        $this->assertEquals(3, $gr['faculty_accepted']);
        $this->assertEquals(1, $gr['faculty_modified']);
        $this->assertEquals(0.25, $gr['mae']); // one answer off by 1 of 4
        $this->assertEquals(75.0, $gr['exact_agreement_rate']);
        // No student identity anywhere in the aggregate payload
        $this->assertStringNotContainsString('student_identifier', json_encode($data));
        $this->assertStringNotContainsString('Student 0', json_encode($data));
    }

    public function test_filters_scope_every_section(): void
    {
        $this->seedAnalysis();
        Sanctum::actingAs($this->faculty);
        // Assessment filter → only the Final (0 questions, 1 report)
        $d = $this->getJson("/api/analytics/overview?assessment_id={$this->final->id}")->json('data');
        $this->assertEquals(0, $d['kpis']['questions']['value']);
        $this->assertEquals(58.0, $d['assessment_quality']['average_score']);
        $this->assertEquals(0, $d['difficulty']['total_questions']);
        $this->assertEquals(0, $d['similarity']['by_status']['POTENTIAL_DUPLICATE']['questions']);
        $this->assertEquals(0, $d['recommendations']['total']);
        // Type filter
        $this->assertEquals(84.0, $this->getJson('/api/analytics/overview?assessment_type=midterm')->json('data.assessment_quality.average_score'));
        // Semester filter → Spring course has no assessments
        $d = $this->getJson('/api/analytics/overview?semester=Spring&academic_year=2026')->json('data');
        $this->assertEquals(1, $d['kpis']['courses']['value']);
        $this->assertEquals(0, $d['kpis']['assessments']['value']);
        // Date range excludes the December final
        $this->assertEquals(1, $this->getJson('/api/analytics/overview?start_date=2026-09-01&end_date=2026-10-31')->json('data.assessment_quality.analyzed_assessments'));
        // Course filter for another faculty's course is refused
        $this->getJson("/api/analytics/overview?course_id={$this->otherCourse->id}")->assertStatus(403);
        // Validation
        $this->getJson('/api/analytics/overview?start_date=2026-10-01&end_date=2026-01-01')->assertStatus(422);
        // Filter options are scoped to accessible courses
        $opts = $this->getJson('/api/analytics/filters')->assertOk()->json('data');
        $this->assertCount(2, $opts['courses']);
        $this->assertSame(['Fall', 'Spring'], $opts['semesters']);
    }

    public function test_course_isolation_and_role_restrictions(): void
    {
        $this->seedAnalysis();
        $this->seedGrades();
        $this->seedPerformanceRun();
        // Other faculty sees only their own course
        Sanctum::actingAs($this->other);
        $d = $this->getJson('/api/analytics/overview')->json('data');
        $this->assertEquals(1, $d['kpis']['courses']['value']);
        $this->assertEquals(10.0, $d['assessment_quality']['average_score']);
        $this->assertEquals(0, $d['learning_outcomes']['total_outcomes']);
        $this->getJson("/api/analytics/courses/{$this->course->id}")->assertStatus(403);
        $this->getJson("/api/analytics/courses/{$this->course->id}/performance")->assertStatus(403);
        $this->getJson("/api/analytics/compare?assessment_ids[]={$this->midterm->id}&assessment_ids[]={$this->final->id}")->assertStatus(403);

        // Reviewer may see analysis aggregates but never student-level performance
        Sanctum::actingAs($this->reviewer);
        $d = $this->getJson("/api/analytics/courses/{$this->course->id}")->assertOk()->json('data');
        $this->assertEquals(84.0, collect($d['assessments'])->firstWhere('title', 'Midterm')['quality_score']);
        $this->assertFalse($d['performance']['available']);
        $this->assertTrue($d['scope']['student_data_restricted']);
        $this->assertSame('Restricted for your role', $d['kpis']['student_performance']['basis']);
        $this->assertSame([], $d['question_performance']);
        $this->assertFalse($d['grading']['available']);

        // Owner sees everything
        Sanctum::actingAs($this->faculty);
        $d = $this->getJson("/api/analytics/courses/{$this->course->id}/performance")->assertOk()->json('data');
        $this->assertTrue($d['performance']['available']);
        $this->assertArrayNotHasKey('assessment_quality', $d);
    }

    public function test_compare_history_and_ai_evaluation_summary(): void
    {
        $this->seedAnalysis();
        $this->seedGrades();
        $this->seedPerformanceRun();
        Assessment::create(['course_id' => $this->spring->id, 'title' => 'Spring Midterm', 'type' => 'midterm', 'total_marks' => 50, 'status' => 'completed', 'assessment_date' => '2026-03-01']);
        AnalysisReport::create(['assessment_id' => Assessment::where('title', 'Spring Midterm')->value('id'), 'analysis_version' => 1, 'is_current' => true, 'overall_score' => 76, 'analysis_status' => 'completed', 'analyzed_at' => now()]);
        $ds = AiEvaluationDataset::create(['name' => 'd', 'task' => 'DIFFICULTY_CLASSIFICATION', 'version' => 'v1', 'source' => 'FACULTY_VALIDATED', 'split' => 'TEST', 'status' => 'COMPLETED', 'created_by' => $this->faculty->id]);
        AiEvaluationRun::create(['dataset_id' => $ds->id, 'task' => 'DIFFICULTY_CLASSIFICATION', 'status' => 'COMPLETED', 'gate_status' => 'PASSED', 'example_count' => 40, 'processed_count' => 40, 'created_by' => $this->faculty->id, 'completed_at' => now(),
            'summary' => ['headline_metric' => 'macro_f1', 'headline_value' => 0.82, 'metrics' => ['macro_f1' => 0.82], 'gates' => [], 'warnings' => [], 'regression' => null, 'error_breakdown' => [], 'size_category' => 'LIMITED', 'limitations' => []]]);

        Sanctum::actingAs($this->faculty);
        $cmp = $this->getJson("/api/analytics/compare?assessment_ids[]={$this->midterm->id}&assessment_ids[]={$this->final->id}")->assertOk()->json('data');
        $this->assertEquals(2, $cmp['authorized']);
        $this->assertEquals(84.0, $cmp['assessments'][0]['quality_score']);
        $this->assertEquals(75.0, $cmp['assessments'][0]['performance']['average_percentage']);
        $this->assertEquals(58.0, $cmp['assessments'][1]['quality_score']);
        $this->assertFalse($cmp['assessments'][1]['performance']['available']);
        $this->assertEquals(1, $cmp['assessments'][0]['similarity']['POTENTIAL_DUPLICATE']['questions']);

        $h = $this->getJson("/api/analytics/courses/{$this->course->id}/history")->assertOk()->json('data');
        $this->assertSame('CSE101', $h['course_code']);
        $terms = collect($h['terms'])->keyBy('term');
        $this->assertEquals(76.0, $terms['2026 Spring']['average_quality']);
        $this->assertEquals(71.0, $terms['2026 Fall']['average_quality']);
        $this->assertEquals(75.0, $terms['2026 Fall']['average_performance']);

        $ai = $this->getJson('/api/analytics/overview')->json('data.ai_evaluation');
        $this->assertEquals(1, $ai['evaluated_tasks']);
        $tasks = collect($ai['tasks'])->keyBy('task');
        $this->assertEquals(0.82, $tasks['DIFFICULTY_CLASSIFICATION']['headline_value']);
        $this->assertFalse($tasks['BLOOM_CLASSIFICATION']['evaluated']);
        $this->assertNull($tasks['BLOOM_CLASSIFICATION']['headline_value']);
        $this->assertCount(1, $ai['trend']);
    }

    public function test_collaboration_rubric_summaries_and_exports(): void
    {
        $this->seedAnalysis();
        CollaborationComment::create(['course_id' => $this->course->id, 'user_id' => $this->reviewer->id, 'commentable_type' => 'assessment', 'commentable_id' => $this->midterm->id, 'body' => 'Private note', 'status' => 'ACTIVE']);
        CollaborationComment::create(['course_id' => $this->course->id, 'user_id' => $this->faculty->id, 'commentable_type' => 'assessment', 'commentable_id' => $this->midterm->id, 'body' => 'Done', 'status' => 'RESOLVED']);
        Rubric::create(['question_id' => $this->questions[0]->id, 'assessment_id' => $this->midterm->id, 'created_by' => $this->faculty->id, 'title' => 'R1', 'total_marks' => 10, 'status' => 'APPROVED', 'version' => 1, 'generation_method' => 'ai_assisted']);
        Rubric::create(['question_id' => $this->questions[1]->id, 'assessment_id' => $this->midterm->id, 'created_by' => $this->faculty->id, 'title' => 'R2', 'total_marks' => 10, 'status' => 'DRAFT', 'version' => 1, 'generation_method' => 'manual']);

        Sanctum::actingAs($this->faculty);
        $d = $this->getJson('/api/analytics/overview')->json('data');
        $this->assertEquals(1, $d['collaboration']['shared_courses']);
        $this->assertEquals(1, $d['collaboration']['active_collaborators']);
        $this->assertEquals(1, $d['collaboration']['open_discussions']);
        $this->assertEquals(1, $d['collaboration']['resolved_discussions']);
        $this->assertStringNotContainsString('Private note', json_encode($d));
        $this->assertEquals([2, 1, 1, 0], [$d['rubrics']['total'], $d['rubrics']['draft'], $d['rubrics']['approved'], $d['rubrics']['archived']]);

        $csv = $this->get('/api/analytics/export?format=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('difficulty,easy,30', $csv->getContent());
        $this->assertStringContainsString('learning_outcome,CO1,75', $csv->getContent());
        $pdf = $this->get('/api/analytics/export?format=pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->getJson('/api/analytics/export?format=json')->assertOk()->assertJsonPath('data.kpis.questions.value', 10);
        $this->assertEquals(3, \App\Models\AuditLog::where('action', 'ANALYTICS_EXPORTED')->count());
    }

    public function test_overview_is_cached_until_source_data_changes(): void
    {
        Sanctum::actingAs($this->faculty);
        $first = $this->getJson('/api/analytics/overview')->json('data.meta');
        $this->assertFalse($first['cached']);
        $second = $this->getJson('/api/analytics/overview')->json('data.meta');
        $this->assertTrue($second['cached']);
        $this->assertSame($first['generated_at'], $second['generated_at']);
        $this->questions[0]->update(['difficulty_level' => 'hard', 'updated_at' => now()->addSecond()]);
        $third = $this->getJson('/api/analytics/overview')->json('data.meta');
        $this->assertFalse($third['cached']);
        $this->assertFalse($this->getJson('/api/analytics/overview?fresh=1')->json('data.meta.cached'));
    }
}
