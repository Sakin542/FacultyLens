<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\CollaborationComment;
use App\Models\Course;
use App\Models\CourseCollaborationInvitation;
use App\Models\CourseCollaborator;
use App\Models\DocumentProcessing;
use App\Models\GeneratedQuestion;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\QuestionGenerationRequest;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Notifications\CollaborationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 34: Faculty Collaboration — invitations, roles, server-side permissions, comments, activity, privacy.
 */
class CollaborationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $editor;
    protected User $reviewer;
    protected User $viewer;
    protected User $outsider;
    protected Course $course;
    protected Course $otherCourse;
    protected Assessment $assessment;
    protected Question $question;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();

        $this->owner = User::factory()->create(['name' => 'Dr. A', 'email' => 'a@university.edu']);
        $this->editor = User::factory()->create(['name' => 'Dr. B', 'email' => 'b@university.edu']);
        $this->reviewer = User::factory()->create(['name' => 'Dr. C', 'email' => 'c@university.edu']);
        $this->viewer = User::factory()->create(['name' => 'Dr. D', 'email' => 'd@university.edu']);
        $this->outsider = User::factory()->create(['name' => 'Dr. X', 'email' => 'x@university.edu']);

        $this->course = Course::create(['user_id' => $this->owner->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026']);
        $this->otherCourse = Course::create(['user_id' => $this->outsider->id, 'course_code' => 'EEE201', 'course_name' => 'Circuits', 'semester' => 'Fall', 'academic_year' => '2026']);
        LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO1', 'description' => 'Explain normalization.', 'cognitive_level' => 'Understand', 'sort_order' => 1]);
        $this->assessment = Assessment::create(['course_id' => $this->course->id, 'title' => 'Midterm', 'type' => 'midterm', 'total_marks' => 30, 'status' => 'draft']);
        $this->question = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Explain normalization up to 3NF.', 'question_type' => 'descriptive', 'marks' => 10, 'cognitive_level' => 'Understand']);

        foreach ([[$this->editor, 'EDITOR'], [$this->reviewer, 'REVIEWER'], [$this->viewer, 'VIEWER']] as [$u, $role]) {
            CourseCollaborator::create(['course_id' => $this->course->id, 'user_id' => $u->id, 'invited_by' => $this->owner->id, 'role' => $role, 'status' => 'ACTIVE', 'accepted_at' => now()]);
        }
    }

    protected function inviteAs(User $actor, string $email = 'new@university.edu', string $role = 'EDITOR')
    {
        Sanctum::actingAs($actor);

        return $this->postJson("/api/courses/{$this->course->id}/collaborators/invite", ['email' => $email, 'role' => $role]);
    }

    // ---- invitations ----------------------------------------------------------

    public function test_owner_invites_and_invitee_accepts_becoming_active(): void
    {
        $invitee = User::factory()->create(['email' => 'new@university.edu', 'name' => 'Dr. New']);
        $res = $this->inviteAs($this->owner);
        $res->assertStatus(201)->assertJsonPath('data.role', 'EDITOR')->assertJsonPath('data.status', 'PENDING');
        $token = str_replace(rtrim(config('collaboration.frontend_url'), '/') . '/collaboration/invitations/', '', $res->json('data.accept_url'));
        $this->assertGreaterThanOrEqual(64, strlen($token));
        $this->assertDatabaseMissing('course_collaboration_invitations', ['token_hash' => $token]); // stored hashed only
        $this->assertDatabaseHas('course_collaborators', ['course_id' => $this->course->id, 'user_id' => $invitee->id, 'status' => 'PENDING']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'COLLABORATOR_INVITED', 'course_id' => $this->course->id]);
        Notification::assertSentTo($invitee, CollaborationNotification::class);

        // Public preview exposes only minimal info
        $this->getJson("/api/collaboration/invitations/{$token}")->assertOk()
            ->assertJsonPath('data.course.course_code', 'CSE101')->assertJsonPath('data.role', 'EDITOR')->assertJsonPath('data.invited_email', null);

        // Wrong account cannot accept
        Sanctum::actingAs($this->outsider);
        $this->postJson("/api/collaboration/invitations/{$token}/accept")->assertStatus(403);

        Sanctum::actingAs($invitee);
        $this->getJson('/api/collaboration/invitations')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/collaboration/invitations/{$token}/accept")->assertOk()->assertJsonPath('data.role', 'EDITOR')->assertJsonPath('data.status', 'ACTIVE');
        $this->assertDatabaseHas('course_collaborators', ['course_id' => $this->course->id, 'user_id' => $invitee->id, 'status' => 'ACTIVE', 'role' => 'EDITOR']);
        $this->assertDatabaseHas('course_collaboration_invitations', ['id' => $res->json('data.id'), 'status' => 'ACCEPTED']);

        // Single use
        $this->postJson("/api/collaboration/invitations/{$token}/accept")->assertStatus(410);
        // Course now visible to the new editor
        $this->getJson("/api/courses/{$this->course->id}")->assertOk()->assertJsonPath('data.current_role', 'EDITOR')->assertJsonPath('data.permissions.edit_course', true)->assertJsonPath('data.permissions.delete_course', false);
        $this->getJson('/api/courses')->assertOk()->assertJsonCount(1, 'data');
        Notification::assertSentTo($this->owner, CollaborationNotification::class);
    }

    public function test_decline_grants_no_access(): void
    {
        $invitee = User::factory()->create(['email' => 'new@university.edu']);
        $token = str_replace(rtrim(config('collaboration.frontend_url'), '/') . '/collaboration/invitations/', '', $this->inviteAs($this->owner)->json('data.accept_url'));
        Sanctum::actingAs($invitee);
        $this->postJson("/api/collaboration/invitations/{$token}/decline")->assertOk()->assertJsonPath('data.status', 'DECLINED');
        $this->getJson("/api/courses/{$this->course->id}")->assertStatus(403);
        $this->assertDatabaseHas('course_collaborators', ['user_id' => $invitee->id, 'status' => 'DECLINED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'COLLABORATION_DECLINED']);
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        $invitee = User::factory()->create(['email' => 'new@university.edu']);
        $token = str_replace(rtrim(config('collaboration.frontend_url'), '/') . '/collaboration/invitations/', '', $this->inviteAs($this->owner)->json('data.accept_url'));
        CourseCollaborationInvitation::query()->update(['expires_at' => now()->subMinute()]);
        Sanctum::actingAs($invitee);
        $this->postJson("/api/collaboration/invitations/{$token}/accept")->assertStatus(410);
        $this->assertDatabaseMissing('course_collaborators', ['user_id' => $invitee->id, 'status' => 'ACTIVE']);
        $this->getJson('/api/collaboration/invitations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_duplicate_and_invalid_invitations_are_rejected(): void
    {
        $this->inviteAs($this->owner, 'b@university.edu')->assertStatus(422); // already active
        $this->inviteAs($this->owner, 'a@university.edu')->assertStatus(422); // owner
        $this->inviteAs($this->owner)->assertStatus(201);
        $this->inviteAs($this->owner)->assertStatus(422); // pending duplicate
        $this->inviteAs($this->owner, 'z@university.edu', 'OWNER')->assertStatus(422);
        $this->inviteAs($this->owner, 'not-an-email')->assertStatus(422);
        $this->assertSame(1, CourseCollaborationInvitation::count());
        $this->getJson("/api/collaboration/invitations/short")->assertStatus(404);
        $this->getJson('/api/collaboration/invitations/' . str_repeat('x', 64))->assertStatus(404);
    }

    public function test_only_owner_manages_collaborators(): void
    {
        foreach ([$this->editor, $this->reviewer, $this->viewer, $this->outsider] as $u) {
            $this->inviteAs($u)->assertStatus(403);
            $this->patchJson("/api/courses/{$this->course->id}/collaborators/{$this->viewer->id}/role", ['role' => 'EDITOR'])->assertStatus(403);
            $this->deleteJson("/api/courses/{$this->course->id}/collaborators/{$this->reviewer->id}")->assertStatus(403);
        }
        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/courses/{$this->course->id}/collaborators/{$this->reviewer->id}/role", ['role' => 'EDITOR'])->assertOk()->assertJsonPath('data.role', 'EDITOR');
        $this->assertDatabaseHas('audit_logs', ['action' => 'COLLABORATOR_ROLE_CHANGED']);
        Notification::assertSentTo($this->reviewer, CollaborationNotification::class);

        $this->deleteJson("/api/courses/{$this->course->id}/collaborators/{$this->viewer->id}")->assertOk();
        $this->assertDatabaseHas('course_collaborators', ['user_id' => $this->viewer->id, 'status' => 'REVOKED']);
        Sanctum::actingAs($this->viewer);
        $this->getJson("/api/courses/{$this->course->id}")->assertStatus(403);
    }

    public function test_owner_is_protected(): void
    {
        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/courses/{$this->course->id}/collaborators/{$this->owner->id}")->assertStatus(422);
        $this->patchJson("/api/courses/{$this->course->id}/collaborators/{$this->owner->id}/role", ['role' => 'VIEWER'])->assertStatus(422);
        Sanctum::actingAs($this->editor);
        $this->deleteJson("/api/courses/{$this->course->id}/collaborators/{$this->owner->id}")->assertStatus(403);
        $this->assertSame($this->owner->id, $this->course->fresh()->user_id);
    }

    // ---- permission matrix ----------------------------------------------------

    public function test_collaboration_overview_reports_role_and_permissions(): void
    {
        Sanctum::actingAs($this->reviewer);
        $this->getJson("/api/courses/{$this->course->id}/collaboration")->assertOk()
            ->assertJsonPath('data.current_user.role', 'REVIEWER')
            ->assertJsonPath('data.permissions.view', true)
            ->assertJsonPath('data.permissions.comment', true)
            ->assertJsonPath('data.permissions.edit_assessment', false)
            ->assertJsonPath('data.permissions.manage_collaborators', false)
            ->assertJsonPath('data.permissions.view_student_data', false)
            ->assertJsonPath('data.owner.name', 'Dr. A')
            ->assertJsonPath('data.owner.email', null) // emails only for managers
            ->assertJsonCount(3, 'data.collaborators')
            ->assertJsonPath('data.pending_invitations', []);
        Sanctum::actingAs($this->outsider);
        $this->getJson("/api/courses/{$this->course->id}/collaboration")->assertStatus(403);
    }

    public function test_roles_are_enforced_on_course_and_assessment_actions(): void
    {
        // Editor: edit course, create/edit assessment, but not delete
        Sanctum::actingAs($this->editor);
        $this->putJson("/api/courses/{$this->course->id}", ['course_code' => 'CSE101', 'course_name' => 'Database Systems II', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3])->assertOk();
        $this->postJson("/api/courses/{$this->course->id}/assessments", ['title' => 'Quiz 1', 'type' => 'quiz', 'total_marks' => 10, 'status' => 'draft'])->assertStatus(201);
        $this->deleteJson("/api/assessments/{$this->assessment->id}")->assertStatus(403);
        $this->deleteJson("/api/courses/{$this->course->id}")->assertStatus(403);
        $this->getJson('/api/assessments')->assertOk();

        // Reviewer: read-only
        Sanctum::actingAs($this->reviewer);
        $this->getJson("/api/courses/{$this->course->id}")->assertOk()->assertJsonPath('data.current_role', 'REVIEWER');
        $this->getJson("/api/assessments/{$this->assessment->id}")->assertOk()->assertJsonPath('data.permissions.edit_assessment', false);
        $this->getJson("/api/courses/{$this->course->id}/learning-outcomes")->assertOk();
        $this->putJson("/api/courses/{$this->course->id}", ['course_code' => 'CSE101', 'course_name' => 'Hacked', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3])->assertStatus(403);
        $this->postJson("/api/courses/{$this->course->id}/assessments", ['title' => 'Quiz 2', 'type' => 'quiz', 'total_marks' => 10, 'status' => 'draft'])->assertStatus(403);
        $this->putJson("/api/assessments/{$this->assessment->id}", ['title' => 'Hacked', 'type' => 'midterm', 'total_marks' => 30, 'status' => 'draft'])->assertStatus(403);
        $this->postJson("/api/courses/{$this->course->id}/learning-outcomes", ['code' => 'CO9', 'description' => 'x', 'cognitive_level' => 'Apply', 'sort_order' => 9])->assertStatus(403);

        // Viewer: view only
        Sanctum::actingAs($this->viewer);
        $this->getJson("/api/assessments/{$this->assessment->id}")->assertOk();
        $this->getJson("/api/ai/assessments/{$this->assessment->id}/analysis")->assertStatus(200);

        // Outsider: nothing
        Sanctum::actingAs($this->outsider);
        $this->getJson("/api/courses/{$this->course->id}")->assertStatus(403);
        $this->getJson("/api/assessments/{$this->assessment->id}")->assertStatus(403);
        $this->getJson("/api/courses/{$this->course->id}/learning-outcomes")->assertStatus(403);
        $this->getJson("/api/courses/{$this->course->id}/comments")->assertStatus(403);
        $this->getJson("/api/courses/{$this->course->id}/collaboration/activity")->assertStatus(403);
        $this->getJson('/api/assessments')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/courses')->assertOk()->assertJsonCount(1, 'data'); // only their own
    }

    public function test_course_isolation_for_members_of_other_courses(): void
    {
        Sanctum::actingAs($this->editor); // editor on CSE101, nothing on EEE201
        $this->getJson("/api/courses/{$this->otherCourse->id}")->assertStatus(403);
        $this->getJson("/api/courses/{$this->otherCourse->id}/assessments")->assertStatus(403);
        $this->getJson("/api/courses/{$this->otherCourse->id}/collaboration")->assertStatus(403);
        $this->getJson("/api/courses/{$this->otherCourse->id}/comments")->assertStatus(403);
        $this->getJson("/api/documents?course_id={$this->otherCourse->id}")->assertStatus(403);
    }

    public function test_assessment_update_conflict_detection(): void
    {
        Sanctum::actingAs($this->editor);
        $stale = $this->assessment->updated_at->copy()->subMinute()->toIso8601String();
        $this->putJson("/api/assessments/{$this->assessment->id}", ['title' => 'Midterm v2', 'type' => 'midterm', 'total_marks' => 30, 'status' => 'draft', 'expected_updated_at' => $stale])->assertStatus(409);
        $this->assertSame('Midterm', $this->assessment->fresh()->title);
        $this->putJson("/api/assessments/{$this->assessment->id}", ['title' => 'Midterm v2', 'type' => 'midterm', 'total_marks' => 30, 'status' => 'draft', 'expected_updated_at' => $this->assessment->updated_at->toIso8601String()])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'ASSESSMENT_UPDATED', 'course_id' => $this->course->id, 'user_id' => $this->editor->id]);
    }

    // ---- documents & student data privacy -------------------------------------

    public function test_document_access_follows_role(): void
    {
        $doc = DocumentProcessing::create(['user_id' => $this->owner->id, 'course_id' => $this->course->id, 'document_type' => 'syllabus', 'original_file_name' => 's.txt', 'stored_file_name' => 's.txt', 'file_path' => 'documents/s.txt', 'mime_type' => 'text/plain', 'file_size' => 5, 'processing_status' => 'completed', 'cleaned_text' => 'hello']);
        Storage::disk('local')->put('documents/s.txt', 'hello');

        Sanctum::actingAs($this->reviewer);
        $this->getJson("/api/documents?course_id={$this->course->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/documents/{$doc->id}")->assertOk();
        $this->get("/api/documents/{$doc->id}/download")->assertStatus(200);
        $this->deleteJson("/api/documents/{$doc->id}")->assertStatus(403);

        Sanctum::actingAs($this->viewer);
        $this->getJson("/api/documents/{$doc->id}")->assertOk();
        $this->get("/api/documents/{$doc->id}/download")->assertStatus(403);

        Sanctum::actingAs($this->editor);
        $this->postJson('/api/documents', ['course_id' => $this->course->id, 'document_type' => 'other', 'file' => UploadedFile::fake()->createWithContent('n.txt', 'lecture notes text')])->assertStatus(201);

        Sanctum::actingAs($this->outsider);
        $this->getJson("/api/documents/{$doc->id}")->assertStatus(403);
        $this->get("/api/documents/{$doc->id}/download")->assertStatus(403);
        $this->getJson('/api/documents')->assertOk()->assertJsonCount(0, 'data');
        $this->assertStringNotContainsString('documents/s.txt', json_encode($this->getJson("/api/courses/{$this->course->id}/collaboration")->json()));
    }

    public function test_student_data_is_hidden_from_reviewers_and_viewers(): void
    {
        $student = Student::create(['created_by' => $this->owner->id, 'student_identifier' => 'STU001', 'name' => 'Student One']);
        $submission = StudentSubmission::create(['assessment_id' => $this->assessment->id, 'student_id' => $student->id, 'status' => 'SUBMITTED', 'grading_status' => 'NOT_STARTED', 'submitted_at' => now(), 'total_marks' => 30]);
        StudentAnswer::create(['student_submission_id' => $submission->id, 'question_id' => $this->question->id, 'answer_text' => 'Normalization reduces redundancy.', 'answer_type' => 'TEXT', 'max_marks' => 10]);

        foreach ([$this->reviewer, $this->viewer, $this->outsider] as $u) {
            Sanctum::actingAs($u);
            $this->getJson("/api/assessments/{$this->assessment->id}/submissions")->assertStatus(403);
            $this->getJson("/api/submissions/{$submission->id}")->assertStatus(403);
        }
        Sanctum::actingAs($this->editor);
        $this->getJson("/api/assessments/{$this->assessment->id}/submissions")->assertOk();
        $this->getJson("/api/submissions/{$submission->id}")->assertOk();
    }

    // ---- comments -------------------------------------------------------------

    public function test_comment_thread_lifecycle_and_permissions(): void
    {
        $report = AnalysisReport::create(['assessment_id' => $this->assessment->id, 'analysis_version' => 1, 'overall_score' => 82, 'analysis_status' => 'completed', 'is_current' => true, 'total_questions' => 1]);

        // Viewer cannot comment (default), reviewer can
        Sanctum::actingAs($this->viewer);
        $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'analysis_report', 'commentable_id' => $report->id, 'body' => 'x'])->assertStatus(403);

        Sanctum::actingAs($this->reviewer);
        $root = $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'analysis_report', 'commentable_id' => $report->id, 'body' => 'I think the score is too high.', 'mentions' => [$this->owner->id, $this->outsider->id]]);
        $root->assertStatus(201)->assertJsonPath('data.author.name', 'Dr. C')->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.mentions', [$this->owner->id]); // outsider mention dropped
        $rootId = $root->json('data.id');
        Notification::assertSentTo($this->owner, CollaborationNotification::class);
        $this->assertEquals(82.0, (float) $report->fresh()->overall_score, 'Comments must not alter AI results');

        // Retry with identical body is idempotent
        $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'analysis_report', 'commentable_id' => $report->id, 'body' => 'I think the score is too high.'])->assertStatus(201)->assertJsonPath('data.id', $rootId);
        $this->assertSame(1, CollaborationComment::count());

        // Editor replies, reply to a reply attaches to root
        Sanctum::actingAs($this->editor);
        $reply = $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'analysis_report', 'commentable_id' => $report->id, 'body' => 'Agreed, let us review.', 'parent_id' => $rootId]);
        $reply->assertStatus(201)->assertJsonPath('data.parent_id', $rootId);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'analysis_report', 'commentable_id' => $report->id, 'body' => 'I will review it.', 'parent_id' => $reply->json('data.id')])->assertStatus(201)->assertJsonPath('data.parent_id', $rootId);

        $list = $this->getJson("/api/courses/{$this->course->id}/comments?commentable_type=analysis_report&commentable_id={$report->id}");
        $list->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(2, 'data.0.replies')->assertJsonPath('meta.can_comment', true);

        // Edit own comment only
        Sanctum::actingAs($this->reviewer);
        $this->putJson("/api/comments/{$rootId}", ['body' => 'I think the score may be too high.'])->assertOk()->assertJsonPath('data.body', 'I think the score may be too high.');
        $this->assertNotNull(CollaborationComment::find($rootId)->edited_at);
        $this->putJson("/api/comments/{$reply->json('data.id')}", ['body' => 'hijack'])->assertStatus(403);

        // Reviewer cannot resolve someone else's thread they don't own? (author can) ; owner resolves
        Sanctum::actingAs($this->viewer);
        $this->postJson("/api/comments/{$rootId}/resolve")->assertStatus(403);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/comments/{$rootId}/resolve")->assertOk()->assertJsonPath('data.status', 'RESOLVED')->assertJsonPath('data.resolved_by.name', 'Dr. A');
        $this->getJson("/api/courses/{$this->course->id}/comments?status=RESOLVED")->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/comments/{$rootId}/reopen")->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->assertDatabaseHas('audit_logs', ['action' => 'COMMENT_RESOLVED', 'course_id' => $this->course->id]);

        // Outsider blocked everywhere
        Sanctum::actingAs($this->outsider);
        $this->getJson("/api/comments/{$rootId}")->assertStatus(403);
        $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'analysis_report', 'commentable_id' => $report->id, 'body' => 'x'])->assertStatus(403);

        // Target must belong to the course
        Sanctum::actingAs($this->owner);
        $foreignAssessment = Assessment::create(['course_id' => $this->otherCourse->id, 'title' => 'Q', 'type' => 'quiz', 'total_marks' => 10, 'status' => 'draft']);
        $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'assessment', 'commentable_id' => $foreignAssessment->id, 'body' => 'x'])->assertStatus(404);
        $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'user', 'commentable_id' => 1, 'body' => 'x'])->assertStatus(422);

        // Soft delete keeps history
        $this->deleteJson("/api/comments/{$rootId}")->assertOk();
        $this->assertSoftDeleted('collaboration_comments', ['id' => $rootId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'COMMENT_DELETED']);
    }

    public function test_generated_question_review_respects_roles(): void
    {
        $req = QuestionGenerationRequest::create(['user_id' => $this->owner->id, 'course_id' => $this->course->id, 'question_type' => 'descriptive', 'marks' => 10, 'number_of_questions' => 1, 'generation_status' => 'COMPLETED']);
        $draft = GeneratedQuestion::create(['generation_request_id' => $req->id, 'sequence' => 1, 'question_text' => 'Analyze normalization anomalies in the given schema.', 'original_question_text' => 'Analyze normalization anomalies in the given schema.', 'question_type' => 'descriptive', 'marks' => 10, 'validation_status' => 'PASSED', 'review_status' => 'DRAFT', 'version' => 1]);

        Sanctum::actingAs($this->reviewer);
        $this->getJson("/api/question-generation/{$req->id}")->assertOk();
        $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'generated_question', 'commentable_id' => $draft->id, 'body' => 'Too broad; narrow to 3NF.'])->assertStatus(201);
        $this->postJson("/api/generated-questions/{$draft->id}/approve")->assertStatus(403);
        $this->putJson("/api/generated-questions/{$draft->id}", ['marks' => 5])->assertStatus(403);
        $this->assertSame('DRAFT', $draft->fresh()->review_status);

        Sanctum::actingAs($this->editor);
        $this->putJson("/api/generated-questions/{$draft->id}", ['marks' => 8, 'expected_version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->putJson("/api/generated-questions/{$draft->id}", ['marks' => 6, 'expected_version' => 1])->assertStatus(409); // stale
        $this->postJson("/api/generated-questions/{$draft->id}/approve")->assertOk()->assertJsonPath('data.review_status', 'APPROVED');

        Sanctum::actingAs($this->outsider);
        $this->getJson("/api/question-generation/{$req->id}")->assertStatus(403);
        $this->getJson('/api/question-generation')->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- activity, summary, notifications, rate limits --------------------------

    public function test_activity_feed_is_paginated_and_course_scoped(): void
    {
        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/courses/{$this->course->id}/collaborators/{$this->viewer->id}/role", ['role' => 'REVIEWER'])->assertOk();
        $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'question', 'commentable_id' => $this->question->id, 'body' => 'Question 1 may be too difficult.'])->assertStatus(201);

        $res = $this->getJson("/api/courses/{$this->course->id}/collaboration/activity?per_page=1");
        $res->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 1);
        $this->assertGreaterThanOrEqual(2, $res->json('meta.total'));
        $this->assertStringContainsString('Dr. A commented on question', $res->json('data.0.summary'));

        Sanctum::actingAs($this->viewer);
        $this->getJson("/api/courses/{$this->course->id}/collaboration/activity")->assertOk();
        Sanctum::actingAs($this->outsider);
        $this->getJson("/api/courses/{$this->course->id}/collaboration/activity")->assertStatus(403);
    }

    public function test_summary_and_notifications_are_user_scoped(): void
    {
        Sanctum::actingAs($this->editor);
        $this->getJson('/api/collaboration/summary')->assertOk()->assertJsonPath('data.shared_courses_count', 1)->assertJsonPath('data.shared_courses.0.role', 'EDITOR')->assertJsonPath('data.pending_invitations_count', 0);

        // Database notification round trip (channel faked in setUp, so insert the row the channel would write)
        $this->editor->notifications()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => CollaborationNotification::class,
            'data' => ['event' => 'COLLABORATOR_ROLE_CHANGED', 'title' => 'Role changed', 'body' => 'x', 'course_id' => $this->course->id]]);
        $this->getJson('/api/notifications?unread=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.unread_count', 1)->assertJsonPath('data.0.event', 'COLLABORATOR_ROLE_CHANGED');
        $id = $this->getJson('/api/notifications')->json('data.0.id');
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/notifications/{$id}/read")->assertStatus(404); // not theirs
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($this->editor);
        $this->postJson("/api/notifications/{$id}/read")->assertOk();
        $this->getJson('/api/notifications?unread=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_rate_limits(): void
    {
        config(['collaboration.invite_rate_limit_per_hour' => 2, 'collaboration.comment_rate_limit_per_minute' => 2]);
        RateLimiter::clear('collaboration-invite');
        RateLimiter::clear('collaboration-comment');
        $this->inviteAs($this->owner, 'one@university.edu')->assertStatus(201);
        $this->inviteAs($this->owner, 'two@university.edu')->assertStatus(201);
        $this->inviteAs($this->owner, 'three@university.edu')->assertStatus(429);

        Sanctum::actingAs($this->editor);
        foreach (['a', 'b'] as $b) {
            $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'course', 'commentable_id' => $this->course->id, 'body' => "comment {$b}"])->assertStatus(201);
        }
        $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'course', 'commentable_id' => $this->course->id, 'body' => 'comment c'])->assertStatus(429);
    }
}
