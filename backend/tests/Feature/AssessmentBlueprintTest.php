<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\AssessmentBlueprint;
use App\Models\AuditLog;
use App\Models\CoPoMapping;
use App\Models\Course;
use App\Models\CourseCollaborator;
use App\Models\LearningOutcome;
use App\Models\PreviousQuestion;
use App\Models\Program;
use App\Models\ProgramOutcome;
use App\Models\Question;
use App\Models\QuestionGenerationRequest;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 37: Assessment Blueprint — deterministic validation, versioning, authorization and blueprint-vs-question comparison.
 */
class AssessmentBlueprintTest extends TestCase
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
    protected LearningOutcome $co3;
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
        $this->co3 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO3', 'description' => 'Optimize queries.', 'sort_order' => 3]);
        $this->foreignLo = LearningOutcome::create(['course_id' => $this->otherCourse->id, 'code' => 'CO9', 'description' => 'Foreign.', 'sort_order' => 1]);
        $this->midterm = Assessment::create(['course_id' => $this->course->id, 'title' => 'Midterm Examination', 'type' => 'midterm', 'total_marks' => 50, 'duration_minutes' => 90, 'status' => 'draft']);
    }

    /** The spec example: 50 marks, 90 min, 8 questions, difficulty 30/50/20, CO 10/50/40, Bloom 25/25/25/25 (matching the plan rows). */
    protected function payload(array $overrides = []): array
    {
        $base = [
            'title' => 'Midterm blueprint', 'total_marks' => 50, 'total_questions' => 8, 'duration_minutes' => 90,
            'sections' => [
                ['title' => 'Section A', 'question_type' => 'mcq', 'question_count' => 4, 'marks_per_question' => 2.5],
                ['title' => 'Section B', 'question_type' => 'problem_solving', 'question_count' => 2, 'marks_per_question' => 10],
                ['title' => 'Section C', 'question_type' => 'descriptive', 'question_count' => 2, 'marks_per_question' => 10],
            ],
            'constraints' => [
                'difficulty' => [['key' => 'easy', 'target_percentage' => 30], ['key' => 'medium', 'target_percentage' => 50], ['key' => 'hard', 'target_percentage' => 20]],
                'cognitive' => [['key' => 'Understand', 'target_percentage' => 25], ['key' => 'Apply', 'target_percentage' => 25], ['key' => 'Analyze', 'target_percentage' => 25], ['key' => 'Evaluate', 'target_percentage' => 25]],
                'learning_outcomes' => [['learning_outcome_id' => $this->co1->id, 'target_percentage' => 10], ['learning_outcome_id' => $this->co2->id, 'target_percentage' => 50], ['learning_outcome_id' => $this->co3->id, 'target_percentage' => 40]],
                'topics' => [['topic' => 'Normalization', 'target_count' => 4, 'target_marks' => 25], ['topic' => 'SQL', 'target_count' => 4, 'target_marks' => 25]],
                'question_types' => [['question_type' => 'mcq', 'target_count' => 4, 'marks_each' => 2.5], ['question_type' => 'problem_solving', 'target_count' => 2, 'marks_each' => 10], ['question_type' => 'descriptive', 'target_count' => 2, 'marks_each' => 10]],
            ],
            'items' => [
                ['section_order' => 1, 'learning_outcome_id' => $this->co1->id, 'question_type' => 'mcq', 'difficulty_level' => 'easy', 'cognitive_level' => 'Understand', 'question_count' => 2, 'marks_each' => 2.5, 'topic' => 'SQL'],
                ['section_order' => 1, 'learning_outcome_id' => $this->co2->id, 'question_type' => 'mcq', 'difficulty_level' => 'medium', 'cognitive_level' => 'Apply', 'question_count' => 2, 'marks_each' => 2.5, 'topic' => 'Normalization'],
                ['section_order' => 2, 'learning_outcome_id' => $this->co2->id, 'question_type' => 'problem_solving', 'difficulty_level' => 'medium', 'cognitive_level' => 'Analyze', 'question_count' => 2, 'marks_each' => 10, 'topic' => 'Normalization'],
                ['section_order' => 3, 'learning_outcome_id' => $this->co3->id, 'question_type' => 'descriptive', 'difficulty_level' => 'hard', 'cognitive_level' => 'Evaluate', 'question_count' => 2, 'marks_each' => 10, 'topic' => 'SQL'],
            ],
        ];
        // Lists are replaced wholesale (array_replace_recursive would merge rows by index)
        foreach ($overrides as $k => $v) {
            if ($k === 'constraints') {
                foreach ($v as $ck => $cv) {
                    $base['constraints'][$ck] = $cv;
                }
            } else {
                $base[$k] = $v;
            }
        }

        return $base;
    }

    protected function createBlueprint(array $overrides = []): array
    {
        Sanctum::actingAs($this->faculty);

        return $this->postJson("/api/assessments/{$this->midterm->id}/blueprint", $this->payload($overrides))->assertStatus(201)->json('data');
    }

    public function test_requires_auth_and_reports_empty_state(): void
    {
        $this->getJson("/api/assessments/{$this->midterm->id}/blueprint")->assertStatus(401);
        Sanctum::actingAs($this->faculty);
        $res = $this->getJson("/api/assessments/{$this->midterm->id}/blueprint")->assertOk();
        $this->assertNull($res->json('data.blueprint'));
        $this->assertTrue($res->json('data.permissions.edit'));
    }

    public function test_creates_and_validates_a_consistent_blueprint(): void
    {
        $data = $this->createBlueprint();
        $bp = $data['blueprint'];
        $this->assertSame(1, $bp['version']);
        $this->assertSame('VALIDATED', $bp['status']);
        $this->assertCount(3, $bp['sections']);
        $this->assertEquals(10.0, $bp['sections'][0]['total_marks']);
        $this->assertCount(4, $bp['items']);
        $this->assertSame(1, $bp['items'][0]['section_order']);
        $v = $data['validation'];
        $this->assertSame('VALID_WITH_WARNINGS', $v['status']); // 8 questions cannot exactly represent 30/50/20 → suggestion warning
        $this->assertSame([], $v['errors']);
        $this->assertStringContainsString('Suggested allocation: 2 Easy / 4 Medium / 2 Hard', collect($v['warnings'])->pluck('message')->implode(' '));
        $this->assertEquals(50, $v['totals']['section_marks']);
        $this->assertSame(8, $v['totals']['section_questions']);
        $this->assertEquals(100, $v['completeness']['score']);
        $this->assertEquals(1.8, $v['time_indicator']['minutes_per_mark']);
        $this->assertSame('TYPICAL', $v['time_indicator']['band']);
        $cov = $data['coverage'];
        $this->assertSame([2, 4, 2], array_column($cov['distributions']['difficulty']['rows'], 'derived_count'));
        $this->assertEquals(5.0, collect($cov['distributions']['learning_outcomes']['rows'])->firstWhere('code', 'CO1')['target_marks']);
        $this->assertFalse($cov['distributions']['program_outcomes']['available']);
        $m = $cov['matrices']['co_x_difficulty'];
        $this->assertSame(['easy', 'medium', 'hard'], $m['columns']);
        $this->assertSame(['CO1' => 2, 'CO2' => 4, 'CO3' => 2], collect($m['rows'])->pluck('total', 'label')->all());
        $this->assertSame(8, $m['total']);
        $this->assertSame(1, AuditLog::where('action', 'BLUEPRINT_CREATED')->count());
    }

    public function test_marks_count_and_percentage_errors_block_finalization(): void
    {
        $data = $this->createBlueprint([
            'total_questions' => 7,
            'sections' => [['title' => 'Section A', 'question_type' => 'mcq', 'question_count' => 4, 'marks_per_question' => 2.5], ['title' => 'Section B', 'question_type' => 'problem_solving', 'question_count' => 2, 'marks_per_question' => 7.5], ['title' => 'Section C', 'question_type' => 'descriptive', 'question_count' => 2, 'marks_per_question' => 10]],
            'constraints' => ['cognitive' => [['key' => 'Understand', 'target_percentage' => 30], ['key' => 'Apply', 'target_percentage' => 40], ['key' => 'Analyze', 'target_percentage' => 30], ['key' => 'Evaluate', 'target_percentage' => 10]],
                'learning_outcomes' => [['learning_outcome_id' => $this->co1->id, 'target_percentage' => 20], ['learning_outcome_id' => $this->co2->id, 'target_percentage' => 40], ['learning_outcome_id' => $this->co3->id, 'target_percentage' => 30]]],
        ]);
        $v = $data['validation'];
        $this->assertSame('INVALID', $v['status']);
        $this->assertSame('DRAFT', $data['blueprint']['status']);
        $messages = collect($v['errors'])->pluck('message')->implode(' | ');
        $this->assertStringContainsString('Allocated section marks: 45. Required marks: 50. Difference: -5.', $messages);
        $this->assertStringContainsString('Question count mismatch: sections plan 8 question(s) but the blueprint specifies 7.', $messages);
        $this->assertStringContainsString('Cognitive level distribution totals 110%', $messages);
        $this->assertStringContainsString('Learning-outcome coverage totals 90%', $messages);
        $this->assertStringContainsString('Question types plan 8 question(s) but the blueprint specifies 7', $messages);
        $this->postJson("/api/blueprints/{$data['blueprint']['id']}/finalize")->assertStatus(422)->assertJsonPath('validation.status', 'INVALID');
        $this->assertSame('DRAFT', AssessmentBlueprint::find($data['blueprint']['id'])->status);
    }

    public function test_evidence_based_warnings_and_recommendations(): void
    {
        $data = $this->createBlueprint([
            'constraints' => ['difficulty' => [['key' => 'easy', 'target_percentage' => 62.5], ['key' => 'medium', 'target_percentage' => 37.5], ['key' => 'hard', 'target_percentage' => 0]],
                'cognitive' => [['key' => 'Understand', 'target_percentage' => 50], ['key' => 'Apply', 'target_percentage' => 50], ['key' => 'Analyze', 'target_percentage' => 0], ['key' => 'Evaluate', 'target_percentage' => 0]],
                'learning_outcomes' => [['learning_outcome_id' => $this->co1->id, 'target_percentage' => 95], ['learning_outcome_id' => $this->co2->id, 'target_percentage' => 5], ['learning_outcome_id' => $this->co3->id, 'target_percentage' => 0]]],
            'items' => [],
        ]);
        $w = collect($data['validation']['warnings'])->pluck('message')->implode(' | ');
        $this->assertSame('VALID_WITH_WARNINGS', $data['validation']['status']);
        $this->assertStringContainsString('Easy questions (62.5%) are above the configured target (30%)', $w);
        $this->assertStringContainsString('No Analyze-level questions planned.', $w);
        $this->assertStringContainsString('CO3 has no planned questions.', $w);
        $this->assertStringContainsString('CO2 has limited planned coverage (5% of marks).', $w);
        $cats = array_column($data['validation']['recommendations'], 'category');
        $this->assertContains('DIFFICULTY', $cats);
        $this->assertContains('COGNITIVE_LEVEL', $cats);
        $this->assertContains('LEARNING_OUTCOME', $cats);
        $this->assertLessThan(100, $data['validation']['completeness']['score']); // no plan rows
    }

    public function test_server_side_input_and_cross_course_checks(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->postJson("/api/assessments/{$this->midterm->id}/blueprint", $this->payload(['total_marks' => -5]))->assertStatus(422);
        $this->postJson("/api/assessments/{$this->midterm->id}/blueprint", $this->payload(['sections' => [['title' => 'A', 'question_count' => 0, 'marks_per_question' => 5]]]))->assertStatus(422);
        $this->postJson("/api/assessments/{$this->midterm->id}/blueprint", $this->payload(['constraints' => ['difficulty' => [['key' => 'easy', 'target_percentage' => 130]]]]))->assertStatus(422);
        $this->postJson("/api/assessments/{$this->midterm->id}/blueprint", $this->payload(['constraints' => ['learning_outcomes' => [['learning_outcome_id' => $this->foreignLo->id, 'target_percentage' => 100]]]]))->assertStatus(422)->assertJsonFragment(['message' => 'Learning outcome targets must reference outcomes of this course.']);
        $this->postJson("/api/assessments/{$this->midterm->id}/blueprint", $this->payload(['constraints' => ['difficulty' => [['key' => 'easy', 'target_percentage' => 50], ['key' => 'easy', 'target_percentage' => 50]]]]))->assertStatus(422)->assertJsonFragment(['message' => 'Duplicate DIFFICULTY target for "easy".']);
        $this->postJson("/api/assessments/{$this->midterm->id}/blueprint", $this->payload(['constraints' => ['program_outcomes' => [['program_outcome_id' => 1, 'target_percentage' => 100]]]]))->assertStatus(422);
        $this->assertSame(0, AssessmentBlueprint::count());
    }

    public function test_program_outcome_blueprint_when_configured(): void
    {
        $program = Program::create(['code' => 'BSCSE', 'name' => 'BSc CSE', 'status' => 'ACTIVE', 'created_by' => $this->faculty->id]);
        $po1 = ProgramOutcome::create(['program_id' => $program->id, 'code' => 'PO1', 'title' => 'Engineering knowledge', 'sort_order' => 1, 'status' => 'ACTIVE']);
        $po2 = ProgramOutcome::create(['program_id' => $program->id, 'code' => 'PO2', 'title' => 'Problem analysis', 'sort_order' => 2, 'status' => 'ACTIVE']);
        $this->course->update(['program_id' => $program->id]);
        $data = $this->createBlueprint(['constraints' => ['program_outcomes' => [['program_outcome_id' => $po1->id, 'target_percentage' => 60], ['program_outcome_id' => $po2->id, 'target_percentage' => 40]]]]);
        $po = $data['coverage']['distributions']['program_outcomes'];
        $this->assertTrue($po['available']);
        $this->assertTrue($po['configured']);
        $this->assertEquals(100, $po['percentage_total']);
        $this->assertSame(['PO1', 'PO2'], array_column($po['rows'], 'code'));
    }

    public function test_update_versioning_and_finalized_immutability(): void
    {
        $data = $this->createBlueprint();
        $id = $data['blueprint']['id'];
        $this->putJson("/api/blueprints/{$id}", $this->payload(['title' => 'Edited']))->assertOk()->assertJsonPath('data.blueprint.title', 'Edited')->assertJsonPath('data.blueprint.version', 1);
        $this->assertSame(1, AuditLog::where('action', 'BLUEPRINT_UPDATED')->count());

        $this->postJson("/api/blueprints/{$id}/finalize")->assertOk()->assertJsonPath('data.blueprint.status', 'FINALIZED');
        $this->assertNotNull(AssessmentBlueprint::find($id)->finalized_at);
        $this->assertSame(1, AuditLog::where('action', 'BLUEPRINT_FINALIZED')->count());
        $this->postJson("/api/blueprints/{$id}/finalize")->assertStatus(409);
        $this->deleteJson("/api/blueprints/{$id}")->assertStatus(409);

        // Editing a finalized blueprint creates version 2 and leaves version 1 untouched
        $res = $this->putJson("/api/blueprints/{$id}", $this->payload(['total_marks' => 60, 'total_questions' => 10, 'sections' => [['title' => 'Only', 'question_type' => 'descriptive', 'question_count' => 10, 'marks_per_question' => 6]],
            'constraints' => ['question_types' => [['question_type' => 'descriptive', 'target_count' => 10, 'marks_each' => 6]], 'topics' => []], 'items' => []]))->assertOk();
        $this->assertSame(2, $res->json('data.blueprint.version'));
        $this->assertEquals(60, $res->json('data.blueprint.total_marks'));
        $v1 = AssessmentBlueprint::find($id);
        $this->assertEquals(50, $v1->total_marks);
        $this->assertSame('ARCHIVED', $v1->status);
        $this->assertFalse($v1->is_current);
        $this->assertSame(1, AuditLog::where('action', 'BLUEPRINT_VERSION_CREATED')->count());
        $this->assertSame([2, 1], array_column($res->json('data.versions'), 'version'));
        $this->assertSame($res->json('data.blueprint.id'), $this->getJson("/api/assessments/{$this->midterm->id}/blueprint")->json('data.blueprint.id'));
        $this->putJson("/api/blueprints/{$id}", $this->payload())->assertStatus(409); // archived versions are read-only? No: finalized → new version; archived → 409
        $this->postJson("/api/assessments/{$this->midterm->id}/blueprint", $this->payload())->assertStatus(409); // draft v2 exists

        // Draft deletion promotes the finalized version back to current
        $this->deleteJson('/api/blueprints/' . $res->json('data.blueprint.id'))->assertOk();
        $this->assertTrue(AssessmentBlueprint::find($id)->fresh()->is_current);
    }

    public function test_authorization_and_course_isolation(): void
    {
        $data = $this->createBlueprint();
        $id = $data['blueprint']['id'];
        Sanctum::actingAs($this->other);
        $this->getJson("/api/assessments/{$this->midterm->id}/blueprint")->assertStatus(403);
        $this->putJson("/api/blueprints/{$id}", $this->payload())->assertStatus(403);
        $this->postJson("/api/blueprints/{$id}/validate")->assertStatus(403);
        $this->postJson("/api/blueprints/{$id}/finalize")->assertStatus(403);
        $this->getJson("/api/blueprints/{$id}/comparison")->assertStatus(403);
        $this->deleteJson("/api/blueprints/{$id}")->assertStatus(403);
        $this->postJson("/api/blueprints/{$id}/generate-questions")->assertStatus(403);
        // Reviewer may view but not edit
        Sanctum::actingAs($this->reviewer);
        $res = $this->getJson("/api/assessments/{$this->midterm->id}/blueprint")->assertOk();
        $this->assertFalse($res->json('data.permissions.edit'));
        $this->getJson("/api/blueprints/{$id}/coverage")->assertOk();
        $this->postJson("/api/blueprints/{$id}/validate")->assertStatus(403);
        $this->putJson("/api/blueprints/{$id}", $this->payload())->assertStatus(403);
        $this->assertSame('VALIDATED', AssessmentBlueprint::find($id)->status);
    }

    public function test_comparison_reflects_actual_question_metadata(): void
    {
        $data = $this->createBlueprint();
        $id = $data['blueprint']['id'];
        Http::fake(); // no AI calls needed for comparison
        // Actual question set: 8 questions matching the plan except one mismatched CO/difficulty
        $spec = [
            ['mcq', 2.5, 'easy', 'Understand', $this->co1->id, ['SQL']], ['mcq', 2.5, 'easy', 'Understand', $this->co1->id, ['SQL']],
            ['mcq', 2.5, 'medium', 'Apply', $this->co2->id, ['Normalization']], ['mcq', 2.5, 'medium', 'Apply', $this->co2->id, ['Normalization']],
            ['problem_solving', 10, 'medium', 'Analyze', $this->co2->id, ['Normalization']], ['problem_solving', 10, 'medium', 'Analyze', $this->co2->id, ['Normalization']],
            ['descriptive', 10, 'hard', 'Evaluate', $this->co3->id, ['SQL']], ['descriptive', 10, 'hard', 'Evaluate', $this->co3->id, ['SQL']],
        ];
        $qs = [];
        foreach ($spec as $i => [$type, $marks, $d, $c, $lo, $topics]) {
            $qs[] = Question::create(['assessment_id' => $this->midterm->id, 'question_number' => $i + 1, 'question_text' => "Question " . ($i + 1), 'question_type' => $type, 'marks' => $marks, 'difficulty_level' => $d, 'cognitive_level' => $c, 'learning_outcome_id' => $lo, 'ai_topics' => $topics]);
        }
        $cmp = $this->getJson("/api/blueprints/{$id}/comparison")->assertOk()->json('data');
        $this->assertTrue($cmp['actual']['has_questions']);
        $this->assertEquals(100, $cmp['compliance_percent'], json_encode(collect($cmp['dimensions'])->flatMap(fn ($d) => $d['rows'])->where('status', 'MISMATCH')->values()));
        $this->assertSame(['MATCH', 'MATCH'], array_column($cmp['structure'], 'status'));
        $this->assertSame('CLOSE', $cmp['summary']['difficulty']); // 2/4/2 of 8 vs 30/50/20 → within tolerance
        $this->assertSame('MATCH', $cmp['summary']['learning_outcomes']);
        $this->assertSame('MATCH', $cmp['summary']['topics']);
        $this->assertSame('NOT_CONFIGURED', $cmp['summary']['program_outcomes']);
        $hard = collect($cmp['dimensions']['difficulty']['rows'])->firstWhere('key', 'hard');
        $this->assertEquals(25.0, $hard['actual_percentage']); // 2/8 vs target 20 → within tolerance 5 → CLOSE
        $this->assertSame('CLOSE', $hard['status']);

        // REAL-DATA: Medium → Hard on Q3 changes difficulty; CO2 → CO3 on Q5 changes CO coverage
        $qs[2]->update(['difficulty_level' => 'hard']);
        $qs[4]->update(['learning_outcome_id' => $this->co3->id]);
        $cmp2 = $this->getJson("/api/blueprints/{$id}/comparison?sync_recommendations=1")->assertOk()->json('data');
        $rows = collect($cmp2['dimensions']['difficulty']['rows'])->keyBy('key');
        $this->assertEquals(37.5, $rows['hard']['actual_percentage']);
        $this->assertSame('MISMATCH', $rows['hard']['status']);
        $this->assertSame('MISMATCH', $cmp2['summary']['difficulty']);
        $co = collect($cmp2['dimensions']['learning_outcomes']['rows'])->keyBy('label');
        $this->assertEquals(30.0, $co['CO2']['actual_percentage']); // Q3+Q4+Q6 = 2.5+2.5+10 of 50
        $this->assertLessThan(100, $cmp2['compliance_percent']);
        // Nothing was modified automatically
        $this->assertSame('hard', $qs[2]->fresh()->difficulty_level);
        $this->assertSame(8, Question::where('assessment_id', $this->midterm->id)->count());
        $this->assertSame('draft', $this->midterm->fresh()->status);
        // Recommendations are only attached when an analysis report exists
        $this->assertSame(0, $cmp2['recommendations_sync']['created']);
        AnalysisReport::create(['assessment_id' => $this->midterm->id, 'analysis_version' => 1, 'is_current' => true, 'overall_score' => 80, 'analysis_status' => 'completed', 'analyzed_at' => now()]);
        $cmp3 = $this->getJson("/api/blueprints/{$id}/comparison?sync_recommendations=1")->json('data');
        $this->assertGreaterThan(0, $cmp3['recommendations_sync']['created']);
        $count = Recommendation::where('source_metric', 'Assessment Blueprint')->count();
        $this->getJson("/api/blueprints/{$id}/comparison?sync_recommendations=1");
        $this->assertSame($count, Recommendation::where('source_metric', 'Assessment Blueprint')->count()); // deduplicated
        $this->assertGreaterThanOrEqual(2, AuditLog::where('action', 'BLUEPRINT_QUESTION_VALIDATED')->count());
    }

    public function test_question_bank_validation_reports_failed_constraints(): void
    {
        $data = $this->createBlueprint();
        $id = $data['blueprint']['id'];
        $match = Question::create(['assessment_id' => $this->midterm->id, 'question_number' => 1, 'question_text' => 'Decompose the relation into 3NF.', 'question_type' => 'problem_solving', 'marks' => 10, 'difficulty_level' => 'medium', 'cognitive_level' => 'Analyze', 'learning_outcome_id' => $this->co2->id, 'ai_topics' => ['Normalization']]);
        $mismatch = Question::create(['assessment_id' => $this->midterm->id, 'question_number' => 2, 'question_text' => 'Hard apply CO1 question.', 'question_type' => 'problem_solving', 'marks' => 10, 'difficulty_level' => 'hard', 'cognitive_level' => 'Apply', 'learning_outcome_id' => $this->co1->id, 'ai_topics' => ['Normalization']]);
        $prev = PreviousQuestion::create(['user_id' => $this->faculty->id, 'course_id' => $this->course->id, 'question_text' => 'Old MCQ', 'question_type' => 'mcq', 'marks' => 2.5, 'difficulty_level' => 'easy', 'cognitive_level' => 'Understand', 'source' => 'previous_exam']);
        $foreign = Question::create(['assessment_id' => Assessment::create(['course_id' => $this->otherCourse->id, 'title' => 'X', 'type' => 'quiz', 'total_marks' => 10, 'status' => 'draft'])->id, 'question_number' => 1, 'question_text' => 'Foreign', 'question_type' => 'mcq', 'marks' => 1]);

        $res = $this->postJson("/api/blueprints/{$id}/validate-questions", ['question_ids' => [$match->id, $mismatch->id, $foreign->id], 'previous_question_ids' => [$prev->id]])->assertOk()->json('data');
        $this->assertSame(3, $res['evaluated']); // foreign question silently excluded
        $byId = collect($res['results'])->keyBy(fn ($r) => $r['source'] . ':' . $r['id']);
        $this->assertSame('MATCH', $byId["question:{$match->id}"]['status']);
        $mm = $byId["question:{$mismatch->id}"];
        $this->assertSame('CONSTRAINT_MISMATCH', $mm['status']);
        $this->assertEqualsCanonicalizing(['difficulty', 'cognitive_level', 'learning_outcome'], $mm['failed_constraints']);
        $this->assertSame('medium', $mm['checks']['difficulty']['target']);
        $pq = $byId["previous_question:{$prev->id}"];
        $this->assertContains('learning_outcome', $pq['failed_constraints']); // bank questions carry no CO
        $this->postJson("/api/blueprints/{$id}/validate-questions", [])->assertStatus(422);
    }

    public function test_generates_step33_requests_from_a_finalized_blueprint(): void
    {
        Queue::fake();
        $data = $this->createBlueprint();
        $id = $data['blueprint']['id'];
        $this->postJson("/api/blueprints/{$id}/generate-questions")->assertStatus(422); // must be finalized first
        $this->postJson("/api/blueprints/{$id}/finalize")->assertOk();
        $res = $this->postJson("/api/blueprints/{$id}/generate-questions", ['language' => 'English'])->assertStatus(202)->json('data');
        // Plan rows group by CO/topic: CO1+SQL, CO2+Normalization, CO3+SQL → 3 STEP 33 requests, 8 questions total
        $this->assertCount(3, $res['requests']);
        $this->assertSame(8, array_sum(array_column($res['requests'], 'number_of_questions')));
        $reqs = QuestionGenerationRequest::where('assessment_id', $this->midterm->id)->get();
        $this->assertCount(3, $reqs);
        $co2 = $reqs->firstWhere('learning_outcome_id', $this->co2->id);
        $this->assertSame('Normalization', $co2->topic);
        $this->assertSame(4, $co2->number_of_questions);
        $this->assertEquals([['difficulty_level' => 'medium', 'cognitive_level' => 'Apply', 'question_type' => 'mcq', 'marks' => 2.5, 'count' => 2], ['difficulty_level' => 'medium', 'cognitive_level' => 'Analyze', 'question_type' => 'problem_solving', 'marks' => 10.0, 'count' => 2]], $co2->blueprint);
        $this->assertSame('PENDING', $co2->generation_status);
        $this->assertSame(0, Question::where('assessment_id', $this->midterm->id)->count()); // nothing added to the assessment
        $this->assertSame(1, AuditLog::where('action', 'BLUEPRINT_QUESTION_GENERATION_STARTED')->count());
        // Reviewer cannot generate
        Sanctum::actingAs($this->reviewer);
        $this->postJson("/api/blueprints/{$id}/generate-questions")->assertStatus(403);
    }

    public function test_analytics_and_report_expose_blueprint_compliance(): void
    {
        $data = $this->createBlueprint();
        Question::create(['assessment_id' => $this->midterm->id, 'question_number' => 1, 'question_text' => 'Q', 'question_type' => 'mcq', 'marks' => 50, 'difficulty_level' => 'hard', 'cognitive_level' => 'Create', 'learning_outcome_id' => $this->co1->id]);
        $an = $this->getJson('/api/analytics/overview')->assertOk()->json('data.blueprint_compliance');
        $this->assertSame(1, $an['assessments_with_blueprint']);
        $this->assertSame($data['blueprint']['id'], $an['rows'][0]['blueprint_id']);
        $this->assertSame('MISMATCH', $an['rows'][0]['summary']['difficulty']);
        $this->assertNotNull($an['average_compliance']);
        AnalysisReport::create(['assessment_id' => $this->midterm->id, 'analysis_version' => 1, 'is_current' => true, 'overall_score' => 80, 'analysis_status' => 'completed', 'analyzed_at' => now(), 'findings' => []]);
        $report = app(\App\Services\AssessmentReportService::class)->buildReportData($this->midterm->fresh());
        $this->assertSame(1, $report['assessment_blueprint']['version']);
        $this->assertCount(3, $report['assessment_blueprint']['sections']);
        $this->assertArrayHasKey('comparison', $report['assessment_blueprint']);
    }
}
