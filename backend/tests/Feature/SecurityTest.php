<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\DocumentProcessing;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $facultyA;
    protected User $facultyB;
    protected Course $courseA;
    protected Assessment $assessmentA;
    protected Question $questionA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facultyA = User::factory()->create([
            'name' => 'Faculty A',
            'email' => 'faculty.a@aust.edu',
            'password' => Hash::make('FacultyA123!'),
        ]);

        $this->facultyB = User::factory()->create([
            'name' => 'Faculty B',
            'email' => 'faculty.b@aust.edu',
            'password' => Hash::make('FacultyB123!'),
        ]);

        $this->courseA = Course::create([
            'user_id' => $this->facultyA->id,
            'course_code' => 'CSE-401',
            'course_name' => 'Software Engineering',
            'semester' => 'Spring',
            'academic_year' => '2026',
            'credits' => 3,
        ]);

        $this->assessmentA = Assessment::create([
            'course_id' => $this->courseA->id,
            'title' => 'Final Examination',
            'type' => 'Final',
            'total_marks' => 100,
            'status' => 'Published',
        ]);

        $this->questionA = Question::create([
            'assessment_id' => $this->assessmentA->id,
            'question_number' => 1,
            'question_text' => 'Explain design patterns in software architecture.',
            'marks' => 10,
        ]);
    }

    public function test_unauthenticated_requests_return_401(): void
    {
        $this->getJson('/api/courses')->assertStatus(401);
        $this->getJson('/api/assessments')->assertStatus(401);
        $this->getJson('/api/documents')->assertStatus(401);
        $this->postJson('/api/ai/analyze-question', ['question' => 'test'])->assertStatus(401);
    }

    public function test_faculty_b_cannot_view_or_update_faculty_a_course(): void
    {
        $this->actingAs($this->facultyB, 'sanctum')
            ->getJson("/api/courses/{$this->courseA->id}")
            ->assertStatus(403);

        $this->actingAs($this->facultyB, 'sanctum')
            ->putJson("/api/courses/{$this->courseA->id}", ['course_name' => 'Compromised'])
            ->assertStatus(403);

        $this->actingAs($this->facultyB, 'sanctum')
            ->deleteJson("/api/courses/{$this->courseA->id}")
            ->assertStatus(403);
    }

    public function test_faculty_b_cannot_view_or_modify_faculty_a_assessment(): void
    {
        $this->actingAs($this->facultyB, 'sanctum')
            ->getJson("/api/assessments/{$this->assessmentA->id}")
            ->assertStatus(403);

        $this->actingAs($this->facultyB, 'sanctum')
            ->putJson("/api/assessments/{$this->assessmentA->id}", ['title' => 'Tampered Title'])
            ->assertStatus(403);

        $this->actingAs($this->facultyB, 'sanctum')
            ->deleteJson("/api/assessments/{$this->assessmentA->id}")
            ->assertStatus(403);
    }

    public function test_faculty_b_cannot_run_ai_analysis_on_faculty_a_assessment(): void
    {
        $this->actingAs($this->facultyB, 'sanctum')
            ->postJson("/api/ai/assessments/{$this->assessmentA->id}/analyze-questions")
            ->assertStatus(403);
    }

    public function test_passwords_are_never_exposed_in_json_responses(): void
    {
        $response = $this->actingAs($this->facultyA, 'sanctum')->getJson('/api/auth/user');
        $response->assertStatus(200);

        $this->assertArrayNotHasKey('password', $response->json('user'));
        $this->assertArrayNotHasKey('remember_token', $response->json('user'));
    }
}

