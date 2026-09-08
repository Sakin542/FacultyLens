<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Question;
use App\Models\Rubric;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 26: Student Answer Management.
 */
class StudentSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected User $other;
    protected Course $course;
    protected Assessment $assessment;
    protected Question $q1;
    protected Question $q2;
    protected Question $q3;
    protected Student $stu1;
    protected Student $stu2;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->faculty = User::factory()->create(['email' => 'faculty@university.edu']);
        $this->other = User::factory()->create(['email' => 'other@university.edu']);

        $this->course = Course::create([
            'user_id' => $this->faculty->id,
            'course_code' => 'CSE101',
            'course_name' => 'Database Systems',
            'semester' => 'Fall',
            'academic_year' => '2026',
        ]);
        $this->assessment = Assessment::create([
            'course_id' => $this->course->id,
            'title' => 'Midterm Examination',
            'type' => 'midterm',
            'total_marks' => 30,
            'status' => 'draft',
        ]);
        $this->q1 = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Define a primary key.', 'question_type' => 'short_answer', 'marks' => 5]);
        $this->q2 = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 2, 'question_text' => 'Explain database normalization.', 'question_type' => 'descriptive', 'marks' => 10]);
        $this->q3 = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 3, 'question_text' => 'Design an ER diagram.', 'question_type' => 'problem_solving', 'marks' => 15]);

        $this->stu1 = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'STU001', 'name' => 'Student One']);
        $this->stu2 = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'STU002', 'name' => 'Student Two']);
    }

    protected function createSubmission(?Student $student = null): StudentSubmission
    {
        return StudentSubmission::create([
            'assessment_id' => $this->assessment->id,
            'student_id' => ($student ?? $this->stu1)->id,
            'status' => 'SUBMITTED',
            'grading_status' => 'NOT_STARTED',
            'submitted_at' => now(),
            'total_marks' => 30,
        ]);
    }

    protected function fakePng(string $name = 'scan.png'): UploadedFile
    {
        // Real 1x1 transparent PNG so MIME sniffing works without the GD extension.
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    protected function foreignSubmission(): array
    {
        $course = Course::create(['user_id' => $this->other->id, 'course_code' => 'EEE201', 'course_name' => 'Circuits', 'semester' => 'Fall', 'academic_year' => '2026']);
        $assessment = Assessment::create(['course_id' => $course->id, 'title' => 'Quiz', 'type' => 'quiz', 'total_marks' => 10, 'status' => 'draft']);
        $question = Question::create(['assessment_id' => $assessment->id, 'question_number' => 1, 'question_text' => "Define Ohm's law.", 'question_type' => 'short_answer', 'marks' => 10]);
        $student = Student::create(['created_by' => $this->other->id, 'student_identifier' => 'EE001', 'name' => 'EE Student']);
        $submission = StudentSubmission::create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'status' => 'SUBMITTED', 'grading_status' => 'NOT_STARTED']);
        $answer = StudentAnswer::create(['student_submission_id' => $submission->id, 'question_id' => $question->id, 'answer_type' => 'TEXT', 'answer_text' => 'V = IR', 'answer_status' => 'NOT_REVIEWED']);

        return compact('course', 'assessment', 'question', 'student', 'submission', 'answer');
    }

    // --------------------------------------------------------------- students

    public function test_students_require_authentication(): void
    {
        $this->getJson('/api/students')->assertStatus(401);
        $this->postJson('/api/students', ['student_identifier' => 'X', 'name' => 'Y'])->assertStatus(401);
    }

    public function test_faculty_can_create_and_list_students(): void
    {
        Sanctum::actingAs($this->faculty);

        $this->postJson('/api/students', ['student_identifier' => 'STU003', 'name' => 'Student Three', 'email' => 's3@uni.edu', 'section' => 'A'])
            ->assertStatus(201)
            ->assertJsonPath('data.student_identifier', 'STU003')
            ->assertJsonPath('data.created_by', $this->faculty->id);

        $res = $this->getJson('/api/students?search=STU00');
        $res->assertStatus(200)->assertJsonPath('meta.total', 3);
    }

    public function test_duplicate_student_identifier_rejected_case_insensitively(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->postJson('/api/students', ['student_identifier' => 'stu001', 'name' => 'Dup'])->assertStatus(422);
        $this->postJson('/api/students', ['student_identifier' => 'bad id!', 'name' => 'Dup'])->assertStatus(422);
        $this->postJson('/api/students', ['name' => 'No id'])->assertStatus(422);
    }

    public function test_students_are_scoped_to_faculty(): void
    {
        Sanctum::actingAs($this->other);
        $this->getJson('/api/students')->assertStatus(200)->assertJsonPath('meta.total', 0);
        $this->getJson("/api/students/{$this->stu1->id}")->assertStatus(403);
        $this->putJson("/api/students/{$this->stu1->id}", ['name' => 'Hijack'])->assertStatus(403);
        $this->deleteJson("/api/students/{$this->stu1->id}")->assertStatus(403);
        // Same identifier is allowed for a different faculty member.
        $this->postJson('/api/students', ['student_identifier' => 'STU001', 'name' => 'Other faculty student'])->assertStatus(201);
    }

    public function test_student_with_submissions_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->createSubmission();
        $this->deleteJson("/api/students/{$this->stu1->id}")->assertStatus(422);
        $this->deleteJson("/api/students/{$this->stu2->id}")->assertStatus(200);
    }

    // ------------------------------------------------------------ submissions

    public function test_create_submission_uses_route_assessment_and_ignores_payload_assessment(): void
    {
        Sanctum::actingAs($this->faculty);

        $res = $this->postJson("/api/assessments/{$this->assessment->id}/submissions", [
            'student_id' => $this->stu1->id,
            'assessment_id' => 99999,
            'submission_identifier' => 'MIDTERM-001',
            'submitted_at' => '2026-09-08T10:30:00',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.assessment_id', $this->assessment->id)
            ->assertJsonPath('data.status', 'SUBMITTED')
            ->assertJsonPath('data.grading_status', 'NOT_STARTED')
            ->assertJsonPath('data.total_marks', 30)
            ->assertJsonPath('data.awarded_marks', null)
            ->assertJsonPath('data.student.student_identifier', 'STU001');

        $this->assertDatabaseHas('audit_logs', ['action' => 'SUBMISSION_CREATED', 'entity_type' => 'StudentSubmission']);
    }

    public function test_create_submission_rejects_foreign_student_and_duplicates(): void
    {
        Sanctum::actingAs($this->faculty);
        $foreign = Student::create(['created_by' => $this->other->id, 'student_identifier' => 'X1', 'name' => 'Foreign']);

        $this->postJson("/api/assessments/{$this->assessment->id}/submissions", ['student_id' => $foreign->id])->assertStatus(422);
        $this->postJson("/api/assessments/{$this->assessment->id}/submissions", ['student_id' => 99999])->assertStatus(422);
        $this->postJson("/api/assessments/{$this->assessment->id}/submissions", ['student_id' => $this->stu1->id])->assertStatus(201);
        $this->postJson("/api/assessments/{$this->assessment->id}/submissions", ['student_id' => $this->stu1->id])->assertStatus(422);
    }

    public function test_list_submissions_paginates_filters_and_excludes_answer_bodies(): void
    {
        Sanctum::actingAs($this->faculty);
        $s1 = $this->createSubmission($this->stu1);
        $s2 = $this->createSubmission($this->stu2);
        $s2->update(['status' => 'UNDER_REVIEW', 'grading_status' => 'IN_PROGRESS']);
        StudentAnswer::create(['student_submission_id' => $s1->id, 'question_id' => $this->q1->id, 'answer_type' => 'TEXT', 'answer_text' => 'SECRET ANSWER BODY', 'answer_status' => 'NOT_REVIEWED']);

        $res = $this->getJson("/api/assessments/{$this->assessment->id}/submissions?per_page=1");
        $res->assertStatus(200)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('SECRET ANSWER BODY', $res->getContent());

        $this->getJson("/api/assessments/{$this->assessment->id}/submissions?status=UNDER_REVIEW")
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $s2->id);
        $this->getJson("/api/assessments/{$this->assessment->id}/submissions?grading_status=NOT_STARTED")
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $s1->id)->assertJsonPath('data.0.answers_count', 1);
        $this->getJson("/api/assessments/{$this->assessment->id}/submissions?search=STU002")
            ->assertJsonPath('meta.total', 1);
        $this->getJson("/api/assessments/{$this->assessment->id}/submissions?search=Student%20One")
            ->assertJsonPath('meta.total', 1);
    }

    public function test_submission_summary_returns_real_counts(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->getJson("/api/assessments/{$this->assessment->id}/submissions/summary")
            ->assertStatus(200)->assertJsonPath('data.total_submissions', 0)->assertJsonPath('data.questions_count', 3);

        $s1 = $this->createSubmission($this->stu1);
        $this->createSubmission($this->stu2);
        StudentAnswer::create(['student_submission_id' => $s1->id, 'question_id' => $this->q1->id, 'answer_type' => 'TEXT', 'answer_text' => 'x', 'answer_status' => 'REVIEWED']);

        $this->getJson("/api/assessments/{$this->assessment->id}/submissions/summary")
            ->assertJsonPath('data.total_submissions', 2)
            ->assertJsonPath('data.by_status.SUBMITTED', 2)
            ->assertJsonPath('data.by_grading_status.NOT_STARTED', 2)
            ->assertJsonPath('data.answers_by_status.REVIEWED', 1)
            ->assertJsonPath('data.answers_by_status.NOT_REVIEWED', 0);
    }

    public function test_show_submission_returns_questions_answers_and_rubric_availability(): void
    {
        Sanctum::actingAs($this->faculty);
        $sub = $this->createSubmission();
        StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $this->q2->id, 'answer_type' => 'TEXT', 'answer_text' => 'Normalization is...', 'answer_status' => 'NOT_REVIEWED']);
        Rubric::create(['question_id' => $this->q2->id, 'assessment_id' => $this->assessment->id, 'created_by' => $this->faculty->id, 'title' => 'R', 'total_marks' => 10, 'status' => 'APPROVED', 'version' => 1, 'generation_method' => 'template_based']);

        $res = $this->getJson("/api/submissions/{$sub->id}");
        $res->assertStatus(200)
            ->assertJsonPath('data.student.student_identifier', 'STU001')
            ->assertJsonPath('data.assessment.title', 'Midterm Examination')
            ->assertJsonPath('data.assessment.course.course_code', 'CSE101')
            ->assertJsonCount(3, 'data.questions')
            ->assertJsonPath('data.questions.0.answer', null)
            ->assertJsonPath('data.questions.1.answer.answer_text', 'Normalization is...')
            ->assertJsonPath('data.questions.1.answer.awarded_marks', null)
            ->assertJsonPath('data.questions.1.approved_rubric.status', 'APPROVED')
            ->assertJsonPath('data.questions.0.approved_rubric', null)
            ->assertJsonPath('data.allowed_transitions', ['UNDER_REVIEW', 'DRAFT']);
        // Storage path never exposed.
        $this->assertStringNotContainsString('answer_file_path', $res->getContent());
        // No AI grading fields.
        $this->assertStringNotContainsString('ai_', $res->getContent());
    }

    public function test_status_workflow_allows_valid_and_blocks_invalid_transitions(): void
    {
        Sanctum::actingAs($this->faculty);
        $sub = $this->createSubmission();

        $this->patchJson("/api/submissions/{$sub->id}/status", ['status' => 'GRADED'])->assertStatus(422);
        $this->patchJson("/api/submissions/{$sub->id}/status", ['status' => 'RETURNED'])->assertStatus(422);
        $this->patchJson("/api/submissions/{$sub->id}/status", ['status' => 'BOGUS'])->assertStatus(422);

        $this->patchJson("/api/submissions/{$sub->id}/status", ['status' => 'UNDER_REVIEW'])
            ->assertStatus(200)->assertJsonPath('data.status', 'UNDER_REVIEW')->assertJsonPath('data.grading_status', 'IN_PROGRESS');
        $this->patchJson("/api/submissions/{$sub->id}/status", ['status' => 'GRADED'])
            ->assertStatus(200)->assertJsonPath('data.grading_status', 'FACULTY_REVIEWED');
        $this->patchJson("/api/submissions/{$sub->id}/status", ['status' => 'RETURNED'])
            ->assertStatus(200)->assertJsonPath('data.grading_status', 'FINALIZED')->assertJsonPath('data.allowed_transitions', []);
        $this->patchJson("/api/submissions/{$sub->id}/status", ['status' => 'DRAFT'])->assertStatus(422);

        $this->assertEquals('RETURNED', $sub->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'SUBMISSION_STATUS_CHANGED']);
    }

    public function test_delete_submission_removes_answers_and_files(): void
    {
        Sanctum::actingAs($this->faculty);
        $sub = $this->createSubmission();
        $file = UploadedFile::fake()->create('answer.pdf', 50, 'application/pdf');
        $path = $this->postJson("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q1->id, 'file' => $file])->assertStatus(201);
        $stored = StudentAnswer::first()->answer_file_path;
        Storage::disk('local')->assertExists($stored);

        $this->deleteJson("/api/submissions/{$sub->id}")->assertStatus(200);

        $this->assertDatabaseCount('student_submissions', 0);
        $this->assertDatabaseCount('student_answers', 0);
        Storage::disk('local')->assertMissing($stored);
        $this->assertDatabaseHas('students', ['id' => $this->stu1->id]);
    }

    // ---------------------------------------------------------------- answers

    public function test_add_text_answer(): void
    {
        Sanctum::actingAs($this->faculty);
        $sub = $this->createSubmission();

        $this->postJson("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q2->id, 'answer_text' => 'Normalization reduces redundancy.'])
            ->assertStatus(201)
            ->assertJsonPath('data.answer_type', 'TEXT')
            ->assertJsonPath('data.answer_status', 'NOT_REVIEWED')
            ->assertJsonPath('data.is_faculty_edited', false)
            ->assertJsonPath('data.has_file', false)
            ->assertJsonPath('data.awarded_marks', null);

        $this->assertDatabaseHas('audit_logs', ['action' => 'ANSWER_ADDED']);
        $log = AuditLog::where('action', 'ANSWER_ADDED')->first();
        $this->assertStringNotContainsString('Normalization reduces', json_encode($log->metadata));
    }

    public function test_add_answer_rejects_question_from_another_assessment(): void
    {
        Sanctum::actingAs($this->faculty);
        $sub = $this->createSubmission();
        $otherAssessment = Assessment::create(['course_id' => $this->course->id, 'title' => 'Final', 'type' => 'final', 'total_marks' => 10, 'status' => 'draft']);
        $foreignQuestion = Question::create(['assessment_id' => $otherAssessment->id, 'question_number' => 1, 'question_text' => 'Other', 'question_type' => 'descriptive', 'marks' => 10]);

        $this->postJson("/api/submissions/{$sub->id}/answers", ['question_id' => $foreignQuestion->id, 'answer_text' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('message', "The question does not belong to this submission's assessment.");
        $this->postJson("/api/submissions/{$sub->id}/answers", ['question_id' => 99999, 'answer_text' => 'x'])->assertStatus(422);
        $this->assertDatabaseCount('student_answers', 0);
    }

    public function test_add_answer_rejects_duplicate_and_empty(): void
    {
        Sanctum::actingAs($this->faculty);
        $sub = $this->createSubmission();
        $this->postJson("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q1->id, 'answer_text' => 'A key'])->assertStatus(201);
        $this->postJson("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q1->id, 'answer_text' => 'Again'])->assertStatus(422);
        $this->postJson("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q2->id, 'answer_text' => '   '])->assertStatus(422);
        $this->assertDatabaseCount('student_answers', 1);
    }

    public function test_marks_validation_enforces_question_maximum(): void
    {
        Sanctum::actingAs($this->faculty);
        $sub = $this->createSubmission();

        $this->postJson("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q2->id, 'answer_text' => 'x', 'awarded_marks' => 15])->assertStatus(422);
        $this->postJson("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q2->id, 'answer_text' => 'x', 'awarded_marks' => -1])->assertStatus(422);
        $id = $this->postJson("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q2->id, 'answer_text' => 'x', 'awarded_marks' => 7.5])
            ->assertStatus(201)->assertJsonPath('data.awarded_marks', 7.5)->assertJsonPath('data.answer_status', 'REVIEWED')->json('data.id');

        $this->putJson("/api/student-answers/{$id}", ['awarded_marks' => 10.01])->assertStatus(422);
        $this->putJson("/api/student-answers/{$id}", ['awarded_marks' => 10])->assertStatus(200)->assertJsonPath('data.awarded_marks', 10);

        $sub->refresh();
        $this->assertEquals(10.0, (float) $sub->awarded_marks);
        $this->assertEquals('IN_PROGRESS', $sub->grading_status);
    }

    public function test_update_answer_preserves_original_and_flags_faculty_edit(): void
    {
        Sanctum::actingAs($this->faculty);
        $sub = $this->createSubmission();
        $id = $this->postJson("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q1->id, 'answer_text' => 'orignal typo text'])->json('data.id');

        $res = $this->putJson("/api/student-answers/{$id}", ['answer_text' => 'original corrected text', 'faculty_feedback' => 'Good attempt.']);
        $res->assertStatus(200)
            ->assertJsonPath('data.answer_text', 'original corrected text')
            ->assertJsonPath('data.original_answer_text', 'orignal typo text')
            ->assertJsonPath('data.is_faculty_edited', true)
            ->assertJsonPath('data.faculty_feedback', 'Good attempt.');

        // Cannot change the question or submission of an existing answer.
        $this->putJson("/api/student-answers/{$id}", ['question_id' => $this->q2->id])->assertStatus(422);
        $this->assertEquals($this->q1->id, StudentAnswer::find($id)->question_id);

        $this->putJson("/api/student-answers/{$id}", ['answer_status' => 'UNDER_REVIEW'])->assertStatus(200)->assertJsonPath('data.answer_status', 'UNDER_REVIEW');
        $this->putJson("/api/student-answers/{$id}", ['answer_status' => 'WEIRD'])->assertStatus(422);
    }

    public function test_file_answer_upload_download_and_delete(): void
    {
        Sanctum::actingAs($this->faculty);
        $sub = $this->createSubmission();
        $file = UploadedFile::fake()->create('../../evil name.pdf', 120, 'application/pdf');

        $res = $this->post("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q3->id, 'file' => $file], ['Accept' => 'application/json']);
        $res->assertStatus(201)
            ->assertJsonPath('data.answer_type', 'FILE')
            ->assertJsonPath('data.has_file', true)
            ->assertJsonPath('data.answer_file_type', 'application/pdf');
        $this->assertStringNotContainsString('..', $res->json('data.answer_file_name'));

        $answer = StudentAnswer::first();
        $this->assertStringStartsWith("student-answers/user_{$this->faculty->id}/assessment_{$this->assessment->id}/submission_{$sub->id}/", $answer->answer_file_path);
        $this->assertStringNotContainsString('evil', $answer->answer_file_path);
        Storage::disk('local')->assertExists($answer->answer_file_path);

        $this->get("/api/student-answers/{$answer->id}/download")->assertStatus(200)->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->deleteJson("/api/student-answers/{$answer->id}")->assertStatus(200);
        Storage::disk('local')->assertMissing($answer->answer_file_path);
        $this->assertDatabaseCount('student_answers', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ANSWER_DELETED']);
    }

    public function test_image_answer_sets_image_type(): void
    {
        Sanctum::actingAs($this->faculty);
        $sub = $this->createSubmission();
        $this->post("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q3->id, 'file' => $this->fakePng()], ['Accept' => 'application/json'])
            ->assertStatus(201)->assertJsonPath('data.answer_type', 'IMAGE');
    }

    public function test_invalid_and_oversized_files_rejected(): void
    {
        Sanctum::actingAs($this->faculty);
        $sub = $this->createSubmission();

        $this->post("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q1->id, 'file' => UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q1->id, 'file' => UploadedFile::fake()->create('script.php', 10, 'text/x-php')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q1->id, 'file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        // Extension/content mismatch: PNG bytes named .pdf
        $fake = $this->fakePng();
        $renamed = new UploadedFile($fake->getPathname(), 'photo.pdf', 'application/pdf', null, true);
        $this->post("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q1->id, 'file' => $renamed], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertDatabaseCount('student_answers', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles('student-answers'));
    }

    public function test_download_without_file_returns_404(): void
    {
        Sanctum::actingAs($this->faculty);
        $sub = $this->createSubmission();
        $id = $this->postJson("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q1->id, 'answer_text' => 'text only'])->json('data.id');
        $this->get("/api/student-answers/{$id}/download")->assertStatus(404);
    }

    // ------------------------------------------------------------- CSV import

    public function test_csv_import_creates_submissions_and_answers(): void
    {
        Sanctum::actingAs($this->faculty);
        $csv = "student_identifier,question_number,answer_text\nSTU001,1,\"A primary key uniquely identifies a row.\"\nSTU001,2,\"Normalization...\"\nstu002,1,\"Key answer\"\n";
        $file = UploadedFile::fake()->createWithContent('answers.csv', $csv);

        $this->post("/api/assessments/{$this->assessment->id}/submissions/import", ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('data.submissions_created', 2)
            ->assertJsonPath('data.answers_created', 3);

        $this->assertDatabaseCount('student_submissions', 2);
        $this->assertDatabaseCount('student_answers', 3);
        $this->assertDatabaseHas('student_answers', ['answer_text' => 'Key answer', 'original_answer_text' => 'Key answer', 'answer_type' => 'TEXT']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ANSWERS_IMPORTED']);
    }

    public function test_csv_import_is_all_or_nothing_with_row_errors(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->createSubmission($this->stu1);
        StudentAnswer::create(['student_submission_id' => 1, 'question_id' => $this->q1->id, 'answer_type' => 'TEXT', 'answer_text' => 'existing', 'answer_status' => 'NOT_REVIEWED']);

        $csv = "student_identifier,question_number,answer_text\n"
            . "STU001,1,\"dup of existing\"\n"          // duplicate against DB
            . "STU002,9,\"bad question\"\n"             // question not in assessment
            . "STU999,1,\"unknown student\"\n"          // unknown student
            . "STU002,2,\"ok\"\nSTU002,2,\"dup in file\"\n" // duplicate within file
            . "STU002,3,\"\"\n";                        // empty
        $file = UploadedFile::fake()->createWithContent('answers.csv', $csv);

        $res = $this->post("/api/assessments/{$this->assessment->id}/submissions/import", ['file' => $file], ['Accept' => 'application/json']);
        $res->assertStatus(422);
        $errors = $res->json('errors');
        $this->assertCount(5, $errors);
        $this->assertStringContainsString('already has an answer', $errors[0]);
        $this->assertStringContainsString('question number', $errors[1]);
        $this->assertStringContainsString('not registered', $errors[2]);
        $this->assertStringContainsString('duplicate', $errors[3]);
        $this->assertStringContainsString('empty', $errors[4]);

        $this->assertDatabaseCount('student_submissions', 1);
        $this->assertDatabaseCount('student_answers', 1);
    }

    public function test_csv_import_requires_columns(): void
    {
        Sanctum::actingAs($this->faculty);
        $file = UploadedFile::fake()->createWithContent('answers.csv', "student,answer\nSTU001,x\n");
        $this->post("/api/assessments/{$this->assessment->id}/submissions/import", ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->post("/api/assessments/{$this->assessment->id}/submissions/import", ['file' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    // --------------------------------------------------------------- security

    public function test_cross_faculty_access_is_blocked_everywhere(): void
    {
        $f = $this->foreignSubmission();
        Sanctum::actingAs($this->faculty);

        $this->getJson("/api/assessments/{$f['assessment']->id}/submissions")->assertStatus(403);
        $this->getJson("/api/assessments/{$f['assessment']->id}/submissions/summary")->assertStatus(403);
        $this->postJson("/api/assessments/{$f['assessment']->id}/submissions", ['student_id' => $this->stu1->id])->assertStatus(403);
        $this->post("/api/assessments/{$f['assessment']->id}/submissions/import", ['file' => UploadedFile::fake()->createWithContent('a.csv', "student_identifier,question_number,answer_text\n")], ['Accept' => 'application/json'])->assertStatus(403);
        $this->getJson("/api/submissions/{$f['submission']->id}")->assertStatus(403);
        $this->patchJson("/api/submissions/{$f['submission']->id}/status", ['status' => 'UNDER_REVIEW'])->assertStatus(403);
        $this->deleteJson("/api/submissions/{$f['submission']->id}")->assertStatus(403);
        $this->postJson("/api/submissions/{$f['submission']->id}/answers", ['question_id' => $f['question']->id, 'answer_text' => 'x'])->assertStatus(403);
        $this->putJson("/api/student-answers/{$f['answer']->id}", ['answer_text' => 'tamper'])->assertStatus(403);
        $this->deleteJson("/api/student-answers/{$f['answer']->id}")->assertStatus(403);
        $this->get("/api/student-answers/{$f['answer']->id}/download")->assertStatus(403);

        $this->assertEquals('V = IR', $f['answer']->fresh()->answer_text);
        $this->assertDatabaseCount('student_submissions', 1);
    }

    public function test_cannot_answer_own_submission_with_foreign_faculty_question(): void
    {
        $f = $this->foreignSubmission();
        Sanctum::actingAs($this->faculty);
        $sub = $this->createSubmission();

        $this->postJson("/api/submissions/{$sub->id}/answers", ['question_id' => $f['question']->id, 'answer_text' => 'x'])->assertStatus(422);
        $this->assertDatabaseCount('student_answers', 1); // only the foreign one
    }

    public function test_unauthenticated_requests_rejected(): void
    {
        $sub = $this->createSubmission();
        $this->getJson("/api/assessments/{$this->assessment->id}/submissions")->assertStatus(401);
        $this->getJson("/api/submissions/{$sub->id}")->assertStatus(401);
        $this->postJson("/api/submissions/{$sub->id}/answers", ['question_id' => $this->q1->id, 'answer_text' => 'x'])->assertStatus(401);
    }

    public function test_relationships(): void
    {
        $sub = $this->createSubmission();
        $answer = StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $this->q1->id, 'answer_type' => 'TEXT', 'answer_text' => 'x', 'answer_status' => 'NOT_REVIEWED']);

        $this->assertCount(1, $this->assessment->fresh()->submissions);
        $this->assertCount(1, $this->stu1->fresh()->submissions);
        $this->assertCount(1, $sub->fresh()->answers);
        $this->assertEquals($this->q1->id, $answer->question->id);
        $this->assertEquals($sub->id, $answer->submission->id);
        $this->assertCount(1, $this->q1->fresh()->studentAnswers);
        $this->assertCount(1, $this->faculty->fresh()->students()->where('id', $this->stu1->id)->get());

        $this->q1->delete();
        $this->assertDatabaseCount('student_answers', 0);
    }
}
