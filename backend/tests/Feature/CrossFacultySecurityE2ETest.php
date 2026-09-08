<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\AssessmentReport;
use App\Models\Course;
use App\Models\DocumentProcessing;
use App\Models\Question;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CrossFacultySecurityE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_faculty_b_is_denied_access_to_all_faculty_a_resources(): void
    {
        // 1. Create Faculty A and Faculty B
        $facultyA = User::factory()->create(['role' => 'FACULTY', 'email' => 'prof_a@aust.edu']);
        $facultyB = User::factory()->create(['role' => 'FACULTY', 'email' => 'prof_b@aust.edu']);

        // 2. Faculty A creates Course, Assessment, Document, Analysis, Recommendation, Report
        $courseA = Course::create([
            'user_id' => $facultyA->id,
            'course_code' => 'CSE101',
            'course_name' => 'Database Systems',
            'semester' => 'Spring',
            'academic_year' => '2026',
            'credits' => 3,
            'status' => 'active',
        ]);

        $assessmentA = Assessment::create([
            'course_id' => $courseA->id,
            'title' => 'Midterm Examination',
            'type' => 'Midterm',
            'total_marks' => 50,
            'duration_minutes' => 90,
            'status' => 'Draft',
        ]);

        $questionA = Question::create([
            'assessment_id' => $assessmentA->id,
            'question_number' => 1,
            'question_text' => 'Explain 3NF decomposition.',
            'marks' => 10,
        ]);

        $docA = DocumentProcessing::create([
            'user_id' => $facultyA->id,
            'course_id' => $courseA->id,
            'document_type' => 'question_paper',
            'original_file_name' => 'midterm.txt',
            'stored_file_name' => 'midterm_hash123.txt',
            'file_path' => 'documents/midterm.txt',
            'mime_type' => 'text/plain',
            'file_size' => 100,
            'processing_status' => 'completed',
        ]);

        $analysisA = AnalysisReport::create([
            'assessment_id' => $assessmentA->id,
            'overall_score' => 84.5,
            'status' => 'completed',
            'version' => 1,
            'is_current' => true,
        ]);

        $recA = Recommendation::create([
            'analysis_report_id' => $analysisA->id,
            'category' => 'difficulty',
            'title' => 'Rebalance Hard Questions',
            'description' => 'Add more challenging questions.',
            'recommendation' => 'Include 1 hard question.',
            'priority' => 'medium',
            'status' => 'pending',
        ]);

        $reportA = AssessmentReport::create([
            'report_uuid' => (string) Str::uuid(),
            'assessment_id' => $assessmentA->id,
            'analysis_report_id' => $analysisA->id,
            'file_name' => 'faculty_a_report.pdf',
            'file_path' => 'reports/faculty_a_report.pdf',
            'file_size' => 1024,
            'generation_status' => 'completed',
            'generated_at' => now(),
            'share_token' => 'token_secret_123',
            'is_shareable' => false,
        ]);

        // 3. Faculty B attempts access -> must receive 403 Forbidden or 404 Not Found
        // Course Access
        $this->actingAs($facultyB, 'sanctum')->getJson("/api/courses/{$courseA->id}")
            ->assertStatus(403);

        // Course Update
        $this->actingAs($facultyB, 'sanctum')->putJson("/api/courses/{$courseA->id}", ['course_name' => 'Hacked'])
            ->assertStatus(403);

        // Assessment Access
        $this->actingAs($facultyB, 'sanctum')->getJson("/api/assessments/{$assessmentA->id}")
            ->assertStatus(403);

        // Document Download
        $this->actingAs($facultyB, 'sanctum')->getJson("/api/documents/{$docA->id}/download")
            ->assertStatus(403);

        // Analysis History / Details
        $this->actingAs($facultyB, 'sanctum')->getJson("/api/analysis/{$analysisA->id}")
            ->assertStatus(403);

        // Recommendation Feedback submission
        $this->actingAs($facultyB, 'sanctum')->postJson("/api/recommendations/{$recA->id}/feedback", [
            'decision' => 'ACCEPTED',
            'rating' => 5,
        ])->assertStatus(403);

        // Report PDF Download
        $this->actingAs($facultyB, 'sanctum')->getJson("/api/assessment-reports/{$reportA->id}/download")
            ->assertStatus(403);

        // 4. Verify Faculty A can access everything without disruption
        $this->actingAs($facultyA, 'sanctum')->getJson("/api/courses/{$courseA->id}")
            ->assertStatus(200);

        $this->actingAs($facultyA, 'sanctum')->getJson("/api/assessments/{$assessmentA->id}")
            ->assertStatus(200);

        $this->actingAs($facultyA, 'sanctum')->getJson("/api/analysis/{$analysisA->id}")
            ->assertStatus(200);
    }
}
