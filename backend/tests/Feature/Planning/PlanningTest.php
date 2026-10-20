<?php

namespace Tests\Feature\Planning;

use App\Models\AcademicEvent;
use App\Models\Assessment;
use App\Models\CoPoMapping;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\LearningOutcomePerformanceResult;
use App\Models\Notification;
use App\Models\PerformanceAnalysisRun;
use App\Models\Program;
use App\Models\ProgramOutcome;
use App\Models\Question;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Support\ZipBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlanningTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected User $colleague;
    protected User $head;
    protected User $outsider;
    protected Course $spring;
    protected Course $fall;

    protected function setUp(): void
    {
        parent::setUp();
        config(['notifications.queue.enabled' => false]);
        $this->faculty = User::factory()->create(['department' => 'Computer Science & Engineering']);
        $this->colleague = User::factory()->create(['department' => 'computer science & engineering ']);
        $this->head = User::factory()->create(['department' => 'Computer Science & Engineering', 'role' => 'DEPARTMENT_HEAD']);
        $this->outsider = User::factory()->create(['department' => 'EEE', 'role' => 'DEPARTMENT_HEAD']);

        // Two offerings of CSE311: Fall 2025 (weak design) then Spring 2026 (improved)
        $this->fall = $this->offering('Fall', '2025', [['Remember', 10, null], ['Understand', 10, null]], [55.0]);
        $this->spring = $this->offering('Spring', '2026', [['Understand', 10, 'CO1'], ['Analyze', 10, 'CO2']], [72.0, 64.0]);
    }

    /** @param list<array{string, int, ?string}> $questions  @param list<float> $coAverages */
    protected function offering(string $semester, string $year, array $questions, array $coAverages): Course
    {
        $course = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE311', 'course_name' => 'Database Systems', 'semester' => $semester, 'academic_year' => $year, 'credits' => 3, 'status' => 'active']);
        $los = [];
        foreach (['CO1', 'CO2'] as $i => $code) {
            $los[$code] = LearningOutcome::create(['course_id' => $course->id, 'code' => $code, 'description' => "Outcome {$code}", 'cognitive_level' => 'Apply', 'sort_order' => $i + 1]);
        }
        $a = Assessment::create(['course_id' => $course->id, 'title' => "Final {$semester}", 'type' => 'final', 'total_marks' => 20, 'status' => 'completed']);
        foreach ($questions as $i => [$bloom, $marks, $co]) {
            Question::create(['assessment_id' => $a->id, 'question_number' => $i + 1, 'question_text' => "Q{$i}", 'question_type' => 'descriptive', 'marks' => $marks, 'difficulty_level' => 'medium', 'cognitive_level' => $bloom, 'learning_outcome_id' => $co ? $los[$co]->id : null]);
        }
        $run = PerformanceAnalysisRun::create(['assessment_id' => $a->id, 'course_id' => $course->id, 'status' => 'COMPLETED', 'is_current' => true, 'expected_performance_percent' => 60, 'minimum_responses' => 5, 'overall_average_percentage' => array_sum($coAverages) / count($coAverages), 'student_count' => 10]);
        foreach ($coAverages as $i => $avg) {
            $lo = array_values($los)[$i];
            LearningOutcomePerformanceResult::create(['performance_analysis_run_id' => $run->id, 'learning_outcome_id' => $lo->id, 'lo_code' => $lo->code, 'lo_description' => $lo->description, 'question_count' => 1, 'response_count' => 10, 'total_marks' => 10, 'average_percentage' => $avg, 'performance_status' => $avg >= 60 ? 'ON_TARGET' : 'BELOW_TARGET']);
        }

        return $course;
    }

    // ------------------------------------------------------------------ course quality across semesters

    public function test_quality_trend_orders_offerings_and_explains_changes(): void
    {
        $data = $this->actingAs($this->faculty, 'sanctum')->getJson("/api/courses/{$this->spring->id}/quality-trend")->assertOk()->json('data');

        $this->assertSame(['Fall 2025', 'Spring 2026'], array_column(array_column($data['offerings'], 'course'), 'term'));
        $this->assertEquals(0, $data['offerings'][0]['higher_order_percent']);
        $this->assertEquals(50, $data['offerings'][1]['higher_order_percent']);
        $this->assertEquals(100, $data['offerings'][1]['co_coverage_percent']);
        $insights = collect($data['insights'])->keyBy('metric');
        $this->assertSame('improved', $insights['higher_order_percent']['direction']);
        $this->assertStringContainsString('from 0% of marks to 50% of marks', $insights['higher_order_percent']['text']);
        $this->assertSame('improved', $insights['co_coverage_percent']['direction']);

        $this->actingAs($this->outsider, 'sanctum')->getJson("/api/courses/{$this->spring->id}/quality-trend")->assertForbidden();
    }

    // ------------------------------------------------------------------ OBE

    public function test_obe_attainment_and_pack_zip(): void
    {
        $program = Program::create(['code' => 'BSc-CSE', 'name' => 'BSc in CSE', 'department' => 'CSE', 'status' => 'active', 'created_by' => $this->faculty->id]);
        $po1 = ProgramOutcome::create(['program_id' => $program->id, 'code' => 'PO1', 'title' => 'Engineering knowledge', 'sort_order' => 1, 'status' => 'active']);
        $los = LearningOutcome::where('course_id', $this->spring->id)->orderBy('sort_order')->get();
        CoPoMapping::create(['course_id' => $this->spring->id, 'learning_outcome_id' => $los[0]->id, 'program_outcome_id' => $po1->id, 'mapping_level' => 3]);
        CoPoMapping::create(['course_id' => $this->spring->id, 'learning_outcome_id' => $los[1]->id, 'program_outcome_id' => $po1->id, 'mapping_level' => 1]);

        $data = $this->actingAs($this->faculty, 'sanctum')->getJson("/api/courses/{$this->spring->id}/obe")->assertOk()->json('data');
        // CO1 72% -> level 3, CO2 64% -> level 2; PO1 = (3*3 + 2*1) / 4 = 2.75
        $this->assertSame([3, 2], array_column($data['co_attainment'], 'level'));
        $this->assertEquals(2.75, $data['po_attainment'][0]['attainment']);
        $this->assertTrue($data['po_attainment'][0]['attained']);

        $res = $this->actingAs($this->faculty, 'sanctum')->get("/api/courses/{$this->spring->id}/obe-pack")->assertOk();
        $this->assertSame('application/zip', $res->headers->get('Content-Type'));
        $names = $this->zipNames($res->getContent());
        $this->assertEqualsCanonicalizing(['OBE-report.pdf', 'co-attainment.csv', 'po-attainment.csv', 'co-po-matrix.csv', 'README.txt'], $names);
    }

    public function test_course_archive_contains_materials_assessments_obe_and_respects_student_data(): void
    {
        $a = Assessment::where('course_id', $this->spring->id)->first();
        $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'S001', 'name' => 'Student 1']);
        $sub = StudentSubmission::create(['assessment_id' => $a->id, 'student_id' => $student->id, 'status' => 'GRADED', 'grading_status' => 'FACULTY_REVIEWED', 'submitted_at' => now(), 'total_marks' => 20, 'awarded_marks' => 15]);
        foreach ($a->questions as $q) {
            StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $q->id, 'answer_type' => 'TEXT', 'answer_text' => 'An answer', 'awarded_marks' => 7.5, 'answer_status' => 'REVIEWED']);
        }

        $names = $this->zipNames($this->actingAs($this->faculty, 'sanctum')->get("/api/courses/{$this->spring->id}/archive")->assertOk()->getContent());
        $this->assertContains('02-assessments/01-final-spring/question-paper-with-answers.pdf', $names);
        $this->assertContains('02-assessments/01-final-spring/results.csv', $names);
        $this->assertContains('03-obe/OBE-report.pdf', $names);

        // A department head can archive the course for review, without student results
        $names = $this->zipNames($this->actingAs($this->head, 'sanctum')->get("/api/courses/{$this->spring->id}/archive")->assertOk()->getContent());
        $this->assertNotContains('02-assessments/01-final-spring/results.csv', $names);
    }

    public function test_zip_builder_output_is_a_valid_archive(): void
    {
        $zip = (new ZipBuilder())->add('a/b.txt', str_repeat('hello ', 100))->add('../evil.txt', 'x')->add('a/b.txt', 'dup')->output();
        $names = $this->zipNames($zip);
        $this->assertSame(['a/b.txt', 'evil.txt', 'a/b (2).txt'], $names);
        $this->assertSame(str_repeat('hello ', 100), $this->zipEntry($zip, 'a/b.txt'));
    }

    // ------------------------------------------------------------------ department head

    public function test_department_head_has_read_only_access_to_department_courses(): void
    {
        $colleagueCourse = Course::create(['user_id' => $this->colleague->id, 'course_code' => 'CSE101', 'course_name' => 'Programming', 'semester' => 'Spring', 'academic_year' => '2026']);

        $this->actingAs($this->head, 'sanctum')->getJson("/api/courses/{$colleagueCourse->id}")->assertOk();
        $this->actingAs($this->head, 'sanctum')->putJson("/api/courses/{$colleagueCourse->id}", ['course_name' => 'Hacked'])->assertForbidden();
        $this->actingAs($this->outsider, 'sanctum')->getJson("/api/courses/{$colleagueCourse->id}")->assertForbidden();

        $data = $this->actingAs($this->head, 'sanctum')->getJson('/api/department/overview')->assertOk()->json('data');
        $this->assertSame(3, $data['summary']['courses']);
        $this->assertSame(3, $data['summary']['faculty']);
        $fall = collect($data['courses'])->firstWhere('course.term', 'Fall 2025');
        $this->assertContains('Some course outcomes are not assessed', $fall['flags']);
        // A course with no assessed work is "no data", never "not attained"
        $empty = collect($data['courses'])->firstWhere('course.course_code', 'CSE101');
        $this->assertSame(['No assessment questions recorded yet'], $empty['flags']);
        $this->assertNull($empty['co_attained_percent']);

        $this->actingAs($this->faculty, 'sanctum')->getJson('/api/department/overview')->assertForbidden();
    }

    public function test_department_head_reports_only_on_own_department(): void
    {
        $this->actingAs($this->head, 'sanctum')->postJson('/api/reports/preview', [
            'report_type' => 'CO_COVERAGE', 'scope_type' => 'DEPARTMENT', 'format' => 'PDF', 'filters' => ['department' => 'EEE'],
        ])->assertForbidden();

        // Own department works, even without choosing it explicitly
        $this->actingAs($this->head, 'sanctum')->postJson('/api/reports/preview', [
            'report_type' => 'CO_COVERAGE', 'scope_type' => 'DEPARTMENT', 'format' => 'PDF', 'filters' => [],
        ])->assertOk();

        // A stale ADMIN role on another account grants no department/institution scope once the admin email is set
        config(['admin.email' => 'boss@aust.edu']);
        $stale = User::factory()->create(['role' => 'ADMIN']);
        $this->actingAs($stale, 'sanctum')->postJson('/api/reports/preview', [
            'report_type' => 'CO_COVERAGE', 'scope_type' => 'INSTITUTION', 'format' => 'PDF', 'filters' => [],
        ])->assertForbidden();
    }

    // ------------------------------------------------------------------ workload

    public function test_workload_counts_courses_contact_hours_backlog_and_deadlines(): void
    {
        $this->spring->update(['contact_hours_per_week' => 4.5]);
        $a = Assessment::where('course_id', $this->spring->id)->first();
        $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'S9', 'name' => 'S9']);
        $sub = StudentSubmission::create(['assessment_id' => $a->id, 'student_id' => $student->id, 'status' => 'SUBMITTED', 'grading_status' => 'PENDING', 'submitted_at' => now(), 'total_marks' => 20]);
        foreach ($a->questions as $q) {
            StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $q->id, 'answer_type' => 'TEXT', 'answer_text' => 'x', 'answer_status' => 'PENDING']);
        }
        AcademicEvent::create(['user_id' => $this->faculty->id, 'course_id' => $this->spring->id, 'title' => 'Quiz 2', 'type' => 'quiz', 'starts_at' => now()->addDays(3)]);

        $data = $this->actingAs($this->faculty, 'sanctum')->getJson('/api/workload')->assertOk()->json('data');
        $this->assertSame(2, $data['totals']['active_courses']);
        $this->assertEquals(7.5, $data['totals']['contact_hours_per_week']); // 4.5 set + 3 credits fallback
        $this->assertSame(2, $data['totals']['ungraded_answers']);
        $this->assertEquals(round(7.5 + 2 * 4 / 60 + 3, 1), $data['estimate_hours_this_week']['total']);
        $this->assertSame('Quiz 2', $data['upcoming'][0]['title']);
    }

    // ------------------------------------------------------------------ calendar

    public function test_calendar_events_include_dated_assessments_and_send_reminders_once(): void
    {
        Carbon::setTestNow('2026-10-20 09:00:00');
        Assessment::where('course_id', $this->spring->id)->update(['assessment_date' => '2026-10-25']);

        $event = $this->actingAs($this->faculty, 'sanctum')->postJson('/api/calendar/events', [
            'title' => 'Midterm grading due', 'type' => 'grading_deadline', 'starts_at' => '2026-10-21 10:00:00',
            'course_id' => $this->spring->id, 'reminder_offsets' => [60, 1440],
        ])->assertCreated()->json('data');
        $this->assertSame([1440, 60], $event['reminder_offsets']);

        $list = $this->actingAs($this->faculty, 'sanctum')->getJson('/api/calendar/events?from=2026-10-01&to=2026-10-31')->assertOk()->json('data');
        $this->assertSame(['Midterm grading due', 'Final Spring'], array_column($list, 'title'));
        $this->assertFalse($list[1]['editable']);

        // 23 hours before: the one-day reminder is due, the one-hour one is not
        Carbon::setTestNow('2026-10-20 11:00:00');
        $this->assertSame(1, app(\App\Services\Planning\CalendarService::class)->sendDueReminders());
        $this->assertSame(0, app(\App\Services\Planning\CalendarService::class)->sendDueReminders());
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->faculty->id]);
        $this->assertSame(1, Notification::query()->where('notifiable_id', $this->faculty->id)->count());

        Carbon::setTestNow('2026-10-21 09:30:00');
        $this->assertSame(1, app(\App\Services\Planning\CalendarService::class)->sendDueReminders());

        $ics = $this->actingAs($this->faculty, 'sanctum')->get('/api/calendar/export.ics')->assertOk();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics->getContent());
        $this->assertStringContainsString('SUMMARY:[CSE311] Midterm grading due', $ics->getContent());
        $this->assertStringContainsString('TRIGGER:-PT1440M', $ics->getContent());

        $this->actingAs($this->colleague, 'sanctum')->deleteJson("/api/calendar/events/{$event['id']}")->assertNotFound();
        $this->actingAs($this->colleague, 'sanctum')->postJson('/api/calendar/events', ['title' => 'x', 'type' => 'other', 'starts_at' => '2026-10-22', 'course_id' => $this->spring->id])->assertForbidden();
        Carbon::setTestNow();
    }

    // ------------------------------------------------------------------ helpers

    /** @return list<string> */
    protected function zipNames(string $zip): array
    {
        $names = [];
        $offset = 0;
        while (($pos = strpos($zip, "PK\x01\x02", $offset)) !== false) {
            $len = unpack('v', substr($zip, $pos + 28, 2))[1];
            $names[] = substr($zip, $pos + 46, $len);
            $offset = $pos + 46 + $len;
        }

        return $names;
    }

    protected function zipEntry(string $zip, string $name): ?string
    {
        $offset = 0;
        while (($pos = strpos($zip, "PK\x03\x04", $offset)) !== false) {
            $h = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen', substr($zip, $pos + 4, 26));
            $entryName = substr($zip, $pos + 30, $h['nlen']);
            $data = substr($zip, $pos + 30 + $h['nlen'] + $h['elen'], $h['csize']);
            if ($entryName === $name) {
                $out = $h['method'] === 8 ? gzinflate($data) : $data;
                $this->assertSame($h['crc'], crc32($out));

                return $out;
            }
            $offset = $pos + 30 + $h['nlen'] + $h['elen'] + $h['csize'];
        }

        return null;
    }
}
