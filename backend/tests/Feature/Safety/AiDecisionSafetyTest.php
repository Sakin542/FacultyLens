<?php

namespace Tests\Feature\Safety;

use App\Models\AiGradingResult;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\GeneratedQuestion;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\QuestionGenerationRequest;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Services\AiSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 46: AI grading / rubric / question-generation safety invariants on the Laravel side.
 *
 *  - AI never finalizes, changes or ranks academic marks; faculty columns are untouched by AI output.
 *  - Invalid or unsafe AI output is rejected, audited and NOT persisted.
 *  - Failures produce status FAILED with an honest message, never a fabricated result.
 *  - The internal AI key never appears in responses or audit metadata.
 */
class AiDecisionSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected Course $course;
    protected Assessment $assessment;
    protected Question $question;
    protected Rubric $rubric;
    protected StudentSubmission $submission;
    protected StudentAnswer $answer;
    /** @var RubricCriterion[] */
    protected array $criteria = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.ai.api_key' => 'test-internal-ai-key-XYZ']);

        $this->faculty = User::factory()->create(['email' => 'faculty@university.edu']);
        $this->course = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026']);
        $this->assessment = Assessment::create(['course_id' => $this->course->id, 'title' => 'Midterm', 'type' => 'midterm', 'total_marks' => 30, 'status' => 'draft']);
        $this->question = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Explain database normalization.', 'question_type' => 'descriptive', 'marks' => 10, 'difficulty_level' => 'medium', 'cognitive_level' => 'Understand']);

        $this->rubric = Rubric::create(['question_id' => $this->question->id, 'assessment_id' => $this->assessment->id, 'created_by' => $this->faculty->id, 'title' => 'R', 'total_marks' => 10, 'status' => 'APPROVED', 'version' => 1, 'generation_method' => 'template_based']);
        foreach ([4, 6] as $i => $m) {
            $this->criteria[] = RubricCriterion::create(['rubric_id' => $this->rubric->id, 'criterion' => "C{$i}", 'description' => 'd', 'max_marks' => $m, 'expected_indicators' => [], 'sort_order' => $i + 1]);
        }

        $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'STU001', 'name' => 'Student One', 'email' => 'student.one@university.edu']);
        $this->submission = StudentSubmission::create(['assessment_id' => $this->assessment->id, 'student_id' => $student->id, 'status' => 'SUBMITTED', 'grading_status' => 'NOT_STARTED', 'submitted_at' => now(), 'total_marks' => 10]);
        $this->answer = StudentAnswer::create(['student_submission_id' => $this->submission->id, 'question_id' => $this->question->id, 'answer_type' => 'TEXT', 'answer_text' => 'Normalization reduces redundancy.', 'original_answer_text' => 'Normalization reduces redundancy.', 'answer_status' => 'NOT_REVIEWED']);
    }

    protected function aiGrade(array $marks = [4, 6], array $overrides = []): array
    {
        $results = [];
        foreach ($this->criteria as $i => $c) {
            $results[] = ['rubric_criterion_id' => $c->id, 'criterion' => $c->criterion, 'suggested_marks' => $marks[$i], 'maximum_marks' => (float) $c->max_marks,
                'evaluation' => 'e', 'evidence' => [], 'missing_elements' => [], 'coverage_level' => 'STRONG'];
        }

        return array_replace([
            'status' => 'success', 'suggested_marks' => array_sum($marks), 'maximum_marks' => 10, 'criterion_results' => $results,
            'overall_feedback' => 'ok', 'strengths' => [], 'missing_elements' => [], 'evaluation_summary' => 's',
            'metadata' => ['model' => 'facultylens-grading-engine', 'version' => '1.0.0', 'generation_method' => 'embedding_rubric_alignment', 'generative_model_used' => false],
        ], $overrides);
    }

    // ---- grading: AI suggested ≠ final ---------------------------------------

    public function test_full_marks_suggestion_never_finalizes_passes_or_changes_faculty_marks(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/grade-answer' => Http::response($this->aiGrade([4, 6]))]);

        $this->postJson("/api/student-answers/{$this->answer->id}/ai-grade")->assertStatus(202);

        $result = AiGradingResult::where('student_answer_id', $this->answer->id)->current()->firstOrFail();
        $this->assertSame('COMPLETED', $result->grading_status);
        $this->assertEquals(10, (float) $result->suggested_marks);

        $this->answer->refresh();
        $this->submission->refresh();
        $this->assertNull($this->answer->awarded_marks, 'AI suggested mark must not become the awarded mark');
        $this->assertSame('NOT_REVIEWED', $this->answer->answer_status);
        $this->assertNull($this->answer->faculty_feedback);
        $this->assertSame('SUBMITTED', $this->submission->status, 'AI must not move a submission to GRADED/RETURNED');
        $this->assertSame('AI_ASSISTED', $this->submission->grading_status, 'only an "assisted" marker, never FINALIZED');
        $this->assertNull($this->submission->awarded_marks);
        $this->assertNull($result->faculty_decision);
    }

    public function test_ai_result_cannot_finalize_even_when_response_claims_final_fields(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/grade-answer' => Http::response($this->aiGrade([4, 6], [
            'final_marks' => 10, 'awarded_marks' => 10, 'grading_status' => 'FINALIZED', 'faculty_decision' => 'ACCEPTED', 'answer_status' => 'REVIEWED',
        ]))]);

        $this->postJson("/api/student-answers/{$this->answer->id}/ai-grade")->assertStatus(202);

        $result = AiGradingResult::current()->firstOrFail();
        $this->assertSame('COMPLETED', $result->grading_status);
        $this->assertNull($result->faculty_decision);
        $this->assertNull($this->answer->fresh()->awarded_marks);
        $this->assertSame('NOT_REVIEWED', $this->answer->fresh()->answer_status);
    }

    public function test_faculty_final_mark_is_stored_separately_from_ai_mark(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/grade-answer' => Http::response($this->aiGrade([4, 6]))]);
        $this->postJson("/api/student-answers/{$this->answer->id}/ai-grade")->assertStatus(202);

        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", ['final_marks' => 7, 'decision' => 'MODIFIED'])->assertStatus(200);

        $this->assertEquals(7, (float) $this->answer->fresh()->awarded_marks);
        $this->assertEquals(10, (float) AiGradingResult::current()->first()->suggested_marks, 'AI mark preserved, not overwritten by faculty mark');
    }

    public function test_out_of_range_or_negative_ai_marks_are_rejected_and_nothing_is_saved(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/grade-answer' => Http::response($this->aiGrade([4, 6], ['suggested_marks' => 15]))]);

        $this->postJson("/api/student-answers/{$this->answer->id}/ai-grade")->assertStatus(202);
        $result = AiGradingResult::current()->firstOrFail();
        $this->assertSame('FAILED', $result->grading_status);
        $this->assertNull($result->suggested_marks);
        $this->assertSame(0, $result->criterionResults()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => AiSafetyService::EVENT_RESULT_REJECTED]);
    }

    public function test_service_outage_records_failed_status_with_honest_message_and_no_fake_marks(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/grade-answer' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused to http://ai:8001 (key test-internal-ai-key-XYZ)')]);

        $this->postJson("/api/student-answers/{$this->answer->id}/ai-grade")->assertStatus(202);
        $result = AiGradingResult::current()->firstOrFail();
        $this->assertSame('FAILED', $result->grading_status);
        $this->assertNull($result->suggested_marks);
        $this->assertStringContainsString('unavailable', strtolower($result->error_message));

        $failure = AuditLog::where('action', AiSafetyService::EVENT_SERVICE_FAILURE)->firstOrFail();
        $this->assertStringNotContainsString('test-internal-ai-key', json_encode($failure->metadata));
        $this->assertStringNotContainsString('ai:8001', json_encode($failure->metadata));

        // Faculty data untouched
        $this->assertNull($this->answer->fresh()->awarded_marks);
        $this->assertSame('NOT_STARTED', $this->submission->fresh()->grading_status);
    }

    public function test_retry_after_failure_does_not_duplicate_results(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/grade-answer' => Http::sequence()->push(['error' => 'x'], 503)->push($this->aiGrade([4, 6]))]);

        $this->postJson("/api/student-answers/{$this->answer->id}/ai-grade")->assertStatus(202);
        $this->assertSame('FAILED', AiGradingResult::current()->first()->grading_status);

        $this->postJson("/api/student-answers/{$this->answer->id}/ai-grade")->assertStatus(202);
        $this->assertSame(1, AiGradingResult::where('student_answer_id', $this->answer->id)->where('is_current', true)->count());
        $this->assertSame('COMPLETED', AiGradingResult::current()->first()->grading_status);
    }

    // ---- secrets --------------------------------------------------------------

    public function test_internal_ai_key_is_sent_to_ai_service_but_never_returned_to_clients_or_audit(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/grade-answer' => Http::response($this->aiGrade([4, 6]))]);

        $this->postJson("/api/student-answers/{$this->answer->id}/ai-grade")->assertStatus(202);
        Http::assertSent(fn ($r) => $r->hasHeader('X-AI-Service-Key', 'test-internal-ai-key-XYZ'));

        $show = $this->getJson("/api/student-answers/{$this->answer->id}/ai-grading")->assertStatus(200);
        $this->assertStringNotContainsString('test-internal-ai-key', $show->getContent());
        $this->assertStringNotContainsString('test-internal-ai-key', json_encode(AuditLog::all()->pluck('metadata')));
        $this->assertStringNotContainsString('X-AI-Service-Key', $show->getContent());
    }

    public function test_health_endpoints_do_not_expose_configuration_or_secrets(): void
    {
        foreach (['/api/health', '/api/health/ready'] as $url) {
            $body = $this->getJson($url)->getContent();
            $this->assertStringNotContainsString('test-internal-ai-key', $body);
            $this->assertStringNotContainsString('AI_SERVICE_API_KEY', $body);
            $this->assertStringNotContainsString('DB_PASSWORD', $body);
        }
    }

    // ---- rubric --------------------------------------------------------------

    public function test_rubric_with_criteria_not_summing_to_question_marks_is_rejected(): void
    {
        Sanctum::actingAs($this->faculty);
        $q = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 2, 'question_text' => 'Describe 3NF in detail.', 'question_type' => 'descriptive', 'marks' => 5]);
        Http::fake(['*/api/v1/generate-rubric' => Http::response([
            'status' => 'success', 'generation_method' => 'template_based', 'draft_status' => 'DRAFT',
            'rubric' => ['title' => 'R', 'question_text' => 'Describe 3NF in detail.', 'total_marks' => 8, 'general_guidance' => '',
                'criteria' => [['criterion' => 'A', 'description' => 'd', 'max_marks' => 5, 'scoring_guidance' => '', 'expected_indicators' => [], 'sort_order' => 1],
                               ['criterion' => 'B', 'description' => 'd', 'max_marks' => 3, 'scoring_guidance' => '', 'expected_indicators' => [], 'sort_order' => 2]]],
            'metadata' => ['model' => 'x', 'version' => '1', 'criteria_count' => 2, 'validation_passed' => true],
        ])]);

        $res = $this->postJson("/api/questions/{$q->id}/rubrics/generate");
        $this->assertContains($res->status(), [422, 502, 503], $res->getContent());
        $this->assertSame(0, Rubric::where('question_id', $q->id)->count());
        $this->assertEquals(5, (float) $q->fresh()->marks, 'question marks are never silently changed to match the AI');
    }

    public function test_generated_rubric_is_always_a_draft_never_approved(): void
    {
        Sanctum::actingAs($this->faculty);
        $q = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 3, 'question_text' => 'Explain BCNF with an example.', 'question_type' => 'descriptive', 'marks' => 6]);
        Http::fake(['*/api/v1/generate-rubric' => Http::response([
            'status' => 'success', 'generation_method' => 'ai_assisted', 'draft_status' => 'APPROVED',
            'rubric' => ['title' => 'R', 'question_text' => 'Explain BCNF with an example.', 'total_marks' => 6, 'general_guidance' => '',
                'criteria' => [['criterion' => 'A', 'description' => 'd', 'max_marks' => 6, 'scoring_guidance' => '', 'expected_indicators' => [], 'sort_order' => 1]]],
            'metadata' => ['model' => 'x', 'version' => '1', 'criteria_count' => 1, 'validation_passed' => true],
        ])]);

        $this->postJson("/api/questions/{$q->id}/rubrics/generate")->assertSuccessful();
        $rubric = Rubric::where('question_id', $q->id)->firstOrFail();
        $this->assertSame('DRAFT', $rubric->status);
        $this->assertNull($rubric->approved_at);
    }

    // ---- question generation --------------------------------------------------

    public function test_insufficient_source_material_marks_request_failed_without_drafts(): void
    {
        Sanctum::actingAs($this->faculty);
        $co = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO1', 'description' => 'Analyze normalization.', 'cognitive_level' => 'Analyze', 'sort_order' => 1]);
        Http::fake([
            '*/api/v1/embeddings/batch' => Http::response(['status' => 'success', 'vectors' => [[1, 0, 0, 0]], 'model' => 'm', 'embedding_dimension' => 4]),
            '*/api/v1/generate-questions' => Http::response([
                'status' => 'insufficient_source_material', 'questions' => [], 'generation_method' => 'template', 'model' => 't', 'model_version' => '1',
                'embedding_model' => 'm', 'prompt_version' => '1', 'requested_count' => 2, 'generated_count' => 0,
                'grounding_status' => 'INSUFFICIENT_SOURCE_MATERIAL', 'warnings' => ['Insufficient source material: the retrieved course documents do not cover the requested topic.'], 'disclaimer' => 'd',
            ]),
        ]);

        $res = $this->postJson('/api/question-generation', ['course_id' => $this->course->id, 'assessment_id' => $this->assessment->id, 'topic' => 'Quantum computing',
            'learning_outcome_id' => $co->id, 'question_type' => 'descriptive', 'marks' => 5, 'number_of_questions' => 2])->assertStatus(202);

        $request = QuestionGenerationRequest::findOrFail($res->json('data.id'));
        $this->assertSame('FAILED', $request->generation_status);
        $this->assertStringContainsString('Insufficient source material', $request->error_message);
        $this->assertSame(0, GeneratedQuestion::count());
        $this->assertDatabaseHas('audit_logs', ['action' => AiSafetyService::EVENT_RESULT_REJECTED, 'entity_type' => 'QuestionGenerationRequest']);
    }

    public function test_generated_drafts_are_never_added_to_assessment_automatically(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake([
            '*/api/v1/embeddings/batch' => Http::response(['status' => 'success', 'vectors' => [[1, 0, 0, 0]], 'model' => 'm', 'embedding_dimension' => 4]),
            '*/api/v1/generate-questions' => Http::response([
                'status' => 'success', 'generation_method' => 'template', 'model' => 't', 'model_version' => '1', 'embedding_model' => 'm', 'prompt_version' => '1',
                'requested_count' => 1, 'generated_count' => 1, 'warnings' => [], 'disclaimer' => 'd', 'grounding_status' => 'NOT_REQUESTED',
                'questions' => [['question_text' => 'Analyze the normalization anomalies in the given schema and justify the decomposition.', 'question_type' => 'DESCRIPTIVE', 'marks' => 5,
                    'difficulty_level' => 'MEDIUM', 'cognitive_level' => 'ANALYZE', 'topic' => 'Normalization', 'review_status' => 'APPROVED', 'official_question_id' => 1,
                    'validation' => ['overall_status' => 'PASSED', 'warnings' => []]]],
            ]),
        ]);

        $before = Question::count();
        $this->postJson('/api/question-generation', ['course_id' => $this->course->id, 'assessment_id' => $this->assessment->id, 'topic' => 'Normalization',
            'question_type' => 'descriptive', 'marks' => 5, 'number_of_questions' => 1])->assertStatus(202);

        $draft = GeneratedQuestion::firstOrFail();
        $this->assertSame('DRAFT', $draft->review_status, 'AI-claimed approval is ignored');
        $this->assertNull($draft->official_question_id);
        $this->assertSame($before, Question::count(), 'no official question is created without faculty approval');
    }

    // ---- AiSafetyService unit behaviour -----------------------------------------

    public function test_safety_service_detects_unsupported_certainty_and_confidence_claims(): void
    {
        $svc = app(AiSafetyService::class);
        $this->assertNotEmpty($svc->unsupportedCertainty('This is definitely an exact duplicate and 99% accurate.'));
        $this->assertSame([], $svc->unsupportedCertainty('Potential duplicate based on semantic similarity; review recommended.'));
        $this->assertTrue($svc->containsSecretLeak('key: hf_abcdefghijklmnopqrstuvwxyz'));
        $this->assertFalse($svc->containsSecretLeak('Normalization reduces redundancy.'));
        $this->assertSame(['cited' => [1, 7], 'invalid' => [7], 'fabricated' => false], $svc->validateCitations('A [S1] and B [S7]', 2));
        $this->assertTrue($svc->validateCitations('Only [S9]', 2)['fabricated']);
        $this->assertSame(0.84, $svc->normalizeConfidence(84));
        $this->assertNull($svc->normalizeConfidence(1.5));
        $this->assertNull($svc->normalizeConfidence('high'));
    }
}
