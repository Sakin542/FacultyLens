<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\DocumentProcessing;
use App\Models\LearningOutcome;
use App\Models\PreviousQuestion;
use App\Models\Question;
use App\Models\QuestionLearningOutcomeAlignment;
use App\Models\QuestionSimilarityMatch;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiIntegrationTest extends TestCase
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
    protected DocumentProcessing $docA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facultyA = User::factory()->create([
            'name' => 'Professor Smith',
            'email' => 'smith@university.edu',
            'department' => 'Computer Science',
        ]);

        $this->facultyB = User::factory()->create([
            'name' => 'Professor Jones',
            'email' => 'jones@university.edu',
            'department' => 'Data Science',
        ]);

        $this->courseA = Course::create([
            'user_id' => $this->facultyA->id,
            'course_code' => 'CSE-401',
            'course_name' => 'Advanced Algorithms',
            'semester' => 'Spring',
            'academic_year' => '2026',
        ]);

        $this->assessmentA = Assessment::create([
            'course_id' => $this->courseA->id,
            'title' => 'Final Examination 2026',
            'type' => 'final',
            'total_marks' => 30,
            'status' => 'draft',
        ]);

        $this->lo1 = LearningOutcome::create([
            'course_id' => $this->courseA->id,
            'code' => 'LO1',
            'description' => 'Analyze time and space complexity of graph algorithms.',
        ]);

        $this->q1 = Question::create([
            'assessment_id' => $this->assessmentA->id,
            'question_number' => 1,
            'question_text' => 'Compute the shortest path using Dijkstra and analyze its complexity.',
            'marks' => 15,
            'question_type' => 'problem_solving',
            'difficulty_level' => 'medium',
        ]);

        $this->q2 = Question::create([
            'assessment_id' => $this->assessmentA->id,
            'question_number' => 2,
            'question_text' => 'Explain the difference between Prim and Kruskal minimum spanning tree algorithms.',
            'marks' => 15,
            'question_type' => 'descriptive',
            'difficulty_level' => 'easy',
        ]);

        $this->prevQ1 = PreviousQuestion::create([
            'user_id' => $this->facultyA->id,
            'course_id' => $this->courseA->id,
            'question_number' => 1,
            'question_text' => 'Explain Dijkstra shortest path algorithm with time complexity.',
            'source_exam_name' => 'Final Exam 2025',
            'academic_year' => '2025',
        ]);

        $this->docA = DocumentProcessing::create([
            'user_id' => $this->facultyA->id,
            'course_id' => $this->courseA->id,
            'assessment_id' => $this->assessmentA->id,
            'document_type' => 'question_paper',
            'original_file_name' => 'exam2026.pdf',
            'stored_file_name' => 'stored_exam2026.pdf',
            'file_path' => 'documents/stored_exam2026.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'extracted_text' => 'Question 1: Compute shortest path...',
            'cleaned_text' => 'Question 1: Compute shortest path...',
            'processing_status' => 'completed',
        ]);
    }

    public function test_document_analysis_requires_authentication()
    {
        $response = $this->postJson('/api/ai/analyze-document', [
            'document_id' => $this->docA->id,
        ]);

        $response->assertStatus(401);
    }

    public function test_document_analysis_rejects_unauthorized_user()
    {
        Sanctum::actingAs($this->facultyB);

        $response = $this->postJson('/api/ai/analyze-document', [
            'document_id' => $this->docA->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_document_analysis_rejects_incomplete_document()
    {
        Sanctum::actingAs($this->facultyA);

        $this->docA->update(['processing_status' => 'processing']);

        $response = $this->postJson('/api/ai/analyze-document', [
            'document_id' => $this->docA->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonFragment(['status' => 'error']);
    }

    public function test_document_analysis_succeeds_with_mocked_fastapi()
    {
        Sanctum::actingAs($this->facultyA);

        Http::fake([
            '*/api/v1/analyze' => Http::response([
                'status' => 'success',
                'cleaned_text' => 'Cleaned exam text',
                'detected_structure' => ['questions_found' => 2],
            ], 200),
        ]);

        $response = $this->postJson('/api/ai/analyze-document', [
            'document_id' => $this->docA->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'success');
    }

    public function test_unified_assessment_analysis_persists_all_ai_layers_atomically()
    {
        Sanctum::actingAs($this->facultyA);

        $fakeFastApiResponse = [
            'status' => 'success',
            'method' => 'unified-fastapi-pipeline',
            'assessment_id' => $this->assessmentA->id,
            'summary' => [
                'total_questions' => 2,
                'overall_quality_score' => 88.0,
                'quality_rating' => 'EXCELLENT',
                'total_recommendations' => 1,
            ],
            'questions_analysis' => [
                'status' => 'success',
                'total_questions' => 2,
                'questions' => [
                    [
                        'number' => 1,
                        'classification' => ['type' => 'problem_solving'],
                        'difficulty' => ['level' => 'hard'],
                        'cognitive_level' => ['level' => 'analyze'],
                        'topics' => ['Dijkstra', 'Graph Theory'],
                    ],
                    [
                        'number' => 2,
                        'classification' => ['type' => 'descriptive'],
                        'difficulty' => ['level' => 'medium'],
                        'cognitive_level' => ['level' => 'understand'],
                        'topics' => ['Minimum Spanning Tree', 'Kruskal'],
                    ],
                ],
                'summary' => [],
            ],
            'alignment_analysis' => [
                'status' => 'success',
                'overall_alignment_score' => 90.0,
                'question_alignment' => [
                    [
                        'question_id' => $this->q1->id,
                        'question_number' => 1,
                        'matched_learning_outcome' => [
                            'id' => $this->lo1->id,
                            'code' => 'LO1',
                            'similarity_score' => 0.92,
                        ],
                        'alignment_status' => 'STRONG',
                        'alignment_score' => 0.92,
                        'reasoning' => 'Strong alignment to graph complexity analysis.',
                    ],
                ],
                'learning_outcome_coverage' => [],
            ],
            'similarity_analysis' => [
                'status' => 'success',
                'average_similarity_score' => 0.85,
                'potential_duplicates_count' => 1,
                'highly_similar_count' => 0,
                'matches' => [
                    [
                        'current_question_number' => 1,
                        'matches' => [
                            [
                                'previous_question_id' => $this->prevQ1->id,
                                'similarity_score' => 0.88,
                                'similarity_status' => 'POTENTIAL_DUPLICATE',
                            ],
                        ],
                        'reasoning' => 'Highly similar Dijkstra algorithm question.',
                    ],
                ],
            ],
            'quality_analysis' => [
                'status' => 'success',
                'overall_quality_score' => 88.0,
                'rating' => 'EXCELLENT',
                'components' => [
                    'topic_coverage' => 90.0,
                    'learning_outcome_coverage' => 90.0,
                    'difficulty_balance' => 85.0,
                    'cognitive_diversity' => 85.0,
                ],
            ],
            'recommendations' => [
                'status' => 'success',
                'total_recommendations' => 1,
                'high_priority_count' => 1,
                'medium_priority_count' => 0,
                'low_priority_count' => 0,
                'recommendations' => [
                    [
                        'category' => 'similarity',
                        'priority' => 'HIGH',
                        'problem' => 'Potential Question Repetition',
                        'explanation' => 'Question 1 is 88% similar to 2025 Midterm.',
                        'recommendation' => 'Modify the scenario or constraints of Q1.',
                        'source_metric' => 'similarity_score',
                        'evidence' => ['similarity' => 0.88],
                    ],
                ],
            ],
        ];

        Http::fake([
            '*/api/v1/analyze-assessment' => Http::response($fakeFastApiResponse, 200),
        ]);

        $response = $this->postJson("/api/ai/assessments/{$this->assessmentA->id}/analyze");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        // Verify AnalysisReport persistence and lifecycle
        $report = AnalysisReport::where('assessment_id', $this->assessmentA->id)->first();
        $this->assertNotNull($report);
        $this->assertEquals('completed', $report->analysis_status);
        $this->assertNull($report->processing_error);
        $this->assertEquals(88.0, (float) $report->overall_score);
        $this->assertEquals(1, $report->similar_questions_count);

        // Verify Question AI fields updated without touching faculty manual columns
        $this->q1->refresh();
        $this->assertEquals('hard', $this->q1->ai_difficulty_level);
        $this->assertEquals('analyze', $this->q1->ai_cognitive_level);
        $this->assertEquals('completed', $this->q1->ai_analysis_status);
        // Manual fields intact
        $this->assertEquals('medium', $this->q1->difficulty_level);
        $this->assertEquals(15, $this->q1->marks);

        // Verify QuestionSimilarityMatch created
        $simMatch = QuestionSimilarityMatch::where('analysis_report_id', $report->id)->first();
        $this->assertNotNull($simMatch);
        $this->assertEquals($this->q1->id, $simMatch->current_question_id);
        $this->assertEquals($this->prevQ1->id, $simMatch->previous_question_id);
        $this->assertEquals('POTENTIAL_DUPLICATE', $simMatch->similarity_status);

        // Verify QuestionLearningOutcomeAlignment created
        $alignMatch = QuestionLearningOutcomeAlignment::where('analysis_report_id', $report->id)->first();
        $this->assertNotNull($alignMatch);
        $this->assertEquals($this->q1->id, $alignMatch->question_id);
        $this->assertEquals($this->lo1->id, $alignMatch->learning_outcome_id);
        $this->assertEquals('STRONG', $alignMatch->alignment);

        // Verify Recommendation created
        $rec = Recommendation::where('analysis_report_id', $report->id)->first();
        $this->assertNotNull($rec);
        $this->assertEquals('Potential Question Repetition', $rec->title);
        $this->assertEquals('pending', $rec->status);
    }

    public function test_recommendation_decision_state_is_preserved_across_reanalysis()
    {
        Sanctum::actingAs($this->facultyA);

        // Create an existing report and an accepted recommendation
        $report = AnalysisReport::create([
            'assessment_id' => $this->assessmentA->id,
            'overall_score' => 75.0,
            'total_questions' => 2,
            'analysis_status' => 'completed',
            'analyzed_at' => now(),
        ]);

        $rec = Recommendation::create([
            'analysis_report_id' => $report->id,
            'title' => 'Potential Question Repetition',
            'category' => 'similarity',
            'problem' => 'Potential Question Repetition',
            'description' => 'Original description',
            'explanation' => 'Original explanation',
            'recommendation' => 'Original recommendation',
            'priority' => 'high',
            'status' => 'accepted',
            'faculty_notes' => 'Faculty reviewed and decided to rephrase in exam paper.',
        ]);

        // Mock re-analysis with the same recommendation title
        Http::fake([
            '*/api/v1/analyze-assessment' => Http::response([
                'status' => 'success',
                'summary' => ['total_questions' => 2, 'overall_quality_score' => 80.0],
                'quality_analysis' => ['overall_quality_score' => 80.0, 'components' => []],
                'recommendations' => [
                    'recommendations' => [
                        [
                            'problem' => 'Potential Question Repetition',
                            'category' => 'similarity',
                            'priority' => 'HIGH',
                            'explanation' => 'Updated explanation from AI',
                            'recommendation' => 'Updated recommendation text',
                        ]
                    ]
                ]
            ], 200),
        ]);

        $response = $this->postJson("/api/ai/assessments/{$this->assessmentA->id}/analyze");
        $response->assertStatus(200);

        // STEP 42 (BUG-001): a re-analysis is a NEW report version — the historical recommendation is untouched,
        // and the faculty decision is carried onto the new version's recommendation together with the updated AI text.
        $rec->refresh();
        $this->assertEquals('accepted', $rec->status);
        $this->assertEquals('Faculty reviewed and decided to rephrase in exam paper.', $rec->faculty_notes);
        $this->assertEquals('Original recommendation', $rec->recommendation, 'Historical recommendation text must not be rewritten');

        $current = AnalysisReport::where('assessment_id', $this->assessmentA->id)->where('is_current', true)->firstOrFail();
        $this->assertNotEquals($report->id, $current->id, 'Re-analysis must produce a new report version');
        $this->assertEquals(80.0, (float) $current->overall_score);
        $this->assertEquals(75.0, (float) $report->fresh()->overall_score, 'Previous version keeps its score');

        $newRec = Recommendation::where('analysis_report_id', $current->id)->where('title', 'Potential Question Repetition')->firstOrFail();
        $this->assertEquals('accepted', $newRec->status);
        $this->assertEquals('Faculty reviewed and decided to rephrase in exam paper.', $newRec->faculty_notes);
        $this->assertEquals('Updated recommendation text', $newRec->recommendation);
    }

    public function test_fastapi_timeout_returns_504_and_records_failed_lifecycle()
    {
        Sanctum::actingAs($this->facultyA);

        Http::fake([
            '*/api/v1/analyze-assessment' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out after 120000 milliseconds');
            },
        ]);

        $response = $this->postJson("/api/ai/assessments/{$this->assessmentA->id}/analyze");

        $response->assertStatus(504)
            ->assertJsonPath('status', 'error');

        $report = AnalysisReport::where('assessment_id', $this->assessmentA->id)->first();
        $this->assertNotNull($report);
        $this->assertEquals('failed', $report->analysis_status);
        $this->assertNotNull($report->processing_error);
    }

    public function test_fastapi_connection_error_returns_502_and_records_failed_lifecycle()
    {
        Sanctum::actingAs($this->facultyA);

        Http::fake([
            '*/api/v1/analyze-assessment' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Failed to connect to 127.0.0.1:8001: Connection refused');
            },
        ]);

        $response = $this->postJson("/api/ai/assessments/{$this->assessmentA->id}/analyze");

        $response->assertStatus(502)
            ->assertJsonPath('status', 'error');

        $report = AnalysisReport::where('assessment_id', $this->assessmentA->id)->first();
        $this->assertNotNull($report);
        $this->assertEquals('failed', $report->analysis_status);
    }

    public function test_malformed_ai_response_score_out_of_range_triggers_rollback()
    {
        Sanctum::actingAs($this->facultyA);

        Http::fake([
            '*/api/v1/analyze-assessment' => Http::response([
                'status' => 'success',
                'quality_analysis' => [
                    'overall_quality_score' => 150.0, // Invalid score > 100
                ],
            ], 200),
        ]);

        $response = $this->postJson("/api/ai/assessments/{$this->assessmentA->id}/analyze");

        $response->assertStatus(502);

        $report = AnalysisReport::where('assessment_id', $this->assessmentA->id)->first();
        $this->assertNotNull($report);
        $this->assertEquals('failed', $report->analysis_status);
        $this->assertStringContainsString('Expected range is 0 to 100', $report->processing_error);
    }

    public function test_get_assessment_analysis_returns_empty_state_when_not_analyzed()
    {
        Sanctum::actingAs($this->facultyA);

        $response = $this->getJson("/api/ai/assessments/{$this->assessmentA->id}/analysis");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.analysis_status', 'not_analyzed')
            ->assertJsonPath('data.report', null)
            ->assertJsonPath('data.assessment.id', $this->assessmentA->id);
    }

    public function test_get_assessment_analysis_blocks_unauthorized_faculty()
    {
        Sanctum::actingAs($this->facultyB);

        $response = $this->getJson("/api/ai/assessments/{$this->assessmentA->id}/analysis");

        $response->assertStatus(403)
            ->assertJsonPath('status', 'error');
    }

    public function test_get_assessment_analysis_returns_full_data_when_analyzed()
    {
        Sanctum::actingAs($this->facultyA);

        $report = AnalysisReport::create([
            'assessment_id' => $this->assessmentA->id,
            'overall_score' => 86.5,
            'topic_coverage_score' => 89.0,
            'learning_outcome_alignment_score' => 84.0,
            'difficulty_balance_score' => 82.0,
            'cognitive_level_balance_score' => 80.0,
            'similarity_score' => 15.0,
            'total_questions' => 2,
            'similar_questions_count' => 0,
            'analysis_status' => 'completed',
            'findings' => [
                'quality' => [
                    'overall_quality_score' => 86.5,
                    'rating' => 'GOOD',
                    'findings' => ['Balanced exam design across topics.'],
                ],
            ],
            'analyzed_at' => now(),
        ]);

        Recommendation::create([
            'analysis_report_id' => $report->id,
            'category' => 'difficulty',
            'problem' => 'Slight difficulty skew',
            'title' => 'Slight difficulty skew',
            'description' => 'Consider adjusting easy questions',
            'explanation' => 'Too few easy questions',
            'recommendation' => 'Add 1 easy question',
            'source_metric' => 'Difficulty',
            'priority' => 'medium',
            'status' => 'pending',
        ]);

        $response = $this->getJson("/api/ai/assessments/{$this->assessmentA->id}/analysis");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.analysis_status', 'completed')
            ->assertJsonPath('data.report.overall_score', 86.5)
            ->assertJsonPath('data.report.rating', 'GOOD')
            ->assertJsonPath('data.recommendation_summary.total_recommendations', 1)
            ->assertJsonPath('data.recommendations.0.title', 'Slight difficulty skew');
    }
}


