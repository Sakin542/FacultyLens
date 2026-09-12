<?php

namespace Tests\Feature\Regression;

use App\Models\Assessment;
use App\Models\AssessmentVersion;
use App\Models\Course;
use App\Models\Question;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUG-003 regression: deleting an assessment (or its course) must not silently cascade
 * through finalized student grades or finalized assessment versions.
 */
class AcademicRecordDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected Course $course;
    protected Assessment $assessment;
    protected Question $question;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculty = User::factory()->create();
        $this->course = Course::create([
            'user_id' => $this->faculty->id,
            'course_code' => 'DEL-101',
            'course_name' => 'Deletion Guard',
            'semester' => 'Fall',
            'academic_year' => '2026',
        ]);
        $this->assessment = Assessment::create([
            'course_id' => $this->course->id,
            'title' => 'Final',
            'type' => 'final',
            'total_marks' => 10,
            'status' => 'draft',
        ]);
        $this->question = Question::create([
            'assessment_id' => $this->assessment->id,
            'question_number' => 1,
            'question_text' => 'Explain normalization up to third normal form.',
            'marks' => 10,
            'question_type' => 'descriptive',
        ]);
        Sanctum::actingAs($this->faculty);
    }

    protected function gradedSubmission(): StudentSubmission
    {
        $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'S-1', 'name' => 'Student One']);
        $submission = StudentSubmission::create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $student->id,
            'status' => StudentSubmission::STATUS_GRADED,
            'grading_status' => StudentSubmission::GRADING_FACULTY_REVIEWED,
            'submitted_at' => now(),
            'total_marks' => 10,
            'awarded_marks' => 7,
        ]);
        StudentAnswer::create([
            'student_submission_id' => $submission->id,
            'question_id' => $this->question->id,
            'answer_type' => 'TEXT',
            'answer_text' => 'Answer',
            'awarded_marks' => 7,
            'answer_status' => 'REVIEWED',
        ]);

        return $submission;
    }

    public function test_assessment_with_finalized_grades_cannot_be_deleted(): void
    {
        $submission = $this->gradedSubmission();

        $this->deleteJson("/api/assessments/{$this->assessment->id}")->assertStatus(409);

        $this->assertDatabaseHas('assessments', ['id' => $this->assessment->id]);
        $this->assertDatabaseHas('student_submissions', ['id' => $submission->id, 'awarded_marks' => 7]);
        $this->assertDatabaseHas('student_answers', ['student_submission_id' => $submission->id, 'awarded_marks' => 7]);
    }

    public function test_assessment_with_finalized_version_cannot_be_deleted(): void
    {
        AssessmentVersion::create([
            'assessment_id' => $this->assessment->id,
            'version_number' => 1,
            'version_label' => 'v1',
            'version_type' => AssessmentVersion::TYPE_MAJOR,
            'status' => AssessmentVersion::STATUS_FINALIZED,
            'title' => 'Final',
            'assessment_type' => 'final',
            'total_marks' => 10,
            'question_count' => 1,
            'created_by' => $this->faculty->id,
            'finalized_at' => now(),
            'finalized_by' => $this->faculty->id,
        ]);

        $this->deleteJson("/api/assessments/{$this->assessment->id}")->assertStatus(409);
        $this->assertDatabaseHas('assessment_versions', ['assessment_id' => $this->assessment->id, 'status' => 'FINALIZED']);
    }

    public function test_course_with_graded_assessment_cannot_be_deleted(): void
    {
        $this->gradedSubmission();

        $this->deleteJson("/api/courses/{$this->course->id}")->assertStatus(409);
        $this->assertDatabaseHas('courses', ['id' => $this->course->id]);
        $this->assertDatabaseCount('student_answers', 1);
    }

    public function test_assessment_without_academic_records_can_still_be_deleted(): void
    {
        $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'S-2', 'name' => 'Student Two']);
        StudentSubmission::create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $student->id,
            'status' => StudentSubmission::STATUS_DRAFT,
            'grading_status' => StudentSubmission::GRADING_NOT_STARTED,
        ]);

        $this->deleteJson("/api/assessments/{$this->assessment->id}")->assertOk();
        $this->assertDatabaseMissing('assessments', ['id' => $this->assessment->id]);
    }
}
