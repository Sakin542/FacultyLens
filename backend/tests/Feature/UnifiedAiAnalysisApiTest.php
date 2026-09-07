<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\PreviousQuestion;
use App\Models\Question;
use App\Models\QuestionSimilarityMatch;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnifiedAiAnalysisApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $facultyA;
    protected User $facultyB;
    protected Course $courseA;
    protected Assessment $assessmentA;
    protected Question $q1;
    protected Question $q2;
    protected LearningOutcome $lo1;
    protected PreviousQuestion $prevQ1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facultyA = User::factory()->create([
            'email' => 'facultya@university.edu',
            'department' => 'Computer Science',
        ]);

        $this->facultyB = User::factory()->create([
            'email' => 'facultyb@university.edu',
            'department' => 'Data Science',
        ]);

        $this->courseA = Course::create([
            'user_id' => $this->facultyA->id,
            'course_code' => 'CSE-301',
            'course_name' => 'Database Systems',
            'semester' => 'Fall',
            'academic_year' => '2026',
        ]);

        $this->assessmentA = Assessment::create([
            'course_id' => $this->courseA->id,
            'title' => 'Midterm Exam 2026',
            'type' => 'midterm',
            'total_marks' => 20,
            'status' => 'draft',
        ]);

        $this->lo1 = LearningOutcome::create([
            'course_id' => $this->courseA->id,
            'code' => 'LO1',
            'description' => 'Understand relational database indexing and normalization.',
        ]);

        $this->q1 = Question::create([
            'assessment_id' => $this->assessmentA->id,
            'question_number' => 1,
            'question_text' => 'Explain the structure of B+ trees.',
            'marks' => 10,
            'question_type' => 'descriptive',
        ]);

        $this->q2 = Question::create([
            'assessment_id' => $this->assessmentA->id,
            'question_number' => 2,
            'question_text' => 'Design an ER diagram for a hospital management system.',
            'marks' => 10,
            'question_type' => 'problem_solving',
        ]);

        $this->prevQ1 = PreviousQuestion::create([
            'user_id' => $this->facultyA->id,
            'course_id' => $this->courseA->id,
            'question_number' => 1,
            'question_text' => 'Describe how B+ trees index disk blocks.',
            'source_exam_name' => 'Midterm 2025',
            'academic_year' => '2025',
        ]);
    }

    public function test_unauthenticated_request_is_rejected()
    {
        $response = $this->postJson('/api/ai/analyze-assessment', [
            'assessment_id' => $this->assessmentA->id,
        ]);

        $response->assertStatus(401);
    }

    public function test_multi_tenant_isolation_prevents_unauthorized_analysis()
    {
        Sanctum::actingAs($this->facultyB);

        $response = $this->postJson('/api/ai/analyze-assessment', [
            'assessment_id' => $this->assessmentA->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_successful_unified_assessment_analysis_and_persistence()
    {
        Sanctum::actingAs($this->facultyA);

        $mockResponse = [
            'status' => 'success',
            'method' => 'unified_assessment_analysis_pipeline',
            'assessment_id' => $this->assessmentA->id,
            'course_id' => $this->courseA->id,
            'questions_analysis' => [
                'total_questions' => 2,
                'questions' => [
                    [
                        'number' => 1,
                        'text' => 'Explain the structure of B+ trees.',
                        'cognitive_level' => ['level' => 'Understand', 'score' => 0.85],
                        'difficulty' => ['level' => 'Medium', 'score' => 0.6],
                        'classification' => ['type' => 'Descriptive'],
                        'topics' => [['name' => 'Indexing', 'confidence' => 0.9]],
                    ],
                    [
                        'number' => 2,
                        'text' => 'Design an ER diagram for a hospital management system.',
                        'cognitive_level' => ['level' => 'Create', 'score' => 0.9],
                        'difficulty' => ['level' => 'Hard', 'score' => 0.8],
                        'classification' => ['type' => 'Problem Solving'],
                        'topics' => [['name' => 'ER Modeling', 'confidence' => 0.9]],
                    ],
                ],
                'summary' => [],
            ],
            'alignment_analysis' => [
                'status' => 'success',
                'overall_alignment_score' => 88.5,
                'coverage_percentage' => 100.0,
                'question_alignment' => [],
                'learning_outcome_coverage' => [],
            ],
            'similarity_analysis' => [
                'status' => 'success',
                'overall_similarity_score' => 0.72,
                'potential_duplicates_count' => 0,
                'matches' => [
                    [
                        'current_question_number' => 1,
                        'matches' => [
                            [
                                'previous_question_id' => $this->prevQ1->id,
                                'similarity_score' => 0.82,
                                'similarity_status' => 'HIGHLY_SIMILAR',
                            ]
                        ],
                    ]
                ],
            ],
            'quality_analysis' => [
                'status' => 'success',
                'overall_quality_score' => 85.0,
                'rating' => 'EXCELLENT',
                'findings' => ['Balanced cognitive diversity.'],
                'components' => [],
            ],
            'recommendations' => [
                'status' => 'success',
                'total_recommendations' => 1,
                'high_priority_count' => 0,
                'medium_priority_count' => 1,
                'low_priority_count' => 0,
                'recommendations' => [
                    [
                        'category' => 'semantic_similarity',
                        'problem' => 'High similarity detected with Question 1 of 2025 Midterm.',
                        'explanation' => 'Question 1 is 82% similar to a question asked in 2025.',
                        'recommendation' => 'Consider varying the question phrasing or parameters.',
                        'priority' => 'MEDIUM',
                        'source_metric' => 'semantic_similarity',
                        'evidence' => ['similarity_score' => 0.82],
                    ]
                ],
            ],
            'summary' => [
                'total_questions' => 2,
                'overall_quality_score' => 85.0,
                'quality_rating' => 'EXCELLENT',
                'lo_coverage_percentage' => 100.0,
                'total_recommendations' => 1,
            ],
        ];

        Http::fake([
            '*/api/v1/analyze-assessment' => Http::response($mockResponse, 200),
        ]);

        $response = $this->postJson('/api/ai/analyze-assessment', [
            'assessment_id' => $this->assessmentA->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.summary.overall_quality_score', 85);

        // Verify Database Persistence
        $this->assertDatabaseHas('analysis_reports', [
            'assessment_id' => $this->assessmentA->id,
            'overall_score' => 85.0,
            'analysis_status' => 'completed',
        ]);

        $this->assertDatabaseHas('recommendations', [
            'problem' => 'High similarity detected with Question 1 of 2025 Midterm.',
            'priority' => 'medium',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('question_similarity_matches', [
            'current_question_id' => $this->q1->id,
            'previous_question_id' => $this->prevQ1->id,
            'similarity_status' => 'HIGHLY_SIMILAR',
        ]);

        // Verify question fields updated
        $this->q1->refresh();
        $this->assertEquals('Understand', $this->q1->ai_cognitive_level);
        $this->assertEquals('Medium', $this->q1->ai_difficulty_level);
    }

    public function test_analyze_assessment_by_route_endpoint()
    {
        Sanctum::actingAs($this->facultyA);

        $mockResponse = [
            'status' => 'success',
            'method' => 'unified_assessment_analysis_pipeline',
            'assessment_id' => $this->assessmentA->id,
            'quality_analysis' => [
                'overall_quality_score' => 90.0,
            ],
            'summary' => [
                'overall_quality_score' => 90.0,
            ],
            'recommendations' => ['recommendations' => []],
        ];

        Http::fake([
            '*/api/v1/analyze-assessment' => Http::response($mockResponse, 200),
        ]);

        $response = $this->postJson("/api/ai/assessments/{$this->assessmentA->id}/analyze");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
    }

    public function test_step15_api_aliases()
    {
        Sanctum::actingAs($this->facultyA);

        Http::fake([
            '*/api/v1/analyze-questions' => Http::response([
                'status' => 'success',
                'total_questions' => 1,
                'questions' => [],
                'summary' => [],
            ], 200),
            '*/api/v1/analyze-similarity' => Http::response([
                'status' => 'success',
                'overall_similarity_score' => 0.5,
                'question_similarities' => [],
            ], 200),
            '*/api/v1/analyze-alignment' => Http::response([
                'status' => 'success',
                'overall_alignment_score' => 80.0,
                'aligned_questions_count' => 1,
                'total_questions' => 1,
                'total_learning_outcomes' => 1,
                'covered_learning_outcomes_count' => 1,
                'question_alignment' => [],
                'learning_outcome_coverage' => [],
                'findings' => [],
                'thresholds' => ['strong' => 0.7, 'weak' => 0.5],
            ], 200),
        ]);

        // 1. Question analysis alias
        $resp1 = $this->postJson('/api/ai/question-analysis', [
            'questions' => [
                ['number' => 1, 'text' => 'What is dynamic programming?'],
            ],
        ]);
        $resp1->assertStatus(200);

        // 2. Similarity analysis alias
        $resp2 = $this->postJson('/api/ai/similarity-analysis', [
            'current_questions' => [
                ['number' => 1, 'text' => 'What is dynamic programming?'],
            ],
            'previous_questions' => [],
        ]);
        $resp2->assertStatus(200);

        // 3. Alignment analysis alias
        $resp3 = $this->postJson('/api/ai/alignment-analysis', [
            'questions' => [
                ['number' => 1, 'text' => 'What is dynamic programming?'],
            ],
            'learning_outcomes' => [
                ['code' => 'LO1', 'description' => 'Understand algorithmic paradigms.'],
            ],
        ]);
        $resp3->assertStatus(200);
    }
}
