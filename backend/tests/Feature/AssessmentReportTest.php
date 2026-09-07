<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\AssessmentReport;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\PreviousQuestion;
use App\Models\Question;
use App\Models\QuestionLearningOutcomeAlignment;
use App\Models\QuestionSimilarityMatch;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssessmentReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $facultyA;
    protected User $facultyB;
    protected Course $courseA;
    protected Assessment $assessmentA;
    protected Question $q1;
    protected Question $q2;
    protected LearningOutcome $lo1;
    protected AnalysisReport $analysisReport;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->facultyA = User::factory()->create([
            'name' => 'Dr. Alan Turing',
            'email' => 'turing@cambridge.edu',
            'department' => 'Computer Science',
        ]);

        $this->facultyB = User::factory()->create([
            'name' => 'Dr. Ada Lovelace',
            'email' => 'lovelace@oxford.edu',
            'department' => 'Mathematics',
        ]);

        $this->courseA = Course::create([
            'user_id' => $this->facultyA->id,
            'course_code' => 'CS-301',
            'course_name' => 'Theory of Computation',
            'semester' => 'Fall',
            'academic_year' => '2026',
        ]);

        $this->assessmentA = Assessment::create([
            'course_id' => $this->courseA->id,
            'title' => 'Midterm Examination 2026',
            'type' => 'midterm',
            'total_marks' => 50,
            'duration_minutes' => 90,
            'assessment_date' => '2026-10-15',
            'status' => 'draft',
        ]);

        $this->lo1 = LearningOutcome::create([
            'course_id' => $this->courseA->id,
            'code' => 'LO1',
            'description' => 'Understand and construct Deterministic Finite Automata.',
        ]);

        $this->q1 = Question::create([
            'assessment_id' => $this->assessmentA->id,
            'question_number' => 1,
            'question_text' => 'Construct a DFA that recognizes the language of binary strings with an even number of zeros.',
            'marks' => 20,
            'difficulty_level' => 'easy',
            'cognitive_level' => 'apply',
            'learning_outcome_id' => $this->lo1->id,
        ]);

        $this->q2 = Question::create([
            'assessment_id' => $this->assessmentA->id,
            'question_number' => 2,
            'question_text' => 'Prove that the language L = {0^n 1^n | n >= 0} is not regular using the Pumping Lemma.',
            'marks' => 30,
            'difficulty_level' => 'hard',
            'cognitive_level' => 'analyze',
            'learning_outcome_id' => $this->lo1->id,
        ]);

        $this->analysisReport = AnalysisReport::create([
            'assessment_id' => $this->assessmentA->id,
            'overall_score' => 88.50,
            'topic_coverage_score' => 85.00,
            'learning_outcome_alignment_score' => 92.00,
            'difficulty_balance_score' => 84.00,
            'cognitive_level_balance_score' => 86.00,
            'similarity_score' => 12.00,
            'total_questions' => 2,
            'similar_questions_count' => 1,
            'analysis_status' => 'completed',
            'analyzed_at' => now(),
            'findings' => [
                'quality_analysis' => [
                    'overall_quality_score' => 88.5,
                    'rating' => 'GOOD',
                    'topic_coverage_score' => 85.0,
                    'learning_outcome_alignment_score' => 92.0,
                    'difficulty_balance_score' => 84.0,
                    'cognitive_level_balance_score' => 86.0,
                    'question_diversity_score' => 88.0,
                    'marks_distribution_score' => 90.0,
                    'topic_coverage_details' => [
                        'total_topics' => 3,
                        'covered_topics' => 2,
                        'low_coverage_topics' => 1,
                        'not_covered_topics' => 0,
                        'topics' => [
                            ['topic' => 'Finite Automata', 'status' => 'COVERED', 'question_count' => 1, 'marks' => 20],
                            ['topic' => 'Pumping Lemma', 'status' => 'COVERED', 'question_count' => 1, 'marks' => 30],
                            ['topic' => 'Turing Machines', 'status' => 'LOW_COVERAGE', 'question_count' => 0, 'marks' => 0],
                        ],
                    ],
                    'findings' => [
                        [
                            'severity' => 'info',
                            'category' => 'Balance',
                            'problem' => 'Assessment has a strong concentration in theoretical proofs.',
                            'evidence' => 'Question 2 carries 60% of total marks.',
                            'explanation' => 'Consider breaking proof into structured sub-questions.',
                        ],
                    ],
                ],
            ],
        ]);

        QuestionLearningOutcomeAlignment::create([
            'analysis_report_id' => $this->analysisReport->id,
            'question_id' => $this->q1->id,
            'learning_outcome_id' => $this->lo1->id,
            'similarity_score' => 0.91,
            'alignment' => 'strong',
            'reasoning' => 'Question directly asks for DFA construction which tests LO1.',
        ]);

        $prevQ = PreviousQuestion::create([
            'user_id' => $this->facultyA->id,
            'course_id' => $this->courseA->id,
            'question_text' => 'Design a DFA accepting all binary strings with an even number of 0s.',
            'marks' => 15,
            'source_assessment' => 'Midterm 2024',
            'source_year' => 2024,
        ]);

        QuestionSimilarityMatch::create([
            'analysis_report_id' => $this->analysisReport->id,
            'current_question_id' => $this->q1->id,
            'previous_question_id' => $prevQ->id,
            'similarity_score' => 0.88,
            'similarity_status' => 'duplicate',
            'reasoning' => 'Semantically and syntactically almost identical to 2024 question.',
        ]);

        Recommendation::create([
            'analysis_report_id' => $this->analysisReport->id,
            'title' => 'High Question Similarity',
            'description' => 'Vary the language condition to preserve assessment freshness.',
            'category' => 'Similarity',
            'priority' => 'high',
            'status' => 'pending',
            'problem' => 'Question 1 is highly similar to Midterm 2024 Question.',
            'recommendation' => 'Vary the language condition to preserve assessment freshness.',
            'evidence' => ['88% match with Midterm 2024.'],
            'explanation' => 'High recurrence of questions reduces exam discriminant power.',
        ]);
    }

    public function test_owner_can_view_assessment_report_data(): void
    {
        Sanctum::actingAs($this->facultyA);

        $response = $this->getJson("/api/assessments/{$this->assessmentA->id}/report");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.assessment.course_code', 'CS-301')
            ->assertJsonPath('data.assessment.title', 'Midterm Examination 2026')
            ->assertJsonPath('data.overall_quality.score', 88.5)
            ->assertJsonPath('data.overall_quality.rating', 'GOOD')
            ->assertJsonStructure([
                'status',
                'data' => [
                    'assessment',
                    'overall_quality' => ['score', 'rating', 'dimensions'],
                    'topic_coverage',
                    'learning_outcome_alignment',
                    'difficulty_distribution',
                    'cognitive_distribution',
                    'similar_questions',
                    'findings',
                    'recommendations',
                    'disclaimer',
                    'engine_metadata',
                ],
            ]);
    }

    public function test_unauthorized_user_cannot_view_assessment_report_data(): void
    {
        Sanctum::actingAs($this->facultyB);

        $response = $this->getJson("/api/assessments/{$this->assessmentA->id}/report");

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_view_assessment_report_data(): void
    {
        $response = $this->getJson("/api/assessments/{$this->assessmentA->id}/report");

        $response->assertStatus(401);
    }

    public function test_report_fails_if_analysis_is_not_completed(): void
    {
        Sanctum::actingAs($this->facultyA);

        $this->analysisReport->update(['analysis_status' => 'processing']);

        $response = $this->getJson("/api/assessments/{$this->assessmentA->id}/report");

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_owner_can_generate_pdf_report(): void
    {
        Sanctum::actingAs($this->facultyA);

        $response = $this->postJson("/api/assessments/{$this->assessmentA->id}/report/generate");

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.report.generation_status', 'completed')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'report' => ['id', 'uuid', 'file_name', 'file_size', 'generation_status'],
                    'download_url',
                ],
            ]);

        $reportId = $response->json('data.report.id');
        $report = AssessmentReport::findOrFail($reportId);

        $this->assertEquals($this->assessmentA->id, $report->assessment_id);
        $this->assertEquals('completed', $report->generation_status);
        $this->assertNotEmpty($report->file_path);
        $this->assertTrue(Storage::disk('local')->exists($report->file_path));
    }

    public function test_owner_can_download_generated_pdf(): void
    {
        Sanctum::actingAs($this->facultyA);

        $genResponse = $this->postJson("/api/assessments/{$this->assessmentA->id}/report/generate");
        $reportId = $genResponse->json('data.report.id');

        $downloadResponse = $this->get("/api/assessment-reports/{$reportId}/download");

        $downloadResponse->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $downloadResponse->headers->get('Content-Type'));
    }

    public function test_non_owner_cannot_download_generated_pdf(): void
    {
        Sanctum::actingAs($this->facultyA);
        $genResponse = $this->postJson("/api/assessments/{$this->assessmentA->id}/report/generate");
        $reportId = $genResponse->json('data.report.id');

        Sanctum::actingAs($this->facultyB);
        $downloadResponse = $this->get("/api/assessment-reports/{$reportId}/download");

        $downloadResponse->assertStatus(403);
    }

    public function test_owner_can_share_and_revoke_report(): void
    {
        Sanctum::actingAs($this->facultyA);

        $genResponse = $this->postJson("/api/assessments/{$this->assessmentA->id}/report/generate");
        $reportId = $genResponse->json('data.report.id');
        $report = AssessmentReport::findOrFail($reportId);

        // Share report
        $shareResponse = $this->postJson("/api/assessment-reports/{$report->id}/share");
        $shareResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.is_shareable', true);

        $token = $shareResponse->json('data.share_token');
        $this->assertNotEmpty($token);

        // Verify public view
        $publicViewResponse = $this->getJson("/api/shared/reports/{$token}");
        $publicViewResponse->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.assessment.course_code', 'CS-301');

        // Verify public download
        $publicDownloadResponse = $this->get("/api/shared/reports/{$token}/download");
        $publicDownloadResponse->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $publicDownloadResponse->headers->get('Content-Type'));

        // Revoke share
        $revokeResponse = $this->postJson("/api/assessment-reports/{$report->id}/revoke-share");
        $revokeResponse->assertStatus(200)
            ->assertJsonPath('data.is_shareable', false);

        // Subsequent public view returns 404
        $subsequentView = $this->getJson("/api/shared/reports/{$token}");
        $subsequentView->assertStatus(404);

        // Subsequent public download returns 404
        $subsequentDownload = $this->get("/api/shared/reports/{$token}/download");
        $subsequentDownload->assertStatus(404);
    }
}
