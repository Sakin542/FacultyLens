<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\AssessmentVersion;
use App\Models\AuditLog;
use App\Models\InstitutionalReport;
use App\Models\PerformanceAnalysisRun;
use App\Models\Question;
use App\Models\Recommendation;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * STEP 41 — "Golden Path": one faculty member walks the whole product through the public HTTP API,
 * from registration to institutional reports, against the REAL AI service (no mocks).
 *
 * Every step asserts on the persisted database state, not just on the HTTP response, so that a broken
 * link between two modules (e.g. grades that never reach performance analysis) fails the test.
 *
 * The test is skipped — never silently passed — when the FastAPI service is unreachable.
 */
class GoldenPathTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('testing');
    }

    protected function api(): static
    {
        return $this->actingAs($this->faculty, 'sanctum');
    }

    protected function ok(TestResponse $response, int $status = 200): array
    {
        $response->assertStatus($status);

        return $response->json();
    }

    public function test_full_academic_lifecycle_from_registration_to_institutional_reports(): void
    {
        $this->requireLiveAiService();

        // ---------------------------------------------------------------- 1. register + login
        $this->postJson('/api/auth/register', [
            'name' => 'Dr. Ada Lovelace', 'email' => 'ada.lovelace@university.edu', 'department' => 'CSE', 'designation' => 'Professor',
            'password' => 'GoldenPath#2026', 'password_confirmation' => 'GoldenPath#2026',
        ])->assertStatus(201);
        $this->postJson('/api/auth/login', ['email' => 'ada.lovelace@university.edu', 'password' => 'GoldenPath#2026'])->assertOk();
        $this->faculty = User::where('email', 'ada.lovelace@university.edu')->firstOrFail();
        $this->assertSame('FACULTY', $this->faculty->role);

        // ---------------------------------------------------------------- 2. course + learning outcomes
        $course = $this->ok($this->api()->postJson('/api/courses', [
            'course_code' => 'CSE-401', 'course_name' => 'Artificial Intelligence', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active',
            'description' => 'Search, knowledge representation, machine learning fundamentals.',
        ]), 201)['data'];
        $courseId = $course['id'];

        $lo = [];
        foreach ([
            ['LO1', 'Explain uninformed and informed search strategies and their complexity.', 'Understand'],
            ['LO2', 'Apply knowledge representation techniques to model a problem domain.', 'Apply'],
            ['LO3', 'Evaluate machine learning models using appropriate metrics.', 'Evaluate'],
        ] as $i => [$code, $desc, $cog]) {
            $lo[$code] = $this->ok($this->api()->postJson("/api/courses/{$courseId}/learning-outcomes", ['code' => $code, 'description' => $desc, 'cognitive_level' => $cog, 'sort_order' => $i + 1]), 201)['data']['id'];
        }
        $this->assertDatabaseCount('learning_outcomes', 3);

        // ---------------------------------------------------------------- 3. program outcomes + CO/PO mapping
        $programId = $this->ok($this->api()->postJson('/api/programs', ['code' => 'BSC-CSE', 'name' => 'B.Sc. in Computer Science and Engineering']), 201)['data']['id'];
        $this->api()->putJson("/api/courses/{$courseId}", ['program_id' => $programId])->assertOk();
        $this->assertDatabaseHas('courses', ['id' => $courseId, 'program_id' => $programId]);
        $po = [];
        foreach ([['PO1', 'Engineering knowledge'], ['PO2', 'Problem analysis'], ['PO3', 'Modern tool usage']] as [$code, $title]) {
            $po[$code] = $this->ok($this->api()->postJson("/api/programs/{$programId}/outcomes", ['code' => $code, 'title' => $title]), 201)['data']['id'];
        }
        foreach ([['LO1', 'PO1', 3], ['LO2', 'PO2', 2], ['LO3', 'PO2', 3], ['LO3', 'PO3', 2]] as [$l, $p, $level]) {
            $this->api()->postJson("/api/courses/{$courseId}/co-po-mappings", ['learning_outcome_id' => $lo[$l], 'program_outcome_id' => $po[$p], 'mapping_level' => $level])->assertStatus(201);
        }
        $copo = $this->ok($this->api()->postJson("/api/courses/{$courseId}/co-po-mapping/analyze"));
        $this->assertSame('success', $copo['status']);
        $this->assertDatabaseCount('co_po_mappings', 4);

        // ---------------------------------------------------------------- 4. assessment + questions
        $assessmentId = $this->ok($this->api()->postJson("/api/courses/{$courseId}/assessments", [
            'title' => 'Midterm Examination', 'type' => 'midterm', 'total_marks' => 100, 'duration_minutes' => 120, 'status' => 'draft', 'assessment_date' => '2026-10-15',
        ]), 201)['data']['id'];

        // Questions enter FacultyLens through paper upload / generation; the Golden Path creates them directly (no public CRUD endpoint).
        $spec = [
            ['Define the terms state space, initial state and goal test in the context of problem solving agents.', 'descriptive', 10, 'easy', 'Remember', 'LO1'],
            ['Explain the difference between breadth-first search and depth-first search with respect to completeness and memory.', 'descriptive', 10, 'easy', 'Understand', 'LO1'],
            ['Compare greedy best-first search with A* search. Under what condition is A* optimal?', 'descriptive', 15, 'medium', 'Understand', 'LO1'],
            ['Represent the statement "Every student who studies AI passes the exam" in first-order logic.', 'problem_solving', 10, 'medium', 'Apply', 'LO2'],
            ['Design a semantic network for a small library domain with at least five concepts and their relations.', 'problem_solving', 15, 'medium', 'Apply', 'LO2'],
            ['Convert the following propositional formula to conjunctive normal form and explain each step: (P → Q) ∧ ¬(Q ∨ R).', 'problem_solving', 10, 'medium', 'Apply', 'LO2'],
            ['A classifier reports 95% accuracy on a dataset where 94% of samples belong to one class. Evaluate whether accuracy is an appropriate metric and justify an alternative.', 'descriptive', 15, 'hard', 'Evaluate', 'LO3'],
            ['Given a confusion matrix with TP=40, FP=10, FN=20, TN=30, compute precision, recall and F1 score and interpret the results.', 'problem_solving', 15, 'hard', 'Analyze', 'LO3'],
        ];
        $questionIds = [];
        foreach ($spec as $i => [$text, $type, $marks, $difficulty, $cog, $loCode]) {
            $questionIds[] = Question::create(['assessment_id' => $assessmentId, 'question_number' => $i + 1, 'question_text' => $text, 'question_type' => $type, 'marks' => $marks,
                'difficulty_level' => $difficulty, 'cognitive_level' => $cog, 'learning_outcome_id' => $lo[$loCode]])->id;
        }
        $this->assertSame(100, (int) Question::where('assessment_id', $assessmentId)->sum('marks'), 'Question marks must add up to the assessment total');

        // ---------------------------------------------------------------- 5. blueprint create → validate → finalize
        $blueprint = $this->ok($this->api()->postJson("/api/assessments/{$assessmentId}/blueprint", [
            'title' => 'Midterm blueprint', 'total_marks' => 100, 'total_questions' => 8, 'duration_minutes' => 120,
            'sections' => [
                ['title' => 'Search', 'question_type' => 'descriptive', 'question_count' => 3, 'marks_per_question' => 35 / 3],
                ['title' => 'Knowledge representation', 'question_type' => 'problem_solving', 'question_count' => 3, 'marks_per_question' => 35 / 3],
                ['title' => 'Machine learning', 'question_type' => 'descriptive', 'question_count' => 2, 'marks_per_question' => 15],
            ],
            'constraints' => [
                'difficulty' => [['key' => 'easy', 'target_percentage' => 25], ['key' => 'medium', 'target_percentage' => 50], ['key' => 'hard', 'target_percentage' => 25]],
                'learning_outcomes' => [['learning_outcome_id' => $lo['LO1'], 'target_percentage' => 35], ['learning_outcome_id' => $lo['LO2'], 'target_percentage' => 35], ['learning_outcome_id' => $lo['LO3'], 'target_percentage' => 30]],
            ],
            'items' => [],
        ]), 201)['data'];
        $blueprintId = $blueprint['blueprint']['id'];
        $validation = $this->ok($this->api()->postJson("/api/blueprints/{$blueprintId}/validate"))['data'];
        $this->assertNotSame('INVALID', $validation['validation']['status'] ?? $validation['status'] ?? null, 'Blueprint must be structurally valid: '.json_encode($validation));
        $finalized = $this->ok($this->api()->postJson("/api/blueprints/{$blueprintId}/finalize"))['data']['blueprint'];
        $this->assertSame('FINALIZED', $finalized['status']);
        $this->assertDatabaseHas('assessment_blueprints', ['id' => $blueprintId, 'status' => 'FINALIZED']);

        $check = $this->ok($this->api()->postJson("/api/blueprints/{$blueprintId}/validate-questions", ['question_ids' => $questionIds]))['data'];
        $this->assertSame(count($questionIds), $check['evaluated']);

        // ---------------------------------------------------------------- 6. live AI analysis
        $analysis = $this->api()->postJson('/api/ai/analyze-assessment', ['assessment_id' => $assessmentId]);
        $analysis->assertOk();
        $quality = $analysis->json('data.quality_analysis');
        $this->assertIsArray($quality);
        $this->assertGreaterThanOrEqual(0, $quality['overall_quality_score']);
        $this->assertLessThanOrEqual(100, $quality['overall_quality_score']);

        $report = AnalysisReport::where('assessment_id', $assessmentId)->where('is_current', true)->firstOrFail();
        $this->assertSame('completed', $report->analysis_status);
        $this->assertNotNull($report->overall_score);
        $this->assertSame(count($questionIds), Question::where('assessment_id', $assessmentId)->where('ai_analysis_status', 'completed')->count(), 'Every question must carry AI classification after analysis');
        $this->assertTrue(AuditLog::where('action', 'AI_ANALYSIS_COMPLETED')->where('entity_id', $assessmentId)->exists());

        $status = $this->ok($this->api()->getJson("/api/ai/assessments/{$assessmentId}/analysis-status"));
        $this->assertSame('completed', $status['analysis_status'], json_encode($status));

        // ---------------------------------------------------------------- 7. recommendations → faculty decision
        $recommendations = Recommendation::where('analysis_report_id', $report->id)->get();
        $this->assertGreaterThan(0, $recommendations->count(), 'The unified analysis must persist at least one recommendation for a real assessment');
        $rec = $recommendations->first();
        $this->ok($this->api()->postJson("/api/recommendations/{$rec->id}/feedback", ['decision' => 'ACCEPTED', 'usefulness_rating' => 4, 'comment' => 'Will rebalance the hard questions.']));
        $this->assertSame('accepted', strtolower(Recommendation::find($rec->id)->status));
        $feed = $this->ok($this->api()->getJson('/api/feedback'));
        $this->assertNotEmpty($feed['data'], 'Recorded feedback must appear in the feedback list');

        // ---------------------------------------------------------------- 8. rubric generation + approval (live AI)
        $gradedQuestionId = $questionIds[3];
        $rubric = $this->ok($this->api()->postJson("/api/questions/{$gradedQuestionId}/rubrics/generate"), 201)['data'];
        $this->assertSame('DRAFT', $rubric['status']);
        $this->assertNotEmpty($rubric['criteria']);
        $this->assertEqualsWithDelta(10.0, (float) collect($rubric['criteria'])->sum('max_marks'), 0.01, 'Rubric criteria must add up to the question marks');
        $approved = $this->ok($this->api()->postJson("/api/rubrics/{$rubric['id']}/approve"))['data'];
        $this->assertSame('APPROVED', $approved['status']);

        // ---------------------------------------------------------------- 9. version snapshot → finalize
        $version = $this->ok($this->api()->postJson("/api/assessments/{$assessmentId}/versions", ['change_summary' => 'Initial paper']), 201)['data']['version'];
        $this->assertSame('DRAFT', $version['status']);
        $fin = $this->ok($this->api()->postJson("/api/assessment-versions/{$version['id']}/finalize"))['data']['version'];
        $this->assertSame('FINALIZED', $fin['status']);
        $this->assertSame(count($questionIds), AssessmentVersion::findOrFail($version['id'])->questions()->count(), 'Snapshot must contain every question');
        $this->api()->putJson("/api/assessment-versions/{$version['id']}", ['title' => 'tamper'])->assertStatus(409);

        // ---------------------------------------------------------------- 10. students, submissions, answers, grading
        // Answers arrive through the bulk CSV import (the UI path for a whole class); grades are saved with the
        // same finalize-grade endpoint the grading panel uses. (Per-answer POST/PUT are throttled at 25/min.)
        $marksByStudent = [[8, 9, 7, 6, 5, 4, 3, 9], [10, 7, 12, 8, 12, 9, 10, 11], [6, 5, 9, 5, 8, 6, 7, 6], [9, 8, 13, 9, 13, 8, 12, 13], [4, 3, 5, 2, 6, 3, 4, 5], [7, 6, 10, 7, 10, 7, 9, 10]];
        $studentIds = [];
        $csv = "student_identifier,question_number,answer_text\n";
        foreach ($marksByStudent as $s => $marks) {
            $identifier = sprintf('GP-STU-%03d', $s + 1);
            $studentIds[] = $this->ok($this->api()->postJson('/api/students', ['student_identifier' => $identifier, 'name' => 'Student '.($s + 1), 'section' => $s < 3 ? 'A' : 'B']), 201)['data']['id'];
            foreach ($questionIds as $q => $qid) {
                $csv .= sprintf("%s,%d,\"Answer of student %d to question %d.\"\n", $identifier, $q + 1, $s + 1, $q + 1);
            }
        }
        $import = $this->ok($this->api()->post("/api/assessments/{$assessmentId}/submissions/import", [
            'file' => UploadedFile::fake()->createWithContent('answers.csv', $csv),
        ]), 201)['data'];
        $this->assertSame(6, $import['submissions_created']);
        $this->assertSame(48, $import['answers_created']);

        $submissionIds = [];
        foreach ($studentIds as $s => $studentId) {
            $submission = StudentSubmission::where('assessment_id', $assessmentId)->where('student_id', $studentId)->firstOrFail();
            $submissionIds[] = $submission->id;
            $this->ok($this->api()->patchJson("/api/submissions/{$submission->id}/status", ['status' => 'UNDER_REVIEW']));
            $answers = StudentAnswer::where('student_submission_id', $submission->id)->get()->keyBy('question_id');
            foreach ($questionIds as $q => $qid) {
                $this->ok($this->api()->postJson("/api/student-answers/{$answers[$qid]->id}/finalize-grade", ['final_marks' => $marksByStudent[$s][$q], 'faculty_feedback' => 'Reviewed.']));
            }
            $this->ok($this->api()->patchJson("/api/submissions/{$submission->id}/status", ['status' => 'GRADED']));
        }
        $this->assertSame(6, StudentSubmission::where('assessment_id', $assessmentId)->where('status', 'GRADED')->count());
        $this->assertSame(48, StudentAnswer::whereIn('student_submission_id', $submissionIds)->whereNotNull('awarded_marks')->count());
        $expectedTotals = array_map('array_sum', $marksByStudent);
        $this->assertEqualsCanonicalizing($expectedTotals, StudentSubmission::whereIn('id', $submissionIds)->pluck('awarded_marks')->map(fn ($m) => (int) $m)->all(), 'Submission totals must equal the sum of awarded marks');
        $this->assertSame([100], StudentSubmission::whereIn('id', $submissionIds)->pluck('total_marks')->map(fn ($m) => (int) $m)->unique()->values()->all());

        // ---------------------------------------------------------------- 11. performance analysis
        $perf = $this->ok($this->api()->postJson("/api/assessments/{$assessmentId}/performance/analyze"))['data'];
        $this->assertSame('COMPLETED', $perf['status']);
        $run = PerformanceAnalysisRun::where('assessment_id', $assessmentId)->where('is_current', true)->firstOrFail();
        $this->assertSame(48, $run->finalized_answer_count);
        $expectedAverage = round(array_sum($expectedTotals) / (6 * 100) * 100, 1);
        $this->assertEqualsWithDelta($expectedAverage, (float) $run->overall_average_percentage, 0.6, 'Overall average must be computed from the real awarded marks');
        $this->assertSame(3, $run->learningOutcomeResults()->count(), 'Each learning outcome must receive a performance result');
        $los = $this->ok($this->api()->getJson("/api/assessments/{$assessmentId}/performance/learning-outcomes"))['data'];
        $this->assertNotEmpty($los);

        // ---------------------------------------------------------------- 12. analytics reflect the data
        $overview = $this->ok($this->api()->getJson('/api/analytics/overview?fresh=1'))['data'];
        $this->assertSame(1, $overview['kpis']['courses']['value']);
        $this->assertSame(1, $overview['kpis']['assessments']['value']);
        $this->assertSame(8, $overview['kpis']['questions']['value']);
        $this->assertSame(1, $overview['assessment_quality']['analyzed_assessments']);
        $this->assertTrue($overview['performance']['available']);

        // ---------------------------------------------------------------- 13. institutional reports (PDF/CSV/XLSX) + download + privacy
        $filters = ['course_id' => $courseId, 'assessment_id' => $assessmentId];
        $preview = $this->ok($this->api()->postJson('/api/reports/preview', ['report_type' => 'ASSESSMENT_QUALITY', 'scope_type' => 'ASSESSMENT', 'filters' => $filters]))['data'];
        $this->assertGreaterThan(0, $preview['record_count']);

        $reportIds = [];
        foreach ([['ASSESSMENT_QUALITY', 'PDF', 'ASSESSMENT'], ['QUESTION_ANALYSIS', 'CSV', 'ASSESSMENT'], ['STUDENT_PERFORMANCE', 'XLSX', 'ASSESSMENT'], ['CO_COVERAGE', 'CSV', 'ASSESSMENT'], ['PO_COVERAGE', 'CSV', 'COURSE']] as [$type, $format, $scope]) {
            $res = $this->api()->postJson('/api/reports', ['report_type' => $type, 'scope_type' => $scope, 'filters' => $scope === 'COURSE' ? ['course_id' => $courseId] : $filters, 'format' => $format]);
            $this->assertContains($res->status(), [201, 202], "{$type}/{$format}: ".$res->getContent());
            $reportIds[$type] = $res->json('data.id');
        }
        foreach ($reportIds as $type => $id) {
            $row = InstitutionalReport::findOrFail($id);
            $this->assertSame('COMPLETED', $row->status, "{$type} must complete synchronously for a single assessment: {$row->error_message}");
            $download = $this->api()->get("/api/reports/{$id}/download");
            $download->assertOk();
            $this->assertGreaterThan(200, strlen($download->streamedContent()), "{$type} download must contain real bytes");
        }
        $csv = $this->api()->get("/api/reports/{$reportIds['QUESTION_ANALYSIS']}/download")->streamedContent();
        $this->assertStringContainsString('breadth-first search', $csv, 'Question report must contain the real question text');
        $perfXlsx = $this->api()->get("/api/reports/{$reportIds['STUDENT_PERFORMANCE']}/download")->streamedContent();
        $this->assertStringNotContainsString('GP-STU-001', $perfXlsx, 'Aggregated performance report must not contain student identifiers');
        $this->assertStringNotContainsString('Student 1', $perfXlsx);

        // Reports are private: another faculty member gets 403/404, never the file
        $stranger = User::factory()->create(['role' => 'FACULTY', 'department' => 'EEE']);
        $this->assertContains($this->actingAs($stranger, 'sanctum')->get("/api/reports/{$reportIds['ASSESSMENT_QUALITY']}/download")->status(), [403, 404]);
        $this->actingAs($stranger, 'sanctum')->getJson("/api/assessments/{$assessmentId}")->assertStatus(403);
        $this->actingAs($stranger, 'sanctum')->getJson("/api/courses/{$courseId}")->assertStatus(403);

        // ---------------------------------------------------------------- 14. audit trail covers the chain
        $actions = AuditLog::where('user_id', $this->faculty->id)->pluck('action')->unique()->all();
        foreach (['AI_ANALYSIS_STARTED', 'AI_ANALYSIS_COMPLETED', 'REPORT_GENERATION_COMPLETED', 'REPORT_DOWNLOADED'] as $expected) {
            $this->assertContains($expected, $actions, 'Audit log missing '.$expected.'; present: '.implode(',', $actions));
        }
        $this->assertGreaterThan(10, AuditLog::count());
    }
}
