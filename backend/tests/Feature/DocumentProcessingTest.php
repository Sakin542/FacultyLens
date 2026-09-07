<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\DocumentProcessing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = User::factory()->create();
        $this->course = Course::create([
            'user_id' => $this->user->id,
            'course_code' => 'CSE-301',
            'course_name' => 'Operating Systems',
            'semester' => 'Fall',
            'academic_year' => '2026',
            'credits' => 3,
        ]);
    }

    public function test_faculty_can_upload_and_extract_txt_document(): void
    {
        $textContent = "1. What is virtual memory?\n2. Explain process synchronization using semaphores.\n3. Compare paging and segmentation.";
        $file = UploadedFile::fake()->createWithContent('os_questions.txt', $textContent);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/documents', [
            'course_id' => $this->course->id,
            'document_type' => 'question_paper',
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.processing_status', 'completed')
            ->assertJsonPath('data.original_file_name', 'os_questions.txt');

        $this->assertDatabaseHas('document_processings', [
            'course_id' => $this->course->id,
            'original_file_name' => 'os_questions.txt',
            'processing_status' => 'completed',
        ]);
    }

    public function test_upload_rejects_disallowed_file_types(): void
    {
        $invalidFile = UploadedFile::fake()->create('malicious.exe', 100);

        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/documents', [
            'course_id' => $this->course->id,
            'document_type' => 'syllabus',
            'file' => $invalidFile,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_faculty_cannot_access_documents_of_another_faculty(): void
    {
        $otherUser = User::factory()->create();
        $doc = DocumentProcessing::create([
            'user_id' => $this->user->id,
            'course_id' => $this->course->id,
            'document_type' => 'syllabus',
            'original_file_name' => 'syllabus.txt',
            'stored_file_name' => 'syllabus_123.txt',
            'file_path' => 'documents/syllabus.txt',
            'file_size' => 1024,
            'mime_type' => 'text/plain',
            'processing_status' => 'completed',
        ]);

        $response = $this->actingAs($otherUser, 'sanctum')->getJson("/api/documents/{$doc->id}");
        $response->assertStatus(403);
    }
}
