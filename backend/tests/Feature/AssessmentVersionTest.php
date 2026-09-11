<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\AssessmentBlueprint;
use App\Models\AssessmentVersion;
use App\Models\AssessmentVersionQuestion;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseCollaborator;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Services\AssessmentVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * STEP 38: Assessment Versioning — server-side numbering, snapshots, immutability, comparison, restore, protection.
 */
class AssessmentVersionTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected User $other;
    protected User $reviewer;
    protected Course $course;
    protected Course $otherCourse;
    protected Assessment $midterm;
    protected LearningOutcome $co1;
    protected LearningOutcome $co2;
    protected LearningOutcome $foreignLo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faculty = User::factory()->create(['role' => 'FACULTY']);
        $this->other = User::factory()->create(['role' => 'FACULTY']);
        $this->reviewer = User::factory()->create(['role' => 'FACULTY']);
        $this->course = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        $this->otherCourse = Course::create(['user_id' => $this->other->id, 'course_code' => 'EEE201', 'course_name' => 'Circuits', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        CourseCollaborator::create(['course_id' => $this->course->id, 'user_id' => $this->reviewer->id, 'invited_by' => $this->faculty->id, 'role' => 'REVIEWER', 'status' => 'ACTIVE', 'accepted_at' => now()]);
        $this->co1 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO1', 'description' => 'Explain relational concepts.', 'sort_order' => 1]);
        $this->co2 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO2', 'description' => 'Apply normalization.', 'sort_order' => 2]);
        $this->foreignLo = LearningOutcome::create(['course_id' => $this->otherCourse->id, 'code' => 'CO9', 'description' => 'Foreign.', 'sort_order' => 1]);
        $this->midterm = Assessment::create(['course_id' => $this->course->id, 'title' => 'Midterm Examination', 'type' => 'midterm', 'total_marks' => 30, 'duration_minutes' => 90, 'status' => 'draft']);
        foreach ([['Explain SQL.', 'easy', 'Understand'], ['Explain normalization.', 'medium', 'Apply'], ['Design a schema.', 'hard', 'Create']] as $i => [$text, $d, $c]) {
            Question::create(['assessment_id' => $this->midterm->id, 'question_number' => $i + 1, 'question_text' => $text, 'question_type' => 'descriptive', 'marks' => 10, 'difficulty_level' => $d, 'cognitive_level' => $c, 'learning_outcome_id' => $i < 2 ? $this->co1->id : $this->co2->id]);
        }
    }

    protected function createVersion(array $payload = [], int $expected = 201): array
    {
        Sanctum::actingAs($this->faculty);

        return $this->postJson("/api/assessments/{$this->midterm->id}/versions", $payload)->assertStatus($expected)->json('data');
    }

    /** Question rows as the PUT endpoint expects them (from a presented version). */
    protected function rowsFrom(array $version): array
    {
        return array_map(fn ($q) => array_intersect_key($q, array_flip(['original_question_id', 'question_number', 'section_name', 'question_text', 'question_type', 'marks', 'difficulty_level', 'cognitive_level', 'topic', 'learning_outcome_id', 'program_outcome_id', 'expected_answer'])), $version['questions']);
    }

    // ------------------------------------------------------------- creation & numbering

    public function test_requires_auth_and_lists_empty_history(): void
    {
        $this->getJson("/api/assessments/{$this->midterm->id}/versions")->assertStatus(401);
        Sanctum::actingAs($this->faculty);
        $res = $this->getJson("/api/assessments/{$this->midterm->id}/versions")->assertOk();
        $this->assertSame([], $res->json('data.versions'));
        $this->assertNull($res->json('data.current_version_id'));
        $this->assertTrue($res->json('data.permissions.edit'));
    }

    public function test_first_version_snapshots_the_live_assessment(): void
    {
        $data = $this->createVersion();
        $v = $data['version'];
        $this->assertSame(1, $v['version_number']);
        $this->assertSame('v1.0', $v['version_label']);
        $this->assertSame('DRAFT', $v['status']);
        $this->assertSame('MAJOR', $v['version_type']);
        $this->assertNull($v['based_on_version_id']);
        $this->assertCount(3, $v['questions']);
        $this->assertSame('Explain SQL.', $v['questions'][0]['question_text']);
        $this->assertSame(3, $v['question_count']);
        $this->assertEquals(30, $v['total_marks']);
        $this->assertSame($this->faculty->id, $v['created_by']['id']);
        $this->assertDatabaseCount('assessment_versions', 1);
        $this->assertDatabaseCount('assessment_version_questions', 3);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ASSESSMENT_VERSION_CREATED', 'entity_id' => $v['id'], 'user_id' => $this->faculty->id]);
    }

    public function test_subsequent_versions_are_numbered_server_side_and_never_reuse_numbers(): void
    {
        $v1 = $this->createVersion()['version'];
        $v2 = $this->createVersion(['change_summary' => 'Second draft', 'version_number' => 99, 'version_label' => 'v9.9'])['version'];
        $this->assertSame(2, $v2['version_number']);
        $this->assertSame('v2.0', $v2['version_label']);
        $this->assertSame($v1['id'], $v2['based_on_version_id']);

        // Archive v2, the next version is still v3
        $this->postJson("/api/assessment-versions/{$v2['id']}/archive")->assertOk();
        $v3 = $this->createVersion(['change_summary' => 'Third', 'version_type' => 'MINOR', 'based_on_version_id' => $v1['id']])['version'];
        $this->assertSame(3, $v3['version_number']);
        $this->assertSame('v1.1', $v3['version_label']);
        $this->assertSame($v1['id'], $v3['based_on_version_id']);

        $this->assertSame(1, AssessmentVersion::where('assessment_id', $this->midterm->id)->where('version_number', 2)->count());
    }

    public function test_change_summary_is_required_after_the_first_version(): void
    {
        $this->createVersion();
        Sanctum::actingAs($this->faculty);
        $this->postJson("/api/assessments/{$this->midterm->id}/versions", [])->assertStatus(422)->assertJsonValidationErrors(['change_summary']);
    }

    public function test_based_on_version_must_belong_to_the_assessment(): void
    {
        $foreign = Assessment::create(['course_id' => $this->otherCourse->id, 'title' => 'Other', 'type' => 'quiz', 'total_marks' => 10, 'status' => 'draft']);
        $foreignVersion = AssessmentVersion::create(['assessment_id' => $foreign->id, 'version_number' => 1, 'version_label' => 'v1.0', 'title' => 'Other', 'assessment_type' => 'quiz', 'created_by' => $this->other->id]);
        $this->createVersion();
        Sanctum::actingAs($this->faculty);
        $this->postJson("/api/assessments/{$this->midterm->id}/versions", ['based_on_version_id' => $foreignVersion->id, 'change_summary' => 'x'])->assertStatus(422);
    }

    // ------------------------------------------------------------- cloning & integrity (mandatory test)

    public function test_editing_v2_never_changes_v1_snapshot(): void
    {
        $v1 = $this->createVersion()['version'];
        $v2 = $this->createVersion(['change_summary' => 'Sharpen Q1'])['version'];
        $this->assertSame($v1['questions'][0]['original_question_id'], $v2['questions'][0]['original_question_id']);

        $rows = $this->rowsFrom($v2);
        $rows[0]['question_text'] = 'Explain SQL joins.';
        $res = $this->putJson("/api/assessment-versions/{$v2['id']}", ['questions' => $rows])->assertOk();
        $this->assertSame('Explain SQL joins.', $res->json('data.version.questions.0.question_text'));

        $v1Fresh = $this->getJson("/api/assessments/{$this->midterm->id}/versions/{$v1['id']}")->assertOk()->json('data.version');
        $this->assertSame('Explain SQL.', $v1Fresh['questions'][0]['question_text']);
        $this->assertSame('Explain SQL.', Question::find($v1['questions'][0]['original_question_id'])->question_text, 'live question untouched');
        $this->assertNotSame($v1Fresh['content_hash'], $res->json('data.version.content_hash'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'ASSESSMENT_VERSION_UPDATED', 'entity_id' => $v2['id']]);
    }

    public function test_editing_the_live_question_never_changes_a_snapshot(): void
    {
        $v1 = $this->createVersion()['version'];
        Question::find($v1['questions'][1]['original_question_id'])->update(['question_text' => 'Explain 2NF and 3NF.']);
        $this->assertSame('Explain normalization.', AssessmentVersionQuestion::find($v1['questions'][1]['id'])->question_text);
    }

    public function test_snapshot_preserves_blueprint_and_rubric_state(): void
    {
        $q1 = Question::where('assessment_id', $this->midterm->id)->first();
        $rubric = Rubric::create(['question_id' => $q1->id, 'assessment_id' => $this->midterm->id, 'created_by' => $this->faculty->id, 'title' => 'Q1 rubric', 'total_marks' => 10, 'status' => 'APPROVED', 'version' => 2, 'generation_method' => 'manual']);
        RubricCriterion::create(['rubric_id' => $rubric->id, 'criterion' => 'Correctness', 'description' => 'Correct answer', 'max_marks' => 6, 'sort_order' => 1]);
        RubricCriterion::create(['rubric_id' => $rubric->id, 'criterion' => 'Clarity', 'description' => 'Clear explanation', 'max_marks' => 4, 'sort_order' => 2]);
        Sanctum::actingAs($this->faculty);
        $bp = $this->postJson("/api/assessments/{$this->midterm->id}/blueprint", ['total_marks' => 30, 'total_questions' => 3, 'constraints' => ['difficulty' => [['key' => 'easy', 'target_percentage' => 30], ['key' => 'medium', 'target_percentage' => 50], ['key' => 'hard', 'target_percentage' => 20]]]])->assertStatus(201)->json('data.blueprint');

        $v1 = $this->createVersion()['version'];
        $this->assertSame($bp['id'], $v1['blueprint']['blueprint_id']);
        $this->assertSame(1, $v1['blueprint']['blueprint_version']);
        $this->assertEquals(30.0, $v1['blueprint']['difficulty_distribution']['easy']['percentage']);
        $this->assertSame($rubric->id, $v1['questions'][0]['rubric_snapshot']['rubric_id']);
        $this->assertSame(2, $v1['questions'][0]['rubric_snapshot']['rubric_version']);
        $this->assertCount(2, $v1['questions'][0]['rubric_snapshot']['criteria']);

        // Later blueprint / rubric edits do not rewrite the snapshot
        AssessmentBlueprint::find($bp['id'])->constraints()->where('target_key', 'easy')->update(['target_percentage' => 60]);
        $rubric->criteria()->delete();
        $again = $this->getJson("/api/assessments/{$this->midterm->id}/versions/{$v1['id']}")->json('data.version');
        $this->assertEquals(30.0, $again['blueprint']['difficulty_distribution']['easy']['percentage']);
        $this->assertCount(2, $again['questions'][0]['rubric_snapshot']['criteria']);
        $this->getJson("/api/assessment-versions/{$v1['id']}/blueprint")->assertOk()->assertJsonPath('data.blueprint.blueprint_version', 1);
    }

    // ------------------------------------------------------------- editing rules

    public function test_draft_edit_updates_metadata_and_recomputes_marks(): void
    {
        $v1 = $this->createVersion()['version'];
        $rows = $this->rowsFrom($v1);
        $rows[] = ['question_text' => 'Write a query using JOIN.', 'question_type' => 'problem_solving', 'marks' => 10, 'difficulty_level' => 'medium', 'cognitive_level' => 'Apply', 'learning_outcome_id' => $this->co2->id];
        $res = $this->putJson("/api/assessment-versions/{$v1['id']}", ['title' => 'Midterm (revised)', 'instructions' => 'Answer all.', 'questions' => $rows])->assertOk()->json('data.version');
        $this->assertSame('Midterm (revised)', $res['title']);
        $this->assertSame(4, $res['question_count']);
        $this->assertEquals(40, $res['total_marks']);
        $this->assertNull($res['questions'][3]['original_question_id']);
        $this->assertSame(4, $res['questions'][3]['question_number']);
    }

    public function test_draft_edit_rejects_foreign_learning_outcome_and_foreign_question(): void
    {
        $v1 = $this->createVersion()['version'];
        $rows = $this->rowsFrom($v1);
        $rows[0]['learning_outcome_id'] = $this->foreignLo->id;
        $this->putJson("/api/assessment-versions/{$v1['id']}", ['questions' => $rows])->assertStatus(422);
        $foreign = Assessment::create(['course_id' => $this->otherCourse->id, 'title' => 'Other', 'type' => 'quiz', 'total_marks' => 10, 'status' => 'draft']);
        $fq = Question::create(['assessment_id' => $foreign->id, 'question_number' => 1, 'question_text' => 'Ohm law', 'marks' => 10]);
        $rows = $this->rowsFrom($v1);
        $rows[0]['original_question_id'] = $fq->id;
        $this->putJson("/api/assessment-versions/{$v1['id']}", ['questions' => $rows])->assertStatus(422);
        $this->assertSame('Explain SQL.', AssessmentVersionQuestion::find($v1['questions'][0]['id'])->question_text, 'failed update rolled back');
    }

    public function test_finalized_and_archived_versions_reject_edits(): void
    {
        $v1 = $this->createVersion()['version'];
        $this->postJson("/api/assessment-versions/{$v1['id']}/finalize")->assertOk()->assertJsonPath('data.version.status', 'FINALIZED');
        $this->putJson("/api/assessment-versions/{$v1['id']}", ['title' => 'Nope'])->assertStatus(409);
        $this->assertSame('Midterm Examination', AssessmentVersion::find($v1['id'])->title);

        $v2 = $this->createVersion(['change_summary' => 'Second'])['version'];
        $this->postJson("/api/assessment-versions/{$v2['id']}/archive")->assertOk()->assertJsonPath('data.version.status', 'ARCHIVED');
        $this->putJson("/api/assessment-versions/{$v2['id']}", ['title' => 'Nope'])->assertStatus(409);
        $this->postJson("/api/assessment-versions/{$v2['id']}/finalize")->assertStatus(409);
        $this->assertSame(2, AssessmentVersion::where('assessment_id', $this->midterm->id)->count(), 'historical versions are never deleted');
    }

    // ------------------------------------------------------------- workflow

    public function test_review_approve_finalize_flow_archives_previous_final_and_logs(): void
    {
        $v1 = $this->createVersion()['version'];
        $this->postJson("/api/assessment-versions/{$v1['id']}/finalize")->assertOk();
        $v2 = $this->createVersion(['change_summary' => 'Second'])['version'];
        $this->postJson("/api/assessment-versions/{$v2['id']}/submit-review")->assertOk()->assertJsonPath('data.version.status', 'IN_REVIEW');
        $this->postJson("/api/assessment-versions/{$v2['id']}/submit-review")->assertStatus(409);
        $this->postJson("/api/assessment-versions/{$v2['id']}/approve")->assertOk()->assertJsonPath('data.version.status', 'APPROVED');
        $this->putJson("/api/assessment-versions/{$v2['id']}", ['title' => 'Nope'])->assertStatus(409);
        $res = $this->postJson("/api/assessment-versions/{$v2['id']}/finalize")->assertOk();
        $this->assertSame('FINALIZED', $res->json('data.version.status'));
        $this->assertNotNull($res->json('data.version.finalized_at'));
        $this->assertSame('ARCHIVED', AssessmentVersion::find($v1['id'])->status);

        $list = $this->getJson("/api/assessments/{$this->midterm->id}/versions")->assertOk();
        $this->assertSame($v2['id'], $list->json('data.current_version_id'));
        $this->assertSame(2, $list->json('data.total_versions'));
        foreach (['ASSESSMENT_VERSION_SUBMITTED', 'ASSESSMENT_VERSION_APPROVED', 'ASSESSMENT_VERSION_FINALIZED', 'ASSESSMENT_VERSION_ARCHIVED'] as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->exists(), "$action logged");
        }
        $this->assertFalse(AuditLog::where('action', 'ASSESSMENT_VERSION_FINALIZED')->get()->contains(fn ($l) => str_contains(json_encode($l->metadata), 'Explain SQL')), 'no question text in audit log');
    }

    public function test_finalization_blocks_on_critical_validation_errors(): void
    {
        $v1 = $this->createVersion()['version'];
        $this->putJson("/api/assessment-versions/{$v1['id']}", ['total_marks' => 99])->assertOk();
        $res = $this->postJson("/api/assessment-versions/{$v1['id']}/finalize")->assertStatus(422);
        $this->assertStringContainsString('cannot be finalized', $res->json('message'));
        $this->assertSame('INVALID', $res->json('data.validation.status'));
        $this->assertContains('TOTAL_MARKS_MISMATCH', array_column($res->json('data.validation.errors'), 'code'));
        $this->assertSame('DRAFT', AssessmentVersion::find($v1['id'])->status);

        // Blueprint mismatch is also critical
        Sanctum::actingAs($this->faculty);
        $this->postJson("/api/assessments/{$this->midterm->id}/blueprint", ['total_marks' => 50, 'total_questions' => 8])->assertStatus(201);
        $v2 = $this->createVersion(['change_summary' => 'With blueprint'])['version'];
        $this->putJson("/api/assessment-versions/{$v2['id']}", ['blueprint_id' => AssessmentBlueprint::where('assessment_id', $this->midterm->id)->value('id'), 'total_marks' => 30])->assertOk();
        $res = $this->postJson("/api/assessment-versions/{$v2['id']}/finalize")->assertStatus(422);
        $codes = array_column($res->json('data.validation.errors'), 'code');
        $this->assertContains('BLUEPRINT_QUESTION_COUNT', $codes);
        $this->assertContains('BLUEPRINT_TOTAL_MARKS', $codes);
        $this->postJson("/api/assessment-versions/{$v2['id']}/validate")->assertOk()->assertJsonPath('data.status', 'INVALID');
    }

    // ------------------------------------------------------------- restore

    public function test_restore_creates_a_new_version_and_never_overwrites(): void
    {
        $v1 = $this->createVersion()['version'];
        $v2 = $this->createVersion(['change_summary' => 'Reword Q1'])['version'];
        $rows = $this->rowsFrom($v2);
        $rows[0]['question_text'] = 'Explain SQL joins.';
        $this->putJson("/api/assessment-versions/{$v2['id']}", ['questions' => $rows])->assertOk();
        $this->postJson("/api/assessment-versions/{$v2['id']}/finalize")->assertOk();

        $res = $this->postJson("/api/assessment-versions/{$v1['id']}/restore")->assertStatus(201);
        $v3 = $res->json('data.version');
        $this->assertSame(3, $v3['version_number']);
        $this->assertSame('v3.0', $v3['version_label']);
        $this->assertSame('DRAFT', $v3['status']);
        $this->assertSame($v1['id'], $v3['based_on_version_id']);
        $this->assertSame('Restored structure from v1.0', $v3['change_summary']);
        $this->assertSame('Explain SQL.', $v3['questions'][0]['question_text']);
        $this->assertSame('FINALIZED', AssessmentVersion::find($v2['id'])->status, 'current version untouched');
        $this->assertSame('Explain SQL joins.', AssessmentVersion::find($v2['id'])->questions()->first()->question_text);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ASSESSMENT_VERSION_RESTORED', 'entity_id' => $v3['id']]);
    }

    // ------------------------------------------------------------- comparison

    public function test_comparison_detects_modified_added_removed_and_replaced_questions(): void
    {
        $v1 = $this->createVersion()['version'];
        $v2 = $this->createVersion(['change_summary' => 'Restructure'])['version'];
        $rows = $this->rowsFrom($v2);
        $rows[0]['question_text'] = 'Explain SQL joins.';               // MODIFIED (text)
        $rows[0]['marks'] = 15;                                          // + marks change
        $rows[1]['cognitive_level'] = 'Analyze';                         // MODIFIED (Bloom)
        $rows[1]['learning_outcome_id'] = $this->co2->id;                // + CO change
        unset($rows[2]);                                                 // Q3 REMOVED
        $rows[] = ['question_number' => 4, 'question_text' => 'Normalize the given relation to 3NF.', 'question_type' => 'problem_solving', 'marks' => 10, 'difficulty_level' => 'hard', 'cognitive_level' => 'Apply', 'learning_outcome_id' => $this->co2->id]; // ADDED Q4
        $rows[] = ['question_number' => 5, 'question_text' => 'Define a transaction.', 'question_type' => 'short_answer', 'marks' => 5, 'difficulty_level' => 'easy', 'cognitive_level' => 'Remember']; // ADDED Q5
        $this->putJson("/api/assessment-versions/{$v2['id']}", ['questions' => array_values($rows)])->assertOk();

        $cmp = $this->getJson("/api/assessment-versions/{$v1['id']}/compare/{$v2['id']}")->assertOk()->json('data');
        $this->assertSame(['added' => 2, 'removed' => 1, 'modified' => 2, 'unchanged' => 0], array_intersect_key($cmp['summary'], array_flip(['added', 'removed', 'modified', 'unchanged'])));
        $this->assertSame('MAJOR', $cmp['summary']['detected_change_type']);
        $this->assertEquals(10, $cmp['marks']['total']['difference']);
        $this->assertEquals(1, $cmp['summary']['question_count_difference']);

        $q1 = collect($cmp['questions']['items'])->first(fn ($i) => $i['status'] === 'MODIFIED' && $i['question_number'] === 1);
        $this->assertSame(['marks', 'question_text'], collect($q1['changes'])->pluck('field')->sort()->values()->all());
        $q2 = collect($cmp['questions']['items'])->first(fn ($i) => $i['status'] === 'MODIFIED' && $i['question_number'] === 2);
        $this->assertSame(['cognitive_level', 'learning_outcome_id'], collect($q2['changes'])->pluck('field')->sort()->values()->all());
        $removed = collect($cmp['questions']['items'])->firstWhere('status', 'REMOVED');
        $this->assertSame('Design a schema.', $removed['from']['question_text']);
        $this->assertSame([4, 5], collect($cmp['questions']['items'])->where('status', 'ADDED')->pluck('question_number')->sort()->values()->all());
        $this->assertContains('CO2', $cmp['mappings']['learning_outcomes']['to']);
        $this->assertEquals(15, collect($cmp['marks']['items'])->firstWhere('question_number', 1)['to']);
        $this->assertSame('Explain SQL.', $q1['from']['question_text']);
        $this->assertSame('Explain SQL joins.', $q1['to']['question_text']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ASSESSMENT_VERSION_COMPARED', 'entity_id' => $v1['id']]);
    }

    /** Spec §28: a brand-new question in the same slot is tracked as a replacement (original_question_id changes). */
    public function test_comparison_detects_a_replaced_question(): void
    {
        $v1 = $this->createVersion()['version'];
        $v2 = $this->createVersion(['change_summary' => 'Replace Q3'])['version'];
        $rows = $this->rowsFrom($v2);
        $rows[2] = ['question_number' => 3, 'question_text' => 'Normalize the given relation to 3NF.', 'question_type' => 'problem_solving', 'marks' => 10, 'difficulty_level' => 'hard', 'cognitive_level' => 'Apply', 'learning_outcome_id' => $this->co2->id];
        $this->putJson("/api/assessment-versions/{$v2['id']}", ['questions' => $rows])->assertOk();
        $cmp = $this->getJson("/api/assessment-versions/{$v1['id']}/compare/{$v2['id']}")->assertOk()->json('data');
        $q3 = collect($cmp['questions']['items'])->firstWhere('question_number', 3);
        $this->assertSame('MODIFIED', $q3['status']);
        $this->assertTrue($q3['replaced']);
        $this->assertSame('Design a schema.', $q3['from']['question_text']);
        $this->assertNull($q3['to']['original_question_id']);
        $this->assertSame(['added' => 0, 'removed' => 0, 'modified' => 1, 'unchanged' => 2], array_intersect_key($cmp['summary'], array_flip(['added', 'removed', 'modified', 'unchanged'])));
    }

    public function test_comparison_reports_wording_only_changes_as_minor(): void
    {
        $v1 = $this->createVersion()['version'];
        $v2 = $this->createVersion(['change_summary' => 'Reword'])['version'];
        $rows = $this->rowsFrom($v2);
        $rows[0]['question_text'] = 'Explain SQL briefly.';
        $this->putJson("/api/assessment-versions/{$v2['id']}", ['questions' => $rows, 'instructions' => 'Answer all questions.'])->assertOk();
        $cmp = $this->getJson("/api/assessment-versions/{$v1['id']}/compare/{$v2['id']}")->assertOk()->json('data');
        $this->assertSame('MINOR', $cmp['summary']['detected_change_type']);
        $this->assertSame(1, $cmp['summary']['modified']);
        $this->assertSame(2, $cmp['summary']['unchanged']);
        $this->assertTrue(collect($cmp['metadata'])->firstWhere('field', 'instructions')['changed']);
    }

    /** Spec §51: v1 8 questions / 50 marks / 30-50-20 vs v2 10 questions / 60 marks / 20-50-30. */
    public function test_marks_and_distribution_comparison_matches_spec_example(): void
    {
        Question::where('assessment_id', $this->midterm->id)->delete();
        $this->midterm->update(['total_marks' => 50]);
        $mk = fn (int $n, string $d) => ['question_text' => "Question {$n}", 'question_type' => 'descriptive', 'marks' => 5, 'difficulty_level' => $d, 'cognitive_level' => 'Apply'];
        $v1 = $this->createVersion()['version'];
        $v1Rows = [];
        foreach (array_merge(array_fill(0, 3, 'easy'), array_fill(0, 4, 'medium'), array_fill(0, 1, 'hard')) as $i => $d) {
            $v1Rows[] = array_replace($mk($i + 1, $d), ['marks' => $i === 7 ? 15 : 5]); // 3×5 + 4×5 + 15 = 50
        }
        $this->putJson("/api/assessment-versions/{$v1['id']}", ['questions' => $v1Rows])->assertOk()->assertJsonPath('data.version.total_marks', 50)->assertJsonPath('data.version.question_count', 8);
        Sanctum::actingAs($this->faculty);
        $bp1 = $this->postJson("/api/assessments/{$this->midterm->id}/blueprint", ['total_marks' => 50, 'total_questions' => 8, 'constraints' => ['difficulty' => [['key' => 'easy', 'target_percentage' => 30], ['key' => 'medium', 'target_percentage' => 50], ['key' => 'hard', 'target_percentage' => 20]]]])->assertStatus(201)->json('data.blueprint');
        $this->putJson("/api/assessment-versions/{$v1['id']}", ['blueprint_id' => $bp1['id']])->assertOk();
        $this->postJson("/api/assessment-versions/{$v1['id']}/finalize")->assertOk();

        $v2 = $this->createVersion(['change_summary' => 'Ten questions, harder'])['version'];
        $v2Rows = [];
        foreach (array_merge(array_fill(0, 2, 'easy'), array_fill(0, 5, 'medium'), array_fill(0, 3, 'hard')) as $i => $d) {
            $v2Rows[] = array_replace($mk($i + 1, $d), ['marks' => 6]); // 10×6 = 60
        }
        $this->postJson("/api/blueprints/{$bp1['id']}/finalize")->assertOk();
        $bp2 = $this->putJson("/api/blueprints/{$bp1['id']}", ['total_marks' => 60, 'total_questions' => 10, 'constraints' => ['difficulty' => [['key' => 'easy', 'target_percentage' => 20], ['key' => 'medium', 'target_percentage' => 50], ['key' => 'hard', 'target_percentage' => 30]]]])->assertOk()->json('data.blueprint');
        $this->assertSame(2, $bp2['version']);
        $this->putJson("/api/assessment-versions/{$v2['id']}", ['questions' => $v2Rows, 'blueprint_id' => $bp2['id']])->assertOk();

        $cmp = $this->getJson("/api/assessment-versions/{$v1['id']}/compare/{$v2['id']}")->assertOk()->json('data');
        $this->assertEquals(2, $cmp['summary']['question_count_difference']);
        $this->assertEquals(10, $cmp['marks']['total']['difference']);
        $planned = collect($cmp['blueprint']['planned']['dimensions']['difficulty']['rows'])->keyBy('key');
        $this->assertEquals(-10, $planned['easy']['difference']);
        $this->assertEquals(10, $planned['hard']['difference']);
        $this->assertEquals(0, $planned['medium']['difference']);
        $this->assertFalse($planned['medium']['changed']);
        $this->assertTrue($cmp['blueprint']['changed']);
        $actual = collect($cmp['blueprint']['profile']['difficulty']['rows'])->keyBy('key');
        $this->assertEquals(37.5, $actual['easy']['from']);
        $this->assertEquals(20.0, $actual['easy']['to']);
        $this->assertEquals(12.5, $actual['hard']['from']);
        $this->assertEquals(30.0, $actual['hard']['to']);
        $this->assertSame(1, $cmp['blueprint']['planned']['from']['blueprint_version']);
        $this->assertSame(2, $cmp['blueprint']['planned']['to']['blueprint_version']);
    }

    public function test_comparison_rejects_versions_of_different_assessments(): void
    {
        $v1 = $this->createVersion()['version'];
        $other = Assessment::create(['course_id' => $this->course->id, 'title' => 'Final', 'type' => 'final', 'total_marks' => 10, 'status' => 'draft']);
        $ov = AssessmentVersion::create(['assessment_id' => $other->id, 'version_number' => 1, 'version_label' => 'v1.0', 'title' => 'Final', 'assessment_type' => 'final', 'created_by' => $this->faculty->id]);
        $this->getJson("/api/assessment-versions/{$v1['id']}/compare/{$ov->id}")->assertStatus(422);
        $this->getJson("/api/assessments/{$this->midterm->id}/versions/{$ov->id}")->assertStatus(404);
    }

    // ------------------------------------------------------------- analysis association

    public function test_analysis_reports_attach_to_the_matching_version_and_report_staleness(): void
    {
        $v1 = $this->createVersion()['version'];
        $report = AnalysisReport::create(['assessment_id' => $this->midterm->id, 'analysis_version' => 1, 'is_current' => true, 'overall_score' => 76, 'analysis_status' => 'completed', 'analyzed_at' => now(), 'total_questions' => 3]);
        $this->assertSame($v1['id'], $report->fresh()->assessment_version_id);
        $res = $this->getJson("/api/assessment-versions/{$v1['id']}/analysis")->assertOk();
        $this->assertSame('CURRENT', $res->json('data.status'));
        $this->assertEquals(76, $res->json('data.latest.overall_score'));

        // Draft changes after analysis → STALE
        $rows = $this->rowsFrom($v1);
        $rows[0]['question_text'] = 'Explain SQL joins.';
        $this->putJson("/api/assessment-versions/{$v1['id']}", ['questions' => $rows])->assertOk();
        $this->getJson("/api/assessment-versions/{$v1['id']}/analysis")->assertOk()->assertJsonPath('data.status', 'STALE');

        // A new analysis of the (unchanged) live questions attaches to the version whose snapshot matches, i.e. not v1 anymore
        $v2 = $this->createVersion(['change_summary' => 'Sync from live'])['version'];
        $this->putJson("/api/assessment-versions/{$v2['id']}", ['sync_from_assessment' => true])->assertOk();
        $r2 = AnalysisReport::create(['assessment_id' => $this->midterm->id, 'analysis_version' => 2, 'is_current' => true, 'overall_score' => 84, 'analysis_status' => 'completed', 'analyzed_at' => now(), 'total_questions' => 3]);
        $this->assertSame($v2['id'], $r2->fresh()->assessment_version_id);
        $this->assertSame($v1['id'], $report->fresh()->assessment_version_id, 'old analysis stays with its version');

        $history = $this->getJson("/api/assessments/{$this->midterm->id}/analysis-history")->assertOk()->json('data.history');
        $byVersion = collect($history)->keyBy('version');
        $this->assertSame('v1.0', $byVersion[1]['assessment_version']['version_label']);
        $this->assertSame('STALE', $byVersion[1]['assessment_version']['analysis_state']);
        $this->assertSame('v2.0', $byVersion[2]['assessment_version']['version_label']);
        $this->assertSame('CURRENT', $byVersion[2]['assessment_version']['analysis_state']);

        $cmp = $this->getJson("/api/assessment-versions/{$v1['id']}/compare/{$v2['id']}")->assertOk()->json('data.analysis');
        $this->assertTrue($cmp['available']);
        $this->assertEquals(8, collect($cmp['metrics'])->firstWhere('key', 'overall_score')['difference']);
    }

    // ------------------------------------------------------------- student protection (spec §50)

    public function test_student_submissions_reference_the_version_and_keep_the_historical_question(): void
    {
        $v1 = $this->createVersion()['version'];
        $this->postJson("/api/assessment-versions/{$v1['id']}/finalize")->assertOk();
        $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'STU001', 'name' => 'Student One']);
        Sanctum::actingAs($this->faculty);
        $sub = $this->postJson("/api/assessments/{$this->midterm->id}/submissions", ['student_id' => $student->id])->assertStatus(201)->json('data');
        $q2 = Question::where('assessment_id', $this->midterm->id)->where('question_number', 2)->first();
        $this->postJson("/api/submissions/{$sub['id']}/answers", ['question_id' => $q2->id, 'answer_text' => 'Normalization removes redundancy.'])->assertStatus(201);

        $submission = StudentSubmission::find($sub['id']);
        $this->assertSame($v1['id'], $submission->assessment_version_id);
        $answer = StudentAnswer::where('student_submission_id', $submission->id)->first();
        $this->assertSame($v1['questions'][1]['id'], $answer->assessment_version_question_id);

        // v2 rewrites Q2 and the live question is edited too; the student's historical question stays the same
        $v2 = $this->createVersion(['change_summary' => 'Rewrite Q2'])['version'];
        $rows = $this->rowsFrom($v2);
        $rows[1]['question_text'] = 'Explain 2NF and 3NF.';
        $this->putJson("/api/assessment-versions/{$v2['id']}", ['questions' => $rows])->assertOk();
        $q2->update(['question_text' => 'Explain 2NF and 3NF.']);

        $this->assertSame('Explain normalization.', $answer->fresh()->versionQuestion->question_text);
        $this->assertSame('Normalization removes redundancy.', $answer->fresh()->answer_text);
        $shown = $this->getJson("/api/submissions/{$sub['id']}")->assertOk()->json('data');
        $this->assertSame('v1.0', $shown['assessment_version']['version_label']);
        $q2Row = collect($shown['questions'])->firstWhere('id', $q2->id);
        $this->assertSame('Explain normalization.', $q2Row['question_text']);
        $this->assertSame('Explain 2NF and 3NF.', $q2Row['current_question_text']);

        // A version with submissions is immutable even if it were somehow editable
        $this->assertTrue(AssessmentVersion::find($v1['id'])->hasSubmissions());
        AssessmentVersion::where('id', $v1['id'])->update(['status' => 'DRAFT']);
        $this->putJson("/api/assessment-versions/{$v1['id']}", ['title' => 'Nope'])->assertStatus(409);
        $this->assertSame(1, $this->getJson("/api/assessments/{$this->midterm->id}/versions")->json('data.versions.1.has_submissions') ? 1 : 0);
    }

    // ------------------------------------------------------------- authorization & isolation

    public function test_authorization_and_course_isolation(): void
    {
        $v1 = $this->createVersion()['version'];

        Sanctum::actingAs($this->other);
        $this->getJson("/api/assessments/{$this->midterm->id}/versions")->assertStatus(403);
        $this->postJson("/api/assessments/{$this->midterm->id}/versions", ['change_summary' => 'hijack'])->assertStatus(403);
        $this->getJson("/api/assessments/{$this->midterm->id}/versions/{$v1['id']}")->assertStatus(403);
        $this->putJson("/api/assessment-versions/{$v1['id']}", ['title' => 'x'])->assertStatus(403);
        $this->postJson("/api/assessment-versions/{$v1['id']}/finalize")->assertStatus(403);
        $this->postJson("/api/assessment-versions/{$v1['id']}/archive")->assertStatus(403);
        $this->postJson("/api/assessment-versions/{$v1['id']}/restore")->assertStatus(403);
        $this->getJson("/api/assessment-versions/{$v1['id']}/compare/{$v1['id']}")->assertStatus(403);
        $this->getJson("/api/assessment-versions/{$v1['id']}/analysis")->assertStatus(403);
        $this->assertSame('DRAFT', AssessmentVersion::find($v1['id'])->status);

        // Reviewer collaborator: read + compare, no state changes
        Sanctum::actingAs($this->reviewer);
        $list = $this->getJson("/api/assessments/{$this->midterm->id}/versions")->assertOk();
        $this->assertFalse($list->json('data.permissions.edit'));
        $this->getJson("/api/assessment-versions/{$v1['id']}/compare/{$v1['id']}")->assertOk();
        $this->postJson("/api/assessments/{$this->midterm->id}/versions", ['change_summary' => 'x'])->assertStatus(403);
        $this->postJson("/api/assessment-versions/{$v1['id']}/finalize")->assertStatus(403);
    }

    // ------------------------------------------------------------- transactions

    public function test_failed_clone_rolls_back_the_whole_version(): void
    {
        $this->createVersion();
        $service = app(AssessmentVersionService::class);
        $before = AssessmentVersion::count();
        try {
            $service->createVersion($this->faculty, $this->midterm->fresh(), ['based_on_version_id' => 999999, 'change_summary' => 'x']);
            $this->fail('expected exception');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame($before, AssessmentVersion::count());

        // Rollback when a question row is invalid mid-way through an update
        $v = AssessmentVersion::first();
        try {
            $service->updateDraftVersion($this->faculty, $v, ['title' => 'Should roll back', 'questions' => [['question_text' => 'ok', 'marks' => 5], ['question_text' => 'bad LO', 'marks' => 5, 'learning_outcome_id' => $this->foreignLo->id]]]);
            $this->fail('expected exception');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame('Midterm Examination', $v->fresh()->title);
        $this->assertSame(3, $v->fresh()->questions()->count());
    }
}
