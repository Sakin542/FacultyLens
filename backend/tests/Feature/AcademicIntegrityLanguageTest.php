<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AcademicIntegrityLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_similarity_uses_potential_duplicate_and_not_punitive_terms(): void
    {
        $user = User::factory()->create(['role' => 'FACULTY']);
        $course = Course::create([
            'user_id' => $user->id,
            'course_code' => 'CSE101',
            'course_name' => 'Database Systems',
            'semester' => 'Spring',
            'academic_year' => '2026',
            'credits' => 3,
            'status' => 'active',
        ]);

        $assessment = Assessment::create([
            'course_id' => $course->id,
            'title' => 'Midterm Examination',
            'type' => 'Midterm',
            'total_marks' => 50,
            'duration_minutes' => 90,
            'status' => 'Draft',
        ]);

        Question::create([
            'assessment_id' => $assessment->id,
            'question_number' => 1,
            'question_text' => 'Explain database normalization.',
            'marks' => 10,
        ]);

        Http::fake([
            '*/api/v1/analyze-similarity' => Http::response([
                'status' => 'success',
                'matches' => [
                    [
                        'current_question_id' => 1,
                        'previous_question_id' => 99,
                        'similarity_score' => 0.94,
                        'match_level' => 'POTENTIAL_DUPLICATE',
                        'finding' => 'Potential duplicate identified for faculty review.',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/ai/assessments/{$assessment->id}/analyze-similarity");

        $response->assertStatus(200);
        $content = json_encode($response->json());

        $this->assertStringContainsString('POTENTIAL_DUPLICATE', $content);
        $this->assertStringContainsString('faculty review', strtolower($content));

        $this->assertStringNotContainsString('plagiar', strtolower($content));
        $this->assertStringNotContainsString('cheat', strtolower($content));
        $this->assertStringNotContainsString('infringement', strtolower($content));
        $this->assertStringNotContainsString('invalid assessment', strtolower($content));
    }
}
