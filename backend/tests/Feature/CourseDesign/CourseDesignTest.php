<?php

namespace Tests\Feature\CourseDesign;

use App\Models\AcademicEvent;
use App\Models\Assessment;
use App\Models\CoPoMapping;
use App\Models\Course;
use App\Models\CoursePlan;
use App\Models\LearningOutcome;
use App\Models\Program;
use App\Models\ProgramOutcome;
use App\Models\Question;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CourseDesignTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected User $stranger;
    protected Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faculty = User::factory()->create();
        $this->stranger = User::factory()->create();
        $program = Program::create(['code' => 'BSC', 'name' => 'BSc CSE', 'department' => 'CSE', 'status' => 'active', 'created_by' => $this->faculty->id]);
        foreach (['PO1', 'PO3'] as $i => $code) {
            ProgramOutcome::create(['program_id' => $program->id, 'code' => $code, 'title' => "Outcome {$code}", 'sort_order' => $i, 'status' => 'active']);
        }
        $this->course = Course::create(['user_id' => $this->faculty->id, 'program_id' => $program->id, 'course_code' => 'CSE311', 'course_name' => 'Database Systems', 'description' => 'SQL, normalization, indexing and transactions.', 'semester' => 'Spring', 'academic_year' => '2026', 'credits' => 3]);
    }

    protected function fakeAi(): void
    {
        Http::fake([
            '*/api/v1/syllabus/draft' => fn ($request) => Http::response(['status' => 'success', 'data' => [
                'course' => ['code' => 'CSE311', 'name' => 'Database Systems'],
                'topics' => ['SQL queries', 'Normalization', 'Indexing', 'Transactions'],
                'outcomes' => [
                    ['code' => 'CO1', 'statement' => 'Explain the principles of SQL queries.', 'cognitive_level' => 'Understand', 'topics' => ['SQL queries']],
                    ['code' => 'CO2', 'statement' => 'Apply normalization to schemas.', 'cognitive_level' => 'Apply', 'topics' => ['Normalization']],
                ],
                'co_po' => [['co' => 'CO1', 'po' => 'PO1', 'level' => 3, 'similarity' => 0.61, 'justification' => 'Semantic match 0.61']],
                'co_po_method' => 'embedding',
                'weekly_outline' => [['week' => 1, 'topic' => 'SQL queries', 'outcomes' => ['CO1'], 'kind' => 'teaching']],
                'assessment_plan' => [['component' => 'Final examination', 'weight' => 100, 'outcomes' => ['CO1', 'CO2']]],
                'engine' => 'template', 'model' => null, 'warnings' => [],
                // echo what Laravel sent so the test can check it
                'received_pos' => array_column($request['program_outcomes'], 'code'),
            ]]),
            '*/api/v1/lesson-plan' => fn ($request) => Http::response(['status' => 'success', 'data' => [
                'course' => 'Database Systems',
                'weeks' => [['week' => 1, 'kind' => 'teaching', 'topic' => $request['topics'][0] ?? 'Intro', 'outcomes' => ['CO1'], 'bloom_focus' => 'Understand', 'sessions' => [['title' => 'Lecture', 'kind' => 'lecture', 'minutes' => 75, 'description' => 'x']], 'formative_checks' => [], 'preparation' => 'Read notes.']],
                'assessments' => [['week' => 3, 'type' => 'quiz', 'title' => 'Quiz (week 3)', 'outcomes' => ['CO1']], ['week' => 7, 'type' => 'midterm', 'title' => 'Midterm', 'outcomes' => ['CO1']]],
                'outcome_coverage' => ['CO1' => 1], 'mapping_method' => 'embedding', 'warnings' => [],
            ]]),
            '*/api/v1/summarize-paper' => fn ($request) => strlen($request['text']) < 400
                ? Http::response(['detail' => 'The document is too short to summarize (fewer than 80 words were extracted).'], 422)
                : Http::response(['status' => 'success', 'data' => [
                    'title' => 'Attention-Based Grading', 'authors' => ['Jane Smith'], 'year' => 2025, 'doi' => null, 'keywords' => ['grading'],
                    'summary' => ['We propose a model.'], 'key_findings' => ['It reaches 91% agreement.'], 'methods' => [], 'limitations' => [],
                    'sections_found' => ['abstract'], 'references' => [], 'citation' => ['apa' => 'Smith, J. (2025). Attention-Based Grading.', 'ieee' => 'J. Smith, "Attention-Based Grading," 2025.'],
                    'stats' => ['words' => 900, 'sentences' => 40], 'engine' => 'embedding',
                ]]),
        ]);
    }

    public function test_syllabus_draft_uses_course_program_outcomes_and_apply_creates_cos_and_links(): void
    {
        $this->fakeAi();
        LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO1', 'description' => 'Existing wording', 'cognitive_level' => 'Remember', 'sort_order' => 1]);

        $plan = $this->actingAs($this->faculty, 'sanctum')->postJson("/api/courses/{$this->course->id}/syllabus-draft", ['weeks' => 14])
            ->assertCreated()->json('data');
        $this->assertSame(['PO1', 'PO3'], $plan['content']['received_pos']);
        $this->assertSame('course_program', $plan['content']['program_outcome_source']);

        $res = $this->actingAs($this->faculty, 'sanctum')->postJson("/api/courses/{$this->course->id}/syllabus-draft/{$plan['id']}/apply", [
            'outcomes' => $plan['content']['outcomes'],
            'links' => [['co' => 'CO1', 'po' => 'PO1', 'level' => 3], ['co' => 'CO2', 'po' => 'PO3', 'level' => 2], ['co' => 'CO2', 'po' => 'PO9', 'level' => 1]],
        ])->assertOk();

        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 1, 'links' => 2], $res->json('data'));
        $this->assertSame('Existing wording', LearningOutcome::where('code', 'CO1')->value('description'));
        $this->assertSame('Apply', LearningOutcome::where('code', 'CO2')->value('cognitive_level'));
        $this->assertSame(2, CoPoMapping::where('course_id', $this->course->id)->count());
        $this->assertNotNull(CoursePlan::find($plan['id'])->applied_at);

        $this->actingAs($this->stranger, 'sanctum')->postJson("/api/courses/{$this->course->id}/syllabus-draft", [])->assertForbidden();
    }

    public function test_lesson_plan_reuses_syllabus_topics_exports_pdf_and_fills_calendar(): void
    {
        $this->fakeAi();
        $this->actingAs($this->faculty, 'sanctum')->postJson("/api/courses/{$this->course->id}/lesson-plan", [])->assertStatus(422);
        LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO1', 'description' => 'Explain SQL', 'cognitive_level' => 'Understand', 'sort_order' => 1]);
        $this->actingAs($this->faculty, 'sanctum')->postJson("/api/courses/{$this->course->id}/syllabus-draft", [])->assertCreated();

        $plan = $this->actingAs($this->faculty, 'sanctum')->postJson("/api/courses/{$this->course->id}/lesson-plan", ['sessions_per_week' => 3])
            ->assertCreated()->json('data');
        $this->assertSame('SQL queries', $plan['content']['weeks'][0]['topic']); // topics came from the saved syllabus draft
        $this->assertSame(3, $plan['inputs']['sessions_per_week']);

        $pdf = $this->actingAs($this->faculty, 'sanctum')->get("/api/course-plans/{$plan['id']}/export")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($this->faculty, 'sanctum')->postJson("/api/course-plans/{$plan['id']}/schedule", ['start_date' => '2027-01-04'])->assertOk();
        $this->assertSame(2, AcademicEvent::where('user_id', $this->faculty->id)->count());
        $midterm = AcademicEvent::where('title', 'CSE311 Midterm examination')->firstOrFail();
        $this->assertSame('2027-02-17', $midterm->starts_at->toDateString()); // week 7 starts 15 Feb, +2 days
        $this->assertSame([10080, 1440], $midterm->reminder_offsets);
    }

    public function test_paper_summaries_from_text_and_file_are_private(): void
    {
        $this->fakeAi();
        $text = str_repeat('Automated grading of short answers with transformers improves agreement with experts. ', 20);

        $summary = $this->actingAs($this->faculty, 'sanctum')->postJson('/api/research/summaries', ['text' => $text])->assertCreated()->json('data');
        $this->assertSame('Attention-Based Grading', $summary['title']);
        $this->assertSame('Smith, J. (2025). Attention-Based Grading.', $summary['summary']['citation']['apa']);

        $file = UploadedFile::fake()->createWithContent('paper.txt', $text);
        $this->actingAs($this->faculty, 'sanctum')->post('/api/research/summaries', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.file_name', 'paper.txt');

        $this->actingAs($this->faculty, 'sanctum')->postJson('/api/research/summaries', ['text' => str_repeat('short ', 40)])
            ->assertStatus(422)->assertJsonPath('message', 'The document is too short to summarize (fewer than 80 words were extracted).');

        $this->actingAs($this->faculty, 'sanctum')->getJson('/api/research/summaries')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($this->stranger, 'sanctum')->getJson("/api/research/summaries/{$summary['id']}")->assertNotFound();
        $this->actingAs($this->stranger, 'sanctum')->getJson('/api/research/summaries')->assertJsonCount(0, 'data');
    }

    public function test_grading_queue_lists_answers_per_question_with_progress(): void
    {
        $a = Assessment::create(['course_id' => $this->course->id, 'title' => 'Quiz', 'type' => 'quiz', 'total_marks' => 10, 'status' => 'draft']);
        $q1 = Question::create(['assessment_id' => $a->id, 'question_number' => 1, 'question_text' => 'Explain SQL joins.', 'question_type' => 'descriptive', 'marks' => 5]);
        $q2 = Question::create(['assessment_id' => $a->id, 'question_number' => 2, 'question_text' => 'Normalize R.', 'question_type' => 'descriptive', 'marks' => 5]);
        foreach ([1, 2, 3] as $i) {
            $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => "S{$i}", 'name' => "Student {$i}"]);
            $sub = StudentSubmission::create(['assessment_id' => $a->id, 'student_id' => $student->id, 'status' => 'SUBMITTED', 'grading_status' => 'PENDING', 'submitted_at' => now(), 'total_marks' => 10]);
            StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $q1->id, 'answer_type' => 'TEXT', 'answer_text' => "Join answer {$i}", 'awarded_marks' => $i === 1 ? 4 : null, 'answer_status' => 'PENDING']);
            StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $q2->id, 'answer_type' => 'TEXT', 'answer_text' => "Normal answer {$i}", 'answer_status' => 'PENDING']);
        }

        $data = $this->actingAs($this->faculty, 'sanctum')->getJson("/api/assessments/{$a->id}/grading-queue?status=ungraded")->assertOk()->json('data');
        $this->assertSame($q1->id, $data['question']['id']);
        $this->assertCount(2, $data['answers']);
        $this->assertSame(['total' => 3, 'ungraded' => 2], array_intersect_key($data['questions'][0], ['total' => 1, 'ungraded' => 1]));

        $answerId = $data['answers'][0]['id'];
        $this->actingAs($this->faculty, 'sanctum')->postJson("/api/student-answers/{$answerId}/finalize-grade", ['final_marks' => 3.5, 'faculty_feedback' => 'Good'])->assertOk();
        $data = $this->actingAs($this->faculty, 'sanctum')->getJson("/api/assessments/{$a->id}/grading-queue?question_id={$q1->id}&status=ungraded")->json('data');
        $this->assertCount(1, $data['answers']);

        $this->actingAs($this->stranger, 'sanctum')->getJson("/api/assessments/{$a->id}/grading-queue")->assertForbidden();
    }
}
