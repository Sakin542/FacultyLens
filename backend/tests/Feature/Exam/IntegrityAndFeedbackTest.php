<?php

namespace Tests\Feature\Exam;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\Question;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Services\Exam\AnswerSimilarityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrityAndFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected User $stranger;
    protected Course $course;
    protected Assessment $assessment;
    protected Question $q1;
    protected Question $q2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faculty = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->course = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE311', 'course_name' => 'Database Systems', 'semester' => 'Spring', 'academic_year' => '2026']);
        $this->assessment = Assessment::create(['course_id' => $this->course->id, 'title' => 'Quiz 1', 'type' => 'quiz', 'total_marks' => 20, 'status' => 'draft']);
        $this->q1 = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Explain why indexes speed up queries.', 'question_type' => 'descriptive', 'marks' => 10, 'difficulty_level' => 'easy']);
        $this->q2 = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 2, 'question_text' => 'Analyze a deadlock scenario.', 'question_type' => 'descriptive', 'marks' => 10, 'difficulty_level' => 'hard']);
    }

    protected function submission(int $i, array $marks, array $texts = []): StudentSubmission
    {
        $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => sprintf('S%03d', $i), 'name' => "Student {$i}"]);
        $sub = StudentSubmission::create(['assessment_id' => $this->assessment->id, 'student_id' => $student->id, 'status' => 'GRADED', 'grading_status' => 'FACULTY_REVIEWED', 'submitted_at' => now(), 'total_marks' => 20]);
        foreach ([$this->q1, $this->q2] as $k => $q) {
            StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $q->id, 'answer_type' => 'TEXT', 'answer_text' => $texts[$k] ?? "answer {$i}-{$k}", 'awarded_marks' => $marks[$k], 'answer_status' => 'REVIEWED']);
        }

        return $sub;
    }

    // ------------------------------------------------------------------ item analysis

    public function test_item_analysis_computes_difficulty_discrimination_and_reliability(): void
    {
        // Q1: everyone does well regardless of ability (easy, poor discrimination). Q2: strong students do well.
        $rows = [[10, 10], [9, 9], [10, 8], [9, 6], [10, 4], [9, 2], [10, 1], [9, 0], [10, 0], [9, 0]];
        foreach ($rows as $i => $m) {
            $this->submission($i, $m);
        }
        // An ungraded submission is excluded, not counted as zero
        $sub = $this->submission(99, [5, 5]);
        $sub->answers()->first()->update(['awarded_marks' => null]);

        $data = $this->actingAs($this->faculty, 'sanctum')->getJson("/api/assessments/{$this->assessment->id}/item-analysis")->assertOk()->json('data');

        $this->assertTrue($data['sufficient']);
        $this->assertSame(10, $data['submissions_analyzed']);
        $this->assertSame(1, $data['submissions_excluded_ungraded']);
        [$i1, $i2] = $data['items'];
        $this->assertEqualsWithDelta(0.95, $i1['difficulty_index'], 0.001);
        $this->assertContains('too_easy', $i1['flags']);
        $this->assertSame('excellent', $i2['discrimination_band']);
        // With two items the "rest" of Q2 is Q1, which barely varies, so the correlation is weak but defined
        $this->assertNotNull($i2['item_rest_correlation']);
        $this->assertLessThan(0.5, abs($i2['item_rest_correlation']));
        $this->assertSame('hard', $i2['labelled_difficulty']);
        $this->assertNotNull($data['summary']['cronbach_alpha']);
        // Aggregate only: no student identities in the payload
        $this->assertStringNotContainsString('Student 1', json_encode($data));
    }

    public function test_item_analysis_requires_enough_graded_submissions(): void
    {
        foreach (range(1, 3) as $i) {
            $this->submission($i, [5, 5]);
        }
        $this->actingAs($this->faculty, 'sanctum')->getJson("/api/assessments/{$this->assessment->id}/item-analysis")
            ->assertOk()->assertJsonPath('data.sufficient', false);
        $this->actingAs($this->stranger, 'sanctum')->getJson("/api/assessments/{$this->assessment->id}/item-analysis")->assertForbidden();
    }

    // ------------------------------------------------------------------ plagiarism

    public function test_plagiarism_check_flags_copied_answers_and_ignores_quoted_question(): void
    {
        Http::fake(['*/api/v1/toolkit/answer-embeddings' => Http::response(['detail' => 'down'], 503), '*/api/v1/embeddings/batch' => Http::response(['detail' => 'down'], 503)]);
        $copied = 'An index keeps a sorted structure such as a B+ tree so the database jumps to matching rows instead of scanning the entire table from start to finish.';
        $this->submission(1, [8, 5], [$copied]);
        $this->submission(2, [8, 5], ['My view: '.$copied]);
        $this->submission(3, [6, 5], ['Indexes reduce disk reads because lookups use logarithmic search over ordered keys rather than reading each page sequentially, which matters for large relations.']);
        // Quoting the question is not copying
        $this->submission(4, [2, 5], ['Explain why indexes speed up queries. Explain why indexes speed up queries. Honestly not sure here at all.']);
        $this->submission(5, [2, 5], ['Explain why indexes speed up queries. I think it is about memory caching and the buffer pool in practice.']);

        $res = $this->actingAs($this->faculty, 'sanctum')->postJson("/api/assessments/{$this->assessment->id}/plagiarism-checks")->assertCreated();

        $data = $res->json('data');
        $this->assertSame('lexical', $data['method']);
        $this->assertTrue($data['summary']['semantic_unavailable']);
        $this->assertSame(1, $data['pairs_flagged']);
        $match = $data['matches'][0];
        $this->assertEqualsCanonicalizing(['S001', 'S002'], [$match['a']['student']['student_identifier'], $match['b']['student']['student_identifier']]);
        $this->assertStringContainsString('sorted structure such as a b tree', $match['shared_excerpt']);

        $this->actingAs($this->faculty, 'sanctum')->patchJson("/api/plagiarism-matches/{$match['id']}", ['review_status' => 'dismissed', 'review_note' => 'Group work allowed'])
            ->assertOk()->assertJsonPath('data.review_status', 'dismissed');
        $this->actingAs($this->faculty, 'sanctum')->getJson("/api/assessments/{$this->assessment->id}/plagiarism-checks/latest")
            ->assertOk()->assertJsonPath('data.matches.0.review_note', 'Group work allowed');

        $this->actingAs($this->stranger, 'sanctum')->postJson("/api/assessments/{$this->assessment->id}/plagiarism-checks")->assertForbidden();
    }

    public function test_lexical_score_is_symmetric_and_bounded(): void
    {
        $svc = app(AnswerSimilarityService::class);
        $a = $svc->shingles($svc->normalize('the quick brown fox jumps over the lazy dog'));
        $b = $svc->shingles($svc->normalize('the quick brown fox jumps over a sleepy cat'));
        $this->assertEqualsWithDelta($svc->lexical($a, $b), $svc->lexical($b, $a), 1e-9);
        $this->assertEqualsWithDelta(1.0, $svc->lexical($a, $a), 1e-9);
        $this->assertSame(0.0, $svc->lexical($a, []));
    }

    // ------------------------------------------------------------------ feedback comment bank

    public function test_comment_bank_is_private_and_ranks_by_usage(): void
    {
        $make = fn (array $data) => $this->actingAs($this->faculty, 'sanctum')->postJson('/api/feedback-comments', $data)->assertCreated()->json('data');
        $general = $make(['title' => 'Good structure', 'body' => 'Well organized answer with a clear conclusion.', 'tone' => 'positive']);
        $specific = $make(['title' => 'Missing example', 'body' => 'Add a concrete SQL example.', 'tone' => 'improvement', 'course_id' => $this->course->id, 'criterion' => 'Use of examples']);

        $this->actingAs($this->faculty, 'sanctum')->postJson("/api/feedback-comments/{$specific['id']}/use")->assertOk()->assertJsonPath('data.usage_count', 1);

        $list = $this->actingAs($this->faculty, 'sanctum')->getJson("/api/feedback-comments?course_id={$this->course->id}")->assertOk()->json('data');
        $this->assertSame(['Missing example', 'Good structure'], array_column($list, 'title'));
        $this->actingAs($this->faculty, 'sanctum')->getJson('/api/feedback-comments?tone=positive')->assertJsonCount(1, 'data');

        // Another faculty member cannot see, use, edit or delete it
        $this->actingAs($this->stranger, 'sanctum')->getJson('/api/feedback-comments')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->stranger, 'sanctum')->patchJson("/api/feedback-comments/{$general['id']}", ['title' => 'x'])->assertNotFound();
        $this->actingAs($this->stranger, 'sanctum')->postJson('/api/feedback-comments', ['title' => 'x', 'body' => 'y', 'course_id' => $this->course->id])->assertForbidden();

        $this->actingAs($this->faculty, 'sanctum')->deleteJson("/api/feedback-comments/{$general['id']}")->assertOk();
        $this->assertDatabaseMissing('feedback_comments', ['id' => $general['id']]);
    }
}
