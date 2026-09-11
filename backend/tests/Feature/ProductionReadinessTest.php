<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentBlueprint;
use App\Models\AssessmentVersion;
use App\Models\Course;
use App\Models\CourseCollaborator;
use App\Models\InstitutionalReport;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\Rubric;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Services\AssessmentVersionService;
use App\Services\InstitutionalReportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 40: production readiness — health probes, request tracing, security headers, cross-faculty IDOR sweep
 * over every resource family, seed-data guard, integrity command and integration of the full academic chain.
 */
class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------- health & observability

    public function test_liveness_and_readiness_probes_are_public_coarse_and_secret_free(): void
    {
        Http::fake(['*/health' => Http::response(['status' => 'ok', 'model_loaded' => true], 200)]);
        Storage::fake('local');

        $live = $this->getJson('/api/health')->assertOk()->json();
        $this->assertSame('ok', $live['status']);
        $this->assertSame('connected', $live['database']);

        $ready = $this->getJson('/api/health/ready')->assertOk();
        $data = $ready->json();
        $this->assertSame('ready', $data['status']);
        $this->assertSame(['database' => 'ok', 'cache' => 'ok', 'storage' => 'ok', 'queue' => 'ok', 'ai_service' => 'ok'], $data['components']);
        $body = $ready->getContent();
        foreach ([config('database.connections.mysql.password') ?: 'root123', 'DB_PASSWORD', 'AI_SERVICE_API_KEY', '127.0.0.1', 'mysql'] as $needle) {
            $this->assertStringNotContainsString((string) $needle, $body, "Readiness must not leak '{$needle}'");
        }
        $this->assertArrayNotHasKey('environment', $data);

        // Unauthenticated callers never receive diagnostic details
        $this->assertSame('ok', $this->getJson('/api/health/ready?details=1')->json('components.database'));
    }

    public function test_readiness_degrades_when_ai_service_is_down_but_stays_ready_for_core_dependencies(): void
    {
        Http::fake(['*/health' => Http::response(null, 503)]);
        Storage::fake('local');
        $data = $this->getJson('/api/health/ready')->assertOk()->json();
        $this->assertSame('degraded', $data['status']);
        $this->assertSame('error', $data['components']['ai_service']);
        $this->assertSame('ok', $data['components']['database']);
    }

    public function test_every_api_response_carries_request_id_and_security_headers(): void
    {
        $res = $this->getJson('/api/health');
        $requestId = $res->headers->get('X-Request-Id');
        $this->assertNotEmpty($requestId);
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame("default-src 'none'; frame-ancestors 'none'", $res->headers->get('Content-Security-Policy'));
        $this->assertSame('strict-origin-when-cross-origin', $res->headers->get('Referrer-Policy'));
        $this->assertNotNull($res->headers->get('Permissions-Policy'));

        // A client-supplied id is echoed back so traces can be correlated across nginx → Laravel → logs
        $this->withHeader('X-Request-Id', 'trace-abc-12345')->getJson('/api/health')->assertHeader('X-Request-Id', 'trace-abc-12345');
        // Malformed ids are replaced, never reflected
        $echoed = $this->withHeader('X-Request-Id', '<script>alert(1)</script>')->getJson('/api/health')->headers->get('X-Request-Id');
        $this->assertStringNotContainsString('<', (string) $echoed);
    }

    // ------------------------------------------------------------- authentication & session

    public function test_protected_routes_reject_unauthenticated_and_malformed_requests(): void
    {
        foreach (['/api/courses', '/api/assessments', '/api/analytics/overview', '/api/reports', '/api/reports/types', '/api/ai/evaluation', '/api/collaboration/invitations', '/api/auth/user'] as $path) {
            $this->getJson($path)->assertStatus(401);
        }
        $this->postJson('/api/reports', [])->assertStatus(401);
        $this->postJson('/api/auth/login', ['email' => 'not-an-email'])->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => 'nobody@example.edu', 'password' => 'wrong'])->assertStatus(401);
    }

    // ------------------------------------------------------------- IDOR sweep

    public function test_faculty_b_cannot_read_or_mutate_any_faculty_a_resource_by_id(): void
    {
        Storage::fake('local');
        [$a, $b, $ids] = $this->twoFacultyFixture();
        Sanctum::actingAs($b);

        $reads = [
            "/api/courses/{$ids['course']}",
            "/api/courses/{$ids['course']}/learning-outcomes",
            "/api/courses/{$ids['course']}/assessments",
            "/api/courses/{$ids['course']}/co-po-mapping",
            "/api/courses/{$ids['course']}/collaboration",
            "/api/assessments/{$ids['assessment']}",
            "/api/assessments/{$ids['assessment']}/questions",
            "/api/assessments/{$ids['assessment']}/blueprint",
            "/api/assessments/{$ids['assessment']}/versions",
            "/api/assessments/{$ids['assessment']}/versions/{$ids['version']}",
            "/api/assessments/{$ids['assessment']}/report",
            "/api/assessments/{$ids['assessment']}/submissions",
            "/api/assessments/{$ids['assessment']}/performance",
            "/api/submissions/{$ids['submission']}",
            "/api/student-answers/{$ids['answer']}/ai-grading",
            "/api/rubrics/{$ids['rubric']}",
            "/api/reports/{$ids['report']}",
            "/api/reports/{$ids['report']}/download",
            "/api/analytics/courses/{$ids['course']}",
        ];
        foreach ($reads as $path) {
            $status = $this->getJson($path)->getStatusCode();
            $this->assertContains($status, [403, 404], "GET {$path} returned {$status} for a non-member");
        }

        $mutations = [
            ['PUT', "/api/courses/{$ids['course']}", ['course_name' => 'Hijacked']],
            ['DELETE', "/api/courses/{$ids['course']}", []],
            ['PUT', "/api/assessments/{$ids['assessment']}", ['title' => 'Hijacked']],
            ['DELETE', "/api/assessments/{$ids['assessment']}", []],
            ['POST', "/api/assessments/{$ids['assessment']}/questions", ['question_number' => 99, 'question_text' => 'Injected', 'question_type' => 'mcq', 'marks' => 1]],
            ['POST', "/api/assessments/{$ids['assessment']}/versions", []],
            ['PUT', "/api/assessment-versions/{$ids['version']}", ['title' => 'Hijacked']],
            ['POST', "/api/assessment-versions/{$ids['version']}/finalize", []],
            ['POST', "/api/assessments/{$ids['assessment']}/blueprint", ['total_marks' => 10, 'total_questions' => 1]],
            ['PUT', "/api/student-answers/{$ids['answer']}", ['awarded_marks' => 0]],
            ['PATCH', "/api/submissions/{$ids['submission']}/status", ['status' => 'GRADED']],
            ['PUT', "/api/rubrics/{$ids['rubric']}", ['title' => 'Hijacked']],
            ['POST', "/api/rubrics/{$ids['rubric']}/approve", []],
            ['DELETE', "/api/reports/{$ids['report']}", []],
            ['POST', '/api/reports', ['report_type' => 'ASSESSMENT', 'scope_type' => 'ASSESSMENT', 'filters' => ['course_id' => $ids['course'], 'assessment_id' => $ids['assessment']], 'format' => 'CSV']],
            ['POST', '/api/ai/analyze-assessment', ['assessment_id' => $ids['assessment']]],
        ];
        foreach ($mutations as [$method, $path, $payload]) {
            $status = $this->json($method, $path, $payload)->getStatusCode();
            $this->assertContains($status, [403, 404], "{$method} {$path} returned {$status} for a non-member");
        }

        // Nothing changed
        $this->assertSame('Database Systems', Course::find($ids['course'])->course_name);
        $this->assertSame('Midterm', Assessment::find($ids['assessment'])->title);
        $this->assertEquals(8, (float) StudentAnswer::find($ids['answer'])->awarded_marks);
        $this->assertSame(1, AssessmentVersion::where('assessment_id', $ids['assessment'])->count());
        $this->assertDatabaseHas('institutional_reports', ['id' => $ids['report']]);
        $this->assertSame(3, Question::where('assessment_id', $ids['assessment'])->count());
    }

    public function test_reviewer_collaborator_can_read_but_cannot_edit_or_see_student_data(): void
    {
        Storage::fake('local');
        [$a, $b, $ids] = $this->twoFacultyFixture();
        $reviewer = User::factory()->create(['role' => 'FACULTY']);
        CourseCollaborator::create(['course_id' => $ids['course'], 'user_id' => $reviewer->id, 'invited_by' => $a->id, 'role' => 'REVIEWER', 'status' => 'ACTIVE', 'accepted_at' => now()]);
        Sanctum::actingAs($reviewer);
        $this->getJson("/api/courses/{$ids['course']}")->assertOk();
        $this->getJson("/api/assessments/{$ids['assessment']}")->assertOk();
        $this->putJson("/api/assessments/{$ids['assessment']}", ['title' => 'Nope'])->assertStatus(403);
        $this->assertContains($this->getJson("/api/assessments/{$ids['assessment']}/submissions")->getStatusCode(), [403, 404]);
        $this->postJson('/api/reports/preview', ['report_type' => 'STUDENT_PERFORMANCE', 'scope_type' => 'ASSESSMENT', 'filters' => ['course_id' => $ids['course'], 'assessment_id' => $ids['assessment']]])->assertStatus(403);
        $this->postJson('/api/reports/preview', ['report_type' => 'ASSESSMENT_QUALITY', 'scope_type' => 'ASSESSMENT', 'filters' => ['course_id' => $ids['course'], 'assessment_id' => $ids['assessment']]])->assertOk();
    }

    // ------------------------------------------------------------- academic integrity invariants

    public function test_finalized_version_grade_and_analysis_invariants_hold(): void
    {
        Storage::fake('local');
        [$a, $b, $ids] = $this->twoFacultyFixture();
        Sanctum::actingAs($a);
        // Finalized version is immutable through the API
        $this->putJson("/api/assessment-versions/{$ids['version']}", ['title' => 'Changed'])->assertStatus(409);
        $this->assertSame('Midterm', AssessmentVersion::find($ids['version'])->title);
        // Editing live questions never rewrites the snapshot
        Question::where('assessment_id', $ids['assessment'])->update(['marks' => 1]);
        $this->assertEquals(30, (float) AssessmentVersion::find($ids['version'])->total_marks);
        // Faculty grade is the authoritative value; AI suggestion is separate
        $answer = StudentAnswer::find($ids['answer']);
        $this->assertEquals(8, (float) $answer->awarded_marks);
        $this->assertNull($answer->currentAiGrading);
        // Report stays bound to its version
        $this->assertSame($ids['version'], InstitutionalReport::find($ids['report'])->assessment_version_id);
    }

    // ------------------------------------------------------------- integrity command

    public function test_integrity_check_reports_issues_read_only(): void
    {
        Storage::fake('local');
        [$a, $b, $ids] = $this->twoFacultyFixture();
        Artisan::call('facultylens:integrity-check', ['--json' => true]);
        $clean = json_decode(Artisan::output(), true);
        $this->assertSame(0, $clean['issues'], json_encode(collect($clean['checks'])->where('status', 'issue')->values()));

        // Corrupt: an answer awarded more than the question allows + a version total that no longer matches its snapshot
        DB::table('student_answers')->where('id', $ids['answer'])->update(['awarded_marks' => 999]);
        DB::table('assessment_versions')->where('id', $ids['version'])->update(['total_marks' => 1]);
        $before = DB::table('student_answers')->where('id', $ids['answer'])->value('awarded_marks');

        $code = Artisan::call('facultylens:integrity-check', ['--json' => true, '--fail-on-issues' => true]);
        $out = json_decode(Artisan::output(), true);
        $this->assertSame(1, $code);
        $issues = collect($out['checks'])->where('status', 'issue')->pluck('count', 'check');
        $this->assertEquals(1, $issues['answers_marks_above_max']);
        $this->assertEquals(1, $issues['version_totals_mismatch']);
        // Read-only: the command must never repair data itself
        $this->assertEquals($before, DB::table('student_answers')->where('id', $ids['answer'])->value('awarded_marks'));
        $this->assertEquals(1, (float) DB::table('assessment_versions')->where('id', $ids['version'])->value('total_marks'));
    }

    // ------------------------------------------------------------- seeding

    public function test_development_seeder_builds_the_full_chain_and_is_refused_in_production(): void
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseHas('users', ['email' => 'faculty@example.com']);
        $this->assertGreaterThanOrEqual(2, Course::count());
        $this->assertGreaterThanOrEqual(1, AssessmentBlueprint::count());
        $this->assertSame(1, AssessmentVersion::where('status', 'FINALIZED')->count());
        $this->assertSame(6, Student::count());
        $this->assertSame(6, StudentSubmission::where('grading_status', 'FINALIZED')->count());
        $this->assertGreaterThan(0, StudentAnswer::whereNotNull('awarded_marks')->count());
        $this->assertSame(1, DB::table('performance_analysis_runs')->count());
        $this->assertSame(1, Rubric::count());
        $this->assertSame(1, InstitutionalReport::where('status', 'COMPLETED')->count());
        $this->assertTrue(Str::startsWith(Student::first()->student_identifier, 'DEV-STU-'), 'Seed students are clearly synthetic');
        Artisan::call('facultylens:integrity-check', ['--json' => true]);
        $this->assertSame(0, json_decode(Artisan::output(), true)['issues'], 'Seeded data must be internally consistent');

        // Production guard: seeding is a no-op unless explicitly allowed (call the seeder directly; artisan db:seed itself also prompts in production)
        $this->app['env'] = 'production';
        $before = Student::count();
        (new DatabaseSeeder)->run();
        $this->assertSame($before, Student::count());
        $this->app['env'] = 'testing';
    }

    // ------------------------------------------------------------- fixtures

    /** Faculty A owns a course → assessment (3 questions, 30 marks) → finalized v1.0 → rubric → graded submission → report. */
    protected function twoFacultyFixture(): array
    {
        $a = User::factory()->create(['role' => 'FACULTY']);
        $b = User::factory()->create(['role' => 'FACULTY']);
        $course = Course::create(['user_id' => $a->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        $lo = LearningOutcome::create(['course_id' => $course->id, 'code' => 'CO1', 'description' => 'Explain.', 'sort_order' => 1]);
        $assessment = Assessment::create(['course_id' => $course->id, 'title' => 'Midterm', 'type' => 'midterm', 'total_marks' => 30, 'status' => 'published']);
        $questions = [];
        foreach ([1, 2, 3] as $n) {
            $questions[] = Question::create(['assessment_id' => $assessment->id, 'question_number' => $n, 'question_text' => "Q{$n}", 'question_type' => 'descriptive', 'marks' => 10, 'difficulty_level' => 'medium', 'cognitive_level' => 'Apply', 'learning_outcome_id' => $lo->id]);
        }
        $versions = app(AssessmentVersionService::class);
        $version = $versions->finalizeVersion($a, $versions->createVersion($a, $assessment, []));
        $rubric = Rubric::create(['question_id' => $questions[0]->id, 'assessment_id' => $assessment->id, 'created_by' => $a->id, 'title' => 'R1', 'total_marks' => 10, 'status' => 'DRAFT', 'version' => 1, 'generation_method' => 'manual']);
        $student = Student::create(['created_by' => $a->id, 'student_identifier' => 'S1', 'name' => 'Student One']);
        $submission = StudentSubmission::create(['assessment_id' => $assessment->id, 'assessment_version_id' => $version->id, 'student_id' => $student->id, 'status' => 'GRADED', 'grading_status' => 'FINALIZED', 'submitted_at' => now(), 'total_marks' => 30]);
        $answer = StudentAnswer::create(['student_submission_id' => $submission->id, 'question_id' => $questions[0]->id, 'answer_type' => 'TEXT', 'answer_text' => 'a', 'awarded_marks' => 8, 'answer_status' => 'REVIEWED']);
        $report = app(InstitutionalReportService::class)->create($a, ['report_type' => 'ASSESSMENT', 'scope_type' => 'ASSESSMENT_VERSION', 'filters' => ['course_id' => $course->id, 'assessment_id' => $assessment->id, 'assessment_version_id' => $version->id], 'format' => 'CSV']);
        // Faculty B has an unrelated course so "empty list" is not mistaken for isolation
        Course::create(['user_id' => $b->id, 'course_code' => 'EEE201', 'course_name' => 'Circuits', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);

        return [$a, $b, ['course' => $course->id, 'assessment' => $assessment->id, 'version' => $version->id, 'rubric' => $rubric->id, 'submission' => $submission->id, 'answer' => $answer->id, 'report' => $report->id]];
    }
}
