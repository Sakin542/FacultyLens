<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\DocumentProcessing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentProcessingResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_valid_txt_upload_and_extraction(): void
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

        $content = "Question 1. Explain database normalization.\nQuestion 2. Compare SQL and NoSQL databases.\nQuestion 3. Explain ACID properties.";
        $file = UploadedFile::fake()->createWithContent('midterm_exam.txt', $content);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/documents', [
            'course_id' => $course->id,
            'document_type' => 'question_paper',
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.processing_status', 'completed')
            ->assertJsonPath('data.original_file_name', 'midterm_exam.txt');

        $this->assertDatabaseHas('document_processings', [
            'user_id' => $user->id,
            'course_id' => $course->id,
            'original_file_name' => 'midterm_exam.txt',
            'processing_status' => 'completed',
        ]);
    }

    public function test_rejects_empty_zero_byte_file(): void
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

        $file = UploadedFile::fake()->createWithContent('empty.txt', '');

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/documents', [
            'course_id' => $course->id,
            'document_type' => 'question_paper',
            'file' => $file,
        ]);

        // Returns 201 with processing_status = 'failed' and safe error message
        $response->assertStatus(201);
        $docData = $response->json('data');
        $this->assertEquals('failed', $docData['processing_status']);
        $this->assertStringContainsString('could not be processed', $docData['processing_error']);
    }

    public function test_rejects_unsupported_file_extensions(): void
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

        $file = UploadedFile::fake()->create('malicious.exe', 100);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/documents', [
            'course_id' => $course->id,
            'document_type' => 'question_paper',
            'file' => $file,
        ]);

        $response->assertStatus(422);
    }

    public function test_path_traversal_filename_is_safely_handled(): void
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

        $file = UploadedFile::fake()->createWithContent('../../etc/passwd.txt', 'Q1: What is a relational schema?');

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/documents', [
            'course_id' => $course->id,
            'document_type' => 'question_paper',
            'file' => $file,
        ]);

        $response->assertStatus(201);
        
        $doc = DocumentProcessing::where('user_id', $user->id)->latest()->first();
        $this->assertNotNull($doc);
        $this->assertStringNotContainsString('../', $doc->file_path);
    }
}
