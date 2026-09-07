<?php

namespace Tests\Feature;

use App\Models\AiImprovementSignal;
use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\Recommendation;
use App\Models\RecommendationDecision;
use App\Models\RecommendationFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecommendationFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected User $facultyA;
    protected User $facultyB;
    protected Course $courseA;
    protected Course $courseB;
    protected Assessment $assessmentA;
    protected Assessment $assessmentB;
    protected AnalysisReport $analysisReportA;
    protected AnalysisReport $analysisReportB;
    protected Recommendation $recommendationA;
    protected Recommendation $recommendationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facultyA = User::factory()->create([
            'name' => 'Prof. Alan Turing',
            'email' => 'turing@faculty.edu',
            'department' => 'Computer Science',
        ]);

        $this->facultyB = User::factory()->create([
            'name' => 'Prof. Ada Lovelace',
            'email' => 'lovelace@faculty.edu',
            'department' => 'Mathematics',
        ]);

        $this->courseA = Course::create([
            'user_id' => $this->facultyA->id,
            'course_code' => 'CS-301',
            'course_name' => 'Algorithms & Complexity',
            'semester' => 'Fall',
            'academic_year' => '2026',
        ]);

        $this->courseB = Course::create([
            'user_id' => $this->facultyB->id,
            'course_code' => 'MATH-201',
            'course_name' => 'Discrete Structures',
            'semester' => 'Fall',
            'academic_year' => '2026',
        ]);

        $this->assessmentA = Assessment::create([
            'course_id' => $this->courseA->id,
            'title' => 'Midterm Examination 2026',
            'type' => 'midterm',
            'total_marks' => 100,
            'duration_minutes' => 120,
            'status' => 'draft',
        ]);

        $this->assessmentB = Assessment::create([
            'course_id' => $this->courseB->id,
            'title' => 'Calculus Quiz 1',
            'type' => 'quiz',
            'total_marks' => 50,
            'duration_minutes' => 45,
            'status' => 'draft',
        ]);

        $this->analysisReportA = AnalysisReport::create([
            'assessment_id' => $this->assessmentA->id,
            'analysis_version' => 1,
            'is_current' => true,
            'overall_score' => 84.0,
            'analysis_status' => 'completed',
            'analyzed_at' => now(),
        ]);

        $this->analysisReportB = AnalysisReport::create([
            'assessment_id' => $this->assessmentB->id,
            'analysis_version' => 1,
            'is_current' => true,
            'overall_score' => 78.0,
            'analysis_status' => 'completed',
            'analyzed_at' => now(),
        ]);

        $this->recommendationA = Recommendation::create([
            'analysis_report_id' => $this->analysisReportA->id,
            'category' => 'learning_outcome',
            'problem' => 'LO3 has no aligned examination questions',
            'title' => 'LO3 has no aligned examination questions',
            'description' => 'Consider designing an assessment question for LO3.',
            'recommendation' => 'Add an algorithm design question assessing LO3.',
            'explanation' => 'LO3 is completely unassessed in this midterm.',
            'priority' => 'high',
            'status' => 'pending',
            'source_metric' => 'Learning Outcome Alignment',
        ]);

        $this->recommendationB = Recommendation::create([
            'analysis_report_id' => $this->analysisReportB->id,
            'category' => 'difficulty',
            'problem' => 'Assessment has low hard question representation',
            'title' => 'Low hard questions',
            'description' => 'Increase the number of complex or multi-step questions.',
            'recommendation' => 'Consider converting one easy question to hard difficulty.',
            'priority' => 'medium',
            'status' => 'pending',
            'source_metric' => 'Difficulty Balance',
        ]);
    }

    public function test_faculty_can_accept_recommendation_with_feedback_and_generate_positive_signal(): void
    {
        Sanctum::actingAs($this->facultyA);

        $payload = [
            'decision' => 'ACCEPTED',
            'usefulness_rating' => 5,
            'reason' => 'USEFUL_INSIGHT',
            'comment' => 'Agreed, I will introduce an algorithm question for dynamic programming.',
        ];

        $response = $this->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.recommendation.status', 'accepted')
            ->assertJsonPath('data.decision.new_status', 'ACCEPTED')
            ->assertJsonPath('data.feedback.usefulness_rating', 5)
            ->assertJsonPath('data.signal.signal_type', 'RECOMMENDATION_USEFUL')
            ->assertJsonPath('data.signal.signal_value', 'positive');

        // Check Database Records
        $this->assertDatabaseHas('recommendations', [
            'id' => $this->recommendationA->id,
            'status' => 'accepted',
        ]);

        $this->assertDatabaseHas('recommendation_decisions', [
            'recommendation_id' => $this->recommendationA->id,
            'user_id' => $this->facultyA->id,
            'previous_status' => 'pending',
            'new_status' => 'ACCEPTED',
        ]);

        $this->assertDatabaseHas('recommendation_feedback', [
            'recommendation_id' => $this->recommendationA->id,
            'user_id' => $this->facultyA->id,
            'decision' => 'ACCEPTED',
            'usefulness_rating' => 5,
            'reason' => 'USEFUL_INSIGHT',
        ]);

        $this->assertDatabaseHas('ai_improvement_signals', [
            'recommendation_id' => $this->recommendationA->id,
            'user_id' => $this->facultyA->id,
            'signal_type' => 'RECOMMENDATION_USEFUL',
            'signal_value' => 'positive',
            'assessment_id' => $this->assessmentA->id,
        ]);

        // Verify that analysis report score was NOT altered
        $this->assertEquals(84.0, (float) $this->analysisReportA->fresh()->overall_score);
    }

    public function test_faculty_can_dismiss_recommendation_with_not_applicable_reason(): void
    {
        Sanctum::actingAs($this->facultyA);

        $payload = [
            'decision' => 'DISMISSED',
            'usefulness_rating' => 3,
            'reason' => 'NOT_APPLICABLE',
            'comment' => 'LO3 is evaluated exclusively during the end-of-term project.',
        ];

        $response = $this->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.recommendation.status', 'dismissed')
            ->assertJsonPath('data.signal.signal_type', 'RECOMMENDATION_NOT_APPLICABLE')
            ->assertJsonPath('data.signal.signal_value', 'neutral');

        $this->assertDatabaseHas('recommendations', [
            'id' => $this->recommendationA->id,
            'status' => 'dismissed',
        ]);
    }

    public function test_faculty_can_mark_recommendation_as_reviewed(): void
    {
        Sanctum::actingAs($this->facultyA);

        $payload = [
            'decision' => 'REVIEWED',
            'usefulness_rating' => 4,
            'reason' => 'WILL_IMPLEMENT',
            'comment' => 'Will review with co-instructors before modifying.',
        ];

        $response = $this->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.recommendation.status', 'reviewed')
            ->assertJsonPath('data.decision.new_status', 'REVIEWED');

        $this->assertDatabaseHas('recommendations', [
            'id' => $this->recommendationA->id,
            'status' => 'reviewed',
        ]);
    }

    public function test_feedback_with_incorrect_context_and_low_rating_creates_negative_signal(): void
    {
        Sanctum::actingAs($this->facultyA);

        $payload = [
            'decision' => 'DISMISSED',
            'usefulness_rating' => 1,
            'reason' => 'INCORRECT_CONTEXT',
            'comment' => 'The question model misinterpreted the proof problem as an easy question.',
        ];

        $response = $this->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('data.signal.signal_type', 'RECOMMENDATION_NEEDS_REVIEW')
            ->assertJsonPath('data.signal.signal_value', 'negative');
    }

    public function test_validation_rejects_invalid_decisions_ratings_and_oversized_comments(): void
    {
        Sanctum::actingAs($this->facultyA);

        // Invalid decision
        $resInvalidDecision = $this->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", [
            'decision' => 'SUPER_ACCEPTED',
        ]);
        $resInvalidDecision->assertStatus(422);

        // Rating > 5
        $resRatingHigh = $this->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", [
            'decision' => 'ACCEPTED',
            'usefulness_rating' => 6,
        ]);
        $resRatingHigh->assertStatus(422);

        // Rating < 1
        $resRatingLow = $this->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", [
            'decision' => 'ACCEPTED',
            'usefulness_rating' => 0,
        ]);
        $resRatingLow->assertStatus(422);

        // Comment > 2000 characters
        $resLongComment = $this->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", [
            'decision' => 'ACCEPTED',
            'comment' => str_repeat('A', 2001),
        ]);
        $resLongComment->assertStatus(422);
    }

    public function test_faculty_cannot_submit_feedback_for_another_faculty_recommendation(): void
    {
        Sanctum::actingAs($this->facultyB);

        // Faculty B attempts to submit feedback on Faculty A's recommendation
        $response = $this->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", [
            'decision' => 'ACCEPTED',
            'usefulness_rating' => 5,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('recommendation_feedback', [
            'recommendation_id' => $this->recommendationA->id,
            'user_id' => $this->facultyB->id,
        ]);
    }

    public function test_faculty_can_view_paginated_feedback_history_and_filters(): void
    {
        Sanctum::actingAs($this->facultyA);

        // Submit 2 feedbacks for faculty A
        $this->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", [
            'decision' => 'ACCEPTED',
            'usefulness_rating' => 5,
            'reason' => 'USEFUL_INSIGHT',
            'comment' => 'First feedback comment',
        ]);

        $response = $this->getJson('/api/feedback');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'recommendation_id',
                        'decision',
                        'usefulness_rating',
                        'reason',
                        'comment',
                        'created_at',
                        'recommendation' => ['id', 'category', 'problem', 'status'],
                        'assessment' => ['id', 'title', 'type'],
                        'course' => ['id', 'code', 'name'],
                    ],
                ],
                'meta' => [
                    'current_page',
                    'last_page',
                    'total',
                ],
            ]);

        $this->assertEquals(1, $response->json('meta.total'));

        // Test filtering by decision
        $responseFiltered = $this->getJson('/api/feedback?decision=ACCEPTED');
        $responseFiltered->assertStatus(200);
        $this->assertCount(1, $responseFiltered->json('data'));

        $responseMismatch = $this->getJson('/api/feedback?decision=DISMISSED');
        $responseMismatch->assertStatus(200);
        $this->assertCount(0, $responseMismatch->json('data'));
    }

    public function test_faculty_cannot_see_other_faculty_feedback_history(): void
    {
        // Faculty A creates feedback
        Sanctum::actingAs($this->facultyA);
        $this->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", [
            'decision' => 'ACCEPTED',
            'usefulness_rating' => 5,
        ]);

        // Faculty B checks feedback
        Sanctum::actingAs($this->facultyB);
        $response = $this->getJson('/api/feedback');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_faculty_can_view_feedback_summary_and_analytics(): void
    {
        Sanctum::actingAs($this->facultyA);

        $this->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", [
            'decision' => 'ACCEPTED',
            'usefulness_rating' => 4,
            'reason' => 'USEFUL_INSIGHT',
        ]);

        $response = $this->getJson('/api/feedback/summary');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'success',
                'data' => [
                    'total',
                    'accepted',
                    'dismissed',
                    'reviewed',
                    'average_usefulness',
                    'reason_breakdown',
                    'analytics' => [
                        'total_feedbacks',
                        'acceptance_rate',
                        'average_usefulness',
                        'category_breakdown',
                        'top_reasons',
                    ],
                ],
            ]);

        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals(1, $response->json('data.accepted'));
        $this->assertEquals(4.0, (float) $response->json('data.average_usefulness'));
        $this->assertEquals(100.0, (float) $response->json('data.analytics.acceptance_rate'));
    }

    public function test_faculty_can_fetch_improvement_signals_with_summary(): void
    {
        Sanctum::actingAs($this->facultyA);

        $this->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", [
            'decision' => 'ACCEPTED',
            'usefulness_rating' => 5,
            'reason' => 'USEFUL_INSIGHT',
        ]);

        $response = $this->getJson('/api/ai/improvement-signals');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'success',
                'data' => [
                    'summary' => [
                        'total_signals',
                        'positive_signals',
                        'needs_review_signals',
                        'context_neutral_signals',
                        'disclaimer',
                    ],
                    'signals' => [
                        '*' => [
                            'id',
                            'signal_type',
                            'signal_value',
                            'source',
                            'metadata',
                        ],
                    ],
                ],
            ]);

        $this->assertEquals(1, $response->json('data.summary.total_signals'));
        $this->assertEquals(1, $response->json('data.summary.positive_signals'));
    }

    public function test_quick_status_patch_endpoint_updates_status_and_logs_decision(): void
    {
        Sanctum::actingAs($this->facultyA);

        $response = $this->patchJson("/api/recommendations/{$this->recommendationA->id}/status", [
            'status' => 'accepted',
            'faculty_notes' => 'Quick acceptance from table',
            'reason' => 'WILL_IMPLEMENT',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('recommendations', [
            'id' => $this->recommendationA->id,
            'status' => 'accepted',
            'faculty_notes' => 'Quick acceptance from table',
        ]);

        $this->assertDatabaseHas('recommendation_decisions', [
            'recommendation_id' => $this->recommendationA->id,
            'new_status' => 'ACCEPTED',
        ]);
    }

    public function test_database_transaction_rolls_back_on_failure(): void
    {
        Sanctum::actingAs($this->facultyA);

        // Mock AiImprovementSignal creation failure by simulating exception during submission
        \Illuminate\Support\Facades\Event::listen('eloquent.creating: App\Models\AiImprovementSignal', function () {
            throw new \RuntimeException('Simulated Signal DB failure');
        });

        $response = $this->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", [
            'decision' => 'ACCEPTED',
            'usefulness_rating' => 5,
        ]);

        $response->assertStatus(422);

        // Recommendation status remains 'pending'
        $this->assertEquals('pending', $this->recommendationA->fresh()->status);

        // No decision or feedback record persisted
        $this->assertDatabaseMissing('recommendation_decisions', [
            'recommendation_id' => $this->recommendationA->id,
        ]);
        $this->assertDatabaseMissing('recommendation_feedback', [
            'recommendation_id' => $this->recommendationA->id,
        ]);
    }
}
