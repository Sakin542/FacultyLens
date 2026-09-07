<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssessmentQualityEngineTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;
    protected Course $course;
    protected Course $otherCourse;
    protected Assessment $assessment;
    protected LearningOutcome $lo1;
    protected LearningOutcome $lo2;
    protected Question $q1;
    protected Question $q2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->course = Course::create([
            'user_id' => $this->user->id,
            'course_code' => 'CSE-3101',
            'course_name' => 'Database Systems',
            'description' => 'Relational database theory and design.',
            'semester' => 'Fall',
            'academic_year' => '2026',
            'credits' => 3,
        ]);

        $this->otherCourse = Course::create([
            'user_id' => $this->otherUser->id,
            'course_code' => 'CSE-1101',
            'course_name' => 'Programming Fundamentals',
            'description' => 'Basic C/C++ programming.',
            'semester' => 'Fall',
            'academic_year' => '2026',
            'credits' => 3,
        ]);

        $this->assessment = Assessment::create([
            'course_id' => $this->course->id,
            'title' => 'Final Examination',
            'type' => 'Final',
            'total_marks' => 100,
            'duration_minutes' => 180,
            'status' => 'Published',
        ]);

        $this->lo1 = LearningOutcome::create([
            'course_id' => $this->course->id,
            'code' => 'CLO-1',
            'description' => 'Explain fundamental relational algebra and normal forms.',
            'cognitive_level' => 'Understand',
            'sort_order' => 1,
        ]);

        $this->lo2 = LearningOutcome::create([
            'course_id' => $this->course->id,
            'code' => 'CLO-2',
            'description' => 'Formulate complex queries in SQL and optimize execution plans.',
            'cognitive_level' => 'Apply',
            'sort_order' => 2,
        ]);

        CourseMaterial::create([
            'course_id' => $this->course->id,
            'title' => 'Relational Algebra',
            'description' => 'Unit 1 syllabus',
            'file_name' => 'unit1.pdf',
            'file_path' => 'materials/unit1.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 1024,
            'uploaded_by' => $this->user->id,
        ]);

        $this->q1 = Question::create([
            'assessment_id' => $this->assessment->id,
            'question_number' => 1,
            'question_text' => 'Explain relational algebra projection and selection operations.',
            'marks' => 40.0,
            'question_type' => 'Descriptive',
            'difficulty_level' => 'Easy',
            'cognitive_level' => 'Understand',
            'learning_outcome_id' => $this->lo1->id,
            'ai_topics' => ['Relational Algebra'],
        ]);

        $this->q2 = Question::create([
            'assessment_id' => $this->assessment->id,
            'question_number' => 2,
            'question_text' => 'Write SQL query with grouping and having clauses to aggregate sales.',
            'marks' => 60.0,
            'question_type' => 'Problem Solving',
            'difficulty_level' => 'Medium',
            'cognitive_level' => 'Apply',
            'learning_outcome_id' => $this->lo2->id,
            'ai_topics' => ['SQL Querying'],
        ]);
    }

    public function test_unauthenticated_user_cannot_access_quality_endpoints(): void
    {
        $response = $this->postJson('/api/ai/analyze-assessment-quality', [
            'questions' => [['text' => 'Sample question text?']],
        ]);

        $response->assertStatus(401);
    }

    public function test_validation_fails_on_empty_questions(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/ai/analyze-assessment-quality', [
            'questions' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['questions']);
    }

    public function test_direct_analyze_assessment_quality_success(): void
    {
        Http::fake([
            '*/api/v1/analyze-assessment-quality' => Http::response([
                'status' => 'success',
                'method' => 'assessment_quality_engine',
                'overall_quality_score' => 84.5,
                'rating' => 'GOOD',
                'weights_applied' => [
                    'topic' => 20.0,
                    'learning_outcome' => 20.0,
                    'difficulty' => 15.0,
                    'cognitive' => 15.0,
                    'question_diversity' => 15.0,
                    'marks' => 15.0,
                ],
                'excluded_components' => [],
                'components' => [
                    'topic_coverage' => 90.0,
                    'learning_outcome_coverage' => 85.0,
                    'difficulty_balance' => 80.0,
                    'cognitive_diversity' => 82.0,
                    'question_diversity' => 86.0,
                    'marks_distribution' => 84.0,
                ],
                'topic_analysis' => [
                    'status' => 'AVAILABLE',
                    'score' => 90.0,
                    'methodology' => 'Unweighted',
                    'total_topics_defined' => 2,
                    'covered_topics_count' => 2,
                    'topics' => [],
                ],
                'learning_outcome_analysis' => [
                    'status' => 'AVAILABLE',
                    'score' => 85.0,
                    'methodology' => 'Unweighted',
                    'total_los_defined' => 2,
                    'covered_los_count' => 2,
                    'learning_outcomes' => [],
                ],
                'difficulty_analysis' => [
                    'status' => 'AVAILABLE',
                    'score' => 80.0,
                    'methodology' => 'Deviation',
                    'total_deviation' => 40.0,
                    'distribution' => [],
                ],
                'cognitive_analysis' => [
                    'status' => 'AVAILABLE',
                    'score' => 82.0,
                    'methodology' => 'Shannon entropy',
                    'shannon_entropy' => 1.45,
                    'max_possible_entropy' => 1.79,
                    'dominant_level' => 'Understand',
                    'dominant_percentage' => 40.0,
                    'distribution' => [],
                ],
                'question_diversity_analysis' => [
                    'status' => 'AVAILABLE',
                    'score' => 86.0,
                    'methodology' => 'Shannon entropy',
                    'unique_types_count' => 2,
                    'shannon_entropy' => 1.2,
                    'dominant_type' => 'Descriptive',
                    'dominant_percentage' => 50.0,
                    'distribution' => [],
                ],
                'marks_analysis' => [
                    'status' => 'AVAILABLE',
                    'score' => 84.0,
                    'methodology' => 'Summation and concentration checks',
                    'total_question_marks' => 100.0,
                    'assessment_expected_marks' => 100.0,
                    'marks_match_assessment' => true,
                    'average_marks' => 50.0,
                    'min_marks' => 40.0,
                    'max_marks' => 60.0,
                    'median_marks' => 50.0,
                    'high_concentration_detected' => false,
                    'highest_single_question_share' => 60.0,
                    'highest_single_question_number' => 2,
                    'marks_by_topic' => [],
                    'marks_by_lo' => [],
                    'marks_by_difficulty' => [],
                    'marks_by_cognitive' => [],
                ],
                'findings' => ['Good overall assessment coverage.'],
            ], 200),
        ]);

        $payload = [
            'assessment' => [
                'id' => 1,
                'title' => 'Sample Midterm Exam',
                'total_marks' => 100.0,
            ],
            'questions' => [
                [
                    'text' => 'Define relational schemas and describe primary keys.',
                    'marks' => 20.0,
                    'question_type' => 'Descriptive',
                    'difficulty' => 'Easy',
                    'cognitive_level' => 'Remember',
                    'topics' => ['Relational Model'],
                    'learning_outcome_code' => 'CLO-1',
                ],
                [
                    'text' => 'Apply 3NF normalization to decompose the given relation.',
                    'marks' => 30.0,
                    'question_type' => 'Problem Solving',
                    'difficulty' => 'Medium',
                    'cognitive_level' => 'Apply',
                    'topics' => ['Normalization'],
                    'learning_outcome_code' => 'CLO-2',
                ],
            ],
            'topics' => [
                ['name' => 'Relational Model'],
                ['name' => 'Normalization'],
            ],
            'learning_outcomes' => [
                ['code' => 'CLO-1', 'description' => 'Understand relational models'],
                ['code' => 'CLO-2', 'description' => 'Apply normalization rules'],
            ],
        ];

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/ai/analyze-assessment-quality', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'status',
                    'method',
                    'overall_quality_score',
                    'rating',
                    'components' => [
                        'topic_coverage',
                        'learning_outcome_coverage',
                        'difficulty_balance',
                        'cognitive_diversity',
                        'question_diversity',
                        'marks_distribution',
                    ],
                    'findings',
                ],
            ]);

        $this->assertEquals('success', $response->json('status'));
        $this->assertEquals(84.5, $response->json('data.overall_quality_score'));
    }

    public function test_faculty_cannot_analyze_other_faculty_assessment_quality(): void
    {
        $response = $this->actingAs($this->otherUser, 'sanctum')
            ->postJson("/api/ai/assessments/{$this->assessment->id}/analyze-quality");

        $response->assertStatus(403);
    }

    public function test_analyze_assessment_quality_persists_to_database(): void
    {
        Http::fake([
            '*/api/v1/analyze-assessment-quality' => Http::response([
                'status' => 'success',
                'method' => 'assessment_quality_engine',
                'overall_quality_score' => 86.0,
                'rating' => 'GOOD',
                'weights_applied' => ['topic' => 20.0, 'lo' => 20.0],
                'excluded_components' => [],
                'components' => [
                    'topic_coverage' => 100.0,
                    'learning_outcome_coverage' => 100.0,
                    'difficulty_balance' => 80.0,
                    'cognitive_diversity' => 75.0,
                    'question_diversity' => 85.0,
                    'marks_distribution' => 90.0,
                ],
                'topic_analysis' => ['status' => 'AVAILABLE', 'score' => 100.0],
                'learning_outcome_analysis' => ['status' => 'AVAILABLE', 'score' => 100.0],
                'difficulty_analysis' => ['status' => 'AVAILABLE', 'score' => 80.0],
                'cognitive_analysis' => ['status' => 'AVAILABLE', 'score' => 75.0],
                'question_diversity_analysis' => ['status' => 'AVAILABLE', 'score' => 85.0],
                'marks_analysis' => ['status' => 'AVAILABLE', 'score' => 90.0],
                'findings' => ['Topic coverage is complete.', 'All LOs assessed.'],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/ai/assessments/{$this->assessment->id}/analyze-quality");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'quality' => [
                        'overall_quality_score',
                        'rating',
                        'components',
                        'findings',
                    ],
                    'report' => [
                        'id',
                        'assessment_id',
                        'overall_score',
                        'topic_coverage_score',
                        'learning_outcome_alignment_score',
                        'difficulty_balance_score',
                        'cognitive_level_balance_score',
                        'findings',
                    ],
                ],
            ]);

        // Check report in DB
        $this->assertDatabaseHas('analysis_reports', [
            'assessment_id' => $this->assessment->id,
            'analysis_status' => 'completed',
        ]);

        $report = AnalysisReport::where('assessment_id', $this->assessment->id)->first();
        $this->assertEquals(86.0, (float) $report->overall_score);
        $this->assertEquals(100.0, (float) $report->topic_coverage_score);
        $this->assertEquals(100.0, (float) $report->learning_outcome_alignment_score);
        $this->assertEquals(80.0, (float) $report->difficulty_balance_score);
        $this->assertEquals(75.0, (float) $report->cognitive_level_balance_score);
        $this->assertArrayHasKey('quality_engine', $report->findings);
    }
}

