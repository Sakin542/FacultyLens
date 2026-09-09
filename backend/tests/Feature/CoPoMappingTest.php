<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\CoPoMapping;
use App\Models\CoPoMappingAnalysisRun;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\Program;
use App\Models\ProgramOutcome;
use App\Models\Question;
use App\Models\QuestionCoMapping;
use App\Models\QuestionLearningOutcomeAlignment;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 31: CO/PO Mapping Validator. Deterministic; learning_outcomes act as COs.
 */
class CoPoMappingTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected User $other;
    protected Program $program;
    protected array $po = [];
    protected Course $course;
    protected array $co = [];
    protected Assessment $assessment;
    protected array $q = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['performance.expected_performance_percent' => 70, 'performance.min_responses_for_gap_analysis' => 5]);

        $this->faculty = User::factory()->create(['email' => 'faculty@university.edu']);
        $this->other = User::factory()->create(['email' => 'other@university.edu']);

        $this->program = Program::create(['code' => 'CSE', 'name' => 'Computer Science and Engineering', 'created_by' => $this->faculty->id]);
        foreach (['Engineering Knowledge', 'Problem Analysis', 'Design / Development', 'Investigation', 'Modern Tool Usage'] as $i => $title) {
            $this->po[$i + 1] = ProgramOutcome::create(['program_id' => $this->program->id, 'code' => 'PO' . ($i + 1), 'title' => $title, 'sort_order' => $i + 1]);
        }

        $this->course = Course::create(['user_id' => $this->faculty->id, 'program_id' => $this->program->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026']);
        $levels = ['Understand', 'Analyze', 'Apply', 'Create'];
        foreach ([1, 2, 3, 4] as $i) {
            $this->co[$i] = LearningOutcome::create(['course_id' => $this->course->id, 'code' => "LO{$i}", 'description' => "Outcome {$i}", 'cognitive_level' => $levels[$i - 1], 'sort_order' => $i]);
        }

        $this->assessment = Assessment::create(['course_id' => $this->course->id, 'title' => 'Midterm Examination', 'type' => 'midterm', 'total_marks' => 100, 'status' => 'draft']);
        // Q1,Q2 -> CO1 (20+20), Q3,Q4 -> CO2 (20+20), Q5 -> CO3 (18), Q6 -> CO4 (2)  => 100 marks
        $spec = [[1, 20, 1, 'Understand'], [2, 20, 1, 'Remember'], [3, 20, 2, 'Analyze'], [4, 20, 2, 'Remember'], [5, 18, 3, 'Apply'], [6, 2, 4, 'Create']];
        foreach ($spec as [$n, $marks, $coIdx, $cog]) {
            $this->q[$n] = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => $n, 'question_text' => "Question {$n}", 'question_type' => 'descriptive', 'marks' => $marks, 'cognitive_level' => $cog, 'learning_outcome_id' => $this->co[$coIdx]->id]);
        }
    }

    protected function map(int $co, int $po, int $level): CoPoMapping
    {
        return CoPoMapping::create(['course_id' => $this->course->id, 'learning_outcome_id' => $this->co[$co]->id, 'program_outcome_id' => $this->po[$po]->id, 'mapping_level' => $level, 'created_by' => $this->faculty->id]);
    }

    protected function seedMatrix(): void
    {
        $this->map(1, 1, 3); $this->map(1, 2, 2);
        $this->map(2, 2, 3); $this->map(2, 3, 2);
        $this->map(3, 3, 3); $this->map(3, 4, 2);
        $this->map(4, 4, 3); $this->map(4, 5, 1);
    }

    protected function url(string $suffix = ''): string
    {
        return "/api/courses/{$this->course->id}/co-po-mapping{$suffix}";
    }

    // ------------------------------------------------------------ coverage/matrix

    public function test_co_coverage_matrix_density_and_po_evidence(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedMatrix();
        $res = $this->postJson($this->url('/analyze'))->assertStatus(200)->assertJsonPath('status', 'success');
        $cov = collect($res->json('data.co_coverage'))->keyBy('display_code');

        $this->assertEquals(40.0, $cov['CO1']['coverage_percent']);
        $this->assertEquals(40.0, $cov['CO1']['mapped_marks']);
        $this->assertSame(2, $cov['CO1']['question_count']);
        $this->assertEquals(40.0, $cov['CO2']['coverage_percent']);
        $this->assertEquals(18.0, $cov['CO3']['coverage_percent']);
        $this->assertEquals(2.0, $cov['CO4']['coverage_percent']);
        $this->assertSame('LOW_COVERAGE', $cov['CO4']['coverage_status']);
        $this->assertSame('NO_PERFORMANCE_DATA', $cov['CO1']['status']);

        $matrix = $res->json('data.matrix');
        $this->assertSame(8, $matrix['active_mappings']);
        $this->assertSame(20, $matrix['possible_mappings']);
        $this->assertEquals(40.0, $matrix['density_percent']);
        $this->assertSame(3, $matrix['rows'][0]['cells'][0]['level']); // CO1->PO1 HIGH
        $this->assertSame(0, $matrix['rows'][0]['cells'][2]['level']);
        $this->assertSame('CO1', $matrix['rows'][0]['code']);

        $po = collect($res->json('data.po_evidence'))->keyBy('code');
        // PO2: CO1 (40% × 2/3) + CO2 (40% × 1) = 66.67 ; evidence 80%
        $this->assertEquals(66.67, $po['PO2']['contribution_percent']);
        $this->assertEquals(80.0, $po['PO2']['assessment_evidence_percent']);
        $this->assertSame('HIGH', $po['PO2']['co_evidence']);
        $this->assertSame('ASSESSED', $po['PO2']['evidence_status']);
        // PO5: CO4 only (2% × 1/3)
        $this->assertEquals(0.67, $po['PO5']['contribution_percent']);
        $this->assertSame('LIMITED_EVIDENCE', $po['PO5']['evidence_status']);
        $this->assertSame('LOW', $po['PO5']['co_evidence']);
        $this->assertNull($po['PO5']['student_performance_percent']);

        $summary = $res->json('data.summary');
        $this->assertSame(4, $summary['co_count']);
        $this->assertSame(5, $summary['po_count']);
        $this->assertSame(6, $summary['questions_mapped']);
        $this->assertEquals(100.0, $summary['question_mapping_percent']);
        $this->assertEquals(100.0, $summary['co_coverage_percent']);
        $this->assertEquals(80.0, $summary['po_evidence_percent']);
    }

    public function test_question_mapped_to_two_cos_shares_marks(): void
    {
        Sanctum::actingAs($this->faculty);
        QuestionCoMapping::create(['question_id' => $this->q[6]->id, 'learning_outcome_id' => $this->co[3]->id, 'mapping_source' => 'FACULTY', 'status' => 'CONFIRMED']);
        $cov = collect($this->postJson($this->url('/analyze'))->json('data.co_coverage'))->keyBy('display_code');
        $this->assertEquals(19.0, $cov['CO3']['mapped_marks']);
        $this->assertEquals(1.0, $cov['CO4']['mapped_marks']);
        $this->assertEquals(100.0, collect($cov)->sum('coverage_percent'));
    }

    // -------------------------------------------------------------- findings

    public function test_findings_unmapped_question_unassessed_co_low_coverage_and_mapping_review(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->q[6]->update(['learning_outcome_id' => null]); // CO4 unassessed, Q6 unmapped
        $this->map(1, 1, 3);
        $res = $this->postJson($this->url('/analyze'))->assertStatus(200);
        $findings = collect($res->json('data.findings'));
        $types = $findings->pluck('type')->all();

        $this->assertContains('UNMAPPED_QUESTION', $types);
        $unmapped = $findings->firstWhere('type', 'UNMAPPED_QUESTION');
        $this->assertSame('HIGH', $unmapped['severity']);
        $this->assertSame('high', $unmapped['priority']);
        $this->assertSame([$this->q[6]->id], $unmapped['evidence']['question_ids']);
        $this->assertSame($this->q[6]->id, $unmapped['question_id']);

        $unassessed = $findings->firstWhere('type', 'UNASSESSED_CO');
        $this->assertSame($this->co[4]->id, $unassessed['course_outcome_id']);
        $this->assertSame('MEDIUM', $unassessed['severity']);
        $this->assertStringContainsString('at least one assessment question', $unassessed['recommendation']);

        $review = $findings->where('type', 'MAPPING_REVIEW');
        $this->assertSame(3, $review->count()); // CO2, CO3, CO4 have no PO mapping
        $this->assertContains('UNMAPPED_PO', $types);
        $this->assertSame(4, $findings->where('type', 'UNMAPPED_PO')->count());

        // Findings are sorted by severity and never claim accreditation
        $this->assertSame('HIGH', $findings->first()['severity']);
        $this->assertStringNotContainsString('accredit', strtolower(json_encode($findings->all())));
        $this->assertStringNotContainsString('invalid', strtolower(json_encode($findings->pluck('title')->all())));
    }

    public function test_low_coverage_and_concentration_and_density_findings(): void
    {
        Sanctum::actingAs($this->faculty);
        // Move Q3,Q4,Q5 to CO1 -> CO1 = 98%, CO2/CO3 unassessed, CO4 = 2% low
        $this->q[3]->update(['learning_outcome_id' => $this->co[1]->id]);
        $this->q[4]->update(['learning_outcome_id' => $this->co[1]->id]);
        $this->q[5]->update(['learning_outcome_id' => $this->co[1]->id]);
        foreach ([1, 2, 3, 4] as $co) {
            foreach ([1, 2, 3, 4, 5] as $po) {
                $this->map($co, $po, 3);
            }
        }
        $findings = collect($this->postJson($this->url('/analyze'))->json('data.findings'));
        $this->assertNotNull($findings->firstWhere('type', 'CO_CONCENTRATION'));
        $this->assertEquals(98.0, $findings->firstWhere('type', 'CO_CONCENTRATION')['evidence']['coverage_percent']);
        $low = $findings->firstWhere('type', 'LOW_CO_COVERAGE');
        $this->assertSame($this->co[4]->id, $low['course_outcome_id']);
        $density = $findings->firstWhere('type', 'MAPPING_DENSITY');
        $this->assertEquals(100.0, $density['evidence']['density_percent']);
        $this->assertSame('INFO', $density['severity']);
    }

    public function test_cognitive_mismatch_finding(): void
    {
        Sanctum::actingAs($this->faculty);
        // CO2 (Analyze): Q3 Analyze 20 + Q4 Remember 20 -> 50% lower (< 60 threshold) -> no finding
        // Make Q3 Remember too -> 100% lower -> finding
        $this->q[3]->update(['cognitive_level' => 'Remember']);
        $findings = collect($this->postJson($this->url('/analyze'))->json('data.findings'));
        $mismatch = $findings->where('type', 'CO_COGNITIVE_MISMATCH');
        $this->assertSame(1, $mismatch->count());
        $this->assertSame($this->co[2]->id, $mismatch->first()['course_outcome_id']);
        $this->assertEquals(100.0, $mismatch->first()['evidence']['lower_level_percent']);
        $this->assertStringContainsString('not a certainty', $mismatch->first()['recommendation']);
    }

    public function test_step30_performance_is_incorporated_into_co_and_po(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedMatrix();
        // 5 students finalized: CO1 questions (Q1,Q2) at 90%, CO2 questions (Q3,Q4) at 50%
        foreach (range(1, 5) as $i) {
            $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => "STU00{$i}", 'name' => "S{$i}"]);
            $sub = StudentSubmission::create(['assessment_id' => $this->assessment->id, 'student_id' => $student->id, 'status' => 'GRADED', 'grading_status' => 'FACULTY_REVIEWED', 'total_marks' => 100]);
            foreach ([1 => 18, 2 => 18, 3 => 10, 4 => 10] as $n => $marks) {
                StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $this->q[$n]->id, 'answer_type' => 'TEXT', 'answer_text' => 'a', 'awarded_marks' => $marks, 'answer_status' => 'REVIEWED']);
            }
        }
        $res = $this->postJson($this->url('/analyze'))->assertStatus(200);
        $cov = collect($res->json('data.co_coverage'))->keyBy('display_code');
        $this->assertEquals(90.0, $cov['CO1']['performance_percent']);
        $this->assertSame('STRONG', $cov['CO1']['performance_status']);
        $this->assertSame('STRONG', $cov['CO1']['status']);
        $this->assertEquals(50.0, $cov['CO2']['performance_percent']);
        $this->assertEquals(20.0, $cov['CO2']['performance_gap']);
        $this->assertSame('REVIEW', $cov['CO2']['status']);
        $this->assertNull($cov['CO3']['performance_percent']);
        $this->assertSame('NO_PERFORMANCE_DATA', $cov['CO3']['status']);

        $gap = collect($res->json('data.findings'))->firstWhere('type', 'CO_PERFORMANCE_GAP');
        $this->assertSame($this->co[2]->id, $gap['course_outcome_id']);
        $this->assertStringContainsString('do not establish causes', $gap['recommendation']);

        $po = collect($res->json('data.po_evidence'))->keyBy('code');
        // PO2 = CO1 + CO2 questions: (180+100)/(200+200) = 70%
        $this->assertEquals(70.0, $po['PO2']['student_performance_percent']);
        $this->assertSame('EVIDENCE_AVAILABLE', $po['PO2']['status']);
        $this->assertEquals(50.0, $po['PO3']['student_performance_percent']); // CO2 + CO3(no data)
        $this->assertSame('REVIEW', $po['PO3']['status']);

        // Grades are untouched
        $this->assertSame(0, StudentAnswer::where('answer_status', '!=', 'REVIEWED')->count());
    }

    // ------------------------------------------------------- question <-> CO

    public function test_ai_suggestion_is_pending_until_faculty_confirms(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->q[6]->update(['learning_outcome_id' => null]);
        $report = AnalysisReport::create(['assessment_id' => $this->assessment->id, 'analysis_status' => 'completed', 'analysis_version' => 1, 'is_current' => true]);
        QuestionLearningOutcomeAlignment::create(['analysis_report_id' => $report->id, 'question_id' => $this->q[6]->id, 'learning_outcome_id' => $this->co[2]->id, 'similarity_score' => 0.82, 'alignment' => 'STRONG_ALIGNMENT']);

        $list = collect($this->getJson($this->url('/question-mappings'))->assertStatus(200)->json('data'))->keyBy('question_number');
        $q6 = $list[6];
        $this->assertFalse($q6['is_mapped']);
        $this->assertSame([], $q6['confirmed']);
        $this->assertSame('PENDING', $q6['ai_suggestions'][0]['status']);
        $this->assertSame('AI_SUGGESTED', $q6['ai_suggestions'][0]['mapping_source']);
        $this->assertEquals(0.82, $q6['ai_suggestions'][0]['similarity_score']);
        $this->assertSame('CO2', $q6['ai_suggestions'][0]['code']);
        // Faculty LO on Q1 shows as confirmed FACULTY
        $this->assertSame('FACULTY', $list[1]['confirmed'][0]['source']);

        // Analysis before confirmation: Q6 unmapped
        $before = $this->postJson($this->url('/analyze'))->json('data');
        $this->assertSame(5, $before['summary']['questions_mapped']);
        $this->assertEquals(0.0, collect($before['co_coverage'])->firstWhere('display_code', 'CO4')['coverage_percent']);

        // Confirm -> official
        $this->postJson("/api/questions/{$this->q[6]->id}/co-mappings/confirm", ['learning_outcome_id' => $this->co[2]->id])
            ->assertStatus(200)->assertJsonPath('data.mapping_source', 'FACULTY')->assertJsonPath('data.status', 'CONFIRMED');
        $this->assertDatabaseHas('audit_logs', ['action' => 'QUESTION_CO_MAPPING_CONFIRMED']);

        $list = collect($this->getJson($this->url('/question-mappings'))->json('data'))->keyBy('question_number');
        $this->assertTrue($list[6]['is_mapped']);
        $this->assertSame('CONFIRMED', $list[6]['ai_suggestions'][0]['status']);

        $after = $this->postJson($this->url('/analyze'))->json('data');
        $this->assertSame(6, $after['summary']['questions_mapped']);
        $this->assertEquals(42.0, collect($after['co_coverage'])->firstWhere('display_code', 'CO2')['coverage_percent']);

        // Reject removes it again
        $this->postJson("/api/questions/{$this->q[6]->id}/co-mappings/reject", ['learning_outcome_id' => $this->co[2]->id])->assertStatus(200)->assertJsonPath('data.status', 'REJECTED');
        $this->assertSame(5, $this->postJson($this->url('/analyze?force=1'))->json('data.summary.questions_mapped'));
    }

    public function test_confirm_rejects_co_from_another_course(): void
    {
        Sanctum::actingAs($this->faculty);
        $otherCourse = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE202', 'course_name' => 'Other', 'semester' => 'Fall', 'academic_year' => '2026']);
        $foreignLo = LearningOutcome::create(['course_id' => $otherCourse->id, 'code' => 'LO1', 'description' => 'x', 'sort_order' => 1]);
        $this->postJson("/api/questions/{$this->q[1]->id}/co-mappings/confirm", ['learning_outcome_id' => $foreignLo->id])->assertStatus(422);
    }

    // ------------------------------------------------------------- mappings API

    public function test_mapping_crud_validates_course_and_program_membership(): void
    {
        Sanctum::actingAs($this->faculty);
        $res = $this->postJson("/api/courses/{$this->course->id}/co-po-mappings", ['learning_outcome_id' => $this->co[1]->id, 'program_outcome_id' => $this->po[1]->id, 'mapping_level' => 3, 'justification' => 'Core knowledge'])
            ->assertStatus(201)->assertJsonPath('data.mapping_level', 3)->assertJsonPath('data.level_label', 'HIGH');
        $id = $res->json('data.id');
        // upsert on same pair
        $this->postJson("/api/courses/{$this->course->id}/co-po-mappings", ['learning_outcome_id' => $this->co[1]->id, 'program_outcome_id' => $this->po[1]->id, 'mapping_level' => 1])->assertStatus(200)->assertJsonPath('data.id', $id);
        $this->putJson("/api/co-po-mappings/{$id}", ['mapping_level' => 2])->assertStatus(200)->assertJsonPath('data.level_label', 'MEDIUM');
        $this->postJson("/api/courses/{$this->course->id}/co-po-mappings", ['learning_outcome_id' => $this->co[1]->id, 'program_outcome_id' => $this->po[1]->id, 'mapping_level' => 7])->assertStatus(422);

        // PO from an unrelated program
        $otherProgram = Program::create(['code' => 'EEE', 'name' => 'Electrical', 'created_by' => $this->faculty->id]);
        $foreignPo = ProgramOutcome::create(['program_id' => $otherProgram->id, 'code' => 'PO1', 'title' => 'x']);
        $this->postJson("/api/courses/{$this->course->id}/co-po-mappings", ['learning_outcome_id' => $this->co[1]->id, 'program_outcome_id' => $foreignPo->id, 'mapping_level' => 2])->assertStatus(422);

        // LO from another course
        $otherCourse = Course::create(['user_id' => $this->faculty->id, 'program_id' => $this->program->id, 'course_code' => 'CSE202', 'course_name' => 'Other', 'semester' => 'Fall', 'academic_year' => '2026']);
        $foreignLo = LearningOutcome::create(['course_id' => $otherCourse->id, 'code' => 'LO1', 'description' => 'x', 'sort_order' => 1]);
        $this->postJson("/api/courses/{$this->course->id}/co-po-mappings", ['learning_outcome_id' => $foreignLo->id, 'program_outcome_id' => $this->po[1]->id, 'mapping_level' => 2])->assertStatus(422);

        // Course without a program
        $this->course->update(['program_id' => null]);
        $this->postJson("/api/courses/{$this->course->id}/co-po-mappings", ['learning_outcome_id' => $this->co[2]->id, 'program_outcome_id' => $this->po[1]->id, 'mapping_level' => 2])->assertStatus(422);
        $this->course->update(['program_id' => $this->program->id]);

        $this->deleteJson("/api/co-po-mappings/{$id}")->assertStatus(200);
        $this->assertSame(0, CoPoMapping::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'CO_PO_MAPPING_SAVED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CO_PO_MAPPING_DELETED']);
    }

    public function test_program_and_outcome_management(): void
    {
        Sanctum::actingAs($this->faculty);
        $p = $this->postJson('/api/programs', ['code' => 'ME', 'name' => 'Mechanical'])->assertStatus(201)->json('data');
        $this->postJson('/api/programs', ['code' => 'ME', 'name' => 'Dup'])->assertStatus(422);
        $po = $this->postJson("/api/programs/{$p['id']}/outcomes", ['code' => 'PO1', 'title' => 'Knowledge'])->assertStatus(201)->json('data');
        $this->postJson("/api/programs/{$p['id']}/outcomes", ['code' => 'PO1', 'title' => 'Dup'])->assertStatus(422);
        $this->putJson("/api/program-outcomes/{$po['id']}", ['title' => 'Engineering Knowledge'])->assertStatus(200)->assertJsonPath('data.title', 'Engineering Knowledge');
        $this->getJson('/api/programs')->assertStatus(200)->assertJsonCount(2, 'data');
        $this->deleteJson("/api/program-outcomes/{$po['id']}")->assertStatus(200);
        $this->deleteJson("/api/programs/{$p['id']}")->assertStatus(200);
        // Program linked to a course cannot be deleted
        $this->deleteJson("/api/programs/{$this->program->id}")->assertStatus(422);

        // Course update accepts own program only
        $this->putJson("/api/courses/{$this->course->id}", ['program_id' => $this->program->id])->assertStatus(200);
        $foreign = Program::create(['code' => 'X', 'name' => 'Foreign', 'created_by' => $this->other->id]);
        $this->putJson("/api/courses/{$this->course->id}", ['program_id' => $foreign->id])->assertStatus(422);
    }

    // ------------------------------------------------------------ lifecycle

    public function test_overview_matrix_endpoints_and_stale_after_mapping_change(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedMatrix();
        $overview = $this->getJson($this->url())->assertStatus(200)->json('data');
        $this->assertSame('CSE', $overview['program']['code']);
        $this->assertCount(4, $overview['course_outcomes']);
        $this->assertSame('CO1', $overview['course_outcomes'][0]['display_code']);
        $this->assertCount(5, $overview['program_outcomes']);
        $this->assertCount(8, $overview['mappings']);
        $this->assertNull($overview['current_run']);
        $this->assertStringContainsString('does not constitute an accreditation decision', $overview['disclaimer']);

        $this->getJson($this->url('/matrix'))->assertStatus(200)->assertJsonPath('data.active_mappings', 8);
        $this->getJson($this->url('/findings'))->assertStatus(200)->assertJsonPath('data', []);

        $first = $this->postJson($this->url('/analyze'))->assertStatus(200)->json('data.id');
        $this->postJson($this->url('/analyze'))->assertStatus(409);
        $this->getJson($this->url('/findings'))->assertStatus(200)->assertJsonPath('run.is_stale', false);
        $this->getJson($this->url('/co-performance'))->assertStatus(200)->assertJsonCount(4, 'data');
        $this->getJson($this->url('/po-evidence'))->assertStatus(200)->assertJsonCount(5, 'data');

        // Mapping change -> stale -> regenerate preserves history
        $this->putJson('/api/co-po-mappings/' . CoPoMapping::first()->id, ['mapping_level' => 1])->assertStatus(200);
        $this->getJson($this->url())->assertJsonPath('data.current_run.is_stale', true)->assertJsonPath('data.current_run.status', 'STALE');
        $second = $this->postJson($this->url('/analyze'))->assertStatus(200)->json('data.id');
        $this->assertNotSame($first, $second);
        $this->assertFalse(CoPoMappingAnalysisRun::find($first)->is_current);
        $this->assertSame(2, CoPoMappingAnalysisRun::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'CO_PO_ANALYSIS_GENERATED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CO_PO_ANALYSIS_REGENERATED']);
    }

    public function test_analysis_requires_course_outcomes(): void
    {
        Sanctum::actingAs($this->faculty);
        $empty = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE303', 'course_name' => 'Empty', 'semester' => 'Fall', 'academic_year' => '2026']);
        $this->postJson("/api/courses/{$empty->id}/co-po-mapping/analyze")->assertStatus(422);
    }

    public function test_analysis_without_program_still_reports_co_coverage(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->course->update(['program_id' => null]);
        $res = $this->postJson($this->url('/analyze'))->assertStatus(200);
        $this->assertSame(0, $res->json('data.summary.po_count'));
        $this->assertSame([], $res->json('data.po_evidence'));
        $this->assertCount(4, $res->json('data.co_coverage'));
        $this->assertSame(0, collect($res->json('data.findings'))->where('type', 'MAPPING_REVIEW')->count());
    }

    // ------------------------------------------------------------- security

    public function test_cross_faculty_access_is_blocked(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->seedMatrix();
        $this->postJson($this->url('/analyze'))->assertStatus(200);
        $mappingId = CoPoMapping::first()->id;

        Sanctum::actingAs($this->other);
        $this->getJson($this->url())->assertStatus(403);
        $this->postJson($this->url('/analyze'))->assertStatus(403);
        $this->getJson($this->url('/matrix'))->assertStatus(403);
        $this->getJson($this->url('/findings'))->assertStatus(403);
        $this->getJson($this->url('/question-mappings'))->assertStatus(403);
        $this->postJson("/api/courses/{$this->course->id}/co-po-mappings", ['learning_outcome_id' => $this->co[1]->id, 'program_outcome_id' => $this->po[1]->id, 'mapping_level' => 3])->assertStatus(403);
        $this->putJson("/api/co-po-mappings/{$mappingId}", ['mapping_level' => 0])->assertStatus(403);
        $this->deleteJson("/api/co-po-mappings/{$mappingId}")->assertStatus(403);
        $this->postJson("/api/questions/{$this->q[1]->id}/co-mappings/confirm", ['learning_outcome_id' => $this->co[1]->id])->assertStatus(403);
        $this->getJson("/api/programs/{$this->program->id}")->assertStatus(403);
        $this->postJson("/api/programs/{$this->program->id}/outcomes", ['code' => 'PO9', 'title' => 'x'])->assertStatus(403);
        $this->getJson('/api/programs')->assertStatus(200)->assertJsonCount(0, 'data');
        $this->assertSame(3, CoPoMapping::find($mappingId)->mapping_level);
    }

    public function test_aggregate_payload_has_no_student_identity_and_requires_auth(): void
    {
        $this->getJson($this->url())->assertStatus(401);
        Sanctum::actingAs($this->faculty);
        $this->seedMatrix();
        $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'STU777', 'name' => 'Named Student']);
        $sub = StudentSubmission::create(['assessment_id' => $this->assessment->id, 'student_id' => $student->id, 'status' => 'GRADED', 'grading_status' => 'FACULTY_REVIEWED', 'total_marks' => 100]);
        StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $this->q[1]->id, 'answer_type' => 'TEXT', 'answer_text' => 'a', 'awarded_marks' => 10, 'answer_status' => 'REVIEWED']);
        $json = json_encode($this->postJson($this->url('/analyze'))->json('data'));
        $this->assertStringNotContainsString('STU777', $json);
        $this->assertStringNotContainsString('Named Student', $json);
    }
}
