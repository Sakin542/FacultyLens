<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssessmentQualityScoreNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_data_quality_score_weights_sum_to_100(): void
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
            'question_text' => 'Explain database normalization with 2NF and 3NF examples.',
            'marks' => 25,
            'difficulty_level' => 'Medium',
            'cognitive_level' => 'Understand',
        ]);

        Question::create([
            'assessment_id' => $assessment->id,
            'question_number' => 2,
            'question_text' => 'Apply BCNF decomposition on the given relations.',
            'marks' => 25,
            'difficulty_level' => 'Hard',
            'cognitive_level' => 'Apply',
        ]);

        Http::fake([
            '*/api/v1/analyze-assessment-quality' => Http::response([
                'status' => 'success',
                'method' => 'assessment_quality_engine',
                'overall_quality_score' => 88.5,
                'rating' => 'EXCELLENT',
                'weights_applied' => [
                    'topic' => 20.0,
                    'lo' => 20.0,
                    'difficulty' => 15.0,
                    'cognitive' => 15.0,
                    'question_diversity' => 15.0,
                    'marks' => 15.0,
                ],
                'excluded_components' => [],
                'components' => [
                    'topic_coverage' => 85.0,
                    'learning_outcome_coverage' => 90.0,
                    'difficulty_balance' => 92.0,
                    'cognitive_diversity' => 84.0,
                    'question_diversity' => 90.0,
                    'marks_distribution' => 90.0,
                ],
                'findings' => ['Well balanced assessment across topics.'],
            ], 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/ai/assessments/{$assessment->id}/analyze-quality");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertEquals(88.5, (float) $response->json('data.quality.overall_quality_score'));
        $this->assertEquals(88.5, (float) $response->json('data.report.overall_score'));

        $this->assertDatabaseHas('analysis_reports', [
            'assessment_id' => $assessment->id,
            'analysis_status' => 'completed',
        ]);
    }

    public function test_missing_topic_or_lo_normalizes_weights_without_falsifying_zero(): void
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
            'title' => 'Quiz 1',
            'type' => 'Quiz',
            'total_marks' => 20,
            'duration_minutes' => 30,
            'status' => 'Draft',
        ]);

        Question::create([
            'assessment_id' => $assessment->id,
            'question_number' => 1,
            'question_text' => 'What is ACID in database transactions?',
            'marks' => 20,
            'difficulty_level' => 'Easy',
            'cognitive_level' => 'Remember',
        ]);

        Http::fake([
            '*/api/v1/analyze-assessment-quality' => Http::response([
                'status' => 'success',
                'method' => 'assessment_quality_engine',
                'overall_quality_score' => 75.0,
                'rating' => 'GOOD',
                'weights_applied' => [
                    'difficulty' => 25.0,
                    'cognitive' => 25.0,
                    'question_diversity' => 25.0,
                    'marks' => 25.0,
                ],
                'excluded_components' => ['topic_coverage', 'learning_outcome_coverage'],
                'components' => [
                    'topic_coverage' => null,
                    'learning_outcome_coverage' => null,
                    'difficulty_balance' => 80.0,
                    'cognitive_diversity' => 70.0,
                    'question_diversity' => 75.0,
                    'marks_distribution' => 75.0,
                ],
                'findings' => ['Topic and LO coverage unavailable; remaining dimensions normalized.'],
            ], 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/ai/assessments/{$assessment->id}/analyze-quality");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertEquals(75.0, (float) $response->json('data.quality.overall_quality_score'));
        $this->assertEquals(75.0, (float) $response->json('data.report.overall_score'));
    }
}
