<?php

namespace Tests\Feature;

use App\Jobs\GenerateDocumentEmbeddingsJob;
use App\Models\AcademicChatMessage;
use App\Models\AcademicChatSession;
use App\Models\AcademicChatSource;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\DocumentChunk;
use App\Models\DocumentProcessing;
use App\Models\User;
use App\Services\DocumentChunker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsAcademicChatFixtures;
use Tests\TestCase;

/**
 * STEP 32: AI Chat with Academic Documents (RAG).
 * The AI service is always faked; MiniLM vectors are tiny 4-d toy vectors so cosine ranking is deterministic.
 */
class AcademicChatTest extends TestCase
{
    use RefreshDatabase;

    use BuildsAcademicChatFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpChatFixtures();
    }

    // ---- Auth & ownership -------------------------------------------------

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/academic-chat/sessions')->assertStatus(401);
        $this->postJson('/api/academic-chat/sessions', ['scope_type' => 'COURSE', 'course_id' => $this->course->id])->assertStatus(401);
    }

    public function test_cannot_create_session_for_another_users_course(): void
    {
        Sanctum::actingAs($this->other);
        $this->postJson('/api/academic-chat/sessions', ['scope_type' => 'COURSE', 'course_id' => $this->course->id])->assertStatus(403);
        $this->postJson('/api/academic-chat/sessions', ['scope_type' => 'DOCUMENT', 'document_id' => $this->syllabus->id])->assertStatus(403);
    }

    public function test_session_creation_validates_scope(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->postJson('/api/academic-chat/sessions', ['scope_type' => 'GALAXY'])->assertStatus(422);
        $this->postJson('/api/academic-chat/sessions', ['scope_type' => 'COURSE'])->assertStatus(422);
    }

    public function test_creates_session_with_index_summary_and_audit(): void
    {
        $session = $this->createSession($this->faculty);

        $this->assertSame('COURSE', $session->scope_type);
        $this->assertSame($this->course->id, $session->course_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ACADEMIC_CHAT_SESSION_CREATED', 'entity_id' => $session->id]);

        $this->getJson("/api/academic-chat/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.index.total', 1)
            ->assertJsonPath('data.index.indexed', 1)
            ->assertJsonPath('data.messages', []);
    }

    public function test_other_user_cannot_view_send_or_delete_session(): void
    {
        $session = $this->createSession($this->faculty);

        Sanctum::actingAs($this->other);
        $this->getJson("/api/academic-chat/sessions/{$session->id}")->assertStatus(403);
        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'hi'])->assertStatus(403);
        $this->deleteJson("/api/academic-chat/sessions/{$session->id}")->assertStatus(403);
        $this->assertDatabaseHas('academic_chat_sessions', ['id' => $session->id]);
    }

    public function test_session_list_is_scoped_to_user(): void
    {
        $this->createSession($this->faculty);
        Sanctum::actingAs($this->other);
        $this->postJson('/api/academic-chat/sessions', ['scope_type' => 'COURSE', 'course_id' => $this->otherCourse->id])->assertStatus(201);

        $this->getJson('/api/academic-chat/sessions')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.course_id', $this->otherCourse->id);
    }

    // ---- Message validation ---------------------------------------------

    public function test_message_validation(): void
    {
        $session = $this->createSession($this->faculty);
        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => ''])->assertStatus(422);
        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => str_repeat('a', 5001)])->assertStatus(422);
    }

    // ---- Empty / indexing states ------------------------------------------

    public function test_returns_409_when_scope_has_no_documents(): void
    {
        $empty = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE999', 'course_name' => 'Empty', 'semester' => 'Fall', 'academic_year' => '2026']);
        $session = $this->createSession($this->faculty, ['scope_type' => 'COURSE', 'course_id' => $empty->id]);
        Http::fake();

        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'What is covered?'])
            ->assertStatus(409)
            ->assertJsonPath('data.index.total', 0);
        Http::assertNothingSent();
        $this->assertSame(0, AcademicChatMessage::count());
    }

    public function test_returns_409_while_documents_are_indexing(): void
    {
        $course = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE555', 'course_name' => 'Pending', 'semester' => 'Fall', 'academic_year' => '2026']);
        $this->makeDocument($this->faculty, $course, 'notes.pdf', 'Pending text', 'INDEXING');
        $session = $this->createSession($this->faculty, ['scope_type' => 'COURSE', 'course_id' => $course->id]);

        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'What is covered?'])
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Documents in this scope are still being indexed. Please try again shortly.']);
    }

    // ---- Happy path ---------------------------------------------------------

    public function test_valid_question_returns_grounded_answer_with_sources_and_persists(): void
    {
        $session = $this->createSession($this->faculty);
        $this->fakeAi([1, 0, 0, 0]);

        $response = $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'What does normalization do?']);
        $response->assertStatus(201)
            ->assertJsonPath('data.user_message.role', 'USER')
            ->assertJsonPath('data.assistant_message.role', 'ASSISTANT')
            ->assertJsonPath('data.assistant_message.grounded', true)
            ->assertJsonPath('data.assistant_message.sources.0.document_name', 'syllabus.pdf')
            ->assertJsonPath('data.assistant_message.sources.0.page_number', 3)
            ->assertJsonPath('data.assistant_message.sources.0.section_title', 'Unit 2 Normalization')
            ->assertJsonPath('data.session.message_count', 2);

        $this->assertStringContainsString('Normalization reduces redundancy', $response->json('data.assistant_message.content'));
        $this->assertNotEmpty($response->json('data.assistant_message.disclaimer'));
        $this->assertStringNotContainsString('documents/user_', json_encode($response->json()));

        $this->assertSame(2, AcademicChatMessage::where('academic_chat_session_id', $session->id)->count());
        $this->assertSame(1, AcademicChatSource::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'ACADEMIC_CHAT_MESSAGE_SENT']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ACADEMIC_CHAT_RESPONSE_GENERATED']);

        // Retrieval sent only this user's course chunks, ranked by similarity (chunk 0 first)
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/chat/academic')) {
                return false;
            }
            $chunks = $request->data()['chunks'];
            return count($chunks) === 1 && str_starts_with($chunks[0]['content'], 'Normalization');
        });
    }

    public function test_follow_up_history_and_query_rewrite_are_sent(): void
    {
        $session = $this->createSession($this->faculty);
        $this->fakeAi([1, 0, 0, 0]);
        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'What does normalization do?'])->assertStatus(201);
        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'Why is it useful?'])->assertStatus(201);

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/chat/academic')) {
                return false;
            }
            $data = $request->data();
            return count($data['conversation'] ?? []) === 2
                && str_contains($data['retrieval_query'], 'normalization')
                && str_contains($data['retrieval_query'], 'Why is it useful?');
        });
    }

    public function test_history_is_capped(): void
    {
        config(['academic_chat.max_history_messages' => 2]);
        $session = $this->createSession($this->faculty);
        $this->fakeAi([1, 0, 0, 0]);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => "Question number {$i} about normalization please"])->assertStatus(201);
        }
        Http::assertSent(fn ($r) => str_contains($r->url(), '/chat/academic') && count($r->data()['conversation'] ?? []) <= 2);
    }

    // ---- Negative / grounding ------------------------------------------------

    public function test_unrelated_question_yields_insufficient_evidence_without_calling_generator(): void
    {
        $session = $this->createSession($this->faculty);
        $this->fakeAi([0, 0, 0, 1]); // orthogonal to every stored chunk

        $response = $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'What is the tuition fee?']);
        $response->assertStatus(201)
            ->assertJsonPath('data.assistant_message.grounded', false)
            ->assertJsonPath('data.assistant_message.sources', [])
            ->assertJsonPath('data.assistant_message.generation_method', 'insufficient_evidence');
        $this->assertStringContainsString("couldn't find enough information", $response->json('data.assistant_message.content'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/chat/academic'));
    }

    public function test_ai_failure_does_not_persist_messages(): void
    {
        $session = $this->createSession($this->faculty);
        Http::fake([
            '*/api/v1/embeddings/batch' => Http::response(['status' => 'success', 'vectors' => [[1, 0, 0, 0]], 'model' => 'm', 'embedding_dimension' => 4]),
            '*/api/v1/chat/academic' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'What does normalization do?'])->assertStatus(503);
        $this->assertSame(0, AcademicChatMessage::count());
        $this->assertSame(0, AcademicChatSource::count());
    }

    public function test_malformed_ai_response_is_rejected(): void
    {
        $session = $this->createSession($this->faculty);
        $this->fakeAi([1, 0, 0, 0], ['unexpected' => true]);

        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'What does normalization do?'])->assertStatus(503);
        $this->assertSame(0, AcademicChatMessage::count());
    }

    public function test_sources_referencing_foreign_chunks_are_dropped(): void
    {
        $session = $this->createSession($this->faculty);
        $foreignChunk = DocumentChunk::where('document_processing_id', $this->otherDoc->id)->first();
        $this->fakeAi([1, 0, 0, 0], [
            'answer' => 'Ohm law relates voltage and current. [S1]',
            'sources' => [['source_index' => 1, 'chunk_id' => $foreignChunk->id, 'document_id' => $this->otherDoc->id, 'document_name' => 'circuits.pdf', 'similarity_score' => 0.99]],
            'grounded' => true,
            'generation_method' => 'generative',
            'model' => 'x', 'embedding_model' => 'm', 'prompt_version' => '1.0.0', 'retrieved_count' => 1, 'used_count' => 1, 'disclaimer' => 'd',
        ]);

        $response = $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'What does normalization do?']);
        $response->assertStatus(201)
            ->assertJsonPath('data.assistant_message.sources', [])
            ->assertJsonPath('data.assistant_message.grounded', false);
        $this->assertSame(0, AcademicChatSource::where('document_processing_id', $this->otherDoc->id)->count());
    }

    // ---- Isolation ----------------------------------------------------------

    public function test_retrieval_never_includes_other_users_or_courses_chunks(): void
    {
        $second = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE202', 'course_name' => 'OS', 'semester' => 'Fall', 'academic_year' => '2026']);
        $osDoc = $this->makeDocument($this->faculty, $second, 'os.pdf', 'Deadlock text');
        $this->addChunk($osDoc, 0, 'Deadlock occurs when processes wait circularly.', [1, 0, 0, 0], 1, null);

        $session = $this->createSession($this->faculty); // scope: CSE101
        $this->fakeAi([1, 0, 0, 0]); // identical to the OS chunk and the other user's chunk

        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'Tell me about it'])->assertStatus(201);

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/chat/academic')) {
                return false;
            }
            $ids = array_column($request->data()['chunks'], 'document_id');
            return $ids === [$this->syllabus->id];
        });
    }

    public function test_document_scope_limits_to_that_document(): void
    {
        $notes = $this->makeDocument($this->faculty, $this->course, 'notes.pdf', 'Indexing notes');
        $this->addChunk($notes, 0, 'B-tree indexes speed up lookups.', [1, 0, 0, 0], 2, null);

        $session = $this->createSession($this->faculty, ['scope_type' => 'DOCUMENT', 'document_id' => $notes->id]);
        $this->fakeAi([1, 0, 0, 0]);
        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'What about indexes?'])->assertStatus(201);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/chat/academic')
            && array_column($r->data()['chunks'], 'document_id') === [$notes->id]);
    }

    public function test_deleted_document_is_no_longer_retrievable_and_sources_survive(): void
    {
        $session = $this->createSession($this->faculty);
        $this->fakeAi([1, 0, 0, 0]);
        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'What does normalization do?'])->assertStatus(201);

        $this->syllabus->delete();
        $this->assertSame(0, DocumentChunk::where('document_processing_id', $this->syllabus->id)->count());
        $source = AcademicChatSource::first();
        $this->assertNull($source->document_processing_id);
        $this->assertSame('syllabus.pdf', $source->document_name);

        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'Again?'])->assertStatus(409);
    }

    public function test_deleting_session_cascades_messages(): void
    {
        $session = $this->createSession($this->faculty);
        $this->fakeAi([1, 0, 0, 0]);
        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'What does normalization do?'])->assertStatus(201);

        $this->deleteJson("/api/academic-chat/sessions/{$session->id}")->assertOk();
        $this->assertSame(0, AcademicChatMessage::count());
        $this->assertSame(0, AcademicChatSource::count());
    }

    public function test_rate_limit_applies_to_messages(): void
    {
        config(['academic_chat.rate_limit_per_minute' => 2]);
        RateLimiter::clear('academic-chat');
        $session = $this->createSession($this->faculty);
        $this->fakeAi([1, 0, 0, 0]);

        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'Q1 normalization'])->assertStatus(201);
        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'Q2 normalization'])->assertStatus(201);
        $this->postJson("/api/academic-chat/sessions/{$session->id}/messages", ['message' => 'Q3 normalization'])->assertStatus(429);
    }

    // ---- Indexing pipeline --------------------------------------------------

    public function test_chunker_produces_overlapping_chunks_with_pages_and_sections(): void
    {
        $chunker = new DocumentChunker(chunkSizeWords: 10, overlapWords: 3);
        $pages = [
            1 => "Unit 1 Introduction\n" . implode(' ', array_map(fn ($i) => "w{$i}", range(1, 12))),
            2 => "Unit 2 Normalization\n" . implode(' ', array_map(fn ($i) => "v{$i}", range(1, 8))),
        ];
        $chunks = $chunker->chunk('', $pages);

        $this->assertGreaterThanOrEqual(3, count($chunks));
        $this->assertSame(0, $chunks[0]['chunk_index']);
        $this->assertSame(1, $chunks[0]['page_number']);
        $this->assertSame('Unit 1 Introduction', $chunks[0]['section_title']);
        $this->assertSame(10, $chunks[0]['word_count']);
        // overlap: second chunk starts 7 words in
        $this->assertStringStartsWith('w5', $chunks[1]['content']);
        $last = end($chunks);
        $this->assertSame(2, $last['page_number']);
        $this->assertSame('Unit 2 Normalization', $last['section_title']);
        $this->assertSame(64, strlen($chunks[0]['content_hash']));
    }

    public function test_embeddings_job_indexes_document_and_skips_unchanged(): void
    {
        $doc = $this->makeDocument($this->faculty, $this->course, 'lecture.txt', implode(' ', array_fill(0, 50, 'databases')), 'NOT_INDEXED');
        Http::fake(['*/api/v1/embeddings/batch' => function ($request) {
            $n = count($request->data()['texts']);
            return Http::response(['status' => 'success', 'vectors' => array_fill(0, $n, [0.1, 0.2, 0.3, 0.4]), 'model' => 'sentence-transformers/all-MiniLM-L6-v2', 'embedding_dimension' => 4]);
        }]);

        (new GenerateDocumentEmbeddingsJob($doc))->handle(app(\App\Services\AiService::class), app(\App\Services\DocumentTextExtractor::class));

        $doc->refresh();
        $this->assertSame('INDEXED', $doc->indexing_status);
        $this->assertGreaterThan(0, $doc->chunk_count);
        $this->assertNotNull($doc->index_content_hash);
        $this->assertSame($doc->chunk_count, $doc->chunks()->count());
        $this->assertCount(4, $doc->chunks()->first()->embeddingVector());

        Http::assertSentCount(1);
        (new GenerateDocumentEmbeddingsJob($doc))->handle(app(\App\Services\AiService::class), app(\App\Services\DocumentTextExtractor::class));
        Http::assertSentCount(1); // unchanged hash → no re-embedding
    }

    public function test_embeddings_job_marks_failed_when_ai_unavailable(): void
    {
        $doc = $this->makeDocument($this->faculty, $this->course, 'lecture.txt', 'Some lecture content here', 'NOT_INDEXED');
        Http::fake(['*/api/v1/embeddings/batch' => Http::response(['error' => 'down'], 500)]);

        try {
            (new GenerateDocumentEmbeddingsJob($doc))->handle(app(\App\Services\AiService::class), app(\App\Services\DocumentTextExtractor::class));
            $this->fail('Expected exception');
        } catch (\Exception $e) {
            // expected: job rethrows for retry
        }

        $doc->refresh();
        $this->assertSame('FAILED', $doc->indexing_status);
        $this->assertNotNull($doc->indexing_error);
    }

    public function test_document_endpoints_expose_indexing_status(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->getJson("/api/documents/{$this->syllabus->id}")
            ->assertOk()
            ->assertJsonPath('data.indexing_status', 'INDEXED');
    }
}
