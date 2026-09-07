<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\DocumentProcessing;
use App\Models\Question;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityAcademicIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected User $facultyA;
    protected User $facultyB;
    protected User $adminUser;
    protected Course $courseA;
    protected Assessment $assessmentA;
    protected Question $questionA;
    protected AnalysisReport $reportA;
    protected Recommendation $recommendationA;
    protected DocumentProcessing $documentA;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->facultyA = User::factory()->create([
            'name' => 'Faculty Alpha',
            'email' => 'faculty.alpha@aust.edu',
            'role' => 'FACULTY',
            'password' => Hash::make('Secret123!'),
        ]);

        $this->facultyB = User::factory()->create([
            'name' => 'Faculty Beta',
            'email' => 'faculty.beta@aust.edu',
            'role' => 'FACULTY',
            'password' => Hash::make('Secret123!'),
        ]);

        $this->adminUser = User::factory()->create([
            'name' => 'Dean Administrator',
            'email' => 'admin.dean@aust.edu',
            'role' => 'ADMIN',
            'password' => Hash::make('AdminSecret123!'),
        ]);

        $this->courseA = Course::create([
            'user_id' => $this->facultyA->id,
            'course_code' => 'CSE-4101',
            'course_name' => 'Security & Academic Integrity',
            'semester' => 'Spring',
            'academic_year' => '2026',
            'credits' => 3,
        ]);

        $this->assessmentA = Assessment::create([
            'course_id' => $this->courseA->id,
            'title' => 'Midterm Security Assessment',
            'type' => 'Midterm',
            'total_marks' => 50,
            'status' => 'Published',
        ]);

        $this->questionA = Question::create([
            'assessment_id' => $this->assessmentA->id,
            'question_number' => 1,
            'question_text' => 'What is Insecure Direct Object Reference (IDOR)?',
            'marks' => 10,
        ]);

        $this->reportA = AnalysisReport::create([
            'assessment_id' => $this->assessmentA->id,
            'analysis_version' => 1,
            'overall_score' => 88.5,
            'analysis_status' => 'completed',
            'is_current' => true,
        ]);

        $this->recommendationA = Recommendation::create([
            'analysis_report_id' => $this->reportA->id,
            'title' => 'Increase question diversity',
            'category' => 'question_diversity',
            'problem' => 'Assessment heavily concentrates on essay questions',
            'description' => 'Introduce coding problem or analytical scenarios.',
            'explanation' => 'Adding objective and analytical problems improves holistic assessment.',
            'recommendation' => 'Introduce coding problem or analytical scenarios.',
            'source_metric' => 'question_diversity',
            'priority' => 'medium',
            'status' => 'pending',
        ]);

        $storedPath = Storage::disk('local')->putFile('documents/test', UploadedFile::fake()->create('syllabus.pdf', 50));
        $this->documentA = DocumentProcessing::create([
            'user_id' => $this->facultyA->id,
            'course_id' => $this->courseA->id,
            'assessment_id' => $this->assessmentA->id,
            'document_type' => 'syllabus',
            'original_file_name' => 'syllabus.pdf',
            'stored_file_name' => basename($storedPath),
            'file_path' => $storedPath,
            'mime_type' => 'application/pdf',
            'file_size' => 51200,
            'processing_status' => 'completed',
        ]);
    }

    /**
     * 1. Unauthenticated requests are blocked across all protected endpoints.
     */
    public function test_unauthenticated_requests_blocked_across_endpoints(): void
    {
        $this->getJson('/api/courses')->assertStatus(401);
        $this->getJson("/api/courses/{$this->courseA->id}")->assertStatus(401);
        $this->getJson('/api/assessments')->assertStatus(401);
        $this->getJson("/api/assessments/{$this->assessmentA->id}")->assertStatus(401);
        $this->getJson('/api/documents')->assertStatus(401);
        $this->getJson("/api/documents/{$this->documentA->id}/download")->assertStatus(401);
        $this->getJson('/api/feedback')->assertStatus(401);
        $this->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", ['decision' => 'ACCEPTED'])->assertStatus(401);
        $this->postJson('/api/ai/analyze-assessment', ['assessment_id' => $this->assessmentA->id])->assertStatus(401);
    }

    /**
     * 2. Comprehensive IDOR Protection across Course, Assessment, Document, Recommendation, and Report.
     */
    public function test_idor_protection_for_faculty_resources(): void
    {
        // Faculty B cannot view, update, or delete Faculty A course
        $this->actingAs($this->facultyB, 'sanctum')
            ->getJson("/api/courses/{$this->courseA->id}")
            ->assertStatus(403);

        $this->actingAs($this->facultyB, 'sanctum')
            ->putJson("/api/courses/{$this->courseA->id}", ['course_name' => 'Hijacked'])
            ->assertStatus(403);

        $this->actingAs($this->facultyB, 'sanctum')
            ->deleteJson("/api/courses/{$this->courseA->id}")
            ->assertStatus(403);

        // Faculty B cannot access Faculty A assessment
        $this->actingAs($this->facultyB, 'sanctum')
            ->getJson("/api/assessments/{$this->assessmentA->id}")
            ->assertStatus(403);

        $this->actingAs($this->facultyB, 'sanctum')
            ->deleteJson("/api/assessments/{$this->assessmentA->id}")
            ->assertStatus(403);

        // Faculty B cannot view or download Faculty A document
        $this->actingAs($this->facultyB, 'sanctum')
            ->getJson("/api/documents/{$this->documentA->id}")
            ->assertStatus(403);

        $this->actingAs($this->facultyB, 'sanctum')
            ->getJson("/api/documents/{$this->documentA->id}/download")
            ->assertStatus(403);

        $this->actingAs($this->facultyB, 'sanctum')
            ->deleteJson("/api/documents/{$this->documentA->id}")
            ->assertStatus(403);

        // Faculty B cannot submit feedback on Faculty A recommendation
        $this->actingAs($this->facultyB, 'sanctum')
            ->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", [
                'decision' => 'ACCEPTED',
            ])
            ->assertStatus(403);

        // Faculty B cannot view Faculty A assessment report
        $this->actingAs($this->facultyB, 'sanctum')
            ->getJson("/api/assessments/{$this->assessmentA->id}/report")
            ->assertStatus(403);

        // Faculty B cannot view Faculty A assessment analysis dashboard
        $this->actingAs($this->facultyB, 'sanctum')
            ->getJson("/api/ai/assessments/{$this->assessmentA->id}/analysis")
            ->assertStatus(403);
    }

    /**
     * 3. Role escalation prevention: User cannot inject role="ADMIN".
     */
    public function test_user_cannot_escalate_role_to_admin(): void
    {
        // Registration with role: ADMIN must still create a FACULTY user
        $regResponse = $this->postJson('/api/auth/register', [
            'name' => 'Attacker',
            'email' => 'attacker@aust.edu',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'department' => 'CSE',
            'designation' => 'Lecturer',
            'role' => 'ADMIN',
        ]);

        $regResponse->assertStatus(201);
        $user = User::where('email', 'attacker@aust.edu')->first();
        $this->assertNotNull($user);
        $this->assertEquals('FACULTY', $user->role);
        $this->assertTrue($user->isFaculty());
        $this->assertFalse($user->isAdmin());

        // Profile update attempting role escalation must be ignored
        $updateResponse = $this->actingAs($this->facultyA, 'sanctum')
            ->patchJson('/api/users/me', [
                'name' => 'Faculty Alpha Renamed',
                'role' => 'ADMIN',
            ]);

        $updateResponse->assertStatus(200);
        $this->facultyA->refresh();
        $this->assertEquals('FACULTY', $this->facultyA->role);
        $this->assertEquals('Faculty Alpha Renamed', $this->facultyA->name);
    }

    /**
     * 4. Duplicate AI Analysis Protection: returns 409 Conflict if already processing.
     */
    public function test_duplicate_ai_analysis_returns_409_conflict(): void
    {
        // Mark assessment's latest report as processing
        $this->reportA->update([
            'analysis_status' => 'processing',
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->facultyA, 'sanctum')
            ->postJson('/api/ai/analyze-assessment', [
                'assessment_id' => $this->assessmentA->id,
            ]);

        $response->assertStatus(409);
        $response->assertJson([
            'status' => 'error',
            'message' => 'Analysis is currently processing for this assessment. Please wait for completion.',
        ]);
    }

    /**
     * 5. Document download: Owner can download, non-existent file returns 404.
     */
    public function test_document_download_security(): void
    {
        // Owner downloads successfully
        $response = $this->actingAs($this->facultyA, 'sanctum')
            ->get("/api/documents/{$this->documentA->id}/download");

        $response->assertStatus(200);

        // Delete file on disk to simulate missing source
        Storage::disk('local')->delete($this->documentA->file_path);

        $missingResponse = $this->actingAs($this->facultyA, 'sanctum')
            ->getJson("/api/documents/{$this->documentA->id}/download");

        $missingResponse->assertStatus(404);
        $missingResponse->assertJson(['message' => 'Document file not found on server.']);
    }

    /**
     * 6. Audit Trail: Key academic actions create audit_logs records without sensitive text.
     */
    public function test_audit_trail_records_academic_events(): void
    {
        // 1. Create Assessment -> logs ASSESSMENT_CREATED
        $createAssessment = $this->actingAs($this->facultyA, 'sanctum')
            ->postJson("/api/courses/{$this->courseA->id}/assessments", [
                'title' => 'Quiz 1 - Security Basics',
                'type' => 'Quiz',
                'total_marks' => 20,
                'status' => 'Draft',
            ]);

        $createAssessment->assertStatus(201);
        $assessmentId = $createAssessment->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ASSESSMENT_CREATED',
            'entity_type' => 'Assessment',
            'entity_id' => $assessmentId,
            'user_id' => $this->facultyA->id,
        ]);

        // 2. Submit Recommendation Feedback -> logs RECOMMENDATION_ACCEPTED
        $recResponse = $this->actingAs($this->facultyA, 'sanctum')
            ->postJson("/api/recommendations/{$this->recommendationA->id}/feedback", [
                'decision' => 'ACCEPTED',
                'usefulness_rating' => 5,
                'reason' => 'USEFUL_INSIGHT',
                'comment' => 'Will incorporate coding problems in the next revision.',
            ]);

        $recResponse->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'RECOMMENDATION_ACCEPTED',
            'entity_type' => 'Recommendation',
            'entity_id' => $this->recommendationA->id,
            'user_id' => $this->facultyA->id,
        ]);

        // 3. Delete Document -> logs DOCUMENT_DELETED
        $delResponse = $this->actingAs($this->facultyA, 'sanctum')
            ->deleteJson("/api/documents/{$this->documentA->id}");

        $delResponse->assertStatus(200);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'DOCUMENT_DELETED',
            'entity_type' => 'DocumentProcessing',
            'entity_id' => $this->documentA->id,
            'user_id' => $this->facultyA->id,
        ]);

        // Verify that passwords or raw questions are NEVER present in audit logs
        $logs = AuditLog::all();
        foreach ($logs as $log) {
            $metadataString = json_encode($log->metadata);
            $this->assertStringNotContainsString('password', $metadataString);
            $this->assertStringNotContainsString('Secret123!', $metadataString);
        }
    }

    /**
     * 7. Security Headers Middleware: Responses must include protection headers.
     */
    public function test_security_headers_present_on_api_responses(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
    }

    /**
     * 8. Passwords and remember tokens are never exposed in user API payloads.
     */
    public function test_sensitive_user_fields_never_exposed(): void
    {
        $response = $this->actingAs($this->facultyA, 'sanctum')->getJson('/api/auth/user');
        $response->assertStatus(200);

        $userData = $response->json('user');
        $this->assertArrayNotHasKey('password', $userData);
        $this->assertArrayNotHasKey('remember_token', $userData);
        $this->assertEquals('FACULTY', $userData['role']);
    }

    /**
     * 9. Empty text file upload is rejected or results in failed processing state.
     */
    public function test_empty_document_upload_handled_safely(): void
    {
        // Upload a 0-byte text file
        $emptyFile = UploadedFile::fake()->createWithContent('empty.txt', '');

        $response = $this->actingAs($this->facultyA, 'sanctum')
            ->postJson('/api/documents', [
                'file' => $emptyFile,
                'course_id' => $this->courseA->id,
                'document_type' => 'syllabus',
            ]);

        // Returns 201 with processing_status = 'failed' and safe error message
        $response->assertStatus(201);
        $docData = $response->json('data');
        $this->assertEquals('failed', $docData['processing_status']);
        $this->assertStringContainsString('could not be processed', $docData['processing_error']);
        $this->assertStringNotContainsString('Exception:', $docData['processing_error']);
    }
}

