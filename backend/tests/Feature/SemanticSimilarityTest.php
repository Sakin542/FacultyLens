<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\PreviousQuestion;
use App\Models\Question;
use App\Models\QuestionSimilarityMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SemanticSimilarityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;
    protected Course $course;
    protected Course $otherCourse;
    protected Assessment $assessment;
    protected Question $q1;
    protected Question $q2;
    protected PreviousQuestion $pq1;
    protected PreviousQuestion $pq2;

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

        $this->q1 = Question::create([
            'assessment_id' => $this->assessment->id,
            'question_number' => 1,
            'question_text' => 'Explain the advantages of database normalization.',
            'marks' => 10,
        ]);

        $this->q2 = Question::create([
            'assessment_id' => $this->assessment->id,
            'question_number' => 2,
            'question_text' => 'Apply Dijkstra algorithm to calculate shortest paths.',
            'marks' => 15,
        ]);

        $this->pq1 = PreviousQuestion::create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'question_text' => 'Describe the benefits and importance of database normalization.',
            'source_year' => 2025,
            'source_assessment' => 'Final Examination',
        ]);

        $this->pq2 = PreviousQuestion::create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'question_text' => 'Explain ACID properties in transaction processing.',
            'source_year' => 2024,
            'source_assessment' => 'Midterm Examination',
        ]);
    }

    public function test_unauthenticated_user_cannot_access_similarity_endpoints(): void
    {
        $response = $this->postJson('/api/ai/analyze-similarity', [
            'current_questions' => [['text' => 'Some Question']],
        ]);

        $response->assertStatus(401);
    }

    public function test_validation_fails_on_empty_current_questions(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/ai/analyze-similarity', [
            'current_questions' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_questions']);
    }

    public function test_direct_analyze_similarity_success(): void
    {
        Http::fake([
            '*/api/v1/analyze-similarity' => Http::response([
                'status' => 'success',
                'method' => 'semantic_embedding_cosine_similarity',
                'model' => 'sentence-transformers/all-MiniLM-L6-v2',
                'thresholds' => [
                    'potential_duplicate' => 0.85,
                    'high_similarity' => 0.70,
                    'moderate_similarity' => 0.50,
                ],
                'total_current_questions' => 1,
                'total_previous_questions' => 1,
                'potential_duplicates_count' => 1,
                'highly_similar_count' => 0,
                'somewhat_similar_count' => 0,
                'average_similarity_score' => 91.5,
                'results' => [
                    [
                        'current_question_id' => 1,
                        'current_question_number' => 1,
                        'current_question_text' => 'Explain advantages of normalization.',
                        'max_similarity_score' => 0.915,
                        'max_similarity_status' => 'POTENTIAL_DUPLICATE',
                        'matches' => [
                            [
                                'previous_question_id' => 101,
                                'previous_question_text' => 'Describe benefits of normalization.',
                                'similarity_score' => 0.915,
                                'similarity_status' => 'POTENTIAL_DUPLICATE',
                                'source_year' => 2025,
                                'source_assessment' => 'Final Exam',
                            ]
                        ],
                        'reasoning' => 'Potential duplicate detected (91.5%).',
                    ]
                ],
                'findings' => ['Potential duplicate detected for Q1.'],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/ai/analyze-similarity', [
            'current_questions' => [
                ['id' => 1, 'number' => 1, 'text' => 'Explain advantages of normalization.'],
            ],
            'previous_questions' => [
                ['id' => 101, 'text' => 'Describe benefits of normalization.', 'source_year' => 2025],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.potential_duplicates_count', 1)
            ->assertJsonPath('data.results.0.max_similarity_status', 'POTENTIAL_DUPLICATE');
    }

    public function test_faculty_cannot_analyze_other_faculty_assessment_similarity(): void
    {
        $response = $this->actingAs($this->otherUser, 'sanctum')->postJson(
            "/api/ai/assessments/{$this->assessment->id}/analyze-similarity"
        );

        $response->assertStatus(403);
    }

    public function test_analyze_assessment_similarity_persists_to_database(): void
    {
        Http::fake([
            '*/api/v1/analyze-similarity' => Http::response([
                'status' => 'success',
                'method' => 'semantic_embedding_cosine_similarity',
                'model' => 'sentence-transformers/all-MiniLM-L6-v2',
                'thresholds' => [
                    'potential_duplicate' => 0.85,
                    'high_similarity' => 0.70,
                    'moderate_similarity' => 0.50,
                ],
                'total_current_questions' => 2,
                'total_previous_questions' => 2,
                'potential_duplicates_count' => 1,
                'highly_similar_count' => 0,
                'somewhat_similar_count' => 0,
                'average_similarity_score' => 65.0,
                'results' => [
                    [
                        'current_question_id' => $this->q1->id,
                        'current_question_number' => 1,
                        'current_question_text' => $this->q1->question_text,
                        'max_similarity_score' => 0.92,
                        'max_similarity_status' => 'POTENTIAL_DUPLICATE',
                        'matches' => [
                            [
                                'previous_question_id' => $this->pq1->id,
                                'previous_question_text' => $this->pq1->question_text,
                                'similarity_score' => 0.92,
                                'similarity_status' => 'POTENTIAL_DUPLICATE',
                                'source_year' => 2025,
                                'source_assessment' => 'Final Examination',
                            ]
                        ],
                        'reasoning' => 'Potential duplicate detected with 2025 exam question.',
                    ],
                    [
                        'current_question_id' => $this->q2->id,
                        'current_question_number' => 2,
                        'current_question_text' => $this->q2->question_text,
                        'max_similarity_score' => 0.38,
                        'max_similarity_status' => 'NOT_SIMILAR',
                        'matches' => [
                            [
                                'previous_question_id' => $this->pq2->id,
                                'previous_question_text' => $this->pq2->question_text,
                                'similarity_score' => 0.38,
                                'similarity_status' => 'NOT_SIMILAR',
                                'source_year' => 2024,
                                'source_assessment' => 'Midterm Examination',
                            ]
                        ],
                        'reasoning' => 'Novel content item.',
                    ]
                ],
                'findings' => [
                    'Potential duplicate detected between Q1 and 2025 Final Examination question.',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/ai/assessments/{$this->assessment->id}/analyze-similarity"
        );

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.similarity.potential_duplicates_count', 1)
            ->assertJsonPath('data.report.assessment_id', $this->assessment->id);

        $this->assertDatabaseHas('analysis_reports', [
            'assessment_id' => $this->assessment->id,
            'similarity_score' => 65.00,
            'similar_questions_count' => 1,
            'analysis_status' => 'completed',
        ]);

        $this->assertDatabaseHas('question_similarity_matches', [
            'current_question_id' => $this->q1->id,
            'previous_question_id' => $this->pq1->id,
            'similarity_status' => 'POTENTIAL_DUPLICATE',
        ]);
    }
}

