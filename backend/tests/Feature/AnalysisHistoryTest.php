<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalysisHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $facultyA;
    protected User $facultyB;
    protected Course $courseA;
    protected Course $courseB;
    protected Assessment $assessmentA;
    protected Assessment $assessmentB;
    protected AnalysisReport $analysisReportV1;
    protected AnalysisReport $analysisReportV2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facultyA = User::factory()->create([
            'name' => 'Dr. Alan Turing',
            'email' => 'turing@cambridge.edu',
            'department' => 'Computer Science',
        ]);

        $this->facultyB = User::factory()->create([
            'name' => 'Dr. Ada Lovelace',
            'email' => 'lovelace@oxford.edu',
            'department' => 'Mathematics',
        ]);

        $this->courseA = Course::create([
            'user_id' => $this->facultyA->id,
            'course_code' => 'CS-301',
            'course_name' => 'Theory of Computation',
            'semester' => 'Fall',
            'academic_year' => '2026',
        ]);

        $this->courseB = Course::create([
            'user_id' => $this->facultyB->id,
            'course_code' => 'MATH-101',
            'course_name' => 'Calculus I',
            'semester' => 'Fall',
            'academic_year' => '2026',
        ]);

        $this->assessmentA = Assessment::create([
            'course_id' => $this->courseA->id,
            'title' => 'Midterm Examination 2026',
            'type' => 'midterm',
            'total_marks' => 50,
            'duration_minutes' => 90,
            'assessment_date' => '2026-10-15',
            'status' => 'draft',
        ]);

        $this->assessmentB = Assessment::create([
            'course_id' => $this->courseB->id,
            'title' => 'Calculus Quiz 1',
            'type' => 'quiz',
            'total_marks' => 20,
            'duration_minutes' => 30,
            'assessment_date' => '2026-09-20',
            'status' => 'draft',
        ]);

        // Create Analysis Version 1 for assessmentA
        $this->analysisReportV1 = AnalysisReport::create([
            'assessment_id' => $this->assessmentA->id,
            'analysis_version' => 1,
            'is_current' => false,
            'overall_score' => 75.00,
            'topic_coverage_score' => 70.00,
            'learning_outcome_alignment_score' => 80.00,
            'difficulty_balance_score' => 72.00,
            'cognitive_level_balance_score' => 74.00,
            'similarity_score' => 25.00,
            'total_questions' => 2,
            'similar_questions_count' => 1,
            'analysis_status' => 'completed',
            'analyzed_at' => now()->subDays(5),
            'findings' => [
                'quality_analysis' => [
                    'overall_quality_score' => 75.0,
                    'rating' => 'ACCEPTABLE',
                    'topic_coverage_score' => 70.0,
                    'learning_outcome_alignment_score' => 80.0,
                    'difficulty_balance_score' => 72.0,
                    'cognitive_level_balance_score' => 74.0,
                    'question_diversity_score' => 76.0,
                    'marks_distribution_score' => 78.0,
                    'findings' => [
                        [
                            'severity' => 'warning',
                            'category' => 'Topic Coverage',
                            'problem' => 'Limited topic coverage',
                            'evidence' => 'Only 1 of 3 topics covered',
                            'explanation' => 'Consider adding questions from other modules.',
                        ],
                    ],
                ],
            ],
        ]);

        // Create Analysis Version 2 for assessmentA
        $this->analysisReportV2 = AnalysisReport::create([
            'assessment_id' => $this->assessmentA->id,
            'analysis_version' => 2,
            'is_current' => true,
            'overall_score' => 88.50,
            'topic_coverage_score' => 85.00,
            'learning_outcome_alignment_score' => 92.00,
            'difficulty_balance_score' => 84.00,
            'cognitive_level_balance_score' => 86.00,
            'similarity_score' => 10.00,
            'total_questions' => 3,
            'similar_questions_count' => 0,
            'analysis_status' => 'completed',
            'analyzed_at' => now()->subDay(),
            'findings' => [
                'quality_analysis' => [
                    'overall_quality_score' => 88.5,
                    'rating' => 'GOOD',
                    'topic_coverage_score' => 85.0,
                    'learning_outcome_alignment_score' => 92.0,
                    'difficulty_balance_score' => 84.0,
                    'cognitive_level_balance_score' => 86.0,
                    'question_diversity_score' => 88.0,
                    'marks_distribution_score' => 90.0,
                    'findings' => [
                        [
                            'severity' => 'info',
                            'category' => 'Balance',
                            'problem' => 'Good balance achieved',
                            'evidence' => 'All modules represented',
                            'explanation' => 'Assessment meets department standards.',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_faculty_can_list_analysis_history(): void
    {
        Sanctum::actingAs($this->facultyA);

        $response = $this->getJson('/api/analysis/history');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'assessment_id',
                        'analysis_version',
                        'is_current',
                        'overall_score',
                        'analysis_status',
                        'analyzed_at',
                        'assessment' => [
                            'id',
                            'title',
                            'type',
                        ],
                        'course' => [
                            'id',
                            'course_code',
                            'course_name',
                            'academic_year',
                            'semester',
                        ],
                        'has_report',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'total',
                    'per_page',
                ],
            ]);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals(2, $response->json('meta.total'));
    }

    public function test_history_filters_by_course_id(): void
    {
        Sanctum::actingAs($this->facultyA);

        $response = $this->getJson('/api/analysis/history?course_id=' . $this->courseA->id);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));

        // Non-existent course filter for facultyA
        $responseEmpty = $this->getJson('/api/analysis/history?course_id=9999');
        $responseEmpty->assertStatus(200);
        $this->assertCount(0, $responseEmpty->json('data'));
    }

    public function test_history_filters_by_assessment_type(): void
    {
        Sanctum::actingAs($this->facultyA);

        $responseMidterm = $this->getJson('/api/analysis/history?assessment_type=midterm');
        $responseMidterm->assertStatus(200);
        $this->assertCount(2, $responseMidterm->json('data'));

        $responseQuiz = $this->getJson('/api/analysis/history?assessment_type=quiz');
        $responseQuiz->assertStatus(200);
        $this->assertCount(0, $responseQuiz->json('data'));
    }

    public function test_history_filters_by_search(): void
    {
        Sanctum::actingAs($this->facultyA);

        $response = $this->getJson('/api/analysis/history?search=Midterm');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));

        $responseNoMatch = $this->getJson('/api/analysis/history?search=NonExistentTitle');
        $responseNoMatch->assertStatus(200);
        $this->assertCount(0, $responseNoMatch->json('data'));
    }

    public function test_history_filters_by_academic_year_and_semester(): void
    {
        Sanctum::actingAs($this->facultyA);

        $response = $this->getJson('/api/analysis/history?academic_year=2026&semester=Fall');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));

        $responseMismatch = $this->getJson('/api/analysis/history?academic_year=2024');
        $responseMismatch->assertStatus(200);
        $this->assertCount(0, $responseMismatch->json('data'));
    }

    public function test_faculty_cannot_see_other_faculty_analyses_in_history(): void
    {
        // Faculty B has courseB and assessmentB, create an analysis for assessmentB
        AnalysisReport::create([
            'assessment_id' => $this->assessmentB->id,
            'analysis_version' => 1,
            'is_current' => true,
            'overall_score' => 90.00,
            'analysis_status' => 'completed',
            'analyzed_at' => now(),
        ]);

        Sanctum::actingAs($this->facultyB);

        $response = $this->getJson('/api/analysis/history');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($this->assessmentB->id, $response->json('data.0.assessment_id'));
    }

    public function test_faculty_can_get_assessment_analysis_history_versions(): void
    {
        Sanctum::actingAs($this->facultyA);

        $response = $this->getJson('/api/assessments/' . $this->assessmentA->id . '/analysis-history');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'success',
                'data' => [
                    'assessment_id',
                    'assessment_title',
                    'course_code',
                    'current_analysis_version',
                    'total_versions',
                    'history' => [
                        '*' => [
                            'id',
                            'version',
                            'is_current',
                            'overall_score',
                            'analyzed_at',
                        ],
                    ],
                ],
            ]);

        $this->assertEquals(2, $response->json('data.total_versions'));
        // Verify ordered by version desc
        $this->assertEquals(2, $response->json('data.history.0.version'));
        $this->assertEquals(1, $response->json('data.history.1.version'));
    }

    public function test_faculty_can_view_single_analysis_historical_details(): void
    {
        Sanctum::actingAs($this->facultyA);

        $response = $this->getJson('/api/analysis/' . $this->analysisReportV1->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'success',
                'data' => [
                    'analysis_id',
                    'analysis_version',
                    'is_current',
                    'assessment' => [
                        'id',
                        'title',
                        'course_code',
                    ],
                    'overall_quality' => [
                        'score',
                        'rating',
                        'dimensions',
                    ],
                    'findings',
                    'recommendations',
                    'analyzed_at',
                ],
            ]);

        $this->assertEquals($this->analysisReportV1->id, $response->json('data.analysis_id'));
        $this->assertEquals(1, $response->json('data.analysis_version'));
        $this->assertFalse($response->json('data.is_current'));
        $this->assertEquals(75.0, (float) $response->json('data.overall_quality.score'));
    }

    public function test_faculty_cannot_view_other_faculty_analysis_details(): void
    {
        Sanctum::actingAs($this->facultyB);

        $response = $this->getJson('/api/analysis/' . $this->analysisReportV1->id);
        $response->assertStatus(403);
    }

    public function test_faculty_can_compare_two_analyses(): void
    {
        Sanctum::actingAs($this->facultyA);

        $response = $this->getJson(
            '/api/analysis/compare?left_analysis_id=' . $this->analysisReportV1->id .
            '&right_analysis_id=' . $this->analysisReportV2->id
        );

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'success',
                'data' => [
                    'is_same_assessment',
                    'context_notice',
                    'left' => ['id', 'version', 'overall_score'],
                    'right' => ['id', 'version', 'overall_score'],
                    'changes' => [
                        'overall_score',
                        'topic_coverage_score',
                        'learning_outcome_alignment_score',
                        'difficulty_balance_score',
                        'cognitive_level_balance_score',
                        'similarity_score',
                        'question_diversity_score',
                    ],
                    'interpretations',
                ],
            ]);

        // Difference: 88.5 - 75.0 = 13.5
        $this->assertEquals(13.5, $response->json('data.changes.overall_score'));
        $this->assertStringContainsString('The quality indicator increased by 13.5 points', $response->json('data.interpretations.0'));
    }

    public function test_comparison_rejects_identical_analysis_ids(): void
    {
        Sanctum::actingAs($this->facultyA);

        $response = $this->getJson(
            '/api/analysis/compare?left_analysis_id=' . $this->analysisReportV1->id .
            '&right_analysis_id=' . $this->analysisReportV1->id
        );

        $response->assertStatus(422)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_comparison_rejects_unauthorized_analyses(): void
    {
        Sanctum::actingAs($this->facultyB);

        $response = $this->getJson(
            '/api/analysis/compare?left_analysis_id=' . $this->analysisReportV1->id .
            '&right_analysis_id=' . $this->analysisReportV2->id
        );

        $response->assertStatus(403);
    }

    public function test_faculty_can_fetch_trend_data(): void
    {
        Sanctum::actingAs($this->facultyA);

        $response = $this->getJson('/api/analysis/trends?course_id=' . $this->courseA->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'success',
                'data' => [
                    'course' => [
                        'id',
                        'code',
                        'name',
                    ],
                    'total_data_points',
                    'series' => [
                        '*' => [
                            'analysis_id',
                            'assessment_id',
                            'assessment_title',
                            'version',
                            'analyzed_at',
                            'overall_score',
                            'topic_coverage_score',
                            'learning_outcome_alignment_score',
                            'difficulty_balance_score',
                            'cognitive_level_balance_score',
                        ],
                    ],
                    'summary_text',
                ],
            ]);

        $this->assertEquals(2, $response->json('data.total_data_points'));
    }

    public function test_faculty_can_fetch_improvement_summary(): void
    {
        Sanctum::actingAs($this->facultyA);

        $response = $this->getJson('/api/analysis/improvement-summary?course_id=' . $this->courseA->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'success',
                'data' => [
                    'has_sufficient_data',
                    'baseline_date',
                    'latest_date',
                    'improvements' => [
                        'overall_score',
                        'topic_coverage',
                        'learning_outcome_alignment',
                        'difficulty_balance',
                        'cognitive_diversity',
                    ],
                ],
            ]);

        $this->assertTrue($response->json('data.has_sufficient_data'));
        $this->assertEquals(13.5, $response->json('data.improvements.overall_score'));
    }

    public function test_rerunning_analysis_creates_new_version_and_updates_previous_current_flag(): void
    {
        Sanctum::actingAs($this->facultyA);

        // Current max version for assessmentA is 2 (is_current = true)
        $this->assertTrue($this->analysisReportV2->fresh()->is_current);
        $this->assertEquals(2, $this->analysisReportV2->analysis_version);

        // Re-run unified analysis by creating report version 3 (mimicking controller persistence)
        $analysisReportV3 = AnalysisReport::create([
            'assessment_id' => $this->assessmentA->id,
            'analysis_version' => 3,
            'is_current' => true,
            'overall_score' => 91.00,
            'analysis_status' => 'completed',
            'analyzed_at' => now(),
        ]);

        // When v3 is set to current, v2 is marked as not current
        AnalysisReport::where('assessment_id', $this->assessmentA->id)
            ->where('id', '!=', $analysisReportV3->id)
            ->update(['is_current' => false]);

        $this->assertFalse($this->analysisReportV2->fresh()->is_current);
        $this->assertFalse($this->analysisReportV1->fresh()->is_current);
        $this->assertTrue($analysisReportV3->fresh()->is_current);
        $this->assertEquals(3, $analysisReportV3->analysis_version);

        // Verify history endpoint reflects 3 versions
        $response = $this->getJson('/api/assessments/' . $this->assessmentA->id . '/analysis-history');
        $response->assertStatus(200);
        $this->assertEquals(3, $response->json('data.total_versions'));
        $this->assertEquals(3, $response->json('data.current_analysis_version'));
        $this->assertEquals(3, $response->json('data.history.0.version'));
        $this->assertTrue($response->json('data.history.0.is_current'));
        $this->assertFalse($response->json('data.history.1.is_current'));
    }
}

