<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LearningOutcomeAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;
    protected Course $course;
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
            'description' => 'Relational and distributed database design.',
            'semester' => 'Fall',
            'academic_year' => '2026',
            'credits' => 3,
        ]);

        $this->lo1 = LearningOutcome::create([
            'course_id' => $this->course->id,
            'code' => 'LO1',
            'description' => 'Understand and apply relational database normalization up to BCNF.',
            'bloom_level' => 'Apply',
        ]);

        $this->lo2 = LearningOutcome::create([
            'course_id' => $this->course->id,
            'code' => 'LO2',
            'description' => 'Design distributed transaction control and concurrency management.',
            'bloom_level' => 'Analyze',
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
            'question_text' => 'Normalize the given schema into 3NF and BCNF by finding candidate keys.',
            'marks' => 15,
        ]);

        $this->q2 = Question::create([
            'assessment_id' => $this->assessment->id,
            'question_number' => 2,
            'question_text' => 'Explain two-phase locking and timestamp ordering protocols for transaction isolation.',
            'marks' => 15,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_alignment_endpoints(): void
    {
        $response = $this->postJson('/api/ai/analyze-alignment', [
            'learning_outcomes' => [['description' => 'Some LO']],
            'questions' => [['text' => 'Some Question']],
        ]);

        $response->assertStatus(401);
    }

    public function test_validation_fails_on_empty_payload(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/ai/analyze-alignment', [
            'learning_outcomes' => [],
            'questions' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['learning_outcomes', 'questions']);
    }

    public function test_direct_analyze_alignment_success(): void
    {
        Http::fake([
            '*/api/v1/analyze-alignment' => Http::response([
                'status' => 'success',
                'method' => 'sentence-transformers/all-MiniLM-L6-v2 + cosine_similarity',
                'overall_alignment_score' => 85.0,
                'aligned_questions_count' => 2,
                'total_questions' => 2,
                'total_learning_outcomes' => 2,
                'covered_learning_outcomes_count' => 2,
                'question_alignment' => [
                    [
                        'question_id' => 1,
                        'question_number' => 1,
                        'question_text' => 'Normalize schema into 3NF and BCNF.',
                        'matched_learning_outcome' => [
                            'id' => 1,
                            'code' => 'LO1',
                            'description' => 'Understand and apply normalization',
                            'similarity' => 0.82,
                            'alignment_level' => 'STRONG',
                        ],
                        'alternative_matches' => [],
                        'alignment_status' => 'STRONG',
                        'similarity_score' => 0.82,
                        'reasoning' => 'Strong match to LO1',
                    ],
                ],
                'learning_outcome_coverage' => [
                    [
                        'learning_outcome_id' => 1,
                        'code' => 'LO1',
                        'description' => 'Understand and apply normalization',
                        'coverage_status' => 'COVERED',
                        'matching_questions_count' => 1,
                        'matching_question_numbers' => [1],
                        'max_similarity' => 0.82,
                    ],
                ],
                'findings' => ['Good overall coverage.'],
                'thresholds' => ['strong' => 0.70, 'weak' => 0.50],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/ai/analyze-alignment', [
            'learning_outcomes' => [
                ['id' => 1, 'code' => 'LO1', 'description' => 'Understand and apply normalization'],
            ],
            'questions' => [
                ['id' => 1, 'number' => 1, 'text' => 'Normalize schema into 3NF and BCNF.'],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.overall_alignment_score', 85)
            ->assertJsonPath('data.total_questions', 2);
    }

    public function test_faculty_cannot_analyze_other_faculty_assessment_alignment(): void
    {
        $response = $this->actingAs($this->otherUser, 'sanctum')->postJson(
            "/api/ai/assessments/{$this->assessment->id}/analyze-alignment"
        );

        $response->assertStatus(403);
    }

    public function test_analyze_assessment_alignment_persists_to_analysis_report(): void
    {
        Http::fake([
            '*/api/v1/analyze-alignment' => Http::response([
                'status' => 'success',
                'method' => 'sentence-transformers/all-MiniLM-L6-v2 + cosine_similarity',
                'overall_alignment_score' => 90.0,
                'aligned_questions_count' => 2,
                'total_questions' => 2,
                'total_learning_outcomes' => 2,
                'covered_learning_outcomes_count' => 2,
                'question_alignment' => [
                    [
                        'question_id' => $this->q1->id,
                        'question_number' => 1,
                        'question_text' => $this->q1->question_text,
                        'matched_learning_outcome' => [
                            'id' => $this->lo1->id,
                            'code' => 'LO1',
                            'description' => $this->lo1->description,
                            'similarity' => 0.88,
                            'alignment_level' => 'STRONG',
                        ],
                        'alternative_matches' => [],
                        'alignment_status' => 'STRONG',
                        'similarity_score' => 0.88,
                        'reasoning' => 'Strong match to LO1',
                    ],
                    [
                        'question_id' => $this->q2->id,
                        'question_number' => 2,
                        'question_text' => $this->q2->question_text,
                        'matched_learning_outcome' => [
                            'id' => $this->lo2->id,
                            'code' => 'LO2',
                            'description' => $this->lo2->description,
                            'similarity' => 0.79,
                            'alignment_level' => 'STRONG',
                        ],
                        'alternative_matches' => [],
                        'alignment_status' => 'STRONG',
                        'similarity_score' => 0.79,
                        'reasoning' => 'Strong match to LO2',
                    ],
                ],
                'learning_outcome_coverage' => [
                    [
                        'learning_outcome_id' => $this->lo1->id,
                        'code' => 'LO1',
                        'description' => $this->lo1->description,
                        'coverage_status' => 'COVERED',
                        'matching_questions_count' => 1,
                        'matching_question_numbers' => [1],
                        'max_similarity' => 0.88,
                    ],
                    [
                        'learning_outcome_id' => $this->lo2->id,
                        'code' => 'LO2',
                        'description' => $this->lo2->description,
                        'coverage_status' => 'COVERED',
                        'matching_questions_count' => 1,
                        'matching_question_numbers' => [2],
                        'max_similarity' => 0.79,
                    ],
                ],
                'findings' => [
                    'Excellent alignment: All defined learning outcomes are strongly covered across the exam questions.',
                ],
                'thresholds' => ['strong' => 0.70, 'weak' => 0.50],
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/ai/assessments/{$this->assessment->id}/analyze-alignment"
        );

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.alignment.overall_alignment_score', 90)
            ->assertJsonPath('data.report.assessment_id', $this->assessment->id);

        $this->assertDatabaseHas('analysis_reports', [
            'assessment_id' => $this->assessment->id,
            'learning_outcome_alignment_score' => 90.00,
            'analysis_status' => 'completed',
        ]);

        // Check that question 1 was mapped to LO1
        $this->q1->refresh();
        $this->assertEquals($this->lo1->id, $this->q1->learning_outcome_id);
    }
}

