<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\DocumentChunk;
use App\Models\DocumentProcessing;
use App\Models\GeneratedQuestion;
use App\Models\LearningOutcome;
use App\Models\PreviousQuestion;
use App\Models\Program;
use App\Models\ProgramOutcome;
use App\Models\Question;
use App\Models\QuestionGenerationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 33: Constrained Question Generator. AI service faked; queue is sync in tests so requests complete inline.
 */
class QuestionGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected User $other;
    protected Program $program;
    protected ProgramOutcome $po;
    protected Course $course;
    protected Course $otherCourse;
    protected LearningOutcome $co;
    protected LearningOutcome $foreignCo;
    protected Assessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faculty = User::factory()->create(['email' => 'faculty@university.edu']);
        $this->other = User::factory()->create(['email' => 'other@university.edu']);
        $this->program = Program::create(['code' => 'CSE', 'name' => 'Computer Science', 'created_by' => $this->faculty->id]);
        $this->po = ProgramOutcome::create(['program_id' => $this->program->id, 'code' => 'PO2', 'title' => 'Problem Analysis', 'sort_order' => 2]);
        $this->course = Course::create(['user_id' => $this->faculty->id, 'program_id' => $this->program->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026']);
        $this->otherCourse = Course::create(['user_id' => $this->other->id, 'course_code' => 'EEE201', 'course_name' => 'Circuits', 'semester' => 'Fall', 'academic_year' => '2026']);
        $this->co = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO2', 'description' => 'Analyze database structures and identify normalization issues.', 'cognitive_level' => 'Analyze', 'sort_order' => 2]);
        $this->foreignCo = LearningOutcome::create(['course_id' => $this->otherCourse->id, 'code' => 'CO1', 'description' => 'Apply Ohm law.', 'cognitive_level' => 'Apply', 'sort_order' => 1]);
        $this->assessment = Assessment::create(['course_id' => $this->course->id, 'title' => 'Midterm Examination', 'type' => 'midterm', 'total_marks' => 30, 'status' => 'draft']);
        Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Explain the process of converting a relation into Third Normal Form.', 'question_type' => 'descriptive', 'marks' => 10, 'cognitive_level' => 'Understand', 'learning_outcome_id' => $this->co->id]);
        PreviousQuestion::create(['user_id' => $this->faculty->id, 'course_id' => $this->course->id, 'question_text' => 'Define functional dependency.', 'question_type' => 'short_answer', 'source' => 'previous_exam', 'source_year' => '2025']);
    }

    protected function aiQuestion(array $overrides = []): array
    {
        return array_merge([
            'question_text' => 'Analyze the given relational schema, identify the normalization anomalies present, and explain the trade-offs of decomposing it.',
            'question_type' => 'DESCRIPTIVE', 'marks' => 10, 'difficulty_level' => 'MEDIUM', 'cognitive_level' => 'ANALYZE', 'topic' => 'Normalization',
            'options' => null, 'correct_option' => null, 'expected_answer' => 'Identify partial and transitive dependencies…', 'explanation' => null, 'source_chunk_ids' => [],
            'validation' => [
                'detected_question_type' => 'ANALYTICAL', 'detected_difficulty' => 'MEDIUM', 'detected_cognitive_level' => 'ANALYZE', 'detected_topics' => ['Normalization'],
                'co_alignment_score' => 0.82, 'co_alignment_status' => 'STRONG', 'max_similarity_score' => 0.21, 'similarity_status' => 'NOT_SIMILAR', 'similar_questions' => [],
                'constraints' => ['topic' => true, 'question_type' => true, 'difficulty' => true, 'cognitive_level' => true, 'co_alignment' => true, 'similarity' => true, 'marks' => true],
                'warnings' => [], 'overall_status' => 'PASSED',
            ],
        ], $overrides);
    }

    protected function fakeAi(?array $questions = null, array $extra = []): void
    {
        Http::fake([
            '*/api/v1/embeddings/batch' => Http::response(['status' => 'success', 'vectors' => [[1, 0, 0, 0]], 'model' => 'm', 'embedding_dimension' => 4]),
            '*/api/v1/generate-questions' => function ($request) use ($questions, $extra) {
                $n = $request->data()['number_of_questions'] ?? 1;
                $qs = $questions ?? array_map(fn ($i) => $this->aiQuestion(['question_text' => "Analyze normalization scenario number {$i} and identify its anomalies in detail."]), range(1, $n));
                return Http::response(array_merge([
                    'status' => 'success', 'questions' => $qs, 'generation_method' => 'template', 'model' => 'facultylens-constrained-question-template-engine',
                    'model_version' => '1.0.0', 'embedding_model' => 'sentence-transformers/all-MiniLM-L6-v2', 'prompt_version' => '1.0.0',
                    'requested_count' => $n, 'generated_count' => count($qs), 'blueprint_summary' => null, 'warnings' => [], 'disclaimer' => 'Drafts.',
                ], $extra));
            },
        ]);
    }

    protected function basePayload(array $overrides = []): array
    {
        return array_merge([
            'course_id' => $this->course->id, 'assessment_id' => $this->assessment->id, 'topic' => 'Normalization', 'learning_outcome_id' => $this->co->id,
            'program_outcome_id' => $this->po->id, 'question_type' => 'descriptive', 'difficulty_level' => 'medium', 'cognitive_level' => 'Analyze',
            'marks' => 10, 'number_of_questions' => 3, 'include_expected_answer' => true,
        ], $overrides);
    }

    protected function generate(array $overrides = []): QuestionGenerationRequest
    {
        Sanctum::actingAs($this->faculty);
        $res = $this->postJson('/api/question-generation', $this->basePayload($overrides));
        $res->assertStatus(202);

        return QuestionGenerationRequest::findOrFail($res->json('data.id'));
    }

    // ---- auth / ownership -------------------------------------------------

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/question-generation', $this->basePayload())->assertStatus(401);
        $this->getJson('/api/question-generation')->assertStatus(401);
    }

    public function test_cannot_generate_for_another_users_course(): void
    {
        Sanctum::actingAs($this->other);
        $this->postJson('/api/question-generation', $this->basePayload())->assertStatus(403);
    }

    public function test_co_po_assessment_and_document_must_belong_to_course(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->postJson('/api/question-generation', $this->basePayload(['learning_outcome_id' => $this->foreignCo->id]))->assertStatus(422);
        $otherAssessment = Assessment::create(['course_id' => $this->otherCourse->id, 'title' => 'Quiz', 'type' => 'quiz', 'total_marks' => 10, 'status' => 'draft']);
        $this->postJson('/api/question-generation', $this->basePayload(['assessment_id' => $otherAssessment->id]))->assertStatus(403);
        $otherProgram = Program::create(['code' => 'EEE', 'name' => 'Electrical', 'created_by' => $this->other->id]);
        $otherPo = ProgramOutcome::create(['program_id' => $otherProgram->id, 'code' => 'PO1', 'title' => 'x', 'sort_order' => 1]);
        $this->postJson('/api/question-generation', $this->basePayload(['program_outcome_id' => $otherPo->id]))->assertStatus(422);
        $foreignDoc = DocumentProcessing::create(['user_id' => $this->other->id, 'course_id' => $this->otherCourse->id, 'document_type' => 'syllabus', 'original_file_name' => 'x.pdf', 'stored_file_name' => 'x.pdf', 'file_path' => 'documents/x.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1, 'processing_status' => 'completed', 'indexing_status' => 'INDEXED']);
        $this->postJson('/api/question-generation', $this->basePayload(['document_scope' => ['scope_type' => 'DOCUMENT', 'document_id' => $foreignDoc->id]]))->assertStatus(403);
    }

    public function test_validation_rules(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->postJson('/api/question-generation', $this->basePayload(['number_of_questions' => 21]))->assertStatus(422);
        $this->postJson('/api/question-generation', $this->basePayload(['number_of_questions' => 0]))->assertStatus(422);
        $this->postJson('/api/question-generation', $this->basePayload(['marks' => 0]))->assertStatus(422);
        $this->postJson('/api/question-generation', $this->basePayload(['marks' => -5]))->assertStatus(422);
        $this->postJson('/api/question-generation', $this->basePayload(['marks' => 5000]))->assertStatus(422);
        $this->postJson('/api/question-generation', $this->basePayload(['question_type' => 'essay']))->assertStatus(422);
        $this->postJson('/api/question-generation', $this->basePayload(['cognitive_level' => 'Guess']))->assertStatus(422);
        $this->postJson('/api/question-generation', $this->basePayload(['difficulty_level' => 'impossible']))->assertStatus(422);
    }

    // ---- generation --------------------------------------------------------

    public function test_generates_drafts_without_creating_official_questions(): void
    {
        $this->fakeAi();
        $request = $this->generate(['number_of_questions' => 5]);

        $this->assertSame('COMPLETED', $request->generation_status);
        $this->assertSame(5, $request->generatedQuestions()->count());
        $this->assertSame(1, Question::count(), 'Generation must not publish official questions');
        $this->assertSame('facultylens-constrained-question-template-engine', $request->generation_model);
        $this->assertSame('1.0.0', $request->prompt_version);
        $this->assertSame('sentence-transformers/all-MiniLM-L6-v2', $request->embedding_model);
        $this->assertSame(5, $request->set_summary['total']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'QUESTION_GENERATION_REQUESTED', 'entity_id' => $request->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'QUESTION_GENERATED', 'entity_id' => $request->id]);

        $draft = $request->generatedQuestions()->first();
        $this->assertSame('DRAFT', $draft->review_status);
        $this->assertSame('PASSED', $draft->validation_status);
        $this->assertSame('descriptive', $draft->question_type);
        $this->assertSame('medium', $draft->difficulty_level);
        $this->assertSame('Analyze', $draft->cognitive_level);
        $this->assertSame($this->co->id, $draft->learning_outcome_id);
        $this->assertSame($draft->question_text, $draft->original_question_text);

        // The AI service received grounded, owner-scoped context
        Http::assertSent(function ($r) {
            if (!str_contains($r->url(), 'generate-questions')) {
                return false;
            }
            $d = $r->data();
            $sources = array_column($d['existing_question_context'], 'source');
            return $d['question_type'] === 'DESCRIPTIVE' && $d['cognitive_level'] === 'ANALYZE' && $d['difficulty_level'] === 'MEDIUM'
                && $d['learning_outcome']['code'] === 'CO2' && $d['program_outcome']['code'] === 'PO2' && $d['topic'] === 'Normalization'
                && in_array('assessment', $sources, true) && in_array('previous', $sources, true) && $d['number_of_questions'] === 5;
        });
    }

    public function test_show_and_list_are_owner_scoped_and_expose_validation(): void
    {
        $this->fakeAi();
        $request = $this->generate();
        $this->getJson("/api/question-generation/{$request->id}")->assertOk()
            ->assertJsonPath('data.generation_status', 'COMPLETED')
            ->assertJsonPath('data.questions.0.validation.co_alignment_status', 'STRONG')
            ->assertJsonPath('data.questions.0.review_status', 'DRAFT')
            ->assertJsonPath('data.assessment.remaining_marks', 20);
        $this->getJson('/api/question-generation')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->other);
        $this->getJson("/api/question-generation/{$request->id}")->assertStatus(403);
        $this->getJson("/api/question-generation/{$request->id}/questions")->assertStatus(403);
        $this->getJson('/api/question-generation')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_document_context_is_retrieved_from_indexed_course_documents(): void
    {
        $doc = DocumentProcessing::create(['user_id' => $this->faculty->id, 'course_id' => $this->course->id, 'document_type' => 'syllabus', 'original_file_name' => 'syllabus.pdf', 'stored_file_name' => 's.pdf', 'file_path' => 'documents/s.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1, 'processing_status' => 'completed', 'cleaned_text' => 'x', 'indexing_status' => 'INDEXED']);
        DocumentChunk::create(['document_processing_id' => $doc->id, 'user_id' => $this->faculty->id, 'course_id' => $this->course->id, 'chunk_index' => 0, 'content' => 'Normalization reduces redundancy.', 'content_hash' => 'h', 'page_number' => 3, 'section_title' => 'Unit 2', 'word_count' => 3, 'embedding' => DocumentChunk::packEmbedding([1, 0, 0, 0]), 'embedding_dimension' => 4, 'embedding_model' => 'm', 'embedding_version' => '1']);
        $this->fakeAi();
        $request = $this->generate(['number_of_questions' => 1]);

        $this->assertSame(1, $request->retrieved_chunks);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'generate-questions') && ($r->data()['document_context'][0]['document_name'] ?? null) === 'syllabus.pdf'
            && $r->data()['document_context'][0]['page_number'] === 3 && !str_contains(json_encode($r->data()), 'documents/s.pdf'));
    }

    public function test_marks_exceeding_remaining_allocation_produce_warning_not_change(): void
    {
        $this->fakeAi();
        $request = $this->generate(['number_of_questions' => 3, 'marks' => 10]); // remaining 20, requested 30
        $this->assertStringContainsString('exceed the remaining assessment allocation', $request->warnings[0]);
        $this->assertEquals(30, $this->assessment->fresh()->total_marks);
    }

    public function test_malformed_or_invalid_ai_output_is_not_persisted(): void
    {
        Http::fake([
            '*/api/v1/embeddings/batch' => Http::response(['status' => 'success', 'vectors' => [[1, 0, 0, 0]], 'model' => 'm', 'embedding_dimension' => 4]),
            '*/api/v1/generate-questions' => Http::response(['unexpected' => true]),
        ]);
        $request = $this->generate(['number_of_questions' => 2]);
        $this->assertSame('FAILED', $request->generation_status);
        $this->assertNotNull($request->error_message);
        $this->assertStringNotContainsString('malformed', $request->error_message);
        $this->assertSame(0, GeneratedQuestion::count());
    }

    public function test_invalid_ai_items_are_dropped_and_valid_ones_kept(): void
    {
        $this->fakeAi([
            $this->aiQuestion(['question_text' => 'short']),          // too short
            $this->aiQuestion(['marks' => 0]),                       // invalid marks
            $this->aiQuestion(['question_type' => 'ESSAY']),         // invalid type
            $this->aiQuestion(['question_text' => 'Analyze the schema below and identify every normalization anomaly it contains.']), // valid
        ]);
        $request = $this->generate(['number_of_questions' => 4]);
        $this->assertSame('COMPLETED', $request->generation_status);
        $this->assertSame(1, $request->generatedQuestions()->count());
    }

    public function test_ai_service_failure_marks_request_failed(): void
    {
        Http::fake(['*/api/v1/generate-questions' => Http::response(['error' => 'down'], 500), '*' => Http::response([], 500)]);
        $request = $this->generate();
        $this->assertSame('FAILED', $request->generation_status);
        $this->assertSame(0, GeneratedQuestion::count());
    }

    public function test_validation_warnings_are_preserved_and_visible(): void
    {
        $this->fakeAi([$this->aiQuestion(['validation' => array_merge($this->aiQuestion()['validation'], [
            'detected_cognitive_level' => 'UNDERSTAND', 'co_alignment_score' => 0.42, 'co_alignment_status' => 'NOT_ALIGNED', 'max_similarity_score' => 0.88, 'similarity_status' => 'POTENTIAL_DUPLICATE',
            'similar_questions' => [['existing_id' => 1, 'source' => 'assessment', 'label' => 'Midterm Q1', 'text' => 'Explain…', 'similarity_score' => 0.88, 'status' => 'POTENTIAL_DUPLICATE']],
            'constraints' => ['topic' => true, 'question_type' => true, 'difficulty' => true, 'cognitive_level' => false, 'co_alignment' => false, 'similarity' => false, 'marks' => true],
            'warnings' => ['Cognitive-level mismatch: requested ANALYZE, AI-detected UNDERSTAND.', 'Potential duplicate of an existing question (similarity 0.88). Faculty review required.'],
            'overall_status' => 'FAILED',
        ])])]);
        $request = $this->generate(['number_of_questions' => 1]);
        $q = $request->generatedQuestions()->first();
        $this->assertSame('FAILED', $q->validation_status);
        $this->assertSame('DRAFT', $q->review_status); // still a draft for faculty decision
        $this->assertSame(1, $request->set_summary['potential_duplicates']);
        $this->assertSame(1, $request->set_summary['weak_alignment']);
        $this->getJson("/api/question-generation/{$request->id}")->assertOk()
            ->assertJsonPath('data.questions.0.validation.similarity_status', 'POTENTIAL_DUPLICATE')
            ->assertJsonPath('data.questions.0.validation.constraints.cognitive_level', false)
            ->assertJsonCount(2, 'data.questions.0.validation.warnings');
    }

    // ---- review workflow ----------------------------------------------------

    public function test_edit_preserves_original_and_versions(): void
    {
        $this->fakeAi();
        $q = $this->generate(['number_of_questions' => 1])->generatedQuestions()->first();
        $original = $q->question_text;

        $this->putJson("/api/generated-questions/{$q->id}", ['question_text' => 'Analyze the following order-processing schema and identify each normalization anomaly with justification.', 'marks' => 8])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.review_status', 'REVIEWED')
            ->assertJsonPath('data.is_edited', true)
            ->assertJsonPath('data.original_question_text', $original)
            ->assertJsonPath('data.marks', 8);
        $this->assertDatabaseHas('audit_logs', ['action' => 'QUESTION_EDITED', 'entity_id' => $q->id]);
        $this->putJson("/api/generated-questions/{$q->id}", ['learning_outcome_id' => $this->foreignCo->id])->assertStatus(422);
        $this->putJson("/api/generated-questions/{$q->id}", ['question_text' => 'x'])->assertStatus(422);

        Sanctum::actingAs($this->other);
        $this->putJson("/api/generated-questions/{$q->id}", ['marks' => 1])->assertStatus(403);
    }

    public function test_approve_reject_and_add_to_assessment_flow(): void
    {
        $this->fakeAi();
        $request = $this->generate(['number_of_questions' => 2]);
        [$q1, $q2] = $request->generatedQuestions()->get()->all();

        // Draft cannot be added
        $this->postJson("/api/generated-questions/{$q1->id}/add-to-assessment")->assertStatus(422);
        $this->assertSame(1, Question::count());

        $this->postJson("/api/generated-questions/{$q1->id}/approve", ['note' => 'Good'])->assertOk()->assertJsonPath('data.review_status', 'APPROVED')->assertJsonPath('data.can_add_to_assessment', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'QUESTION_APPROVED', 'entity_id' => $q1->id]);
        $this->assertSame(1, Question::count(), 'Approval alone must not create official questions');

        $res = $this->postJson("/api/generated-questions/{$q1->id}/add-to-assessment");
        $res->assertStatus(201)->assertJsonPath('data.question.assessment_id', $this->assessment->id)->assertJsonPath('data.question.question_number', 2)
            ->assertJsonPath('data.generated_question.can_add_to_assessment', false);
        $official = Question::find($res->json('data.question.id'));
        $this->assertSame($q1->question_text, $official->question_text);
        $this->assertSame('descriptive', $official->question_type);
        $this->assertEquals(10, $official->marks);
        $this->assertSame($this->co->id, $official->learning_outcome_id);
        $this->assertSame('medium', $official->ai_difficulty_level);
        $this->assertSame('Analyze', $official->ai_cognitive_level);
        $this->assertSame($official->id, $q1->fresh()->official_question_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'QUESTION_ADDED_TO_ASSESSMENT', 'entity_id' => $official->id]);

        // Cannot add twice
        $this->postJson("/api/generated-questions/{$q1->id}/add-to-assessment")->assertStatus(422);

        // Reject the other
        $this->postJson("/api/generated-questions/{$q2->id}/reject", ['note' => 'Too similar'])->assertOk()->assertJsonPath('data.review_status', 'REJECTED');
        $this->postJson("/api/generated-questions/{$q2->id}/approve")->assertStatus(422);
        $this->postJson("/api/generated-questions/{$q2->id}/add-to-assessment")->assertStatus(422);
        $this->assertSame(2, Question::count());
    }

    public function test_add_to_assessment_respects_remaining_marks_and_ownership(): void
    {
        $this->fakeAi([$this->aiQuestion(['marks' => 25])]);
        $q = $this->generate(['number_of_questions' => 1, 'marks' => 25])->generatedQuestions()->first();
        $this->postJson("/api/generated-questions/{$q->id}/approve")->assertOk();
        $this->postJson("/api/generated-questions/{$q->id}/add-to-assessment")->assertStatus(422)->assertJsonFragment(['message' => 'Adding this question (25 marks) would exceed the assessment total; 20 marks remain.']);

        $otherAssessment = Assessment::create(['course_id' => $this->otherCourse->id, 'title' => 'Quiz', 'type' => 'quiz', 'total_marks' => 100, 'status' => 'draft']);
        $this->postJson("/api/generated-questions/{$q->id}/add-to-assessment", ['assessment_id' => $otherAssessment->id])->assertStatus(403);

        Sanctum::actingAs($this->other);
        $this->postJson("/api/generated-questions/{$q->id}/approve")->assertStatus(403);
        $this->postJson("/api/generated-questions/{$q->id}/add-to-assessment")->assertStatus(403);
        $this->assertSame(1, Question::count());
    }

    public function test_regenerate_question_keeps_history_and_respects_limit(): void
    {
        config(['question_generation.max_regenerations_per_request' => 1]);
        $this->fakeAi();
        $request = $this->generate(['number_of_questions' => 1]);
        $q = $request->generatedQuestions()->first();

        $res = $this->postJson("/api/generated-questions/{$q->id}/regenerate", ['feedback' => ['too_easy', 'poor_wording'], 'feedback_note' => 'Use an e-commerce schema']);
        $res->assertStatus(201)->assertJsonPath('data.regenerated_from_id', $q->id)->assertJsonPath('data.review_status', 'DRAFT');
        $this->assertSame('REJECTED', $q->fresh()->review_status);
        $this->assertSame(2, $request->generatedQuestions()->count());
        $this->assertSame(1, $request->fresh()->regeneration_count);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'generate-questions') && $r->data()['number_of_questions'] === 1
            && in_array('Too easy', $r->data()['feedback'], true) && in_array('Use an e-commerce schema', $r->data()['feedback'], true));

        $new = GeneratedQuestion::find($res->json('data.id'));
        $this->postJson("/api/generated-questions/{$new->id}/regenerate")->assertStatus(422); // limit reached
        $this->postJson("/api/question-generation/{$request->id}/regenerate")->assertStatus(422);
        $this->postJson("/api/generated-questions/{$new->id}/regenerate", ['feedback' => ['bogus']])->assertStatus(422);
    }

    public function test_regenerate_request_supersedes_unapproved_drafts(): void
    {
        $this->fakeAi();
        $request = $this->generate(['number_of_questions' => 2]);
        [$q1, $q2] = $request->generatedQuestions()->get()->all();
        $this->postJson("/api/generated-questions/{$q1->id}/approve")->assertOk();

        $this->postJson("/api/question-generation/{$request->id}/regenerate", ['feedback' => ['too_similar']])->assertStatus(202);
        $request->refresh();
        $this->assertSame('COMPLETED', $request->generation_status);
        $this->assertSame(1, $request->regeneration_count);
        $this->assertSame('APPROVED', $q1->fresh()->review_status);
        $this->assertSame('REJECTED', $q2->fresh()->review_status);
        $this->assertSame(4, $request->generatedQuestions()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'QUESTION_REGENERATED']);
        // Approved draft is passed as existing context so it is not reproduced
        Http::assertSent(fn ($r) => str_contains($r->url(), 'generate-questions') && in_array('approved_draft', array_column($r->data()['existing_question_context'], 'source'), true));
    }

    public function test_blueprint_mode(): void
    {
        $this->fakeAi(null, ['blueprint_summary' => ['requested' => ['difficulty' => ['EASY' => 2, 'HARD' => 1]], 'generated' => ['difficulty' => ['EASY' => 2, 'MEDIUM' => 1]], 'matches' => false]]);
        Sanctum::actingAs($this->faculty);
        $payload = $this->basePayload(['assessment_id' => null, 'blueprint' => [['difficulty_level' => 'easy', 'cognitive_level' => 'Remember', 'count' => 2], ['difficulty_level' => 'hard', 'cognitive_level' => 'Evaluate', 'count' => 1, 'marks' => 15]]]);
        unset($payload['number_of_questions']);
        $res = $this->postJson('/api/question-generation', $payload)->assertStatus(202);
        $request = QuestionGenerationRequest::find($res->json('data.id'));
        $this->assertSame(3, $request->number_of_questions);
        $this->assertFalse($request->blueprint_summary['matches']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'generate-questions') && $r->data()['blueprint'][1]['cognitive_level'] === 'EVALUATE' && $r->data()['blueprint'][1]['marks'] == 15);
    }

    public function test_rate_limit_applies(): void
    {
        config(['question_generation.rate_limit_per_minute' => 2]);
        RateLimiter::clear('question-generation');
        $this->fakeAi();
        Sanctum::actingAs($this->faculty);
        $this->postJson('/api/question-generation', $this->basePayload(['number_of_questions' => 1]))->assertStatus(202);
        $this->postJson('/api/question-generation', $this->basePayload(['number_of_questions' => 1]))->assertStatus(202);
        $this->postJson('/api/question-generation', $this->basePayload(['number_of_questions' => 1]))->assertStatus(429);
    }
}
