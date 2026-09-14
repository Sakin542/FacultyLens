<?php

namespace Tests\Feature\Notifications;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\CourseCollaborator;
use App\Models\InstitutionalReport;
use App\Models\LearningOutcome;
use App\Models\Notification;
use App\Models\Question;
use App\Models\Recommendation;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 47: every FacultyLens workflow is driven through its REAL entry point (HTTP + faked AI service, sync queue)
 * and the resulting notification is asserted — recipients, type, wording, action URL and privacy.
 */
class NotificationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $editor;
    protected User $viewer;
    protected User $outsider;
    protected Course $course;
    protected Assessment $assessment;
    protected LearningOutcome $lo1;
    protected LearningOutcome $lo2;
    /** @var Question[] */
    protected array $questions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        NotificationFacade::fake(); // mail channel only (invitations) — in-app rows are real DB rows

        $this->owner = User::factory()->create(['name' => 'Dr. Owner', 'email' => 'owner@university.edu']);
        $this->editor = User::factory()->create(['name' => 'Dr. Editor', 'email' => 'editor@university.edu']);
        $this->viewer = User::factory()->create(['name' => 'Dr. Viewer', 'email' => 'viewer@university.edu']);
        $this->outsider = User::factory()->create(['name' => 'Dr. Outsider', 'email' => 'outsider@university.edu']);
        $this->course = Course::create(['user_id' => $this->owner->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        CourseCollaborator::create(['course_id' => $this->course->id, 'user_id' => $this->editor->id, 'invited_by' => $this->owner->id, 'role' => 'EDITOR', 'status' => 'ACTIVE', 'accepted_at' => now()]);
        CourseCollaborator::create(['course_id' => $this->course->id, 'user_id' => $this->viewer->id, 'invited_by' => $this->owner->id, 'role' => 'VIEWER', 'status' => 'ACTIVE', 'accepted_at' => now()]);
        $this->lo1 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO1', 'description' => 'Explain relational concepts.', 'cognitive_level' => 'Understand', 'sort_order' => 1]);
        $this->lo2 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO2', 'description' => 'Apply normalization.', 'cognitive_level' => 'Apply', 'sort_order' => 2]);
        $this->assessment = Assessment::create(['course_id' => $this->course->id, 'title' => 'Midterm Examination', 'type' => 'midterm', 'total_marks' => 30, 'duration_minutes' => 90, 'status' => 'draft']);
        foreach ([['Explain SQL.', 'easy', 'Understand'], ['Explain normalization.', 'medium', 'Apply'], ['Design a schema.', 'hard', 'Create']] as $i => [$text, $d, $c]) {
            $this->questions[] = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => $i + 1, 'question_text' => $text, 'question_type' => 'descriptive', 'marks' => 10, 'difficulty_level' => $d, 'cognitive_level' => $c, 'learning_outcome_id' => $i < 2 ? $this->lo1->id : $this->lo2->id, 'ai_topics' => [$i === 0 ? 'SQL' : 'Normalization']]);
        }
    }

    protected function notificationsOf(User $user, ?string $type = null)
    {
        return Notification::where('user_id', $user->id)->when($type, fn ($q) => $q->where('type', $type))->orderBy('created_at')->get();
    }

    protected function assertNoPrivateContent(Notification $n, array $forbidden): void
    {
        $blob = json_encode($n->toApi());
        foreach ($forbidden as $f) {
            $this->assertStringNotContainsString($f, $blob, "notification leaked [$f]");
        }
    }

    // ------------------------------------------------------------------ AI analysis + recommendations

    protected function analysisResponse(int $recs = 1): array
    {
        $list = [];
        for ($i = 1; $i <= $recs; $i++) {
            $list[] = ['category' => 'learning_outcome', 'problem' => "Problem {$i}", 'explanation' => 'Because.', 'recommendation' => 'Consider revising.', 'priority' => 'MEDIUM', 'source_metric' => 'alignment', 'evidence' => ['score' => 0.4]];
        }

        return [
            'status' => 'success', 'method' => 'unified_assessment_analysis_pipeline', 'assessment_id' => $this->assessment->id, 'course_id' => $this->course->id,
            'questions_analysis' => ['total_questions' => 3, 'questions' => [], 'summary' => []],
            'alignment_analysis' => ['status' => 'success', 'overall_alignment_score' => 80.0, 'coverage_percentage' => 100.0, 'question_alignment' => [], 'learning_outcome_coverage' => []],
            'similarity_analysis' => ['status' => 'success', 'overall_similarity_score' => 0.1, 'potential_duplicates_count' => 0, 'matches' => []],
            'quality_analysis' => ['status' => 'success', 'overall_quality_score' => 85.0, 'rating' => 'EXCELLENT', 'findings' => ['Balanced.'], 'components' => []],
            'recommendations' => ['status' => 'success', 'total_recommendations' => $recs, 'high_priority_count' => 0, 'medium_priority_count' => $recs, 'low_priority_count' => 0, 'recommendations' => $list],
            'summary' => ['total_questions' => 3, 'overall_quality_score' => 85.0, 'quality_rating' => 'EXCELLENT', 'lo_coverage_percentage' => 100.0, 'total_recommendations' => $recs],
        ];
    }

    public function test_assessment_analysis_completion_notifies_analysis_viewers_and_recommendation_deciders(): void
    {
        Http::fake(['*/api/v1/analyze-assessment' => Http::response($this->analysisResponse(2))]);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/ai/assessments/{$this->assessment->id}/analyze")->assertOk();
        $report = AnalysisReport::where('assessment_id', $this->assessment->id)->where('analysis_status', 'completed')->firstOrFail();

        foreach ([$this->owner, $this->editor, $this->viewer] as $u) {
            $n = $this->notificationsOf($u, 'AI_ANALYSIS_COMPLETED')->first();
            $this->assertNotNull($n, "{$u->name} should be notified");
            $this->assertSame('Assessment analysis completed', $n->title);
            $this->assertSame('AI analysis for Midterm Examination is ready for review.', $n->message);
            $this->assertSame("/assessments/{$this->assessment->id}/analysis", $n->action_url);
            $this->assertSame('SUCCESS', $n->severity);
            $this->assertSame('AI', $n->category);
            $this->assertSame($report->id, $n->entity_id);
            $this->assertSame(['assessment_id' => $this->assessment->id, 'analysis_id' => $report->id, 'course_id' => $this->course->id, 'analysis_version' => 1, 'action_label' => 'Open Analysis'], $n->data);
        }
        $this->assertCount(0, $this->notificationsOf($this->outsider));

        // recommendations → only OWNER/EDITOR (approve_recommendation), never VIEWER
        $this->assertCount(1, $this->notificationsOf($this->owner, 'AI_RECOMMENDATION_CREATED'));
        $this->assertCount(1, $this->notificationsOf($this->editor, 'AI_RECOMMENDATION_CREATED'));
        $this->assertCount(0, $this->notificationsOf($this->viewer, 'AI_RECOMMENDATION_CREATED'));
        $rec = $this->notificationsOf($this->owner, 'AI_RECOMMENDATION_CREATED')->first();
        $this->assertSame('New assessment recommendations', $rec->title);
        $this->assertStringContainsString('2 new assessment recommendations', $rec->message);
        $this->assertStringContainsString('may require review', $rec->message);
        $this->assertStringNotContainsString('unfair', strtolower($rec->message));
        $this->assertSame(2, Recommendation::where('analysis_report_id', $report->id)->where('status', 'pending')->count(), 'notification changed no academic decision');
    }

    public function test_assessment_analysis_failure_notifies_runners_only_with_no_success_notification(): void
    {
        Http::fake(['*/api/v1/analyze-assessment' => Http::response(['detail' => 'boom'], 500)]);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/ai/assessments/{$this->assessment->id}/analyze")->assertStatus(502);

        $this->assertSame(0, Notification::where('type', 'AI_ANALYSIS_COMPLETED')->count());
        $this->assertSame(0, Notification::where('type', 'AI_RECOMMENDATION_CREATED')->count());
        foreach ([$this->owner, $this->editor] as $u) {
            $n = $this->notificationsOf($u, 'AI_ANALYSIS_FAILED')->first();
            $this->assertNotNull($n, "{$u->name} (run_analysis) should be told");
            $this->assertSame('AI analysis failed', $n->title);
            $this->assertStringContainsString('No academic data was changed', $n->message);
            $this->assertSame('ERROR', $n->severity);
            $this->assertSame('Retry Analysis', $n->data['action_label']);
            $this->assertNoPrivateContent($n, ['boom', 'detail']);
        }
        $this->assertCount(0, $this->notificationsOf($this->viewer, 'AI_ANALYSIS_FAILED'));
        $this->assertSame('failed', AnalysisReport::where('assessment_id', $this->assessment->id)->latest('id')->value('analysis_status'));
    }

    public function test_async_analysis_job_path_notifies_too(): void
    {
        Http::fake(['*/api/v1/analyze-assessment' => Http::response($this->analysisResponse(0))]);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/ai/assessments/{$this->assessment->id}/analyze?async=1")->assertStatus(202);
        $this->assertCount(1, $this->notificationsOf($this->owner, 'AI_ANALYSIS_COMPLETED'));
        $this->assertCount(0, $this->notificationsOf($this->owner, 'AI_RECOMMENDATION_CREATED'), 'zero recommendations → no recommendation notification');
    }

    // ------------------------------------------------------------------ rubric

    protected function rubricResponse(): array
    {
        $criteria = [];
        foreach ([2, 2, 2, 2, 2] as $idx => $marks) {
            $criteria[] = ['criterion' => 'Criterion ' . ($idx + 1), 'description' => 'D', 'max_marks' => $marks, 'scoring_guidance' => 'g', 'expected_indicators' => ['a', 'b'], 'sort_order' => $idx + 1];
        }

        return ['status' => 'success', 'generation_method' => 'template_based', 'draft_status' => 'DRAFT',
            'rubric' => ['title' => 'Rubric', 'question_text' => $this->questions[0]->question_text, 'total_marks' => 10, 'criteria' => $criteria, 'general_guidance' => 'g'],
            'metadata' => ['model' => 'facultylens-rubric-template-engine', 'version' => '1.0.0', 'generative_model_used' => false, 'criteria_count' => 5, 'validation_passed' => true]];
    }

    public function test_rubric_generation_success_and_failure_notify_the_requesting_faculty(): void
    {
        Http::fake(['*/api/v1/generate-rubric' => Http::response($this->rubricResponse())]);
        Sanctum::actingAs($this->editor);
        $this->postJson("/api/questions/{$this->questions[0]->id}/rubrics/generate")->assertStatus(201);
        $rubric = Rubric::firstOrFail();
        $this->assertSame('DRAFT', $rubric->status);

        $n = $this->notificationsOf($this->editor, 'RUBRIC_GENERATED')->first();
        $this->assertNotNull($n);
        $this->assertSame('Rubric draft generated', $n->title);
        $this->assertStringContainsString('ready for faculty review', $n->message);
        $this->assertStringContainsString('remains a draft until you approve', $n->message);
        $this->assertSame("/assessments/{$this->assessment->id}", $n->action_url);
        $this->assertSame($rubric->id, $n->entity_id);
        $this->assertCount(0, $this->notificationsOf($this->owner, 'RUBRIC_GENERATED'), 'a sync generation only notifies the actor');
    }

    public function test_rubric_generation_failure_notifies_the_requesting_faculty(): void
    {
        Http::fake(['*/api/v1/generate-rubric' => Http::response(['detail' => 'down'], 503)]);
        Sanctum::actingAs($this->editor);
        $this->postJson("/api/questions/{$this->questions[1]->id}/rubrics/generate")->assertStatus(502);
        $f = $this->notificationsOf($this->editor, 'RUBRIC_GENERATION_FAILED')->first();
        $this->assertNotNull($f);
        $this->assertSame('Rubric generation failed', $f->title);
        $this->assertStringContainsString('review the question and try again', $f->message);
        $this->assertNoPrivateContent($f, ['down', 'detail']);
        $this->assertSame(0, Rubric::count(), 'no rubric persisted on failure');
        $this->assertCount(0, $this->notificationsOf($this->editor, 'RUBRIC_GENERATED'));
    }

    // ------------------------------------------------------------------ question generation

    protected function fakeQuestionGeneration(bool $fail = false): void
    {
        $q = ['question_text' => 'Analyze the given relational schema and identify the normalization anomalies present in detail.', 'question_type' => 'DESCRIPTIVE', 'marks' => 10, 'difficulty_level' => 'MEDIUM', 'cognitive_level' => 'APPLY', 'topic' => 'Normalization',
            'options' => null, 'correct_option' => null, 'expected_answer' => 'Identify dependencies.', 'explanation' => null, 'source_chunk_ids' => [],
            'validation' => ['detected_question_type' => 'ANALYTICAL', 'detected_difficulty' => 'MEDIUM', 'detected_cognitive_level' => 'APPLY', 'detected_topics' => ['Normalization'], 'co_alignment_score' => 0.82, 'co_alignment_status' => 'STRONG', 'max_similarity_score' => 0.2, 'similarity_status' => 'NOT_SIMILAR', 'similar_questions' => [],
                'constraints' => ['topic' => true, 'question_type' => true, 'difficulty' => true, 'cognitive_level' => true, 'co_alignment' => true, 'similarity' => true, 'marks' => true], 'warnings' => [], 'overall_status' => 'PASSED']];
        Http::fake([
            '*/api/v1/embeddings/batch' => Http::response(['status' => 'success', 'vectors' => [[1, 0, 0, 0]], 'model' => 'm', 'embedding_dimension' => 4]),
            '*/api/v1/generate-questions' => $fail ? Http::response(['detail' => 'down'], 500) : Http::response([
                'status' => 'success', 'questions' => [$q, $q + ['question_text' => 'Explain why decomposition into third normal form removes transitive dependencies with an example.']], 'generation_method' => 'template', 'model' => 'engine',
                'model_version' => '1.0.0', 'embedding_model' => 'e', 'prompt_version' => '1.0.0', 'requested_count' => 2, 'generated_count' => 2, 'blueprint_summary' => null, 'warnings' => [], 'disclaimer' => 'Drafts.',
            ]),
        ]);
    }

    protected function questionPayload(): array
    {
        return ['course_id' => $this->course->id, 'assessment_id' => $this->assessment->id, 'topic' => 'Normalization', 'learning_outcome_id' => $this->lo2->id, 'question_type' => 'descriptive', 'difficulty_level' => 'medium', 'cognitive_level' => 'Apply', 'marks' => 10, 'number_of_questions' => 2, 'include_expected_answer' => true];
    }

    public function test_question_generation_completion_and_failure_notify_the_requester(): void
    {
        $this->fakeQuestionGeneration();
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/question-generation', $this->questionPayload())->assertStatus(202)->json('data.id');

        $n = $this->notificationsOf($this->owner, 'QUESTION_GENERATION_COMPLETED')->first();
        $this->assertNotNull($n);
        $this->assertSame('Question generation completed', $n->title);
        $this->assertStringContainsString('2 generated question drafts are ready for faculty review', $n->message);
        $this->assertStringContainsString('not added to any assessment until you approve', $n->message);
        $this->assertSame("/courses/{$this->course->id}/question-generator?request={$id}", $n->action_url);
        $this->assertCount(0, $this->notificationsOf($this->editor), 'a personal request notifies only the requester');
        $this->assertSame(0, Question::where('assessment_id', $this->assessment->id)->count() - 3, 'drafts were not added to the assessment');
    }

    public function test_question_generation_failure_notifies_the_requester(): void
    {
        $this->fakeQuestionGeneration(true);
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/question-generation', $this->questionPayload());
        $f = $this->notificationsOf($this->owner, 'QUESTION_GENERATION_FAILED')->first();
        $this->assertNotNull($f);
        $this->assertStringContainsString('No questions were added', $f->message);
        $this->assertNoPrivateContent($f, ['down', 'detail']);
        $this->assertCount(0, $this->notificationsOf($this->owner, 'QUESTION_GENERATION_COMPLETED'));
    }

    // ------------------------------------------------------------------ assessment versions + review

    public function test_version_lifecycle_notifies_editors_and_review_assignment_completes(): void
    {
        Sanctum::actingAs($this->editor);
        $v1 = $this->postJson("/api/assessments/{$this->assessment->id}/versions", ['change_summary' => 'Initial'])->assertStatus(201)->json('data.version');

        // CREATED → other editors (owner), not the actor, never the viewer
        $this->assertCount(1, $this->notificationsOf($this->owner, 'ASSESSMENT_VERSION_CREATED'));
        $this->assertCount(0, $this->notificationsOf($this->editor, 'ASSESSMENT_VERSION_CREATED'));
        $this->assertCount(0, $this->notificationsOf($this->viewer));
        $created = $this->notificationsOf($this->owner, 'ASSESSMENT_VERSION_CREATED')->first();
        $this->assertSame("/assessments/{$this->assessment->id}/versions/{$v1['id']}", $created->action_url);
        $this->assertStringContainsString('Dr. Editor created version', $created->message);

        // SUBMITTED → REVIEW_ASSIGNED to the other editors
        $this->postJson("/api/assessment-versions/{$v1['id']}/submit-review")->assertOk();
        $review = $this->notificationsOf($this->owner, 'REVIEW_ASSIGNED')->first();
        $this->assertNotNull($review);
        $this->assertSame('Review assigned', $review->title);
        $this->assertStringContainsString('You have been assigned to review version', $review->message);
        $this->assertSame('REVIEW', $review->category);
        $this->assertCount(0, $this->notificationsOf($this->editor, 'REVIEW_ASSIGNED'));

        // APPROVED by the owner → all editors; REVIEW_COMPLETED to the submitter
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/assessment-versions/{$v1['id']}/approve")->assertOk();
        $this->assertCount(1, $this->notificationsOf($this->owner, 'ASSESSMENT_VERSION_APPROVED'));
        $this->assertCount(1, $this->notificationsOf($this->editor, 'ASSESSMENT_VERSION_APPROVED'));
        $completed = $this->notificationsOf($this->editor, 'REVIEW_COMPLETED')->first();
        $this->assertNotNull($completed);
        $this->assertStringContainsString('has been completed', $completed->message);
        $this->assertCount(0, $this->notificationsOf($this->owner, 'REVIEW_COMPLETED'));

        // FINALIZED → everyone with edit_assessment, including the actor
        $this->postJson("/api/assessment-versions/{$v1['id']}/finalize")->assertOk();
        foreach ([$this->owner, $this->editor] as $u) {
            $n = $this->notificationsOf($u, 'ASSESSMENT_VERSION_FINALIZED')->first();
            $this->assertNotNull($n, $u->name);
            $this->assertSame('Assessment version finalized', $n->title);
            $this->assertStringContainsString('has been finalized by Dr. Owner and is now locked for editing', $n->message);
        }
        $this->assertSame('FINALIZED', \App\Models\AssessmentVersion::find($v1['id'])->status);
        $this->assertSame(3, Question::where('assessment_id', $this->assessment->id)->count(), 'finalization never rewrote live questions');

        // RESTORED → other editors; the automatic archival during finalize is not announced separately
        $v2 = $this->postJson("/api/assessment-versions/{$v1['id']}/restore", ['change_summary' => 'Restore'])->assertStatus(201)->json('data.version');
        $this->assertCount(1, $this->notificationsOf($this->editor, 'ASSESSMENT_VERSION_RESTORED'));
        $this->assertCount(0, $this->notificationsOf($this->owner, 'ASSESSMENT_VERSION_RESTORED'));

        // ARCHIVED (explicit) → other editors
        $this->postJson("/api/assessment-versions/{$v2['id']}/archive")->assertOk();
        $this->assertCount(1, $this->notificationsOf($this->editor, 'ASSESSMENT_VERSION_ARCHIVED'));
        $this->assertStringContainsString('read-only', $this->notificationsOf($this->editor, 'ASSESSMENT_VERSION_ARCHIVED')->first()->message);
        $this->assertCount(0, $this->notificationsOf($this->outsider));
    }

    // ------------------------------------------------------------------ collaboration

    public function test_collaboration_invitation_decline_removal_and_comment_flows(): void
    {
        $invitee = User::factory()->create(['name' => 'Dr. New', 'email' => 'new@university.edu']);
        Sanctum::actingAs($this->owner);
        $res = $this->postJson("/api/courses/{$this->course->id}/collaborators/invite", ['email' => 'new@university.edu', 'role' => 'REVIEWER'])->assertStatus(201);
        $token = str_replace(rtrim(config('collaboration.frontend_url'), '/') . '/collaboration/invitations/', '', $res->json('data.accept_url'));

        $inv = $this->notificationsOf($invitee, 'COLLABORATION_INVITATION')->first();
        $this->assertNotNull($inv);
        $this->assertSame('You have been invited', substr('You have been invited', 0, 21));
        $this->assertStringContainsString('Dr. Owner invited you to collaborate on CSE101 — Database Systems as Reviewer', $inv->message);
        $this->assertSame('/collaboration/invitations', $inv->action_url);
        $this->assertNotNull($inv->expires_at);
        $this->assertNoPrivateContent($inv, [$token, 'token']);

        Sanctum::actingAs($invitee);
        $this->postJson("/api/collaboration/invitations/{$token}/decline")->assertOk();
        $declined = $this->notificationsOf($this->owner, 'COLLABORATION_REJECTED')->first();
        $this->assertNotNull($declined);
        $this->assertStringContainsString('Dr. New declined your invitation', $declined->message);

        // Comment thread: owner starts, editor replies → owner (participant) gets COMMENT_CREATED, viewer mentioned → MENTION_RECEIVED
        Sanctum::actingAs($this->owner);
        $root = $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'question', 'commentable_id' => $this->questions[0]->id, 'body' => 'Is Q1 too easy?'])->assertStatus(201)->json('data.id');
        Sanctum::actingAs($this->editor);
        $reply = $this->postJson("/api/courses/{$this->course->id}/comments", ['commentable_type' => 'question', 'commentable_id' => $this->questions[0]->id, 'body' => 'Maybe — what do you think?', 'parent_id' => $root, 'mentions' => [$this->viewer->id]])->assertStatus(201)->json('data.id');

        $c = $this->notificationsOf($this->owner, 'COMMENT_CREATED')->first();
        $this->assertNotNull($c);
        $this->assertStringContainsString('A collaborator commented on CSE101', $c->message);
        $this->assertSame("/courses/{$this->course->id}/collaboration?comment={$reply}", $c->action_url);
        $m = $this->notificationsOf($this->viewer, 'MENTION_RECEIVED')->first();
        $this->assertNotNull($m);
        $this->assertStringContainsString('You were mentioned in a collaboration comment', $m->message);
        $this->assertCount(0, $this->notificationsOf($this->editor, 'COMMENT_CREATED'), 'actor is not notified about their own comment');

        // Removal → the removed member only
        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/courses/{$this->course->id}/collaborators/{$this->viewer->id}")->assertOk();
        $r = $this->notificationsOf($this->viewer, 'COLLABORATION_REMOVED')->first();
        $this->assertNotNull($r);
        $this->assertSame('Your collaboration access to CSE101 — Database Systems has been removed.', $r->message);
        $this->assertSame('WARNING', $r->severity);
        $this->assertCount(0, $this->notificationsOf($this->editor, 'COLLABORATION_REMOVED'));
    }

    // ------------------------------------------------------------------ grading

    protected function gradingFixture(): StudentAnswer
    {
        $rubric = Rubric::create(['question_id' => $this->questions[0]->id, 'assessment_id' => $this->assessment->id, 'created_by' => $this->owner->id, 'title' => 'R', 'total_marks' => 10, 'status' => 'APPROVED', 'version' => 1, 'generation_method' => 'template_based']);
        foreach ([2, 2, 2, 2, 2] as $i => $m) {
            RubricCriterion::create(['rubric_id' => $rubric->id, 'criterion' => "C{$i}", 'description' => 'd', 'max_marks' => $m, 'expected_indicators' => ['a'], 'sort_order' => $i + 1]);
        }
        $student = Student::create(['created_by' => $this->owner->id, 'student_identifier' => 'STU001', 'name' => 'Very Private Student Name']);
        $submission = StudentSubmission::create(['assessment_id' => $this->assessment->id, 'student_id' => $student->id, 'status' => 'SUBMITTED', 'grading_status' => 'NOT_STARTED', 'submitted_at' => now(), 'total_marks' => 30]);

        return StudentAnswer::create(['student_submission_id' => $submission->id, 'question_id' => $this->questions[0]->id, 'answer_type' => 'TEXT', 'answer_text' => 'SECRET STUDENT ANSWER TEXT', 'original_answer_text' => 'SECRET STUDENT ANSWER TEXT', 'answer_status' => 'NOT_REVIEWED']);
    }

    public function test_ai_grading_completion_and_failure_notify_the_requester_without_student_data(): void
    {
        $answer = $this->gradingFixture();
        $criteria = RubricCriterion::all();
        $results = $criteria->map(fn ($c) => ['rubric_criterion_id' => $c->id, 'criterion' => $c->criterion, 'suggested_marks' => 1.5, 'maximum_marks' => 2.0, 'evaluation' => 'e', 'evidence' => ['x'], 'missing_elements' => [], 'coverage_level' => 'PARTIAL'])->all();
        Http::fake(['*/api/v1/grade-answer' => Http::response(['status' => 'success', 'suggested_marks' => 7.5, 'maximum_marks' => 10, 'criterion_results' => $results, 'overall_feedback' => 'ok', 'strengths' => [], 'missing_elements' => [], 'evaluation_summary' => 's',
            'metadata' => ['model' => 'g', 'version' => '1', 'generation_method' => 'embedding_rubric_alignment', 'generative_model_used' => false]])]);

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/student-answers/{$answer->id}/ai-grade")->assertStatus(202);

        $n = $this->notificationsOf($this->editor, 'GRADING_COMPLETED')->first();
        $this->assertNotNull($n);
        $this->assertSame('AI grading suggestion ready', $n->title);
        $this->assertStringContainsString('Marks are only applied when you confirm them', $n->message);
        $this->assertSame("/submissions/{$answer->student_submission_id}", $n->action_url);
        $this->assertNoPrivateContent($n, ['SECRET STUDENT', 'Very Private', 'STU001', '7.5']);
        $this->assertNull($answer->fresh()->awarded_marks, 'AI suggestion did not change marks');
        $this->assertCount(0, $this->notificationsOf($this->owner, 'GRADING_COMPLETED'));
    }

    public function test_ai_grading_failure_notifies_the_requester(): void
    {
        $answer = $this->gradingFixture();
        Http::fake(['*/api/v1/grade-answer' => Http::response(['detail' => 'down'], 503)]);
        Sanctum::actingAs($this->editor);
        $this->postJson("/api/student-answers/{$answer->id}/ai-grade");
        $f = $this->notificationsOf($this->editor, 'GRADING_FAILED')->first();
        $this->assertNotNull($f);
        $this->assertStringContainsString('No marks were changed', $f->message);
        $this->assertNoPrivateContent($f, ['SECRET STUDENT', 'down']);
        $this->assertCount(0, $this->notificationsOf($this->editor, 'GRADING_COMPLETED'));
        $this->assertNull($answer->fresh()->awarded_marks);
    }

    public function test_inter_grader_review_event_is_supported_and_worded_neutrally(): void
    {
        event(new \App\Events\InterGraderReviewRequired($this->assessment, $this->questions[0]->id, ['range' => 4]));
        foreach ([$this->owner, $this->editor] as $u) {
            $n = $this->notificationsOf($u, 'INTER_GRADER_REVIEW_REQUIRED')->first();
            $this->assertNotNull($n, $u->name);
            $this->assertSame('Grading consistency review recommended', $n->title);
            $this->assertStringContainsString('variation between faculty grading results', $n->message);
            $this->assertStringContainsString('may require review', $n->message);
            $this->assertStringNotContainsString('incorrect', $n->message);
            $this->assertSame('WARNING', $n->severity);
        }
        $this->assertCount(0, $this->notificationsOf($this->viewer), 'viewers do not see student data');
    }

    // ------------------------------------------------------------------ performance

    public function test_performance_analysis_completion_and_learning_gap_notify_student_data_roles_only(): void
    {
        config(['performance.expected_performance_percent' => 70, 'performance.min_responses_for_gap_analysis' => 5]);
        foreach ([[9, 5, 6], [8, 6, 7], [8, 4, 6], [7, 5, 5], [8, 5, 6]] as $i => $marks) {
            $student = Student::create(['created_by' => $this->owner->id, 'student_identifier' => sprintf('STU%03d', $i + 1), 'name' => "Student {$i}"]);
            $sub = StudentSubmission::create(['assessment_id' => $this->assessment->id, 'student_id' => $student->id, 'status' => 'GRADED', 'grading_status' => 'FACULTY_REVIEWED', 'submitted_at' => now(), 'total_marks' => 30]);
            foreach ($this->questions as $qi => $q) {
                StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $q->id, 'answer_type' => 'TEXT', 'answer_text' => 'answer', 'awarded_marks' => $marks[$qi], 'answer_status' => 'REVIEWED']);
            }
        }

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/assessments/{$this->assessment->id}/performance/analyze")->assertOk();

        foreach ([$this->owner, $this->editor] as $u) {
            $done = $this->notificationsOf($u, 'PERFORMANCE_ANALYSIS_COMPLETED')->first();
            $this->assertNotNull($done, $u->name);
            $this->assertSame('Student performance analysis for Midterm Examination is ready to review.', $done->message);
            $this->assertSame("/assessments/{$this->assessment->id}/analysis", $done->action_url);
            $gap = $this->notificationsOf($u, 'LEARNING_GAP_DETECTED')->first();
            $this->assertNotNull($gap, "{$u->name} gap");
            $this->assertSame('Learning outcome review recommended', $gap->title);
            $this->assertStringContainsString('may require faculty review', $gap->message);
            $this->assertSame('WARNING', $gap->severity);
            $this->assertNoPrivateContent($gap, ['STU00', 'Student 0', 'Student 1', 'rank']);
            $this->assertNoPrivateContent($done, ['STU00', 'Student 0']);
        }
        $this->assertCount(0, $this->notificationsOf($this->viewer), 'VIEWER lacks view_student_data');
    }

    // ------------------------------------------------------------------ reports

    public function test_report_generation_success_and_failure_notify_the_requester_only(): void
    {
        Sanctum::actingAs($this->owner);
        $res = $this->postJson('/api/reports', ['report_type' => 'ASSESSMENT', 'scope_type' => 'ASSESSMENT', 'filters' => ['course_id' => $this->course->id, 'assessment_id' => $this->assessment->id], 'format' => 'CSV'])->assertStatus(201);
        $reportId = $res->json('data.id') ?? $res->json('data.report.id');
        $report = InstitutionalReport::findOrFail($reportId);
        $this->assertSame('COMPLETED', $report->status);

        $n = $this->notificationsOf($this->owner, 'REPORT_GENERATED')->first();
        $this->assertNotNull($n);
        $this->assertSame('Report ready', $n->title);
        $this->assertSame('Your requested Assessment Report report (CSV) is ready to view or download.', $n->message);
        $this->assertSame("/reports/{$report->id}", $n->action_url);
        $this->assertSame($report->expires_at?->toDateTimeString(), $n->expires_at?->toDateTimeString());
        $this->assertCount(0, $this->notificationsOf($this->editor, 'REPORT_GENERATED'));

        // Queued path: async threshold 0 forces the job; sync queue runs it inline
        config(['institutional_reports.async_threshold_records' => 0]);
        $res2 = $this->postJson('/api/reports', ['report_type' => 'ASSESSMENT', 'scope_type' => 'ASSESSMENT', 'filters' => ['course_id' => $this->course->id, 'assessment_id' => $this->assessment->id], 'format' => 'CSV']);
        $this->assertContains($res2->status(), [201, 202]);
        $this->assertSame('COMPLETED', InstitutionalReport::findOrFail($res2->json('data.id') ?? $res2->json('data.report.id'))->status);
        $this->assertCount(2, $this->notificationsOf($this->owner, 'REPORT_GENERATED'));

        // Permanent job failure → FAILED + failure notification, no success notification for that report
        $pending = InstitutionalReport::create(['report_uuid' => (string) Str::uuid(), 'created_by' => $this->owner->id, 'report_type' => 'ASSESSMENT', 'scope_type' => 'ASSESSMENT', 'course_id' => $this->course->id, 'assessment_id' => $this->assessment->id, 'filters' => [], 'title' => 'Pending', 'format' => 'PDF', 'status' => 'PENDING', 'is_async' => true, 'contains_student_data' => false, 'record_count' => 1]);
        (new \App\Jobs\GenerateInstitutionalReportJob($pending->id))->failed(new \RuntimeException('disk full /var/secret'));
        $this->assertSame('FAILED', $pending->fresh()->status);
        $f = $this->notificationsOf($this->owner, 'REPORT_GENERATION_FAILED')->first();
        $this->assertNotNull($f);
        $this->assertSame('Report generation failed', $f->title);
        $this->assertStringContainsString('could not be generated. Please try again', $f->message);
        $this->assertNoPrivateContent($f, ['disk full', '/var/secret']);
        $this->assertCount(2, $this->notificationsOf($this->owner, 'REPORT_GENERATED'));
    }

    // ------------------------------------------------------------------ feedback

    public function test_recommendation_feedback_notifies_other_deciders_without_the_private_notes(): void
    {
        $report = AnalysisReport::create(['assessment_id' => $this->assessment->id, 'analysis_version' => 1, 'is_current' => true, 'overall_score' => 84.0, 'analysis_status' => 'completed', 'analyzed_at' => now()]);
        $rec = Recommendation::create(['analysis_report_id' => $report->id, 'category' => 'learning_outcome', 'problem' => 'CO2 weakly assessed', 'title' => 'CO2 weakly assessed', 'description' => 'd', 'recommendation' => 'r', 'explanation' => 'e', 'priority' => 'high', 'status' => 'pending', 'source_metric' => 'alignment']);

        Sanctum::actingAs($this->editor);
        $this->postJson("/api/recommendations/{$rec->id}/feedback", ['decision' => 'ACCEPTED', 'usefulness_rating' => 5, 'reason' => 'USEFUL_INSIGHT', 'comment' => 'CONFIDENTIAL NOTE about a colleague'])->assertOk();

        $n = $this->notificationsOf($this->owner, 'FACULTY_FEEDBACK_RECEIVED')->first();
        $this->assertNotNull($n);
        $this->assertSame('Faculty feedback received', $n->title);
        $this->assertStringContainsString('Dr. Editor recorded feedback (accepted) on an AI recommendation for Midterm Examination', $n->message);
        $this->assertSame("/assessments/{$this->assessment->id}/analysis", $n->action_url);
        $this->assertNoPrivateContent($n, ['CONFIDENTIAL', 'colleague']);
        $this->assertCount(0, $this->notificationsOf($this->editor, 'FACULTY_FEEDBACK_RECEIVED'), 'submitter is not notified of their own feedback');
        $this->assertCount(0, $this->notificationsOf($this->viewer, 'FACULTY_FEEDBACK_RECEIVED'), 'viewers cannot decide on recommendations');
    }

    // ------------------------------------------------------------------ security / system

    public function test_repeated_failed_logins_raise_one_security_alert_per_window(): void
    {
        config(['notifications.security.failed_login_threshold' => 3]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'owner@university.edu', 'password' => 'wrong-password'])->assertStatus(401);
        }
        // Unknown accounts never create anything (and the response is identical)
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'ghost@university.edu', 'password' => 'wrong-password'])->assertStatus(401);
        }

        $alerts = $this->notificationsOf($this->owner, 'SECURITY_ALERT');
        $this->assertCount(1, $alerts, 'threshold crossing raises exactly one alert per window');
        $n = $alerts->first();
        $this->assertSame('Multiple failed sign-in attempts', $n->title);
        $this->assertSame('CRITICAL', $n->severity);
        $this->assertSame('SECURITY', $n->category);
        $this->assertSame('/settings', $n->action_url);
        $this->assertNoPrivateContent($n, ['wrong-password', '127.0.0.1', 'ip_address']);
        $this->assertSame(1, Notification::count());

        // Mandatory: a disabled preference is ignored
        \App\Models\NotificationPreference::create(['user_id' => $this->owner->id, 'notification_type' => 'SECURITY_ALERT', 'in_app_enabled' => false]);
        $this->travel(2)->hours();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'owner@university.edu', 'password' => 'wrong-password'])->assertStatus(401);
        }
        $this->assertCount(2, $this->notificationsOf($this->owner, 'SECURITY_ALERT'));
    }

    public function test_password_change_raises_a_security_alert(): void
    {
        $user = User::factory()->create(['password' => 'OldPass123!']);
        Sanctum::actingAs($user);
        $this->postJson('/api/auth/change-password', ['current_password' => 'OldPass123!', 'password' => 'NewPass123!', 'password_confirmation' => 'NewPass123!'])->assertOk();

        $n = $this->notificationsOf($user, 'SECURITY_ALERT')->first();
        $this->assertNotNull($n);
        $this->assertSame('Your password was changed', $n->title);
        $this->assertNoPrivateContent($n, ['OldPass', 'NewPass']);
    }

    public function test_system_alert_command_reaches_admins_only(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $this->artisan('notifications:system-alert', ['title' => 'Planned maintenance', 'message' => 'FacultyLens will be unavailable on Sunday 02:00–03:00.', '--key' => 'maint-2026-09-20'])->assertSuccessful();
        $this->artisan('notifications:system-alert', ['title' => 'Planned maintenance', 'message' => 'dup', '--key' => 'maint-2026-09-20'])->assertSuccessful();

        $this->assertCount(1, $this->notificationsOf($admin, 'SYSTEM_ALERT'));
        $this->assertSame('SYSTEM', $this->notificationsOf($admin)->first()->category);
        $this->assertCount(0, $this->notificationsOf($this->owner));
    }
}
