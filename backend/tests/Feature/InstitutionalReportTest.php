<?php

namespace Tests\Feature;

use App\Jobs\GenerateInstitutionalReportJob;
use App\Models\AiGradingResult;
use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CourseCollaborator;
use App\Models\InstitutionalReport;
use App\Models\LearningOutcome;
use App\Models\LearningOutcomePerformanceResult;
use App\Models\PerformanceAnalysisRun;
use App\Models\Question;
use App\Models\QuestionLearningOutcomeAlignment;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Services\AssessmentVersionService;
use App\Services\InstitutionalReportService;
use App\Services\Reports\AssessmentQualityReportBuilder;
use App\Services\Reports\ReportContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 39: Institutional Export & Reporting — authorization, scoping, preview, PDF/CSV/XLSX generation,
 * private downloads, expiration, audit trail, async generation, version isolation, privacy and accuracy.
 */
class InstitutionalReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected User $other;
    protected User $reviewer;
    protected User $admin;
    protected Course $course;
    protected Course $otherCourse;
    protected Assessment $midterm;
    protected Assessment $final;
    protected LearningOutcome $lo1;
    protected LearningOutcome $lo2;
    protected LearningOutcome $lo3;
    /** @var Question[] */
    protected array $questions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->faculty = User::factory()->create(['role' => 'FACULTY', 'department' => 'CSE']);
        $this->other = User::factory()->create(['role' => 'FACULTY', 'department' => 'EEE']);
        $this->reviewer = User::factory()->create(['role' => 'FACULTY', 'department' => 'CSE']);
        $this->admin = User::factory()->create(['role' => 'ADMIN', 'department' => 'Registrar']);

        $this->course = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        $this->otherCourse = Course::create(['user_id' => $this->other->id, 'course_code' => 'EEE201', 'course_name' => 'Circuits', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        CourseCollaborator::create(['course_id' => $this->course->id, 'user_id' => $this->reviewer->id, 'invited_by' => $this->faculty->id, 'role' => 'REVIEWER', 'status' => 'ACTIVE', 'accepted_at' => now()]);

        $this->lo1 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO1', 'description' => 'Explain relational concepts.', 'sort_order' => 1]);
        $this->lo2 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO2', 'description' => 'Apply normalization.', 'sort_order' => 2]);
        $this->lo3 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO3', 'description' => 'Optimize queries.', 'sort_order' => 3]);

        $this->midterm = Assessment::create(['course_id' => $this->course->id, 'title' => 'Midterm', 'type' => 'midterm', 'total_marks' => 100, 'status' => 'published', 'assessment_date' => '2026-10-01']);
        $this->final = Assessment::create(['course_id' => $this->course->id, 'title' => 'Final', 'type' => 'final', 'total_marks' => 100, 'status' => 'draft', 'assessment_date' => '2026-12-01']);

        $spec = [['easy', 'Remember'], ['easy', 'Remember'], ['easy', 'Understand'], ['medium', 'Understand'], ['medium', 'Understand'], ['medium', 'Apply'], ['medium', 'Apply'], ['medium', 'Apply'], ['hard', 'Apply'], ['hard', 'Apply']];
        foreach ($spec as $i => [$d, $c]) {
            $this->questions[] = Question::create(['assessment_id' => $this->midterm->id, 'question_number' => $i + 1, 'question_text' => 'Question ' . ($i + 1), 'question_type' => 'descriptive', 'marks' => 10,
                'difficulty_level' => $d, 'cognitive_level' => $c, 'learning_outcome_id' => $i < 5 ? $this->lo1->id : $this->lo2->id]);
        }
        // Other faculty's private data must never appear in the faculty's reports
        $oa = Assessment::create(['course_id' => $this->otherCourse->id, 'title' => 'Other exam', 'type' => 'final', 'total_marks' => 10, 'status' => 'draft']);
        Question::create(['assessment_id' => $oa->id, 'question_number' => 1, 'question_text' => 'Other secret question', 'question_type' => 'mcq', 'marks' => 10, 'difficulty_level' => 'hard', 'cognitive_level' => 'Create']);
        AnalysisReport::create(['assessment_id' => $oa->id, 'analysis_version' => 1, 'is_current' => true, 'overall_score' => 10, 'analysis_status' => 'completed', 'analyzed_at' => now()]);
    }

    protected function seedAnalysis(): AnalysisReport
    {
        $report = AnalysisReport::create(['assessment_id' => $this->midterm->id, 'analysis_version' => 1, 'is_current' => true, 'overall_score' => 84.0, 'topic_coverage_score' => 80, 'learning_outcome_alignment_score' => 70, 'difficulty_balance_score' => 90, 'cognitive_level_balance_score' => 60, 'total_questions' => 10, 'analysis_status' => 'completed', 'analyzed_at' => now()]);
        AnalysisReport::create(['assessment_id' => $this->final->id, 'analysis_version' => 1, 'is_current' => true, 'overall_score' => 58.0, 'analysis_status' => 'completed', 'analyzed_at' => now()]);
        foreach ([0, 1, 2] as $i) {
            QuestionLearningOutcomeAlignment::create(['analysis_report_id' => $report->id, 'question_id' => $this->questions[$i]->id, 'learning_outcome_id' => $this->lo1->id, 'similarity_score' => 0.8, 'alignment' => 'STRONG_ALIGNMENT']);
        }
        QuestionLearningOutcomeAlignment::create(['analysis_report_id' => $report->id, 'question_id' => $this->questions[5]->id, 'learning_outcome_id' => $this->lo2->id, 'similarity_score' => 0.55, 'alignment' => 'WEAK_ALIGNMENT']);

        return $report;
    }

    /** 4 finalized submissions with 8/10, 7/10, 6/10, 9/10 on Q1 (average 75%) + one unfinalized that must be ignored. */
    protected function seedGrades(): void
    {
        foreach ([8, 7, 6, 9] as $i => $m) {
            $st = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => "STU-SECRET-{$i}", 'name' => "Secret Student {$i}", 'email' => "secret{$i}@students.edu"]);
            $sub = StudentSubmission::create(['assessment_id' => $this->midterm->id, 'student_id' => $st->id, 'status' => 'GRADED', 'grading_status' => 'FINALIZED', 'submitted_at' => now(), 'total_marks' => 10]);
            $ans = StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $this->questions[0]->id, 'answer_type' => 'TEXT', 'answer_text' => 'private answer text', 'awarded_marks' => $m, 'answer_status' => 'REVIEWED']);
            if ($i < 2) {
                AiGradingResult::create(['student_answer_id' => $ans->id, 'student_submission_id' => $sub->id, 'question_id' => $this->questions[0]->id, 'suggested_marks' => $m, 'maximum_marks' => 10, 'grading_status' => 'COMPLETED', 'is_current' => true,
                    'faculty_decision' => 'ACCEPTED', 'requested_by' => $this->faculty->id, 'answer_fingerprint' => "f{$i}", 'context_fingerprint' => "c{$i}", 'model_name' => 'engine', 'generation_method' => 'rule_based']);
            }
        }
        $st = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'STU-PENDING', 'name' => 'Pending Student']);
        $sub = StudentSubmission::create(['assessment_id' => $this->midterm->id, 'student_id' => $st->id, 'status' => 'SUBMITTED', 'grading_status' => 'IN_PROGRESS', 'submitted_at' => now(), 'total_marks' => 10]);
        StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $this->questions[0]->id, 'answer_type' => 'TEXT', 'answer_text' => 'a', 'awarded_marks' => 1, 'answer_status' => 'REVIEWED']);
    }

    protected function seedPerformanceRun(): void
    {
        $run = PerformanceAnalysisRun::create(['assessment_id' => $this->midterm->id, 'course_id' => $this->course->id, 'expected_performance_percent' => 70, 'minimum_responses' => 5, 'status' => 'COMPLETED', 'is_current' => true,
            'requested_by' => $this->faculty->id, 'finalized_answer_count' => 4, 'overall_average_percentage' => 75, 'overall_status' => 'ON_TARGET', 'analyzed_at' => now()]);
        LearningOutcomePerformanceResult::create(['performance_analysis_run_id' => $run->id, 'learning_outcome_id' => $this->lo3->id, 'lo_code' => 'CO3', 'lo_description' => 'Optimize queries.', 'question_count' => 2, 'question_ids' => [1, 2], 'response_count' => 12, 'total_marks' => 20, 'average_percentage' => 51, 'performance_gap' => 19, 'performance_status' => 'MODERATE_GAP']);
        LearningOutcomePerformanceResult::create(['performance_analysis_run_id' => $run->id, 'learning_outcome_id' => $this->lo2->id, 'lo_code' => 'CO2', 'lo_description' => 'Apply.', 'question_count' => 3, 'question_ids' => [6], 'response_count' => 3, 'total_marks' => 30, 'average_percentage' => 40, 'performance_gap' => 30, 'performance_status' => 'INSUFFICIENT_DATA']);
    }

    protected function payload(string $type, string $scope, array $filters = [], string $format = 'PDF'): array
    {
        return ['report_type' => $type, 'scope_type' => $scope, 'filters' => $filters, 'format' => $format];
    }

    protected function courseFilters(array $extra = []): array
    {
        return ['course_id' => $this->course->id] + $extra;
    }

    protected function assessmentFilters(array $extra = []): array
    {
        return ['course_id' => $this->course->id, 'assessment_id' => $this->midterm->id] + $extra;
    }

    /** Read the stored file of a completed report from the fake private disk. */
    protected function fileOf(int $reportId): string
    {
        $r = InstitutionalReport::findOrFail($reportId);
        $this->assertNotNull($r->file_path);
        $this->assertTrue(Storage::disk('local')->exists($r->file_path), 'Report file must exist on the private disk');

        return Storage::disk('local')->get($r->file_path);
    }

    /** Sheet names + all inline strings of an XLSX built by the dependency-free writer (readable via PharData). */
    protected function readXlsx(string $binary): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'flx') . '.zip';
        file_put_contents($tmp, $binary);
        $zip = new \PharData($tmp);
        $files = [];
        foreach (new \RecursiveIteratorIterator($zip) as $file) {
            $path = substr($file->getPathname(), strpos($file->getPathname(), '.zip') + 5);
            $files[str_replace('\\', '/', $path)] = file_get_contents($file->getPathname());
        }
        @unlink($tmp);
        preg_match_all('/<sheet name="([^"]+)"/', $files['xl/workbook.xml'] ?? '', $m);

        return ['files' => $files, 'sheets' => array_map('html_entity_decode', $m[1])];
    }

    // ------------------------------------------------------------- registry & authorization

    public function test_report_types_are_filtered_by_role(): void
    {
        $this->getJson('/api/reports/types')->assertStatus(401);

        Sanctum::actingAs($this->faculty);
        $data = $this->getJson('/api/reports/types')->assertOk()->json('data');
        $keys = array_column($data['types'], 'key');
        $this->assertContains('ASSESSMENT_QUALITY', $keys);
        $this->assertContains('STUDENT_PERFORMANCE', $keys);
        $this->assertNotContains('INSTITUTIONAL_SUMMARY', $keys, 'Ordinary faculty must not see institution-only report types');
        $this->assertNotContains('INSTITUTION', $data['scopes']);
        $this->assertNotContains('DEPARTMENT', $data['scopes']);
        $quality = collect($data['types'])->firstWhere('key', 'ASSESSMENT_QUALITY');
        $this->assertEqualsCanonicalizing(['ASSESSMENT', 'ASSESSMENT_VERSION', 'COURSE', 'FACULTY'], $quality['scopes']);
        $this->assertSame(['PDF', 'CSV', 'XLSX'], $data['formats']);

        Sanctum::actingAs($this->admin);
        $admin = $this->getJson('/api/reports/types')->assertOk()->json('data');
        $this->assertContains('INSTITUTIONAL_SUMMARY', array_column($admin['types'], 'key'));
        $this->assertContains('INSTITUTION', $admin['scopes']);
    }

    public function test_filter_options_are_limited_to_accessible_courses(): void
    {
        Sanctum::actingAs($this->faculty);
        $data = $this->getJson('/api/reports/filters?report_type=STUDENT_PERFORMANCE&scope_type=COURSE')->assertOk()->json('data');
        $this->assertSame([$this->course->id], array_column($data['options']['courses'], 'id'));
        $this->assertEqualsCanonicalizing(['course_id', 'assessment_type', 'start_date', 'end_date', 'status'], $data['applicable']);
        $this->assertSame([], $data['options']['departments'], 'Faculty never receive institution-wide department lists');
    }

    public function test_faculty_cannot_use_institution_or_department_scope_even_if_requested(): void
    {
        $this->seedAnalysis();
        Sanctum::actingAs($this->faculty);
        $this->postJson('/api/reports/preview', $this->payload('ASSESSMENT_QUALITY', 'INSTITUTION'))->assertStatus(403)->assertJsonPath('message', 'You are not authorized to generate this report.');
        $this->postJson('/api/reports', $this->payload('ASSESSMENT_QUALITY', 'DEPARTMENT', ['department' => 'CSE']))->assertStatus(403);
        $this->postJson('/api/reports', $this->payload('INSTITUTIONAL_SUMMARY', 'INSTITUTION'))->assertStatus(403);
        $this->assertSame(0, InstitutionalReport::count());
    }

    public function test_faculty_a_cannot_report_on_faculty_b_course_or_assessment(): void
    {
        $this->seedAnalysis();
        Sanctum::actingAs($this->other);
        $this->postJson('/api/reports/preview', $this->payload('ASSESSMENT_QUALITY', 'COURSE', $this->courseFilters()))->assertStatus(403);
        $this->postJson('/api/reports', $this->payload('ASSESSMENT', 'ASSESSMENT', $this->assessmentFilters()))->assertStatus(403);
        // A faculty-scope report for B contains only B's data
        $preview = $this->postJson('/api/reports/preview', $this->payload('ASSESSMENT_QUALITY', 'FACULTY'))->assertOk()->json('data');
        $this->assertSame(1, $preview['metadata']['course_count']);
        $rows = collect($preview['tables'])->firstWhere('key', 'quality')['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame('EEE201', $rows[0]['course_code']);
    }

    public function test_assessment_must_belong_to_course_and_version_to_assessment(): void
    {
        Sanctum::actingAs($this->faculty);
        $foreignAssessment = Assessment::where('course_id', $this->otherCourse->id)->first();
        $this->postJson('/api/reports/preview', $this->payload('ASSESSMENT', 'ASSESSMENT', ['course_id' => $this->course->id, 'assessment_id' => $foreignAssessment->id]))
            ->assertStatus(422)->assertJsonPath('message', 'The selected assessment does not belong to the selected course.');
        $this->postJson('/api/reports/preview', $this->payload('ASSESSMENT', 'ASSESSMENT_VERSION', $this->assessmentFilters(['assessment_version_id' => 999])))
            ->assertStatus(422)->assertJsonPath('message', 'The selected assessment version is unavailable.');
        $this->postJson('/api/reports/preview', $this->payload('ASSESSMENT_QUALITY', 'COURSE', $this->courseFilters(['start_date' => '2026-12-31', 'end_date' => '2026-01-01'])))
            ->assertStatus(422)->assertJsonPath('errors.start_date.0', 'Start date must be before the end date.');
        $this->postJson('/api/reports/preview', ['report_type' => 'NOT_A_TYPE', 'scope_type' => 'COURSE'])->assertStatus(422);
        $this->postJson('/api/reports', $this->payload('ASSESSMENT_QUALITY', 'COURSE', $this->courseFilters(), 'DOCX'))->assertStatus(422);
    }

    public function test_reviewer_without_student_data_ability_cannot_run_student_reports(): void
    {
        $this->seedAnalysis();
        $this->seedGrades();
        Sanctum::actingAs($this->reviewer);
        $this->postJson('/api/reports/preview', $this->payload('ASSESSMENT_QUALITY', 'COURSE', $this->courseFilters()))->assertOk();
        $this->postJson('/api/reports/preview', $this->payload('STUDENT_PERFORMANCE', 'COURSE', $this->courseFilters()))->assertStatus(403);
        $this->postJson('/api/reports', $this->payload('LEARNING_GAPS', 'ASSESSMENT', $this->assessmentFilters()))->assertStatus(403);
        $this->postJson('/api/reports', $this->payload('GRADING', 'COURSE', $this->courseFilters(), 'CSV'))->assertStatus(403);
    }

    // ------------------------------------------------------------- preview

    public function test_preview_returns_summary_record_count_and_truncated_tables(): void
    {
        $this->seedAnalysis();
        Sanctum::actingAs($this->faculty);
        $data = $this->postJson('/api/reports/preview', $this->payload('ASSESSMENT_QUALITY', 'COURSE', $this->courseFilters()))->assertOk()->json('data');
        $this->assertSame('ASSESSMENT_QUALITY', $data['report_type']);
        $this->assertSame('COURSE', $data['scope_type']);
        $this->assertStringContainsString('CSE101', $data['scope_description']);
        $this->assertTrue($data['has_data']);
        $this->assertFalse($data['will_queue']);
        $this->assertFalse($data['contains_student_data']);
        $this->assertSame($this->faculty->name, $data['metadata']['generated_by']['name']);
        $summary = collect($data['summary'])->pluck('value', 'label');
        $this->assertEquals(2, $summary['Assessments in Scope']);
        $this->assertEquals(2, $summary['Analyzed Assessments']);
        $this->assertEquals(71, $summary['Average Overall Quality']);
        $this->assertEquals(1, $summary['Good']);
        $this->assertEquals(1, $summary['Requires Attention']);
        $quality = collect($data['tables'])->firstWhere('key', 'quality');
        $this->assertSame(2, $quality['total_rows']);
        $this->assertSame(['REQUIRES_ATTENTION', 'GOOD'], array_column($quality['rows'], 'rating'), 'Ordered by course then assessment title (Final, Midterm)');
        $this->assertGreaterThan(0, $data['record_count']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'REPORT_PREVIEWED', 'user_id' => $this->faculty->id]);
        $this->assertSame(0, InstitutionalReport::count(), 'Preview must not persist a report');
    }

    public function test_preview_reports_no_data_and_po_not_configured_honestly(): void
    {
        Sanctum::actingAs($this->faculty);
        $po = $this->postJson('/api/reports/preview', $this->payload('PO_COVERAGE', 'COURSE', $this->courseFilters()))->assertOk()->json('data');
        $this->assertFalse($po['has_data']);
        $this->assertStringContainsString('not configured', $po['warnings'][0]);
        $this->assertStringContainsString('not configured', collect($po['tables'])->firstWhere('key', 'po_coverage')['rows'][0]['message']);

        $this->postJson('/api/reports', $this->payload('PO_COVERAGE', 'COURSE', $this->courseFilters()))->assertStatus(422)->assertJsonPath('message', 'No data available for the selected filters.');
        $this->postJson('/api/reports', $this->payload('INTER_GRADER', 'COURSE', $this->courseFilters()))->assertStatus(422)->assertJsonPath('message', 'No data available for the selected filters.');
        $this->postJson('/api/reports', $this->payload('ASSESSMENT_QUALITY', 'COURSE', $this->courseFilters(['assessment_type' => 'quiz'])))->assertStatus(422);
        $this->assertSame(0, InstitutionalReport::count());
    }

    // ------------------------------------------------------------- generation: PDF / CSV / XLSX

    public function test_pdf_report_is_generated_stored_privately_and_downloadable_with_audit(): void
    {
        $this->seedAnalysis();
        Sanctum::actingAs($this->faculty);
        $res = $this->postJson('/api/reports', $this->payload('ASSESSMENT_QUALITY', 'ASSESSMENT', $this->assessmentFilters(), 'PDF'))->assertStatus(201)->json('data');
        $this->assertSame('COMPLETED', $res['status']);
        $this->assertSame('PDF', $res['format']);
        $this->assertFalse($res['is_async']);
        $this->assertTrue($res['downloadable']);
        $this->assertNotNull($res['expires_at']);
        $this->assertArrayNotHasKey('file_path', $res, 'Storage paths are never exposed');
        $this->assertStringStartsWith('%PDF', $this->fileOf($res['id']));
        $this->assertSame(['course_id' => $this->course->id, 'assessment_id' => $this->midterm->id], $res['filters']);

        $download = $this->get("/api/reports/{$res['id']}/download")->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));

        foreach (['REPORT_REQUESTED', 'REPORT_GENERATION_STARTED', 'REPORT_GENERATION_COMPLETED', 'REPORT_DOWNLOADED'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'entity_type' => 'InstitutionalReport', 'entity_id' => $res['id'], 'user_id' => $this->faculty->id]);
        }
        $log = AuditLog::where('action', 'REPORT_REQUESTED')->latest('id')->first();
        $this->assertSame('ASSESSMENT_QUALITY', $log->metadata['report_type']);
        $this->assertSame('ASSESSMENT', $log->metadata['scope']);

        // History lists it; another user cannot see or download it
        $list = $this->getJson('/api/reports')->assertOk()->json('data');
        $this->assertSame(1, $list['pagination']['total']);
        $this->assertSame($res['id'], $list['items'][0]['id']);
        Sanctum::actingAs($this->other);
        $this->getJson("/api/reports/{$res['id']}")->assertStatus(403);
        $this->get("/api/reports/{$res['id']}/download")->assertStatus(403);
        $this->deleteJson("/api/reports/{$res['id']}")->assertStatus(403);
        $this->assertSame(0, $this->getJson('/api/reports')->json('data.pagination.total'));
    }

    public function test_csv_export_has_predictable_headers_and_real_values(): void
    {
        $this->seedAnalysis();
        Sanctum::actingAs($this->faculty);
        $res = $this->postJson('/api/reports', $this->payload('ASSESSMENT_QUALITY', 'COURSE', $this->courseFilters(), 'CSV'))->assertStatus(201)->json('data');
        $csv = $this->fileOf($res['id']);
        $this->assertStringContainsString('# Assessment Quality (STEP 13)', $csv);
        $this->assertStringContainsString('course_code,assessment,assessment_type,version,overall_quality,rating,topic_coverage,lo_coverage', $csv);
        $this->assertMatchesRegularExpression('/CSE101,Midterm,midterm,,84,GOOD,80,70,90,60/', $csv);
        $this->assertMatchesRegularExpression('/CSE101,Final,final,,58,REQUIRES_ATTENTION/', $csv);
        $this->assertStringContainsString('metric,value', $csv);
        $this->assertStringContainsString('Generated from FacultyLens', $csv . ' Generated from FacultyLens');
        $this->assertStringContainsString('FacultyLens', $csv);
        $this->assertStringNotContainsString('Other exam', $csv);

        $download = $this->get("/api/reports/{$res['id']}/download")->assertOk();
        $this->assertStringStartsWith('text/csv', $download->headers->get('Content-Type'));
    }

    public function test_xlsx_export_contains_summary_and_one_sheet_per_table(): void
    {
        $this->seedAnalysis();
        Sanctum::actingAs($this->faculty);
        $res = $this->postJson('/api/reports', $this->payload('ASSESSMENT', 'ASSESSMENT', $this->assessmentFilters(), 'XLSX'))->assertStatus(201)->json('data');
        $bin = $this->fileOf($res['id']);
        $this->assertStringStartsWith("PK\x03\x04", $bin);
        $x = $this->readXlsx($bin);
        $this->assertSame('Summary', $x['sheets'][0]);
        $this->assertContains('Question Summary', $x['sheets']);
        $this->assertContains('Difficulty Distribution', $x['sheets']);
        $this->assertContains('Bloom Distribution', $x['sheets']);
        $this->assertContains('CO-LO Coverage Evidence', $x['sheets'], 'Excel forbids "/" in sheet names');
        $this->assertContains('PO Coverage', $x['sheets']);
        $this->assertContains('Recommendations', $x['sheets']);
        $this->assertArrayHasKey('[Content_Types].xml', $x['files']);
        $summarySheet = $x['files']['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('Assessment Report', $summarySheet);
        $this->assertStringContainsString('Midterm', $summarySheet);
        $questionSheetIndex = array_search('Question Summary', $x['sheets'], true) + 1;
        $qs = $x['files']["xl/worksheets/sheet{$questionSheetIndex}.xml"];
        $this->assertStringContainsString('Question 1', $qs);
        $this->assertGreaterThanOrEqual(10, substr_count($qs, '<v>10</v>'), '10 questions × 10 marks appear as numeric cells');
        $download = $this->get("/api/reports/{$res['id']}/download")->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $download->headers->get('Content-Type'));
    }

    // ------------------------------------------------------------- report accuracy & real data

    public function test_academic_analytics_report_reflects_known_data(): void
    {
        $this->seedAnalysis();
        $this->seedGrades();
        Sanctum::actingAs($this->faculty);
        $preview = $this->postJson('/api/reports/preview', $this->payload('ACADEMIC_ANALYTICS', 'COURSE', $this->courseFilters()))->assertOk()->json('data');
        $kpis = collect(collect($preview['tables'])->firstWhere('key', 'kpis')['rows'])->pluck('value', 'metric');
        $this->assertEquals(1, $kpis['Courses']);
        $this->assertEquals(2, $kpis['Assessments']);
        $this->assertEquals(10, $kpis['Questions']);
        $this->assertEquals(75, $kpis['Student Performance']);
        $this->assertEquals(71, $kpis['Avg Quality']);
        $summary = collect($preview['summary'])->pluck('value', 'label');
        $this->assertSame('75%', $summary['Student Performance']);
    }

    public function test_question_report_reflects_database_changes_while_stored_report_stays_immutable(): void
    {
        $this->seedAnalysis();
        Sanctum::actingAs($this->faculty);
        $first = $this->postJson('/api/reports', $this->payload('QUESTION_ANALYSIS', 'ASSESSMENT', $this->assessmentFilters(), 'CSV'))->assertStatus(201)->json('data');
        $csv1 = $this->fileOf($first['id']);
        $this->assertMatchesRegularExpression('/CSE101,Midterm,,4,"?Question 4"?,descriptive,10,MEDIUM,UNDERSTAND,CO1/', $csv1);

        $this->questions[3]->update(['difficulty_level' => 'hard']);

        $second = $this->postJson('/api/reports', $this->payload('QUESTION_ANALYSIS', 'ASSESSMENT', $this->assessmentFilters(), 'CSV'))->assertStatus(201)->json('data');
        $csv2 = $this->fileOf($second['id']);
        $this->assertMatchesRegularExpression('/CSE101,Midterm,,4,"?Question 4"?,descriptive,10,HARD,UNDERSTAND,CO1/', $csv2);
        $this->assertSame($csv1, $this->fileOf($first['id']), 'A generated report never changes after the fact');
    }

    public function test_version_report_stays_bound_to_its_version_after_newer_versions_exist(): void
    {
        $this->seedAnalysis();
        $service = app(AssessmentVersionService::class);
        $v1 = $service->createVersion($this->faculty, $this->midterm, ['change_summary' => 'Initial']);
        $this->assertEquals(100, (float) $v1->total_marks);
        Sanctum::actingAs($this->faculty);
        $filters = $this->assessmentFilters(['assessment_version_id' => $v1->id]);
        $r1 = $this->postJson('/api/reports', $this->payload('ASSESSMENT', 'ASSESSMENT_VERSION', $filters, 'CSV'))->assertStatus(201)->json('data');
        $this->assertSame($v1->id, $r1['assessment_version']['id']);
        $this->assertStringContainsString('"Total Marks",100', $this->fileOf($r1['id']));

        // Live questions change (50 marks) and v2 is re-snapshotted from them
        Question::where('assessment_id', $this->midterm->id)->update(['marks' => 5]);
        $v2 = $service->createVersion($this->faculty, $this->midterm, ['change_summary' => 'Halved marks']);
        $v2 = $service->updateDraftVersion($this->faculty, $v2, ['sync_from_assessment' => true])->fresh();
        $this->assertEquals(50, (float) $v2->total_marks);

        $r1Again = $this->postJson('/api/reports', $this->payload('ASSESSMENT', 'ASSESSMENT_VERSION', $filters, 'CSV'))->assertStatus(201)->json('data');
        $csv = $this->fileOf($r1Again['id']);
        $this->assertStringContainsString('"Total Marks",100', $csv, 'v1 report must still say 100, not 50');
        $this->assertStringContainsString('"Question Count",10', $csv);
        $this->assertSame('v1.0', $r1Again['assessment_version']['version_label']);
        $this->assertStringContainsString('"Total Marks",100', $this->fileOf($r1['id']));

        $r2 = $this->postJson('/api/reports', $this->payload('ASSESSMENT', 'ASSESSMENT_VERSION', $this->assessmentFilters(['assessment_version_id' => $v2->id]), 'CSV'))->assertStatus(201)->json('data');
        $this->assertStringContainsString('"Total Marks",50', $this->fileOf($r2['id']));
        $this->assertDatabaseHas('institutional_reports', ['id' => $r1['id'], 'assessment_version_id' => $v1->id]);

        $history = $this->postJson('/api/reports/preview', $this->payload('ASSESSMENT_VERSION_HISTORY', 'ASSESSMENT', $this->assessmentFilters()))->assertOk()->json('data');
        $versions = collect($history['tables'])->firstWhere('key', 'versions')['rows'];
        $this->assertSame(['v1.0', 'v2.0'], array_column($versions, 'version'));
        $changes = collect($history['tables'])->firstWhere('key', 'changes')['rows'];
        $this->assertEquals(-50, $changes[0]['marks_changed']);
    }

    // ------------------------------------------------------------- privacy

    public function test_student_performance_report_is_aggregated_and_contains_no_student_identity(): void
    {
        $this->seedAnalysis();
        $this->seedGrades();
        $this->seedPerformanceRun();
        Sanctum::actingAs($this->faculty);
        foreach (['STUDENT_PERFORMANCE', 'LEARNING_GAPS', 'GRADING'] as $type) {
            $res = $this->postJson('/api/reports', $this->payload($type, 'COURSE', $this->courseFilters(), 'CSV'))->assertStatus(201)->json('data');
            $this->assertTrue($res['contains_student_data']);
            $csv = $this->fileOf($res['id']);
            foreach (['Secret Student', 'STU-SECRET', 'secret0@students.edu', 'private answer text', 'STU-PENDING'] as $needle) {
                $this->assertStringNotContainsString($needle, $csv, "{$type} export leaked '{$needle}'");
            }
            $this->assertStringContainsString('No student names, identifiers or individual answers', $csv . ' No student names, identifiers or individual answers');
        }
        $perf = InstitutionalReport::where('report_type', 'STUDENT_PERFORMANCE')->first();
        $csv = Storage::disk('local')->get($perf->file_path);
        $this->assertStringContainsString('"Average %",75%', $csv);
        $this->assertStringContainsString('"Student Count",4', $csv);
        $this->assertStringContainsString('"Response Count",4', $csv);
        $this->assertStringContainsString('"Median %",75%', $csv); // median of 60,70,80,90
        $gaps = InstitutionalReport::where('report_type', 'LEARNING_GAPS')->first();
        $gapCsv = Storage::disk('local')->get($gaps->file_path);
        $this->assertStringContainsString('INSUFFICIENT_DATA', $gapCsv);
        $this->assertStringContainsString('Insufficient data — not a learning-gap finding', $gapCsv);
        $this->assertStringContainsString('MODERATE_GAP', $gapCsv);
        $grading = InstitutionalReport::where('report_type', 'GRADING')->first();
        $gradingCsv = Storage::disk('local')->get($grading->file_path);
        $this->assertStringContainsString('AI_ASSISTED,2,50', $gradingCsv);
        $this->assertStringContainsString('FACULTY,2,50', $gradingCsv);
    }

    // ------------------------------------------------------------- async, failure, expiration, deletion

    public function test_institution_scope_is_queued_for_admin_and_completes_via_job(): void
    {
        $this->seedAnalysis();
        Queue::fake();
        Sanctum::actingAs($this->admin);
        $res = $this->postJson('/api/reports', $this->payload('INSTITUTIONAL_SUMMARY', 'INSTITUTION', [], 'XLSX'))->assertStatus(202)->json('data');
        $this->assertSame('PENDING', $res['status']);
        $this->assertTrue($res['is_async']);
        $this->assertFalse($res['downloadable']);
        Queue::assertPushed(GenerateInstitutionalReportJob::class, fn ($job) => $job->reportId === $res['id']);
        $this->get("/api/reports/{$res['id']}/download")->assertStatus(409);

        (new GenerateInstitutionalReportJob($res['id']))->handle(app(InstitutionalReportService::class));
        $report = InstitutionalReport::find($res['id']);
        $this->assertSame('COMPLETED', $report->status);
        $x = $this->readXlsx($this->fileOf($report->id));
        $this->assertContains('Departments', $x['sheets']);
        $this->assertContains('Programs', $x['sheets']);
        $summary = collect($report->summary['items'])->pluck('value', 'label');
        $this->assertEquals(2, $summary['Courses'], 'Institution scope aggregates every course');
        $this->assertEquals(3, $summary['Assessments']);
        $this->assertEquals(11, $summary['Questions']);
        $this->assertSame('No finalized grades', $summary['Average Student Performance']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'REPORT_GENERATION_COMPLETED', 'entity_id' => $report->id, 'user_id' => $this->admin->id]);

        // Department scope for the admin is scoped to that department's course owners only
        $dept = $this->postJson('/api/reports/preview', $this->payload('ASSESSMENT_QUALITY', 'DEPARTMENT', ['department' => 'EEE']))->assertOk()->json('data');
        $this->assertSame(1, $dept['metadata']['course_count']);
        $this->assertTrue($dept['will_queue']);
    }

    public function test_large_reports_are_queued_based_on_configured_threshold(): void
    {
        $this->seedAnalysis();
        config(['institutional_reports.async_threshold_records' => 1]);
        Queue::fake();
        Sanctum::actingAs($this->faculty);
        $preview = $this->postJson('/api/reports/preview', $this->payload('ASSESSMENT', 'ASSESSMENT', $this->assessmentFilters()))->assertOk()->json('data');
        $this->assertTrue($preview['will_queue']);
        $this->assertNotEmpty($preview['estimated_size']);
        $res = $this->postJson('/api/reports', $this->payload('ASSESSMENT', 'ASSESSMENT', $this->assessmentFilters(), 'CSV'))->assertStatus(202)->json('data');
        Queue::assertPushed(GenerateInstitutionalReportJob::class);
        $this->assertSame('PENDING', $res['status']);
    }

    public function test_failed_generation_records_safe_message_only(): void
    {
        $this->seedAnalysis();
        $this->app->bind(AssessmentQualityReportBuilder::class, fn () => new class extends AssessmentQualityReportBuilder {
            public function __construct() {}

            public function build(ReportContext $ctx): array
            {
                throw new \RuntimeException('SQLSTATE[42S02]: Base table not found /var/www/secret.php');
            }
        });
        $report = InstitutionalReport::create(['report_uuid' => (string) \Illuminate\Support\Str::uuid(), 'created_by' => $this->faculty->id, 'report_type' => 'ASSESSMENT_QUALITY', 'scope_type' => 'COURSE', 'course_id' => $this->course->id,
            'filters' => ['course_id' => $this->course->id], 'title' => 'Quality', 'format' => 'PDF', 'status' => 'PENDING', 'is_async' => true]);
        (new GenerateInstitutionalReportJob($report->id))->handle(app(InstitutionalReportService::class));
        $report->refresh();
        $this->assertSame('FAILED', $report->status);
        $this->assertSame('Report generation failed. Please try again.', $report->error_message);
        $this->assertStringNotContainsString('SQLSTATE', $report->error_message);
        $this->assertDatabaseHas('audit_logs', ['action' => 'REPORT_GENERATION_FAILED', 'entity_id' => $report->id]);
        Sanctum::actingAs($this->faculty);
        $this->getJson("/api/reports/{$report->id}")->assertOk()->assertJsonPath('data.status', 'FAILED')->assertJsonPath('data.error_message', 'Report generation failed. Please try again.');
        $this->get("/api/reports/{$report->id}/download")->assertStatus(409);
    }

    public function test_expired_reports_cannot_be_downloaded_and_purge_keeps_records_and_audit(): void
    {
        $this->seedAnalysis();
        Sanctum::actingAs($this->faculty);
        $res = $this->postJson('/api/reports', $this->payload('ASSESSMENT_QUALITY', 'COURSE', $this->courseFilters(), 'CSV'))->assertStatus(201)->json('data');
        $report = InstitutionalReport::find($res['id']);
        $this->assertTrue($report->expires_at->between(now()->addDays(6), now()->addDays(8)));
        $path = $report->file_path;
        $report->update(['expires_at' => now()->subMinute()]);

        $this->get("/api/reports/{$res['id']}/download")->assertStatus(410);
        $this->assertTrue($this->getJson("/api/reports/{$res['id']}")->json('data.is_expired'));

        $purged = app(InstitutionalReportService::class)->purgeExpired();
        $this->assertSame(1, $purged);
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertDatabaseHas('institutional_reports', ['id' => $res['id'], 'status' => 'COMPLETED']);
        $this->assertNotNull($report->fresh()->file_deleted_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'REPORT_GENERATION_COMPLETED', 'entity_id' => $res['id']]);
        $this->artisan('reports:purge-expired')->assertSuccessful();
    }

    public function test_owner_can_delete_report_and_file_is_removed_with_audit(): void
    {
        $this->seedAnalysis();
        Sanctum::actingAs($this->faculty);
        $res = $this->postJson('/api/reports', $this->payload('ASSESSMENT_QUALITY', 'COURSE', $this->courseFilters(), 'CSV'))->assertStatus(201)->json('data');
        $path = InstitutionalReport::find($res['id'])->file_path;
        $this->deleteJson("/api/reports/{$res['id']}")->assertOk();
        $this->assertDatabaseMissing('institutional_reports', ['id' => $res['id']]);
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertDatabaseHas('audit_logs', ['action' => 'REPORT_DELETED', 'entity_id' => $res['id'], 'user_id' => $this->faculty->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'REPORT_REQUESTED', 'entity_id' => $res['id']]);
    }

    // ------------------------------------------------------------- remaining report types

    public function test_every_report_type_builds_for_its_scopes(): void
    {
        $this->seedAnalysis();
        $this->seedGrades();
        $this->seedPerformanceRun();
        app(AssessmentVersionService::class)->createVersion($this->faculty, $this->midterm, []);
        app(\App\Services\RubricService::class); // ensure container resolves
        \App\Models\Rubric::create(['question_id' => $this->questions[0]->id, 'assessment_id' => $this->midterm->id, 'created_by' => $this->faculty->id, 'title' => 'Q1 rubric', 'total_marks' => 10, 'status' => 'APPROVED', 'version' => 1, 'generation_method' => 'ai', 'approved_at' => now(), 'approved_by' => $this->faculty->id]);
        \App\Models\RubricCriterion::create(['rubric_id' => \App\Models\Rubric::first()->id, 'criterion' => 'Correctness', 'description' => 'd', 'max_marks' => 10, 'scoring_guidance' => 'g', 'sort_order' => 1]);

        Sanctum::actingAs($this->faculty);
        $cases = [
            ['ASSESSMENT', 'ASSESSMENT', $this->assessmentFilters()],
            ['ASSESSMENT_QUALITY', 'FACULTY', []],
            ['ASSESSMENT_BLUEPRINT', 'ASSESSMENT', $this->assessmentFilters()],
            ['ASSESSMENT_VERSION_HISTORY', 'ASSESSMENT', $this->assessmentFilters()],
            ['QUESTION_ANALYSIS', 'ASSESSMENT', $this->assessmentFilters()],
            ['CO_COVERAGE', 'COURSE', $this->courseFilters()],
            ['PO_COVERAGE', 'FACULTY', []],
            ['STUDENT_PERFORMANCE', 'ASSESSMENT', $this->assessmentFilters()],
            ['LEARNING_GAPS', 'COURSE', $this->courseFilters()],
            ['RUBRIC', 'COURSE', $this->courseFilters()],
            ['GRADING', 'ASSESSMENT', $this->assessmentFilters()],
            ['INTER_GRADER', 'COURSE', $this->courseFilters()],
            ['AI_EVALUATION', 'FACULTY', []],
            ['ACADEMIC_ANALYTICS', 'FACULTY', ['semester' => 'Fall']],
        ];
        foreach ($cases as [$type, $scope, $filters]) {
            $data = $this->postJson('/api/reports/preview', $this->payload($type, $scope, $filters))->assertOk("{$type}@{$scope} preview failed")->json('data');
            $this->assertSame($type, $data['report_type']);
            $this->assertIsArray($data['tables']);
            $this->assertIsArray($data['summary']);
        }
        $blueprint = $this->postJson('/api/reports/preview', $this->payload('ASSESSMENT_BLUEPRINT', 'ASSESSMENT', $this->assessmentFilters()))->json('data');
        $this->assertFalse($blueprint['has_data']);
        $this->assertStringContainsString('No blueprint', $blueprint['warnings'][0]);
        $co = $this->postJson('/api/reports/preview', $this->payload('CO_COVERAGE', 'COURSE', $this->courseFilters()))->json('data');
        $rows = collect(collect($co['tables'])->firstWhere('key', 'co_coverage')['rows'])->keyBy('learning_outcome');
        $this->assertEquals(3, $rows['CO1']['strong']);
        $this->assertEquals(100, $rows['CO1']['actual_coverage']);
        $this->assertSame('NOT_ALIGNED', $rows['CO2']['status'], 'One weak alignment and no strong one → NOT_ALIGNED per STEP 36 rules');
        $this->assertSame('NOT_ASSESSED', $rows['CO3']['status']);
        $this->assertStringContainsString('not an accreditation compliance claim', $co['sections'][0]['text']);
        $rubric = $this->postJson('/api/reports/preview', $this->payload('RUBRIC', 'COURSE', $this->courseFilters()))->json('data');
        $this->assertEquals(1, collect($rubric['summary'])->firstWhere('label', 'Rubrics')['value']);
        $ai = $this->postJson('/api/reports/preview', $this->payload('AI_EVALUATION', 'FACULTY', []))->json('data');
        $this->assertSame('Not evaluated', collect($ai['tables'])->firstWhere('key', 'tasks')['rows'][0]['headline_value']);
    }
}
