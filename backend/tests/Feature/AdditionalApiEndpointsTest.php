<?php

namespace Tests\Feature;

use App\Models\AcademicChatMessage;
use App\Models\AcademicChatSession;
use App\Models\Assessment;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\BuildsAcademicChatFixtures;
use Tests\TestCase;

/**
 * Chat session rename/archive, transcript export, and global search.
 */
class AdditionalApiEndpointsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsAcademicChatFixtures;

    protected AcademicChatSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpChatFixtures();

        $this->session = AcademicChatSession::create([
            'user_id' => $this->faculty->id,
            'scope_type' => 'COURSE',
            'course_id' => $this->course->id,
            'title' => 'Normalization questions',
            'status' => 'ACTIVE',
            'message_count' => 2,
        ]);
        AcademicChatMessage::create(['academic_chat_session_id' => $this->session->id, 'role' => AcademicChatMessage::ROLE_USER, 'content' => 'What is normalization?']);
        AcademicChatMessage::create(['academic_chat_session_id' => $this->session->id, 'role' => AcademicChatMessage::ROLE_ASSISTANT, 'content' => 'It reduces redundancy.', 'grounded' => true]);
    }

    public function test_owner_can_rename_and_archive_session(): void
    {
        Sanctum::actingAs($this->faculty);

        $this->patchJson("/api/academic-chat/sessions/{$this->session->id}", ['title' => '  Unit 2 <b>review</b> ', 'status' => 'ARCHIVED'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Unit 2 review')
            ->assertJsonPath('data.status', 'ARCHIVED');
    }

    public function test_update_validates_and_requires_ownership(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->patchJson("/api/academic-chat/sessions/{$this->session->id}", [])->assertStatus(422);
        $this->patchJson("/api/academic-chat/sessions/{$this->session->id}", ['status' => 'DELETED'])->assertStatus(422);

        Sanctum::actingAs($this->other);
        $this->patchJson("/api/academic-chat/sessions/{$this->session->id}", ['title' => 'Hijack'])->assertStatus(403);
        $this->get("/api/academic-chat/sessions/{$this->session->id}/export")->assertStatus(403);
    }

    public function test_export_markdown_and_json(): void
    {
        Sanctum::actingAs($this->faculty);

        $md = $this->get("/api/academic-chat/sessions/{$this->session->id}/export");
        $md->assertOk();
        $this->assertStringContainsString('text/markdown', $md->headers->get('Content-Type'));
        $this->assertStringContainsString('# Normalization questions', $md->getContent());
        $this->assertStringContainsString('It reduces redundancy.', $md->getContent());

        $json = $this->get("/api/academic-chat/sessions/{$this->session->id}/export?format=json");
        $json->assertOk();
        $decoded = json_decode($json->getContent(), true);
        $this->assertCount(2, $decoded['messages']);

        $this->getJson("/api/academic-chat/sessions/{$this->session->id}/export?format=pdf")->assertStatus(422);
    }

    public function test_search_returns_only_accessible_records(): void
    {
        Assessment::create(['course_id' => $this->course->id, 'title' => 'Database Midterm', 'type' => 'MIDTERM', 'total_marks' => 50]);
        Assessment::create(['course_id' => $this->otherCourse->id, 'title' => 'Database Secret', 'type' => 'MIDTERM', 'total_marks' => 50]);
        Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'S-001', 'name' => 'Database Dana']);

        Sanctum::actingAs($this->faculty);

        $res = $this->getJson('/api/search?q=database')->assertOk();
        $res->assertJsonCount(1, 'data.results.courses')
            ->assertJsonCount(1, 'data.results.assessments')
            ->assertJsonPath('data.results.assessments.0.title', 'Database Midterm')
            ->assertJsonCount(1, 'data.results.students');

        $this->getJson('/api/search?q=circuits')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/search?q=syllabus&types[]=documents')->assertOk()
            ->assertJsonCount(1, 'data.results.documents')
            ->assertJsonMissingPath('data.results.courses');
    }

    public function test_search_validation_and_auth(): void
    {
        $this->getJson('/api/search?q=db')->assertStatus(401);

        Sanctum::actingAs($this->faculty);
        $this->getJson('/api/search?q=a')->assertStatus(422);
        $this->getJson('/api/search?q=data&types[]=galaxies')->assertStatus(422);
    }
}
