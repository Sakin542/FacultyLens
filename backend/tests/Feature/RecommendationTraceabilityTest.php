<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\Question;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecommendationTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_balanced_assessment_produces_empty_recommendations_array(): void
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
            'title' => 'Balanced Midterm',
            'type' => 'Midterm',
            'total_marks' => 50,
            'duration_minutes' => 90,
            'status' => 'Draft',
        ]);

        Http::fake([
            '*/api/v1/generate-recommendations' => Http::response([
                'status' => 'success',
                'method' => 'recommendation_engine',
                'total_recommendations' => 0,
                'high_priority_count' => 0,
                'recommendations' => [],
                'summary' => 'The assessment demonstrates excellent balance with no critical action items.',
            ], 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/ai/assessments/{$assessment->id}/generate-recommendations");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $report = AnalysisReport::where('assessment_id', $assessment->id)->first();
        $this->assertNotNull($report);
        $this->assertEquals(0, Recommendation::where('analysis_report_id', $report->id)->count());
    }

    public function test_recommendations_contain_traceable_evidence_and_explanation(): void
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
            'title' => 'Skewed Exam',
            'type' => 'Final',
            'total_marks' => 100,
            'duration_minutes' => 120,
            'status' => 'Draft',
        ]);

        $recommendationPayload = [
            'status' => 'success',
            'method' => 'recommendation_engine',
            'total_recommendations' => 2,
            'high_priority_count' => 1,
            'recommendations' => [
                [
                    'category' => 'learning_outcome',
                    'problem' => 'Uncovered Learning Outcome LO3',
                    'recommendation' => 'Add an analytical design question assessing LO3.',
                    'priority' => 'HIGH',
                    'evidence' => [
                        'uncovered_los' => ['LO3'],
                        'lo_coverage_percentage' => 66.7,
                    ],
                    'explanation' => '0 out of 5 questions were aligned to LO3 above the 0.50 threshold.',
                    'source_metric' => 'Learning Outcome Alignment',
                ],
                [
                    'category' => 'difficulty',
                    'problem' => 'Overconcentration of Easy Questions',
                    'recommendation' => 'Include more Medium or Hard questions to balance rigor.',
                    'priority' => 'MEDIUM',
                    'evidence' => [
                        'easy_percentage' => 80.0,
                        'hard_percentage' => 0.0,
                    ],
                    'explanation' => 'Recommended difficulty for upper-level exam is 30% Easy, 50% Medium, 20% Hard.',
                    'source_metric' => 'Difficulty Balance',
                ],
            ],
        ];

        Http::fake([
            '*/api/v1/generate-recommendations' => Http::response($recommendationPayload, 200),
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/ai/assessments/{$assessment->id}/generate-recommendations");

        $response->assertStatus(200);

        $report = AnalysisReport::where('assessment_id', $assessment->id)->first();
        $this->assertNotNull($report);

        $rec = Recommendation::where('analysis_report_id', $report->id)->where('category', 'learning_outcome')->first();
        $this->assertNotNull($rec);
        $this->assertEquals('high', $rec->priority);
        $this->assertNotNull($rec->evidence);
        $this->assertEquals(66.7, $rec->evidence['lo_coverage_percentage']);
        $this->assertStringContainsString('LO3', $rec->explanation);
    }
}
