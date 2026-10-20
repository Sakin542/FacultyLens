<?php

namespace Tests\Feature;

use App\Models\AcademicChatSession;
use App\Services\AcademicDocumentRetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsAcademicChatFixtures;
use Tests\TestCase;

/**
 * The same file uploaded twice to a course is refused, and document chat never feeds the same passage to the model
 * twice (found while testing: a syllabus uploaded four times gave four identical "sources").
 */
class DuplicateDocumentTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicChatFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpChatFixtures();
        Queue::fake();
    }

    public function test_exact_re_upload_to_the_same_course_is_refused(): void
    {
        Sanctum::actingAs($this->faculty);
        $upload = fn (string $name, string $content) => $this->postJson('/api/documents', [
            'course_id' => $this->course->id, 'document_type' => 'syllabus', 'file' => UploadedFile::fake()->createWithContent($name, $content),
        ]);

        $upload('cse3201-syllabus.txt', 'Week 1: arrays. Week 2: linked lists.')->assertCreated();
        $upload('cse3201-syllabus.txt', 'Week 1: arrays. Week 2: linked lists.')
            ->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_DOCUMENT')->assertJsonPath('message', fn ($m) => str_contains($m, 'already in this course'));

        // Same name with changed content, or the same content under another name, are different documents
        $upload('cse3201-syllabus.txt', 'Week 1: arrays. Week 2: stacks.')->assertCreated();
        $upload('cse3201-syllabus-copy.txt', 'Week 1: arrays. Week 2: linked lists.')->assertCreated();
    }

    public function test_chat_retrieval_skips_repeated_passages(): void
    {
        foreach (['syllabus-copy-1.pdf', 'syllabus-copy-2.pdf'] as $name) {
            $copy = $this->makeDocument($this->faculty, $this->course, $name, 'Normalization reduces redundancy.');
            $this->addChunk($copy, 0, "Normalization reduces   redundancy in relational schemas.\n", [1, 0, 0, 0], 3, 'Unit 2 Normalization');
        }
        $this->fakeAi([1, 1, 0, 0]);
        $session = $this->createSession($this->faculty);

        $chunks = app(AcademicDocumentRetrievalService::class)->retrieve($this->faculty, AcademicChatSession::findOrFail($session->id), 'What does normalization do?')['chunks'];

        $texts = array_map(fn ($c) => mb_strtolower(preg_replace('/\s+/', ' ', trim($c['content']))), $chunks);
        $this->assertSame(count($texts), count(array_unique($texts)), 'no passage is retrieved twice');
        $this->assertContains('normalization reduces redundancy in relational schemas.', $texts);
        $this->assertContains('the midterm exam is held in week 8 and covers units 1-4.', $texts, 'a different passage fills the freed slot');
    }
}
