<?php

namespace Tests\Feature\Concerns;

use App\Models\AcademicChatSession;
use App\Models\Course;
use App\Models\DocumentChunk;
use App\Models\DocumentProcessing;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Shared STEP 32 chat fixtures: two faculty, two courses, indexed toy-vector chunks, faked AI service.
 * Used by AcademicChatTest (STEP 32) and Safety\AcademicChatSafetyTest (STEP 46).
 */
trait BuildsAcademicChatFixtures
{
    protected User $faculty;
    protected User $other;
    protected Course $course;
    protected Course $otherCourse;
    protected DocumentProcessing $syllabus;
    protected DocumentProcessing $otherDoc;

    protected function setUpChatFixtures(): void
    {
        Storage::fake('local');
        config(['academic_chat.min_relevance_score' => 0.35, 'academic_chat.top_k' => 5]);

        $this->faculty = User::factory()->create(['email' => 'faculty@university.edu']);
        $this->other = User::factory()->create(['email' => 'other@university.edu']);

        $this->course = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026']);
        $this->otherCourse = Course::create(['user_id' => $this->other->id, 'course_code' => 'EEE201', 'course_name' => 'Circuits', 'semester' => 'Fall', 'academic_year' => '2026']);

        $this->syllabus = $this->makeDocument($this->faculty, $this->course, 'syllabus.pdf', 'Normalization reduces redundancy. The midterm is in week 8.');
        $this->addChunk($this->syllabus, 0, 'Normalization reduces redundancy in relational schemas.', [1, 0, 0, 0], 3, 'Unit 2 Normalization');
        $this->addChunk($this->syllabus, 1, 'The midterm exam is held in week 8 and covers units 1-4.', [0, 1, 0, 0], 1, 'Assessment Schedule');

        $this->otherDoc = $this->makeDocument($this->other, $this->otherCourse, 'circuits.pdf', 'Ohm law relates voltage and current.');
        $this->addChunk($this->otherDoc, 0, 'Ohm law relates voltage, current and resistance.', [1, 0, 0, 0], 1, null);
    }

    protected function makeDocument(User $user, Course $course, string $name, string $text, string $indexing = 'INDEXED'): DocumentProcessing
    {
        return DocumentProcessing::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'document_type' => 'syllabus',
            'original_file_name' => $name,
            'stored_file_name' => $name,
            'file_path' => "documents/user_{$user->id}/course_{$course->id}/{$name}",
            'mime_type' => 'application/pdf',
            'file_size' => 1000,
            'extracted_text' => $text,
            'cleaned_text' => $text,
            'processing_status' => 'completed',
            'processed_at' => now(),
            'indexing_status' => $indexing,
            'chunk_count' => 0,
            'indexed_at' => $indexing === 'INDEXED' ? now() : null,
        ]);
    }

    protected function addChunk(DocumentProcessing $doc, int $index, string $content, array $vector, ?int $page, ?string $section): DocumentChunk
    {
        return DocumentChunk::create([
            'document_processing_id' => $doc->id,
            'user_id' => $doc->user_id,
            'course_id' => $doc->course_id,
            'assessment_id' => $doc->assessment_id,
            'chunk_index' => $index,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'page_number' => $page,
            'section_title' => $section,
            'word_count' => str_word_count($content),
            'embedding' => DocumentChunk::packEmbedding($vector),
            'embedding_dimension' => count($vector),
            'embedding_model' => 'sentence-transformers/all-MiniLM-L6-v2',
            'embedding_version' => '1',
        ]);
    }

    /**
     * Fake AI service: query embedding + grounded chat answer citing the first chunk sent.
     * $chatOverride may be an array (static response) or a callable receiving the chunks Laravel sent.
     */
    protected function fakeAi(array $queryVector = [1, 0, 0, 0], array|callable|null $chatOverride = null): void
    {
        Http::fake([
            '*/api/v1/embeddings/batch' => Http::response([
                'vectors' => [$queryVector],
                'model' => 'sentence-transformers/all-MiniLM-L6-v2',
                'embedding_dimension' => count($queryVector),
            ]),
            '*/api/v1/chat/academic' => function ($request) use ($chatOverride) {
                $chunks = $request->data()['chunks'] ?? [];
                if (is_callable($chatOverride)) {
                    return Http::response($chatOverride($chunks));
                }
                if ($chatOverride !== null) {
                    return Http::response($chatOverride);
                }
                $first = $chunks[0] ?? null;

                return Http::response([
                    'answer' => $first ? 'Normalization reduces redundancy in relational schemas. [S1]' : "I couldn't find enough information about that in the documents available to this chat.",
                    'sources' => $first ? [[
                        'source_index' => 1, 'chunk_id' => $first['chunk_id'], 'document_id' => $first['document_id'],
                        'document_name' => $first['document_name'], 'similarity_score' => $first['similarity_score'],
                        'page_number' => $first['page_number'], 'section_title' => $first['section_title'],
                        'excerpt' => $first['content'], 'document_type' => $first['document_type'] ?? null,
                    ]] : [],
                    'grounded' => (bool) $first,
                    'generation_method' => $first ? 'extractive' : 'insufficient_evidence',
                    'model' => 'facultylens-extractive-answer-engine',
                    'model_version' => '1.0.0',
                    'embedding_model' => 'sentence-transformers/all-MiniLM-L6-v2',
                    'prompt_version' => '1.0.0',
                    'retrieved_count' => count($chunks),
                    'used_count' => $first ? 1 : 0,
                    'disclaimer' => 'AI-generated answers are based only on the retrieved document excerpts.',
                ]);
            },
        ]);
    }

    protected function createSession(User $user, array $payload = []): AcademicChatSession
    {
        Sanctum::actingAs($user);
        $response = $this->postJson('/api/academic-chat/sessions', $payload ?: ['scope_type' => 'COURSE', 'course_id' => $this->course->id]);
        $response->assertStatus(201);

        return AcademicChatSession::findOrFail($response->json('data.id'));
    }
}
