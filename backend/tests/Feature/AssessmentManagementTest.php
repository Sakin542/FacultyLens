<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\PreviousQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssessmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->course = Course::create([
            'user_id' => $this->user->id,
            'course_code' => 'CSE-201',
            'course_name' => 'Data Structures',
            'semester' => 'Spring',
            'academic_year' => '2026',
            'credits' => 3,
        ]);
    }

    public function test_faculty_can_create_and_manage_assessments(): void
    {
        // Create assessment
        $createRes = $this->actingAs($this->user, 'sanctum')->postJson("/api/courses/{$this->course->id}/assessments", [
            'title' => 'Midterm Examination',
            'type' => 'Midterm',
            'total_marks' => 50,
            'duration_minutes' => 90,
            'status' => 'Draft',
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.title', 'Midterm Examination');

        $assessmentId = $createRes->json('data.id');

        // Show assessment
        $showRes = $this->actingAs($this->user, 'sanctum')->getJson("/api/assessments/{$assessmentId}");
        $showRes->assertStatus(200)
            ->assertJsonPath('data.id', $assessmentId);

        // Update assessment
        $updateRes = $this->actingAs($this->user, 'sanctum')->putJson("/api/assessments/{$assessmentId}", [
            'total_marks' => 60,
            'status' => 'Published',
        ]);
        $updateRes->assertStatus(200);
        $this->assertEquals(60, (int)$updateRes->json('data.total_marks'));

        // History endpoint
        $historyRes = $this->actingAs($this->user, 'sanctum')->getJson('/api/assessments/history');
        $historyRes->assertStatus(200);

        // Delete assessment
        $deleteRes = $this->actingAs($this->user, 'sanctum')->deleteJson("/api/assessments/{$assessmentId}");
        $deleteRes->assertStatus(200);

        $this->assertDatabaseMissing('assessments', ['id' => $assessmentId]);
    }

    public function test_faculty_can_manage_previous_questions_bank(): void
    {
        $createQRes = $this->actingAs($this->user, 'sanctum')->postJson("/api/courses/{$this->course->id}/previous-questions", [
            'question_text' => 'Explain the time complexity of binary search.',
            'question_type' => 'descriptive',
            'difficulty_level' => 'medium',
            'cognitive_level' => 'Understand',
            'marks' => 5,
            'source' => 'previous_exam',
            'source_year' => '2025',
        ]);

        $createQRes->assertStatus(201)
            ->assertJsonPath('data.question_text', 'Explain the time complexity of binary search.');

        $qId = $createQRes->json('data.id');

        // List bank
        $listRes = $this->actingAs($this->user, 'sanctum')->getJson("/api/courses/{$this->course->id}/previous-questions");
        $listRes->assertStatus(200);

        // Update bank question
        $updateQRes = $this->actingAs($this->user, 'sanctum')->putJson("/api/previous-questions/{$qId}", [
            'marks' => 8,
            'difficulty_level' => 'hard',
        ]);
        $updateQRes->assertStatus(200);
        $this->assertEquals(8, (int)$updateQRes->json('data.marks'));

        // Delete bank question
        $deleteQRes = $this->actingAs($this->user, 'sanctum')->deleteJson("/api/previous-questions/{$qId}");
        $deleteQRes->assertStatus(200);

        $this->assertDatabaseMissing('previous_questions', ['id' => $qId]);
    }

    public function test_faculty_cannot_manage_other_faculty_assessment(): void
    {
        $otherUser = User::factory()->create();
        $otherAssessment = Assessment::create([
            'course_id' => $this->course->id,
            'title' => 'Protected Assessment',
            'type' => 'Quiz',
            'total_marks' => 20,
            'status' => 'Draft',
        ]);

        // Attempt access from other user
        $showRes = $this->actingAs($otherUser, 'sanctum')->getJson("/api/assessments/{$otherAssessment->id}");
        $showRes->assertStatus(403);
    }
}
