<?php

namespace Tests\Feature\Exam;

use App\Models\Assessment;
use App\Models\AssessmentBlueprint;
use App\Models\Course;
use App\Models\ExamPaper;
use App\Models\LearningOutcome;
use App\Models\PreviousQuestion;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExamPaperBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected User $stranger;
    protected Course $course;
    protected Assessment $final;
    protected LearningOutcome $lo1;
    protected LearningOutcome $lo2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faculty = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->course = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE311', 'course_name' => 'Database Systems', 'semester' => 'Spring', 'academic_year' => '2026']);
        $this->lo1 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO1', 'description' => 'Write SQL.', 'cognitive_level' => 'Apply', 'sort_order' => 1]);
        $this->lo2 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'CO2', 'description' => 'Normalize.', 'cognitive_level' => 'Analyze', 'sort_order' => 2]);
        $this->final = Assessment::create(['course_id' => $this->course->id, 'title' => 'Final Examination', 'type' => 'final', 'total_marks' => 40, 'status' => 'draft', 'duration_minutes' => 120]);

        // Question pool: bank questions + questions of an earlier assessment in the same course
        $levels = [['Remember', 'easy'], ['Understand', 'easy'], ['Understand', 'medium'], ['Apply', 'medium'], ['Apply', 'hard'], ['Analyze', 'hard'], ['Analyze', 'medium'], ['Evaluate', 'hard']];
        foreach ($levels as $i => [$bloom, $diff]) {
            PreviousQuestion::create(['user_id' => $this->faculty->id, 'course_id' => $this->course->id, 'question_text' => "Bank question {$i}: {$bloom} the concept of indexing #{$i}.", 'question_type' => 'descriptive', 'marks' => 5, 'difficulty_level' => $diff, 'cognitive_level' => $bloom, 'source' => 'Final 2025']);
        }
        $mid = Assessment::create(['course_id' => $this->course->id, 'title' => 'Midterm', 'type' => 'midterm', 'total_marks' => 20, 'status' => 'draft']);
        Question::create(['assessment_id' => $mid->id, 'question_number' => 1, 'question_text' => 'Write a SQL query joining three tables with 2 conditions.', 'question_type' => 'problem_solving', 'marks' => 10, 'difficulty_level' => 'medium', 'cognitive_level' => 'Apply', 'learning_outcome_id' => $this->lo1->id, 'expected_answer' => 'SELECT ... JOIN ... JOIN ... WHERE a AND b']);
        Question::create(['assessment_id' => $mid->id, 'question_number' => 2, 'question_text' => 'Normalize the given relation to 3NF and justify each step.', 'question_type' => 'descriptive', 'marks' => 10, 'difficulty_level' => 'hard', 'cognitive_level' => 'Analyze', 'learning_outcome_id' => $this->lo2->id, 'expected_answer' => 'Decompose into ...']);
    }

    public function test_pool_lists_bank_course_questions_and_excludes_other_courses(): void
    {
        $otherCourse = Course::create(['user_id' => $this->stranger->id, 'course_code' => 'EEE101', 'course_name' => 'Circuits', 'semester' => 'Spring', 'academic_year' => '2026']);
        PreviousQuestion::create(['user_id' => $this->stranger->id, 'course_id' => $otherCourse->id, 'question_text' => 'Foreign question', 'question_type' => 'descriptive', 'marks' => 5]);

        $res = $this->actingAs($this->faculty, 'sanctum')->getJson("/api/assessments/{$this->final->id}/question-pool")->assertOk();
        $this->assertSame(10, $res->json('meta.total'));
        $this->assertNotContains('Foreign question', array_column($res->json('data'), 'question_text'));

        $this->actingAs($this->stranger, 'sanctum')->getJson("/api/assessments/{$this->final->id}/question-pool")->assertForbidden();
    }

    public function test_build_from_targets_matches_bloom_mix_and_marks(): void
    {
        $res = $this->actingAs($this->faculty, 'sanctum')->postJson("/api/assessments/{$this->final->id}/exam-papers", [
            'mode' => 'targets',
            'seed' => 7,
            'targets' => ['question_count' => 4, 'total_marks' => 40, 'cognitive_mix' => ['Understand' => 25, 'Apply' => 50, 'Analyze' => 25], 'difficulty_mix' => ['medium' => 50, 'hard' => 50]],
        ])->assertCreated();

        $data = $res->json('data');
        $this->assertCount(4, $data['items']);
        $this->assertEquals(40, $data['total_marks']);
        $this->assertSame([], $data['shortfalls']);
        $bloom = array_count_values(array_column($data['items'], 'cognitive_level'));
        $this->assertSame(['Understand' => 1, 'Apply' => 2, 'Analyze' => 1], array_intersect_key($bloom, ['Understand' => 1, 'Apply' => 1, 'Analyze' => 1]));
        $this->assertEquals(50.0, $data['coverage']['bloom']['Apply']['percent']);
        // No question twice
        $this->assertCount(4, array_unique(array_column($data['items'], 'question_text')));
    }

    public function test_total_marks_split_into_whole_marks_that_add_up_exactly(): void
    {
        $data = $this->actingAs($this->faculty, 'sanctum')->postJson("/api/assessments/{$this->final->id}/exam-papers", [
            'mode' => 'targets', 'seed' => 2,
            'targets' => ['question_count' => 6, 'total_marks' => 50, 'cognitive_mix' => ['Remember' => 1, 'Understand' => 1, 'Apply' => 2, 'Analyze' => 1, 'Evaluate' => 1]],
        ])->assertCreated()->json('data');

        $this->assertEquals(50, $data['total_marks']);
        $this->assertSame([8.0, 8.0, 8.0, 8.0, 9.0, 9.0], array_map('floatval', array_column($data['items'], 'marks')));
        // Higher Bloom levels carry the extra marks
        $this->assertSame('Evaluate', end($data['items'])['cognitive_level']);
    }

    public function test_build_from_blueprint_prefers_matching_course_outcomes_and_reports_shortfalls(): void
    {
        $bp = AssessmentBlueprint::create(['assessment_id' => $this->final->id, 'created_by' => $this->faculty->id, 'version' => 1, 'status' => 'draft', 'is_current' => true, 'title' => 'Final blueprint', 'total_marks' => 30, 'total_questions' => 4, 'duration_minutes' => 90]);
        $bp->items()->create(['learning_outcome_id' => $this->lo1->id, 'question_type' => 'problem_solving', 'difficulty_level' => 'medium', 'cognitive_level' => 'Apply', 'question_count' => 1, 'marks_each' => 10, 'total_marks' => 10, 'sort_order' => 1]);
        $bp->items()->create(['learning_outcome_id' => $this->lo2->id, 'question_type' => 'descriptive', 'difficulty_level' => 'hard', 'cognitive_level' => 'Analyze', 'question_count' => 1, 'marks_each' => 10, 'total_marks' => 10, 'sort_order' => 2]);
        $bp->items()->create(['difficulty_level' => 'hard', 'cognitive_level' => 'Create', 'question_count' => 2, 'marks_each' => 5, 'total_marks' => 10, 'sort_order' => 3]);

        $data = $this->actingAs($this->faculty, 'sanctum')->postJson("/api/assessments/{$this->final->id}/exam-papers", ['mode' => 'blueprint', 'seed' => 3])
            ->assertCreated()->json('data');

        $this->assertSame('Write a SQL query joining three tables with 2 conditions.', $data['items'][0]['question_text']);
        $this->assertSame('CO2', $data['items'][1]['learning_outcome']['code']);
        // Nothing in the pool is at the Create level: both slots are reported, not silently filled
        $this->assertSame(2, $data['shortfalls'][0]['missing']);
        $this->assertSame(90, $data['duration_minutes']);
    }

    public function test_items_can_be_added_edited_reordered_and_paper_locked_when_final(): void
    {
        $paper = $this->actingAs($this->faculty, 'sanctum')->postJson("/api/assessments/{$this->final->id}/exam-papers", ['mode' => 'empty'])->assertCreated()->json('data');
        $pool = $this->actingAs($this->faculty, 'sanctum')->getJson("/api/assessments/{$this->final->id}/question-pool?cognitive_level=Apply")->json('data');

        $this->actingAs($this->faculty, 'sanctum')->postJson("/api/exam-papers/{$paper['id']}/items", ['candidate_key' => $pool[0]['key'], 'marks' => 8])->assertCreated();
        $data = $this->actingAs($this->faculty, 'sanctum')->postJson("/api/exam-papers/{$paper['id']}/items", ['question_text' => 'Explain ACID.', 'expected_answer' => 'Atomicity...', 'marks' => 4, 'cognitive_level' => 'understand'])
            ->assertCreated()->json('data');
        $this->assertEquals(12, $data['total_marks']);
        $this->assertSame('Understand', $data['items'][1]['cognitive_level']);

        $ids = array_column($data['items'], 'id');
        $this->actingAs($this->faculty, 'sanctum')->putJson("/api/exam-papers/{$paper['id']}/order", ['item_ids' => array_reverse($ids)])
            ->assertOk()->assertJsonPath('data.items.0.question_text', 'Explain ACID.');

        $this->actingAs($this->faculty, 'sanctum')->patchJson("/api/exam-papers/{$paper['id']}", ['status' => 'final'])->assertOk();
        $this->actingAs($this->faculty, 'sanctum')->deleteJson("/api/exam-papers/{$paper['id']}/items/{$ids[0]}")->assertStatus(409);
        $this->actingAs($this->stranger, 'sanctum')->getJson("/api/exam-papers/{$paper['id']}")->assertForbidden();
    }

    public function test_sets_shuffle_and_use_ai_variants_with_flagged_answer_keys(): void
    {
        Http::fake(['*/api/v1/question-variants' => fn ($request) => Http::response([
            'status' => 'success', 'original' => $request['question_text'], 'mode' => 'numbers', 'engine' => 'deterministic',
            'variants' => [['question_text' => 'VARIANT: '.$request['question_text'], 'expected_answer' => 'new key', 'methods' => ['numeric'], 'number_changes' => [['from' => '2', 'to' => '3']], 'answer_needs_review' => true, 'warnings' => [], 'similarity' => 0.93]],
        ])]);
        $paper = $this->actingAs($this->faculty, 'sanctum')->postJson("/api/assessments/{$this->final->id}/exam-papers", [
            'mode' => 'targets', 'seed' => 11, 'targets' => ['question_count' => 3, 'marks_each' => 5],
        ])->json('data');

        $res = $this->actingAs($this->faculty, 'sanctum')->postJson("/api/exam-papers/{$paper['id']}/sets", ['count' => 2, 'vary' => 'numbers'])->assertCreated();
        $this->assertSame(['B', 'C'], array_column($res->json('data'), 'set_label'));
        $setB = $res->json('data.0');
        $this->assertCount(3, $setB['items']);
        $this->assertTrue(collect($setB['items'])->every(fn ($i) => str_starts_with($i['question_text'], 'VARIANT: ') && $i['answer_needs_review']));
        $this->assertFalse($setB['coverage']['answer_key_complete']);
        $this->assertEquals(15, $setB['total_marks']);
        $this->assertSame(2, ExamPaper::where('parent_paper_id', $paper['id'])->count());
    }

    public function test_sets_fall_back_to_original_wording_when_ai_is_down(): void
    {
        Http::fake(['*/api/v1/question-variants' => Http::response(['detail' => 'down'], 500)]);
        $paper = $this->actingAs($this->faculty, 'sanctum')->postJson("/api/assessments/{$this->final->id}/exam-papers", ['mode' => 'targets', 'targets' => ['question_count' => 2]])->json('data');

        $res = $this->actingAs($this->faculty, 'sanctum')->postJson("/api/exam-papers/{$paper['id']}/sets", ['count' => 1, 'vary' => 'wording'])->assertCreated();
        $this->assertNotEmpty($res->json('warnings'));
        $this->assertEqualsCanonicalizing(array_column($paper['items'], 'question_text'), array_column($res->json('data.0.items'), 'question_text'));
    }

    public function test_export_pdf_and_docx_and_answer_key_needs_edit_rights(): void
    {
        $paper = $this->actingAs($this->faculty, 'sanctum')->postJson("/api/assessments/{$this->final->id}/exam-papers", ['mode' => 'targets', 'targets' => ['question_count' => 3, 'marks_each' => 5]])->json('data');

        $pdf = $this->actingAs($this->faculty, 'sanctum')->get("/api/exam-papers/{$paper['id']}/export?format=pdf&answer_key=1")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('with-answer-key.pdf', $pdf->headers->get('Content-Disposition'));

        $docx = $this->actingAs($this->faculty, 'sanctum')->get("/api/exam-papers/{$paper['id']}/export?format=docx");
        if (class_exists(\ZipArchive::class)) {
            $docx->assertOk();
            $this->assertStringStartsWith('PK', $docx->getContent()); // .docx is a zip
        } else {
            $docx->assertStatus(503)->assertJsonPath('message', 'Word export needs the PHP zip extension on the server. Use PDF, or enable ext-zip.');
        }

        $this->actingAs($this->stranger, 'sanctum')->get("/api/exam-papers/{$paper['id']}/export")->assertForbidden();
    }

    public function test_apply_copies_questions_into_the_assessment_once(): void
    {
        $paper = $this->actingAs($this->faculty, 'sanctum')->postJson("/api/assessments/{$this->final->id}/exam-papers", ['mode' => 'targets', 'targets' => ['question_count' => 3, 'marks_each' => 5]])->json('data');

        $this->actingAs($this->faculty, 'sanctum')->postJson("/api/exam-papers/{$paper['id']}/apply")->assertOk();
        $this->assertSame(3, Question::where('assessment_id', $this->final->id)->count());
        $this->actingAs($this->faculty, 'sanctum')->postJson("/api/exam-papers/{$paper['id']}/apply")->assertStatus(409);
    }
}
