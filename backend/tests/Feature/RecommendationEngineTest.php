<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationEngineTest extends TestCase
{
    use RefreshDatabase;

    protected User $facultyA;
    protected User $facultyB;
    protected Course $courseA;
    protected Assessment $assessmentA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facultyA = User::factory()->create([
            'email' => 'facultyA@university.edu',
            'department' => 'Computer Science',
            'designation' => 'Associate Professor',
        ]);

        $this->facultyB = User::factory()->create([
            'email' => 'facultyB@university.edu',
            'department' => 'Mathematics',
            'designation' => 'Assistant Professor',
        ]);

        $this->courseA = Course::create([
            'user_id' => $this->facultyA->id,
            'course_code' => 'CSE101',
            'course_name' => 'Introduction to Computer Science',
            'description' => 'Foundational computing and algorithm principles',
            'credits' => 3,
            'semester' => 'Spring',
            'academic_year' => '2026',
            'status' => 'active',
        ]);

        $this->assessmentA = Assessment::create([
            'course_id' => $this->courseA->id,
            'title' => 'Midterm Examination 2026',
            'type' => 'midterm',
            'total_marks' => 100,
            'weightage_percentage' => 30,
            'status' => 'draft',
        ]);

        // Add 2 LOs
        LearningOutcome::create([
            'course_id' => $this->courseA->id,
            'code' => 'LO1',
            'description' => 'Explain fundamental computing concepts and algorithm paradigms.',
        ]);
        LearningOutcome::create([
            'course_id' => $this->courseA->id,
            'code' => 'LO2',
            'description' => 'Analyze recursive algorithm complexities and recurrence relations.',
        ]);

        // Add questions
        Question::create([
            'assessment_id' => $this->assessmentA->id,
            'question_number' => 1,
            'question_text' => 'Define what an algorithm is and explain the characteristics of effective algorithms.',
            'marks' => 20,
            'question_type' => 'descriptive',
            'difficulty_level' => 'easy',
            'cognitive_level' => 'remember',
            'ai_difficulty_level' => 'easy',
            'ai_cognitive_level' => 'remember',
        ]);
        Question::create([
            'assessment_id' => $this->assessmentA->id,
            'question_number' => 2,
            'question_text' => 'Explain the working of Binary Search and derive its best-case time complexity.',
            'marks' => 20,
            'question_type' => 'descriptive',
            'difficulty_level' => 'medium',
            'cognitive_level' => 'understand',
            'ai_difficulty_level' => 'medium',
            'ai_cognitive_level' => 'understand',
        ]);
    }

    /**
     * Unauthenticated requests are rejected.
     */
    public function test_unauthenticated_user_cannot_access_recommendation_endpoints(): void
    {
        $res1 = $this->postJson('/api/ai/generate-recommendations', []);
        $res1->assertStatus(401);

        $res2 = $this->postJson("/api/ai/assessments/{$this->assessmentA->id}/generate-recommendations", []);
        $res2->assertStatus(401);

        $res3 = $this->getJson("/api/ai/assessments/{$this->assessmentA->id}/recommendations");
        $res3->assertStatus(401);
    }

    /**
     * Direct stateless recommendation generation.
     */
    public function test_direct_generate_recommendations_success(): void
    {
        $payload = [
            'assessment' => [
                'id' => 1,
                'title' => 'Test Exam',
                'total_marks' => 100,
            ],
            'learning_outcome_analysis' => [
                'status' => 'UNBALANCED',
                'learning_outcomes' => [
                    [
                        'code' => 'LO2',
                        'description' => 'Analyze recurrence relations',
                        'status' => 'NOT_COVERED',
                        'question_count' => 0,
                        'strong_matches_count' => 0,
                        'weak_matches_count' => 0,
                        'max_similarity' => 0.20,
                    ]
                ]
            ],
            'marks_analysis' => [
                'status' => 'MISMATCH',
                'total_marks_expected' => 100.0,
                'total_marks_actual' => 40.0,
                'marks_match' => false,
                'discrepancy' => -60.0,
                'max_single_question_percentage' => 20.0,
            ]
        ];

        $response = $this->actingAs($this->facultyA, 'sanctum')
            ->postJson('/api/ai/generate-recommendations', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'status',
                    'method',
                    'total_recommendations',
                    'high_priority_count',
                    'recommendations',
                ]
            ]);

        $this->assertGreaterThanOrEqual(2, $response->json('data.total_recommendations'));
    }

    /**
     * Faculty cannot generate or access recommendations for another faculty's assessment.
     */
    public function test_faculty_cannot_generate_other_faculty_assessment_recommendations(): void
    {
        $response = $this->actingAs($this->facultyB, 'sanctum')
            ->postJson("/api/ai/assessments/{$this->assessmentA->id}/generate-recommendations");

        $response->assertStatus(403);
    }

    /**
     * Generate assessment recommendations and verify persistence in database.
     */
    public function test_generate_assessment_recommendations_persists_to_database(): void
    {
        $response = $this->actingAs($this->facultyA, 'sanctum')
            ->postJson("/api/ai/assessments/{$this->assessmentA->id}/generate-recommendations");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'summary' => [
                        'total_recommendations',
                        'high_priority_count',
                        'medium_priority_count',
                        'low_priority_count',
                    ],
                    'recommendations',
                ]
            ]);

        $this->assertDatabaseHas('analysis_reports', [
            'assessment_id' => $this->assessmentA->id,
        ]);

        $report = AnalysisReport::where('assessment_id', $this->assessmentA->id)->first();
        $this->assertNotNull($report);

        $recsCount = Recommendation::where('analysis_report_id', $report->id)->count();
        $this->assertGreaterThan(0, $recsCount);

        // Fetch recommendations endpoint
        $fetchRes = $this->actingAs($this->facultyA, 'sanctum')
            ->getJson("/api/ai/assessments/{$this->assessmentA->id}/recommendations");

        $fetchRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.summary.total_recommendations', $recsCount);
    }

    /**
     * Faculty can update recommendation status (accept, dismiss, review).
     */
    public function test_faculty_can_accept_dismiss_review_recommendation(): void
    {
        $report = AnalysisReport::create([
            'assessment_id' => $this->assessmentA->id,
            'overall_score' => 75.0,
            'total_questions' => 2,
            'analysis_status' => 'completed',
            'analyzed_at' => now(),
        ]);

        $recommendation = Recommendation::create([
            'analysis_report_id' => $report->id,
            'category' => 'learning_outcome',
            'problem' => 'LO2 has no aligned examination questions',
            'title' => 'LO2 has no aligned examination questions',
            'description' => 'Consider designing an assessment question for LO2.',
            'explanation' => 'LO2 is completely unassessed in this midterm.',
            'recommendation' => 'Consider adding a recurrence analysis problem.',
            'evidence' => ['lo_code' => 'LO2', 'aligned_questions_count' => 0],
            'source_metric' => 'Learning Outcome Alignment',
            'priority' => 'high',
            'status' => 'pending',
        ]);

        // Accept recommendation
        $acceptRes = $this->actingAs($this->facultyA, 'sanctum')
            ->patchJson("/api/ai/recommendations/{$recommendation->id}/status", [
                'status' => 'accepted',
                'faculty_notes' => 'Will add a divide and conquer question.',
            ]);

        $acceptRes->assertStatus(200)
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.faculty_notes', 'Will add a divide and conquer question.');

        $this->assertDatabaseHas('recommendations', [
            'id' => $recommendation->id,
            'status' => 'accepted',
        ]);

        // Dismiss recommendation
        $dismissRes = $this->actingAs($this->facultyA, 'sanctum')
            ->patchJson("/api/ai/recommendations/{$recommendation->id}/status", [
                'status' => 'dismissed',
                'faculty_notes' => 'This LO is evaluated in the final exam instead.',
            ]);

        $dismissRes->assertStatus(200)
            ->assertJsonPath('data.status', 'dismissed');

        $this->assertDatabaseHas('recommendations', [
            'id' => $recommendation->id,
            'status' => 'dismissed',
        ]);

        // Faculty B cannot modify Faculty A's recommendation
        $unauthRes = $this->actingAs($this->facultyB, 'sanctum')
            ->patchJson("/api/ai/recommendations/{$recommendation->id}/status", [
                'status' => 'accepted',
            ]);

        $unauthRes->assertStatus(403);
    }
}
