<?php

namespace Tests\Feature\Regression;

use App\Models\Assessment;
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
 * BUG-004 regression: a RETURNED submission is terminal. Its answers and marks must be
 * read-only through every write path, not only through finalize-grade.
 */
class ReturnedSubmissionReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected Assessment $assessment;
    protected Question $q1;
    protected Question $q2;
    protected StudentSubmission $submission;
    protected StudentAnswer $answer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculty = User::factory()->create();
        $course = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'RO-101', 'course_name' => 'Read Only', 'semester' => 'Fall', 'academic_year' => '2026']);
        $this->assessment = Assessment::create(['course_id' => $course->id, 'title' => 'Quiz', 'type' => 'quiz', 'total_marks' => 20, 'status' => 'draft']);
        $this->q1 = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Define a primary key and explain its purpose.', 'marks' => 10, 'question_type' => 'short_answer']);
        $this->q2 = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 2, 'question_text' => 'Explain the difference between a clustered and non-clustered index.', 'marks' => 10, 'question_type' => 'descriptive']);

        $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'RO-1', 'name' => 'Student']);
        $this->submission = StudentSubmission::create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $student->id,
            'status' => StudentSubmission::STATUS_RETURNED,
            'grading_status' => StudentSubmission::GRADING_FINALIZED,
            'submitted_at' => now(),
            'total_marks' => 20,
            'awarded_marks' => 8,
        ]);
        $this->answer = StudentAnswer::create([
            'student_submission_id' => $this->submission->id,
            'question_id' => $this->q1->id,
            'answer_type' => 'TEXT',
            'answer_text' => 'A primary key uniquely identifies a row.',
            'awarded_marks' => 8,
            'answer_status' => 'REVIEWED',
        ]);

        Sanctum::actingAs($this->faculty);
    }

    public function test_marks_cannot_be_changed_on_a_returned_submission(): void
    {
        $this->putJson("/api/student-answers/{$this->answer->id}", ['awarded_marks' => 2])->assertStatus(422);

        $this->assertEquals(8.0, (float) $this->answer->fresh()->awarded_marks);
        $this->assertEquals(8.0, (float) $this->submission->fresh()->awarded_marks);
    }

    public function test_answer_text_cannot_be_edited_on_a_returned_submission(): void
    {
        $this->putJson("/api/student-answers/{$this->answer->id}", ['answer_text' => 'tampered'])->assertStatus(422);
        $this->assertSame('A primary key uniquely identifies a row.', $this->answer->fresh()->answer_text);
    }

    public function test_answers_cannot_be_added_to_or_removed_from_a_returned_submission(): void
    {
        $this->postJson("/api/submissions/{$this->submission->id}/answers", [
            'question_id' => $this->q2->id,
            'answer_text' => 'late answer',
        ])->assertStatus(422);
        $this->assertDatabaseCount('student_answers', 1);

        $this->deleteJson("/api/student-answers/{$this->answer->id}")->assertStatus(422);
        $this->assertDatabaseHas('student_answers', ['id' => $this->answer->id]);
    }

    public function test_finalize_grade_endpoint_is_still_blocked_for_returned_submissions(): void
    {
        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", ['final_marks' => 1])->assertStatus(422);
        $this->assertEquals(8.0, (float) $this->answer->fresh()->awarded_marks);
    }

    public function test_graded_submission_can_still_be_regraded(): void
    {
        $this->submission->update(['status' => StudentSubmission::STATUS_GRADED, 'grading_status' => StudentSubmission::GRADING_FACULTY_REVIEWED]);

        $this->putJson("/api/student-answers/{$this->answer->id}", ['awarded_marks' => 9])->assertOk();
        $this->assertEquals(9.0, (float) $this->answer->fresh()->awarded_marks);
    }

    /** BUG-009: deleting a submission must not silently destroy finalized grades. */
    public function test_returned_and_graded_submissions_cannot_be_deleted(): void
    {
        $this->deleteJson("/api/submissions/{$this->submission->id}")->assertStatus(422);
        $this->assertDatabaseHas('student_submissions', ['id' => $this->submission->id]);

        $this->submission->update(['status' => StudentSubmission::STATUS_GRADED, 'grading_status' => StudentSubmission::GRADING_FACULTY_REVIEWED]);
        $this->deleteJson("/api/submissions/{$this->submission->id}")->assertStatus(422);
        $this->assertDatabaseHas('student_answers', ['id' => $this->answer->id, 'awarded_marks' => 8]);

        // Explicitly reopening the submission makes deletion possible again.
        $this->patchJson("/api/submissions/{$this->submission->id}/status", ['status' => 'UNDER_REVIEW'])->assertOk();
        $this->deleteJson("/api/submissions/{$this->submission->id}")->assertOk();
        $this->assertDatabaseMissing('student_submissions', ['id' => $this->submission->id]);
    }
}
