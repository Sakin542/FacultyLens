<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\LearningOutcome;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CourseManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_authenticated_faculty_can_create_and_list_courses(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/courses', [
            'course_code' => 'CSE-101',
            'course_name' => 'Introduction to Computer Science',
            'description' => 'Fundamental principles of computing and programming.',
            'semester' => 'Spring',
            'academic_year' => '2026',
            'credits' => 3,
            'status' => 'active',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.course_code', 'CSE-101')
            ->assertJsonPath('data.course_name', 'Introduction to Computer Science');

        $listResponse = $this->actingAs($this->user, 'sanctum')->getJson('/api/courses');
        $listResponse->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_faculty_can_update_and_delete_their_course(): void
    {
        $course = Course::create([
            'user_id' => $this->user->id,
            'course_code' => 'CSE-101',
            'course_name' => 'Old Title',
            'semester' => 'Fall',
            'academic_year' => '2025',
            'credits' => 3,
        ]);

        $updateRes = $this->actingAs($this->user, 'sanctum')->putJson("/api/courses/{$course->id}", [
            'course_name' => 'Updated Title',
            'semester' => 'Spring',
            'academic_year' => '2026',
        ]);

        $updateRes->assertStatus(200)
            ->assertJsonPath('data.course_name', 'Updated Title');

        $deleteRes = $this->actingAs($this->user, 'sanctum')->deleteJson("/api/courses/{$course->id}");
        $deleteRes->assertStatus(200);

        $this->assertDatabaseMissing('courses', ['id' => $course->id]);
    }

    public function test_faculty_can_manage_learning_outcomes(): void
    {
        $course = Course::create([
            'user_id' => $this->user->id,
            'course_code' => 'CSE-101',
            'course_name' => 'Intro to CS',
            'semester' => 'Spring',
            'academic_year' => '2026',
            'credits' => 3,
        ]);

        // Create LO
        $loRes = $this->actingAs($this->user, 'sanctum')->postJson("/api/courses/{$course->id}/learning-outcomes", [
            'code' => 'CLO-1',
            'description' => 'Explain fundamental computing concepts.',
            'cognitive_level' => 'Understand',
            'sort_order' => 1,
        ]);

        $loRes->assertStatus(201)
            ->assertJsonPath('data.code', 'CLO-1');

        $loId = $loRes->json('data.id');

        // List LOs
        $listRes = $this->actingAs($this->user, 'sanctum')->getJson("/api/courses/{$course->id}/learning-outcomes");
        $listRes->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Update LO
        $updateLoRes = $this->actingAs($this->user, 'sanctum')->putJson("/api/learning-outcomes/{$loId}", [
            'description' => 'Explain updated computing concepts in depth.',
        ]);
        $updateLoRes->assertStatus(200)
            ->assertJsonPath('data.description', 'Explain updated computing concepts in depth.');

        // Delete LO
        $deleteLoRes = $this->actingAs($this->user, 'sanctum')->deleteJson("/api/learning-outcomes/{$loId}");
        $deleteLoRes->assertStatus(200);

        $this->assertDatabaseMissing('learning_outcomes', ['id' => $loId]);
    }

    public function test_faculty_cannot_access_or_modify_another_faculty_course(): void
    {
        $otherUser = User::factory()->create();
        $otherCourse = Course::create([
            'user_id' => $otherUser->id,
            'course_code' => 'MATH-101',
            'course_name' => 'Calculus I',
            'semester' => 'Fall',
            'academic_year' => '2026',
            'credits' => 3,
        ]);

        // Show other course
        $showRes = $this->actingAs($this->user, 'sanctum')->getJson("/api/courses/{$otherCourse->id}");
        $showRes->assertStatus(403);

        // Update other course
        $updateRes = $this->actingAs($this->user, 'sanctum')->putJson("/api/courses/{$otherCourse->id}", [
            'course_name' => 'Hacked Name',
        ]);
        $updateRes->assertStatus(403);

        // Delete other course
        $deleteRes = $this->actingAs($this->user, 'sanctum')->deleteJson("/api/courses/{$otherCourse->id}");
        $deleteRes->assertStatus(403);
    }
}

