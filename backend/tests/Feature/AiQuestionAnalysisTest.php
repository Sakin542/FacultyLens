<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiQuestionAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Course $course;
    protected Assessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->course = Course::create([
            'user_id' => $this->user->id,
            'course_code' => 'CSE-3101',
            'course_name' => 'Database Systems',
            'description' => 'Comprehensive study of relational and distributed databases.',
            'semester' => 'Fall',
            'academic_year' => '2026',
            'credits' => 3,
        ]);
        $this->assessment = Assessment::create([
            'course_id' => $this->course->id,
            'title' => 'Midterm Examination',
            'type' => 'Midterm',
            'total_marks' => 50,
            'duration_minutes' => 90,
            'status' => 'Draft',
        ]);
    }

    public function test_unauthenticated_user_cannot_analyze_questions(): void
    {
        $response = $this->postJson('/api/ai/analyze-question', [
            'question' => 'Define database normalization.',
        ]);

        $response->assertStatus(401);
    }

    public function test_validation_fails_on_empty_question(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/ai/analyze-question', [
            'question' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['question']);
    }

    public function test_analyze_single_question_successfully(): void
    {
        $mockResult = [
            'status' => 'success',
            'question' => 'Define database normalization.',
            'classification' => ['question_type' => 'CONCEPTUAL', 'confidence' => 0.85],
            'topics' => [['name' => 'Normalization', 'confidence' => 0.88]],
            'difficulty' => ['level' => 'EASY', 'method' => 'baseline'],
            'cognitive_level' => ['level' => 'REMEMBER', 'method' => 'baseline'],
        ];

        Http::fake([
            '*/api/v1/analyze-question' => Http::response($mockResult, 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/ai/analyze-question', [
            'question' => 'Define database normalization.',
            'course_topics' => ['Normalization', 'SQL'],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => $mockResult,
            ]);
    }

    public function test_analyze_batch_questions_successfully(): void
    {
        $mockBatchResult = [
            'status' => 'success',
            'total_questions' => 2,
            'questions' => [
                [
                    'number' => 1,
                    'question' => 'Define database normalization.',
                    'classification' => ['question_type' => 'CONCEPTUAL', 'confidence' => 0.85],
                    'topics' => [['name' => 'Normalization', 'confidence' => 0.88]],
                    'difficulty' => ['level' => 'EASY', 'method' => 'baseline'],
                    'cognitive_level' => ['level' => 'REMEMBER', 'method' => 'baseline'],
                ],
                [
                    'number' => 2,
                    'question' => 'Design a normalized database schema.',
                    'classification' => ['question_type' => 'DESCRIPTIVE', 'confidence' => 0.80],
                    'topics' => [['name' => 'Database Design', 'confidence' => 0.90]],
                    'difficulty' => ['level' => 'HARD', 'method' => 'baseline'],
                    'cognitive_level' => ['level' => 'CREATE', 'method' => 'baseline'],
                ],
            ],
            'summary' => [
                'question_types' => ['CONCEPTUAL' => 1, 'DESCRIPTIVE' => 1],
                'difficulty_distribution' => ['EASY' => 1, 'HARD' => 1],
                'cognitive_distribution' => ['REMEMBER' => 1, 'CREATE' => 1],
                'topics_detected' => ['Database Design', 'Normalization'],
            ],
        ];

        Http::fake([
            '*/api/v1/analyze-questions' => Http::response($mockBatchResult, 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/ai/analyze-questions', [
            'questions' => [
                ['number' => 1, 'text' => 'Define database normalization.'],
                ['number' => 2, 'text' => 'Design a normalized database schema.'],
            ],
            'course_topics' => ['Normalization', 'Database Design'],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => $mockBatchResult,
            ]);
    }

    public function test_faculty_cannot_analyze_questions_of_other_faculty_assessment(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser, 'sanctum')->postJson("/api/ai/assessments/{$this->assessment->id}/analyze-questions");

        $response->assertStatus(403);
    }

    public function test_analyze_assessment_questions_updates_database(): void
    {
        $q1 = Question::create([
            'assessment_id' => $this->assessment->id,
            'question_number' => 1,
            'question_text' => 'Define database normalization.',
            'marks' => 5,
        ]);

        $q2 = Question::create([
            'assessment_id' => $this->assessment->id,
            'question_number' => 2,
            'question_text' => 'Design a normalized database schema for a university.',
            'marks' => 10,
        ]);

        $mockBatchResult = [
            'status' => 'success',
            'total_questions' => 2,
            'questions' => [
                [
                    'number' => 1,
                    'question' => 'Define database normalization.',
                    'classification' => ['question_type' => 'CONCEPTUAL', 'confidence' => 0.85],
                    'topics' => [['name' => 'Normalization', 'confidence' => 0.88]],
                    'difficulty' => ['level' => 'EASY', 'method' => 'baseline'],
                    'cognitive_level' => ['level' => 'REMEMBER', 'method' => 'baseline'],
                ],
                [
                    'number' => 2,
                    'question' => 'Design a normalized database schema for a university.',
                    'classification' => ['question_type' => 'DESCRIPTIVE', 'confidence' => 0.80],
                    'topics' => [['name' => 'Database Design', 'confidence' => 0.90]],
                    'difficulty' => ['level' => 'HARD', 'method' => 'baseline'],
                    'cognitive_level' => ['level' => 'CREATE', 'method' => 'baseline'],
                ],
            ],
            'summary' => [
                'question_types' => ['CONCEPTUAL' => 1, 'DESCRIPTIVE' => 1],
                'difficulty_distribution' => ['EASY' => 1, 'HARD' => 1],
                'cognitive_distribution' => ['REMEMBER' => 1, 'CREATE' => 1],
                'topics_detected' => ['Database Design', 'Normalization'],
            ],
        ];

        Http::fake([
            '*/api/v1/analyze-questions' => Http::response($mockBatchResult, 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson("/api/ai/assessments/{$this->assessment->id}/analyze-questions");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $q1->refresh();
        $q2->refresh();

        $this->assertEquals('CONCEPTUAL', $q1->ai_question_type);
        $this->assertEquals('EASY', $q1->ai_difficulty_level);
        $this->assertEquals('REMEMBER', $q1->ai_cognitive_level);
        $this->assertEquals('completed', $q1->ai_analysis_status);
        $this->assertNotNull($q1->ai_analyzed_at);

        $this->assertEquals('DESCRIPTIVE', $q2->ai_question_type);
        $this->assertEquals('HARD', $q2->ai_difficulty_level);
        $this->assertEquals('CREATE', $q2->ai_cognitive_level);
        $this->assertEquals('completed', $q2->ai_analysis_status);
    }
}
