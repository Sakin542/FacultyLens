<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EndToEndWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_end_to_end_academic_workflow(): void
    {
        $this->requireLiveAiService();
        // 1. Faculty Registration & Login
        $regResponse = $this->postJson('/api/auth/register', [
            'name' => 'Prof. Charles Babbage',
            'email' => 'babbage@aust.edu',
            'password' => 'Babbage2026!',
            'password_confirmation' => 'Babbage2026!',
            'department' => 'CSE',
            'designation' => 'Professor',
        ]);
        $regResponse->assertStatus(201);
        $user = User::where('email', 'babbage@aust.edu')->first();

        // 2. Create Course: CSE101 — Introduction to Computer Science
        $courseResponse = $this->actingAs($user, 'sanctum')->postJson('/api/courses', [
            'course_code' => 'CSE101',
            'course_name' => 'Introduction to Computer Science',
            'description' => 'Fundamental principles of algorithms, data structures, and computing.',
            'semester' => 'Spring',
            'academic_year' => '2026',
            'credits' => 3,
            'status' => 'active',
        ]);
        $courseResponse->assertStatus(201);
        $courseId = $courseResponse->json('data.id');

        // 3. Create Learning Outcomes (LO1, LO2, LO3)
        $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$courseId}/learning-outcomes", [
            'code' => 'LO1',
            'description' => 'Explain fundamental computing concepts.',
            'cognitive_level' => 'Understand',
            'sort_order' => 1,
        ])->assertStatus(201);

        $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$courseId}/learning-outcomes", [
            'code' => 'LO2',
            'description' => 'Apply basic problem-solving techniques.',
            'cognitive_level' => 'Apply',
            'sort_order' => 2,
        ])->assertStatus(201);

        $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$courseId}/learning-outcomes", [
            'code' => 'LO3',
            'description' => 'Analyze computational problems.',
            'cognitive_level' => 'Analyze',
            'sort_order' => 3,
        ])->assertStatus(201);

        // 4. Create Assessment: Midterm Examination
        $assessmentResponse = $this->actingAs($user, 'sanctum')->postJson("/api/courses/{$courseId}/assessments", [
            'title' => 'Midterm Examination',
            'type' => 'Midterm',
            'total_marks' => 50,
            'duration_minutes' => 90,
            'status' => 'Draft',
        ]);
        $assessmentResponse->assertStatus(201);
        $assessmentId = $assessmentResponse->json('data.id');

        // 5. Add 5 Questions to Assessment
        $questionsData = [
            ['number' => 1, 'text' => 'Define an algorithm.', 'marks' => 5],
            ['number' => 2, 'text' => 'Explain the importance of data structures.', 'marks' => 10],
            ['number' => 3, 'text' => 'Apply binary search to the following dataset.', 'marks' => 10],
            ['number' => 4, 'text' => 'Compare linear search and binary search.', 'marks' => 10],
            ['number' => 5, 'text' => 'Evaluate the efficiency of the given algorithm.', 'marks' => 15],
        ];

        foreach ($questionsData as $q) {
            Question::create([
                'assessment_id' => $assessmentId,
                'question_number' => $q['number'],
                'question_text' => $q['text'],
                'marks' => $q['marks'],
            ]);
        }

        // 6. Execute AI Question Analysis
        // We test with real live AI service if reachable or fake fallback
        $aiServiceUrl = config('services.ai.url', 'http://ai-service:8001');
        
        $analysisResponse = $this->actingAs($user, 'sanctum')->postJson("/api/ai/assessments/{$assessmentId}/analyze-questions", [
            'course_topics' => ['Algorithms', 'Data Structures', 'Searching Algorithms', 'Complexity Analysis'],
        ]);

        $analysisResponse->assertStatus(200)
            ->assertJsonPath('status', 'success');

        // 7. Verify Database Records are updated with AI tags
        $q1 = Question::where('assessment_id', $assessmentId)->where('question_number', 1)->first();
        $this->assertEquals('completed', $q1->ai_analysis_status);
        $this->assertNotNull($q1->ai_question_type);
        $this->assertNotNull($q1->ai_difficulty_level);
        $this->assertNotNull($q1->ai_cognitive_level);

        $q5 = Question::where('assessment_id', $assessmentId)->where('question_number', 5)->first();
        $this->assertEquals('completed', $q5->ai_analysis_status);
        $this->assertNotNull($q5->ai_cognitive_level);
    }
}
