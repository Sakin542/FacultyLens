<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentVersion;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Services\AssessmentVersionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 41 — database integrity: foreign keys, uniqueness, cross-entity references, bounds and state machines
 * are enforced by the schema and/or the API so that no orphaned or inconsistent academic record can be created.
 */
class DatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;

    protected Course $course;

    protected Assessment $assessment;

    protected Assessment $otherAssessment;

    protected Question $q1;

    protected Question $q2;

    protected Question $foreignQuestion;

    protected Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faculty = User::factory()->create(['role' => 'FACULTY', 'department' => 'CSE']);
        $this->course = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE-401', 'course_name' => 'Artificial Intelligence', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'LO1', 'description' => 'Explain search.', 'sort_order' => 1]);
        $this->assessment = Assessment::create(['course_id' => $this->course->id, 'title' => 'Midterm', 'type' => 'midterm', 'total_marks' => 20, 'status' => 'draft']);
        $this->otherAssessment = Assessment::create(['course_id' => $this->course->id, 'title' => 'Final', 'type' => 'final', 'total_marks' => 10, 'status' => 'draft']);
        $this->q1 = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Define a heuristic.', 'question_type' => 'descriptive', 'marks' => 10, 'difficulty_level' => 'easy', 'cognitive_level' => 'Remember']);
        $this->q2 = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 2, 'question_text' => 'Compare BFS and DFS.', 'question_type' => 'descriptive', 'marks' => 10, 'difficulty_level' => 'medium', 'cognitive_level' => 'Understand']);
        $this->foreignQuestion = Question::create(['assessment_id' => $this->otherAssessment->id, 'question_number' => 1, 'question_text' => 'Final question.', 'question_type' => 'descriptive', 'marks' => 10, 'difficulty_level' => 'easy', 'cognitive_level' => 'Remember']);
        $this->student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'STU-001', 'name' => 'Student One']);
        Sanctum::actingAs($this->faculty);
    }

    // ------------------------------------------------------------------ schema-level guarantees

    public function test_foreign_key_constraints_are_enforced_by_the_database(): void
    {
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys, 'Tests must run with foreign keys ON to be meaningful');

        foreach ([
            'questions' => ['assessment_id' => 999999, 'question_number' => 1, 'question_text' => 'x', 'question_type' => 'mcq', 'marks' => 1, 'difficulty_level' => 'easy', 'cognitive_level' => 'Remember', 'created_at' => now(), 'updated_at' => now()],
            'assessments' => ['course_id' => 999999, 'title' => 'x', 'type' => 'quiz', 'total_marks' => 1, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()],
            'learning_outcomes' => ['course_id' => 999999, 'code' => 'X', 'description' => 'x', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            'student_submissions' => ['assessment_id' => $this->assessment->id, 'student_id' => 999999, 'status' => 'SUBMITTED', 'grading_status' => 'NOT_STARTED', 'created_at' => now(), 'updated_at' => now()],
            'student_answers' => ['student_submission_id' => 999999, 'question_id' => $this->q1->id, 'answer_type' => 'TEXT', 'answer_status' => 'NOT_REVIEWED', 'created_at' => now(), 'updated_at' => now()],
        ] as $table => $row) {
            try {
                DB::table($table)->insert($row);
                $this->fail("{$table}: insert with a dangling foreign key must be rejected");
            } catch (QueryException $e) {
                $this->assertStringContainsStringIgnoringCase('foreign key', $e->getMessage(), $table);
            }
        }
    }

    public function test_deleting_a_course_cascades_to_its_academic_records_without_orphans(): void
    {
        $submission = StudentSubmission::create(['assessment_id' => $this->assessment->id, 'student_id' => $this->student->id, 'status' => 'SUBMITTED', 'grading_status' => 'NOT_STARTED', 'submitted_at' => now(), 'total_marks' => 20]);
        StudentAnswer::create(['student_submission_id' => $submission->id, 'question_id' => $this->q1->id, 'answer_type' => 'TEXT', 'answer_text' => 'a', 'answer_status' => 'NOT_REVIEWED']);
        $version = app(AssessmentVersionService::class)->createVersion($this->faculty, $this->assessment, ['change_summary' => 'v1']);
        $this->assertGreaterThan(0, $version->questions()->count());

        $this->deleteJson("/api/courses/{$this->course->id}")->assertOk();

        foreach (['courses', 'learning_outcomes', 'assessments', 'questions', 'student_submissions', 'student_answers', 'assessment_versions', 'assessment_version_questions'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must be empty after the course is deleted");
        }
        // The student master record is owned by the faculty member, not by the course
        $this->assertDatabaseHas('students', ['id' => $this->student->id]);
        $this->assertSame(0, Artisan::call('facultylens:integrity-check', ['--fail-on-issues' => true]), Artisan::output());
    }

    public function test_unique_constraints_prevent_duplicate_academic_records(): void
    {
        // One submission per student per assessment (schema + API)
        StudentSubmission::create(['assessment_id' => $this->assessment->id, 'student_id' => $this->student->id, 'status' => 'SUBMITTED', 'grading_status' => 'NOT_STARTED', 'submitted_at' => now(), 'total_marks' => 20]);
        $this->postJson("/api/assessments/{$this->assessment->id}/submissions", ['student_id' => $this->student->id])->assertStatus(422);
        $this->expectExceptionForInsert('student_submissions', ['assessment_id' => $this->assessment->id, 'student_id' => $this->student->id, 'status' => 'SUBMITTED', 'grading_status' => 'NOT_STARTED', 'created_at' => now(), 'updated_at' => now()]);

        // One student identifier per faculty member (API) — the same identifier may exist for another faculty member
        $this->postJson('/api/students', ['student_identifier' => 'STU-001', 'name' => 'Duplicate'])->assertStatus(422);
        $other = User::factory()->create(['role' => 'FACULTY']);
        Sanctum::actingAs($other);
        $this->postJson('/api/students', ['student_identifier' => 'STU-001', 'name' => 'Other faculty student'])->assertStatus(201);
        Sanctum::actingAs($this->faculty);

        // One answer per question per submission
        $sub = StudentSubmission::where('student_id', $this->student->id)->first();
        StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $this->q1->id, 'answer_type' => 'TEXT', 'answer_text' => 'a', 'answer_status' => 'NOT_REVIEWED']);
        $this->postJson("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q1->id, 'answer_text' => 'again'])->assertStatus(422);
        $this->assertSame(1, StudentAnswer::where('student_submission_id', $sub->id)->where('question_id', $this->q1->id)->count());

        // One program per (owner, code), one PO per (program, code), one mapping per (LO, PO)
        $programId = $this->postJson('/api/programs', ['code' => 'BSC', 'name' => 'B.Sc.'])->assertStatus(201)->json('data.id');
        $this->postJson('/api/programs', ['code' => 'BSC', 'name' => 'Again'])->assertStatus(422);
        $this->postJson("/api/programs/{$programId}/outcomes", ['code' => 'PO1', 'title' => 'Knowledge'])->assertStatus(201);
        $this->postJson("/api/programs/{$programId}/outcomes", ['code' => 'PO1', 'title' => 'Again'])->assertStatus(422);
    }

    // ------------------------------------------------------------------ cross-entity references

    public function test_answer_cannot_reference_a_question_from_another_assessment(): void
    {
        $sub = $this->postJson("/api/assessments/{$this->assessment->id}/submissions", ['student_id' => $this->student->id])->assertStatus(201)->json('data');
        $this->postJson("/api/submissions/{$sub['id']}/answers", ['question_id' => $this->foreignQuestion->id, 'answer_text' => 'wrong exam'])
            ->assertStatus(422)->assertJsonPath('message', "The question does not belong to this submission's assessment.");
        $this->postJson("/api/submissions/{$sub['id']}/answers", ['question_id' => 999999, 'answer_text' => 'nowhere'])->assertStatus(422);
        $this->assertSame(0, StudentAnswer::count());
    }

    public function test_blueprint_and_version_references_must_stay_within_their_assessment(): void
    {
        $foreignLo = LearningOutcome::create(['course_id' => Course::create(['user_id' => $this->faculty->id, 'course_code' => 'EEE-101', 'course_name' => 'Circuits', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active'])->id, 'code' => 'X1', 'description' => 'Foreign outcome', 'sort_order' => 1]);
        $payload = ['title' => 'BP', 'total_marks' => 20, 'total_questions' => 2, 'duration_minutes' => 60,
            'sections' => [['title' => 'A', 'question_type' => 'descriptive', 'question_count' => 2, 'marks_per_question' => 10]],
            'constraints' => ['learning_outcomes' => [['learning_outcome_id' => $foreignLo->id, 'target_percentage' => 100]]], 'items' => []];
        $this->postJson("/api/assessments/{$this->assessment->id}/blueprint", $payload)->assertStatus(422);
        $this->assertSame(0, DB::table('assessment_blueprints')->count());

        $otherVersion = app(AssessmentVersionService::class)->createVersion($this->faculty, $this->otherAssessment, ['change_summary' => 'other']);
        $this->postJson("/api/assessments/{$this->assessment->id}/versions", ['based_on_version_id' => $otherVersion->id])->assertStatus(422);
        $this->assertSame(0, AssessmentVersion::where('assessment_id', $this->assessment->id)->count());

        // CO/PO mapping: learning outcome must belong to the course, PO to the course's program
        $programId = $this->postJson('/api/programs', ['code' => 'BSC', 'name' => 'B.Sc.'])->json('data.id');
        $this->putJson("/api/courses/{$this->course->id}", ['program_id' => $programId])->assertOk();
        $poId = $this->postJson("/api/programs/{$programId}/outcomes", ['code' => 'PO1', 'title' => 'Knowledge'])->json('data.id');
        $this->postJson("/api/courses/{$this->course->id}/co-po-mappings", ['learning_outcome_id' => $foreignLo->id, 'program_outcome_id' => $poId, 'mapping_level' => 2])->assertStatus(422);
        $this->assertSame(0, DB::table('co_po_mappings')->count());
    }

    // ------------------------------------------------------------------ bounds

    public function test_marks_are_bounded_by_the_question_and_never_negative(): void
    {
        $sub = $this->postJson("/api/assessments/{$this->assessment->id}/submissions", ['student_id' => $this->student->id])->json('data');
        $answer = $this->postJson("/api/submissions/{$sub['id']}/answers", ['question_id' => $this->q1->id, 'answer_text' => 'A heuristic estimates cost to goal.'])->assertStatus(201)->json('data');
        $this->patchJson("/api/submissions/{$sub['id']}/status", ['status' => 'UNDER_REVIEW'])->assertOk();

        foreach ([10.5, 11, 999, -1, -0.01, 'ten'] as $bad) {
            $this->putJson("/api/student-answers/{$answer['id']}", ['awarded_marks' => $bad])->assertStatus(422);
            $this->postJson("/api/student-answers/{$answer['id']}/finalize-grade", ['final_marks' => $bad])->assertStatus(422);
        }
        $this->assertNull(StudentAnswer::find($answer['id'])->awarded_marks, 'No invalid mark may be persisted');

        $this->postJson("/api/student-answers/{$answer['id']}/finalize-grade", ['final_marks' => 10])->assertOk();
        $this->assertEquals(10, StudentAnswer::find($answer['id'])->awarded_marks);
        $this->assertEquals(10, StudentSubmission::find($sub['id'])->awarded_marks);
        $this->postJson("/api/student-answers/{$answer['id']}/finalize-grade", ['final_marks' => 0])->assertOk();
        $this->assertEquals(0, StudentAnswer::find($answer['id'])->awarded_marks);
    }

    public function test_structural_numbers_reject_negative_and_zero_values(): void
    {
        $this->postJson("/api/courses/{$this->course->id}/assessments", ['title' => 'Bad', 'type' => 'quiz', 'total_marks' => -5, 'status' => 'draft'])->assertStatus(422);
        $this->postJson("/api/courses/{$this->course->id}/assessments", ['title' => 'Bad', 'type' => 'quiz', 'total_marks' => 10, 'duration_minutes' => -30, 'status' => 'draft'])->assertStatus(422);
        $this->postJson('/api/courses', ['course_code' => 'X', 'course_name' => 'X', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 0, 'status' => 'active'])->assertStatus(422);
        $this->postJson('/api/courses', ['course_code' => 'X', 'course_name' => 'X', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => -3, 'status' => 'active'])->assertStatus(422);
        $this->postJson("/api/assessments/{$this->assessment->id}/blueprint", ['title' => 'BP', 'total_marks' => 0, 'total_questions' => 1, 'duration_minutes' => 60, 'sections' => [], 'constraints' => [], 'items' => []])->assertStatus(422);
        $this->postJson("/api/assessments/{$this->assessment->id}/blueprint", ['title' => 'BP', 'total_marks' => 10, 'total_questions' => -1, 'duration_minutes' => 60, 'sections' => [], 'constraints' => [], 'items' => []])->assertStatus(422);
        $this->assertSame(2, Assessment::count());
        $this->assertSame(1, Course::count());
    }

    // ------------------------------------------------------------------ state machines

    public function test_submission_status_transitions_follow_the_state_machine(): void
    {
        $sub = $this->postJson("/api/assessments/{$this->assessment->id}/submissions", ['student_id' => $this->student->id])->json('data');
        $this->assertSame('SUBMITTED', $sub['status']);

        $this->patchJson("/api/submissions/{$sub['id']}/status", ['status' => 'GRADED'])->assertStatus(422);
        $this->patchJson("/api/submissions/{$sub['id']}/status", ['status' => 'RETURNED'])->assertStatus(422);
        $this->patchJson("/api/submissions/{$sub['id']}/status", ['status' => 'BOGUS'])->assertStatus(422);
        $this->assertSame('SUBMITTED', StudentSubmission::find($sub['id'])->status);

        $this->patchJson("/api/submissions/{$sub['id']}/status", ['status' => 'UNDER_REVIEW'])->assertOk();

        // A submission with an ungraded answer can never become GRADED (found by the Golden Path run; integrity rule)
        $answer = $this->postJson("/api/submissions/{$sub['id']}/answers", ['question_id' => $this->q1->id, 'answer_text' => 'A heuristic estimates cost.'])->assertStatus(201)->json('data');
        $this->patchJson("/api/submissions/{$sub['id']}/status", ['status' => 'GRADED'])->assertStatus(422)->assertJsonPath('message', '1 answer(s) still need a faculty grade before this submission can be marked as graded.');
        $this->assertSame('UNDER_REVIEW', StudentSubmission::find($sub['id'])->status);
        $this->postJson("/api/student-answers/{$answer['id']}/finalize-grade", ['final_marks' => 7])->assertOk();

        $this->patchJson("/api/submissions/{$sub['id']}/status", ['status' => 'GRADED'])->assertOk();
        $this->patchJson("/api/submissions/{$sub['id']}/status", ['status' => 'RETURNED'])->assertOk();
        $this->patchJson("/api/submissions/{$sub['id']}/status", ['status' => 'UNDER_REVIEW'])->assertStatus(422);
        $this->assertSame('RETURNED', StudentSubmission::find($sub['id'])->status);
    }

    public function test_finalized_versions_and_rubrics_are_immutable_and_snapshots_survive_question_edits(): void
    {
        $service = app(AssessmentVersionService::class);
        $version = $service->createVersion($this->faculty, $this->assessment, ['change_summary' => 'v1']);
        $this->postJson("/api/assessment-versions/{$version->id}/finalize")->assertOk();
        $this->putJson("/api/assessment-versions/{$version->id}", ['title' => 'tamper'])->assertStatus(409);
        $this->postJson("/api/assessment-versions/{$version->id}/finalize")->assertStatus(409);

        $snapshotText = $version->questions()->where('original_question_id', $this->q1->id)->value('question_text');
        $this->q1->update(['question_text' => 'EDITED LIVE QUESTION']);
        $this->assertSame($snapshotText, $version->questions()->where('original_question_id', $this->q1->id)->value('question_text'), 'Finalized snapshot must not follow live edits');

        $this->q1->delete();
        $this->assertSame(2, $version->questions()->count(), 'Snapshot rows survive deletion of the live question');
        $this->assertSame(0, Artisan::call('facultylens:integrity-check', ['--fail-on-issues' => true]), Artisan::output());
    }

    // ------------------------------------------------------------------ helpers

    protected function expectExceptionForInsert(string $table, array $row): void
    {
        try {
            DB::table($table)->insert($row);
            $this->fail("{$table}: duplicate row must be rejected by a unique constraint");
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('unique', $e->getMessage());
        }
    }
}
