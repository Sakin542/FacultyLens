<?php

namespace Tests\Feature;

use App\Models\AiGradingResult;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Services\Admin\FeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaperReviewAndClassSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected Course $course;
    protected Assessment $assessment;
    protected Question $q1;
    protected Question $q2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faculty = User::factory()->create();
        $this->course = Course::query()->create(['user_id' => $this->faculty->id, 'course_code' => 'CSE220', 'course_name' => 'Data Structures', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        $this->assessment = Assessment::query()->create(['course_id' => $this->course->id, 'title' => 'Midterm', 'total_marks' => 20]);
        $this->q1 = Question::query()->create(['assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Explain how BFS works.', 'marks' => 10, 'cognitive_level' => 'Apply']);
        $this->q2 = Question::query()->create(['assessment_id' => $this->assessment->id, 'question_number' => 2, 'question_text' => 'Compare BFS and DFS.', 'marks' => 10]);
    }

    // ------------------------------------------------------------------ paper review

    protected function fakeReview(): void
    {
        Http::fake(['*/api/v1/toolkit/paper-review' => Http::response(['status' => 'success', 'questions' => [], 'bloom' => ['bands' => []], 'duplicates' => [], 'topics' => [], 'findings' => [], 'note' => 'Checklist'])]);
    }

    public function test_paper_review_of_a_stored_assessment_uses_its_questions_and_course_outcomes(): void
    {
        $this->fakeReview();
        LearningOutcome::query()->create(['course_id' => $this->course->id, 'code' => 'CO1', 'description' => 'Apply graph traversal algorithms.', 'sort_order' => 1]);
        Sanctum::actingAs($this->faculty);

        $this->postJson('/api/ai-toolkit/paper-review', ['assessment_id' => $this->assessment->id])
            ->assertOk()->assertJsonPath('data.topics_source', 'course_outcomes');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/toolkit/paper-review')
            && count($r['questions']) === 2 && $r['questions'][0]['marks'] == 10 && $r['questions'][0]['bloom_level'] === 'APPLY'
            && $r['questions'][1]['bloom_level'] === null && $r['topics'] === ['CO1: Apply graph traversal algorithms.']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_TOOL_USED']);
    }

    public function test_paper_review_for_pasted_questions_access_and_switch(): void
    {
        $this->fakeReview();
        Sanctum::actingAs($this->faculty);
        $this->postJson('/api/ai-toolkit/paper-review', ['questions' => [['text' => 'Define a graph.', 'marks' => 5], ['text' => 'Compare BFS and DFS.']], 'topics' => ['Hashing'], 'targets' => ['lower' => [10, 40]]])
            ->assertOk()->assertJsonPath('data.topics_source', 'pasted');
        Http::assertSent(fn (HttpRequest $r) => $r['topics'] === ['Hashing'] && $r['targets']['lower'] === [10, 40]);
        $this->postJson('/api/ai-toolkit/paper-review', ['questions' => [['text' => 'Only one']]])->assertStatus(422);

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->postJson('/api/ai-toolkit/paper-review', ['assessment_id' => $this->assessment->id])->assertStatus(403);

        app(FeatureFlagService::class)->update(['ai_paper_review' => false]);
        Sanctum::actingAs($this->faculty);
        $this->postJson('/api/ai-toolkit/paper-review', ['assessment_id' => $this->assessment->id])->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    // ------------------------------------------------------------------ class summary

    /** A graded answer with finalized marks and (optionally) rubric evidence from AI grading assistance. */
    protected function graded(int $i, Question $q, float $marks, ?array $criteria = null, ?string $decision = 'ACCEPTED', string $gradingStatus = 'FINALIZED'): void
    {
        $student = Student::query()->create(['created_by' => $this->faculty->id, 'student_identifier' => 'S'.$i.'-'.$q->id, 'name' => 'Student '.$i]);
        $sub = StudentSubmission::query()->firstOrCreate(['assessment_id' => $this->assessment->id, 'student_id' => $student->id],
            ['submission_identifier' => 'SUB-'.$i.'-'.$q->id, 'status' => 'SUBMITTED', 'grading_status' => $gradingStatus]);
        $answer = StudentAnswer::query()->create(['student_submission_id' => $sub->id, 'question_id' => $q->id, 'answer_type' => 'TEXT', 'answer_text' => 'Answer '.$i,
            'awarded_marks' => $marks, 'answer_status' => 'REVIEWED']);
        if ($criteria === null) {
            return;
        }
        $result = AiGradingResult::query()->create(['student_answer_id' => $answer->id, 'student_submission_id' => $sub->id, 'question_id' => $q->id, 'suggested_marks' => $marks, 'maximum_marks' => 10,
            'grading_status' => 'COMPLETED', 'is_current' => true, 'faculty_decision' => $decision, 'requested_by' => $this->faculty->id, 'answer_fingerprint' => "f{$i}{$q->id}", 'context_fingerprint' => 'c',
            'model_name' => 'facultylens-grading-engine', 'generation_method' => 'embedding_rubric_alignment']);
        foreach ($criteria as $n => [$criterion, $level, $missing]) {
            $result->criterionResults()->create(['criterion' => $criterion, 'suggested_marks' => 0, 'maximum_marks' => 5, 'evaluation' => 'x', 'evidence' => [], 'missing_elements' => $missing,
                'coverage_level' => $level, 'sort_order' => $n + 1]);
        }
    }

    public function test_class_summary_explains_what_the_class_struggled_with_from_rubric_evidence(): void
    {
        // Q1: 4 students, "Shortest path" mostly missed; "Traversal order" handled well
        foreach ([[4, 'NOT_ADDRESSED'], [5, 'NOT_ADDRESSED'], [6, 'LIMITED'], [9, 'STRONG']] as $i => [$marks, $level]) {
            $this->graded($i, $this->q1, $marks, [
                ['Traversal order', 'STRONG', []],
                ['Shortest path', $level, $level === 'STRONG' ? [] : ['States that BFS finds the shortest path in an unweighted graph']],
            ]);
        }
        // Q2: graded but without AI grading assistance
        foreach ([8, 9, 7] as $i => $marks) {
            $this->graded(10 + $i, $this->q2, $marks);
        }
        // A rejected AI result is ignored, and so are answers whose grades are not final
        $this->graded(20, $this->q1, 10, [['Shortest path', 'NOT_ADDRESSED', ['Something wrong']]], 'REJECTED');
        $this->graded(21, $this->q1, 0, [['Shortest path', 'NOT_ADDRESSED', ['Not final']]], 'ACCEPTED', 'IN_PROGRESS');
        Sanctum::actingAs($this->faculty);

        $data = $this->postJson("/api/assessments/{$this->assessment->id}/performance/class-summary")->assertOk()->json('data');

        $this->assertSame('ready', $data['status']);
        $this->assertSame(8, $data['stats']['answers']);  // 4 + 3 + the rejected-AI one (its marks are final)
        $this->assertSame(4, $data['stats']['rubric_answers']);
        $struggle = $data['struggles'][0];
        $this->assertSame(['Q1', 'Shortest path', 3, 4], [$struggle['question_number'], $struggle['criterion'], $struggle['weak'], $struggle['responses']]);
        $this->assertSame('BFS finds the shortest path in an unweighted graph', $struggle['top_missing'][0]['text']);
        $this->assertSame(3, $struggle['top_missing'][0]['count']);
        $this->assertSame('Traversal order', $data['strengths'][0]['criterion']);

        $note = $data['note'];
        $this->assertStringContainsString('8 graded answers from 8 students across 2 questions', $note);
        $this->assertStringContainsString('"Shortest path" was missing or barely addressed in 3 of 4 answers', $note);
        $this->assertStringContainsString('Worth revisiting in class: BFS finds the shortest path in an unweighted graph.', $note);
        $this->assertStringContainsString('Rubric detail covers 4 of the 8 graded answers', $note);
        $this->assertStringNotContainsString('Something wrong', json_encode($data));
        $this->assertStringNotContainsString('Not final', json_encode($data));
        $this->assertStringNotContainsString('Student 1', json_encode($data)); // names no student
        $this->assertDatabaseHas('audit_logs', ['action' => 'CLASS_SUMMARY_GENERATED']);
    }

    public function test_class_summary_without_finalized_grades_and_access(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->postJson("/api/assessments/{$this->assessment->id}/performance/class-summary")->assertOk()->assertJsonPath('data.status', 'no_data');

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/assessments/{$this->assessment->id}/performance/class-summary")->assertForbidden();

        app(FeatureFlagService::class)->update(['ai_class_summary' => false]);
        Sanctum::actingAs($this->faculty);
        $this->postJson("/api/assessments/{$this->assessment->id}/performance/class-summary")->assertStatus(403);
    }
}
