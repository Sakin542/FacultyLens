<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeAnswerRubricAlignmentJob;
use App\Models\AnswerRubricAlignment;
use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Question;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Services\RubricAlignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 28: Answer <-> Rubric Alignment. Queue runs synchronously in tests unless Queue::fake().
 */
class RubricAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected User $other;
    protected Course $course;
    protected Assessment $assessment;
    protected Question $question;
    protected Rubric $rubric;
    protected array $criteria = [];
    protected StudentSubmission $submission;
    protected StudentAnswer $answer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->faculty = User::factory()->create(['email' => 'faculty@university.edu']);
        $this->other = User::factory()->create(['email' => 'other@university.edu']);

        $this->course = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE101', 'course_name' => 'Database Systems', 'semester' => 'Fall', 'academic_year' => '2026']);
        $this->assessment = Assessment::create(['course_id' => $this->course->id, 'title' => 'Midterm Examination', 'type' => 'midterm', 'total_marks' => 10, 'status' => 'draft']);
        $this->question = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Explain database normalization.', 'question_type' => 'descriptive', 'marks' => 10]);
        $this->rubric = $this->makeRubric('APPROVED', 1);

        $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'STU001', 'name' => 'Student One']);
        $this->submission = StudentSubmission::create(['assessment_id' => $this->assessment->id, 'student_id' => $student->id, 'status' => 'SUBMITTED', 'grading_status' => 'NOT_STARTED', 'submitted_at' => now(), 'total_marks' => 10]);
        $this->answer = StudentAnswer::create([
            'student_submission_id' => $this->submission->id,
            'question_id' => $this->question->id,
            'answer_type' => 'TEXT',
            'answer_text' => 'Normalization organizes data to reduce redundancy. First normal form requires atomic values.',
            'original_answer_text' => 'Normalization organizes data to reduce redundancy. First normal form requires atomic values.',
            'answer_status' => 'NOT_REVIEWED',
        ]);
    }

    protected function makeRubric(string $status, int $version, array $marks = [2, 2, 2, 2, 2]): Rubric
    {
        $rubric = Rubric::create(['question_id' => $this->question->id, 'assessment_id' => $this->assessment->id, 'created_by' => $this->faculty->id, 'title' => 'Normalization rubric', 'total_marks' => array_sum($marks), 'status' => $status, 'version' => $version, 'generation_method' => 'template_based']);
        $names = ['Definition', '1NF', '2NF', '3NF', 'Example'];
        $this->criteria = [];
        foreach ($marks as $i => $m) {
            $this->criteria[] = RubricCriterion::create(['rubric_id' => $rubric->id, 'criterion' => $names[$i], 'description' => 'Description ' . $i, 'max_marks' => $m, 'expected_indicators' => ['indicator a'], 'sort_order' => $i + 1]);
        }

        return $rubric->load('criteria');
    }

    /** STRONG, STRONG, NOT_ALIGNED, NOT_ALIGNED, NOT_ALIGNED -> 40% */
    protected function aiResponse(array $statuses = ['STRONG', 'STRONG', 'NOT_ALIGNED', 'NOT_ALIGNED', 'NOT_ALIGNED'], array $overrides = []): array
    {
        $weights = AnswerRubricAlignment::ALIGNMENT_WEIGHTS;
        $items = [];
        $num = 0;
        $den = 0;
        $sum = 0;
        foreach ($this->criteria as $i => $c) {
            $s = $statuses[$i];
            $items[] = [
                'rubric_criterion_id' => $c->id,
                'criterion' => $c->criterion,
                'max_marks' => (float) $c->max_marks,
                'alignment_status' => $s,
                'alignment_score' => $weights[$s],
                'similarity' => $weights[$s] * 0.9,
                'evidence' => $s === 'NOT_ALIGNED' ? [] : ['Normalization organizes data to reduce redundancy.'],
                'missing_elements' => $s === 'NOT_ALIGNED' ? ['No evidence found for: indicator a.'] : [],
                'explanation' => "Explanation for {$c->criterion}.",
            ];
            $num += $weights[$s] * (float) $c->max_marks;
            $den += (float) $c->max_marks;
            $sum += $weights[$s];
        }
        $weighted = round($num / $den * 100, 2);

        return array_replace_recursive([
            'status' => 'success',
            'overall_alignment_score' => $weighted,
            'unweighted_alignment_score' => round($sum / count($items) * 100, 2),
            'overall_alignment_status' => $weighted >= 75 ? 'STRONG' : ($weighted >= 45 ? 'PARTIAL' : ($weighted >= 20 ? 'WEAK' : 'NOT_ALIGNED')),
            'counts' => ['strong' => 2, 'partial' => 0, 'weak' => 0, 'not_aligned' => 3],
            'summary' => 'The answer shows limited alignment with the rubric criteria.',
            'strengths' => ["Addresses 'Definition' with clear evidence."],
            'missing_elements' => ['2NF: No evidence found for: indicator a.'],
            'criterion_alignments' => $items,
            'metadata' => ['model' => 'facultylens-rubric-alignment-engine', 'version' => '1.0.0', 'method' => 'semantic_and_rubric_alignment', 'thresholds' => ['strong' => 0.75, 'partial' => 0.55, 'weak' => 0.35]],
        ], $overrides);
    }

    protected function fakeAi(array $response, int $status = 200): void
    {
        Http::fake(['*/api/v1/analyze-answer-rubric-alignment' => Http::response($response, $status)]);
    }

    protected function url(): string
    {
        return "/api/student-answers/{$this->answer->id}/rubric-alignment";
    }

    // ------------------------------------------------------------- requesting

    public function test_requires_authentication(): void
    {
        $this->postJson($this->url())->assertStatus(401);
        $this->getJson($this->url())->assertStatus(401);
    }

    public function test_faculty_can_request_alignment_and_result_is_stored(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());

        $this->postJson($this->url(), ['rubric_id' => 999, 'question_id' => 999])->assertStatus(202)->assertJsonPath('status', 'processing');

        $alignment = AnswerRubricAlignment::current()->first();
        $this->assertSame('COMPLETED', $alignment->analysis_status);
        $this->assertEquals(40.0, (float) $alignment->overall_alignment_score);
        $this->assertEquals(40.0, (float) $alignment->unweighted_alignment_score);
        $this->assertSame('WEAK', $alignment->alignment_status);
        $this->assertSame($this->rubric->id, $alignment->rubric_id);
        $this->assertSame(1, $alignment->rubric_version);
        $this->assertSame('facultylens-rubric-alignment-engine', $alignment->model_name);
        $this->assertSame('1.0.0', $alignment->model_version);
        $this->assertSame('semantic_and_rubric_alignment', $alignment->analysis_method);
        $this->assertNotNull($alignment->answer_fingerprint);
        $this->assertCount(5, $alignment->criterionAlignments);
        $this->assertSame('STRONG', $alignment->criterionAlignments[0]->alignment_status);
        $this->assertEquals(1.0, (float) $alignment->criterionAlignments[0]->alignment_score);
        $this->assertSame(['Normalization organizes data to reduce redundancy.'], $alignment->criterionAlignments[0]->evidence);
        $this->assertSame(['No evidence found for: indicator a.'], $alignment->criterionAlignments[2]->missing_elements);

        // Alignment never touches marks, feedback, status
        $fresh = $this->answer->fresh();
        $this->assertNull($fresh->awarded_marks);
        $this->assertNull($fresh->faculty_feedback);
        $this->assertSame('NOT_REVIEWED', $fresh->answer_status);
        $this->assertSame('NOT_STARTED', $this->submission->fresh()->grading_status);

        $this->assertDatabaseHas('audit_logs', ['action' => 'RUBRIC_ALIGNMENT_REQUESTED', 'entity_id' => $alignment->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'RUBRIC_ALIGNMENT_COMPLETED', 'entity_id' => $alignment->id]);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['question']['text'] === 'Explain database normalization.'
                && $body['rubric']['id'] === $this->rubric->id
                && $body['rubric']['version'] === 1
                && count($body['rubric']['criteria']) === 5
                && !str_contains(json_encode($body), 'Student One');
        });
    }

    public function test_show_returns_current_alignment_with_criteria_and_404_when_none(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->getJson($this->url())->assertStatus(404);

        $this->fakeAi($this->aiResponse());
        $this->postJson($this->url())->assertStatus(202);

        $this->getJson($this->url())
            ->assertStatus(200)
            ->assertJsonPath('data.analysis_status', 'COMPLETED')
            ->assertJsonPath('data.overall_alignment_score', 40)
            ->assertJsonPath('data.alignment_status', 'WEAK')
            ->assertJsonPath('data.is_stale', false)
            ->assertJsonPath('data.counts.strong', 2)
            ->assertJsonPath('data.counts.not_aligned', 3)
            ->assertJsonCount(5, 'data.criterion_alignments')
            ->assertJsonPath('data.criterion_alignments.0.criterion', 'Definition')
            ->assertJsonPath('data.criterion_alignments.0.alignment_status', 'STRONG')
            ->assertJsonPath('data.criterion_alignments.2.alignment_status', 'NOT_ALIGNED')
            ->assertJsonPath('data.thresholds.strong', 0.75);
    }

    public function test_submission_show_embeds_rubric_alignment_separately_from_ai_grading(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->url())->assertStatus(202);

        $this->getJson("/api/submissions/{$this->submission->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.questions.0.answer.rubric_alignment.overall_alignment_score', 40)
            ->assertJsonPath('data.questions.0.answer.ai_grading', null)
            ->assertJsonPath('data.questions.0.answer.awarded_marks', null);
    }

    public function test_request_is_queued_and_pending(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->faculty);
        $this->postJson($this->url())->assertStatus(202)->assertJsonPath('data.analysis_status', 'PENDING');
        Queue::assertPushed(AnalyzeAnswerRubricAlignmentJob::class, 1);
    }

    public function test_duplicate_request_while_active_does_not_create_second_job(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->faculty);
        $first = $this->postJson($this->url())->assertStatus(202)->json('data.id');
        $this->postJson($this->url())->assertStatus(202)->assertJsonPath('data.id', $first);
        Queue::assertPushed(AnalyzeAnswerRubricAlignmentJob::class, 1);
        $this->assertSame(1, AnswerRubricAlignment::count());
    }

    public function test_completed_result_returns_409_until_regenerate(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->url())->assertStatus(202);
        $this->postJson($this->url())->assertStatus(409);
        $this->assertSame(1, AnswerRubricAlignment::count());
    }

    // ------------------------------------------------------------ preconditions

    public function test_requires_approved_rubric(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->rubric->update(['status' => 'DRAFT']);
        $this->postJson($this->url())->assertStatus(422)->assertJsonPath('message', RubricAlignmentService::MSG_NO_RUBRIC);
        $this->assertSame(0, AnswerRubricAlignment::count());
    }

    public function test_image_only_answer_is_rejected(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->answer->update(['answer_text' => null, 'answer_type' => 'IMAGE', 'answer_file_path' => 'x/scan.png', 'answer_file_name' => 'scan.png', 'answer_file_type' => 'image/png']);
        $this->postJson($this->url())->assertStatus(422);
        $this->assertSame(0, AnswerRubricAlignment::count());
    }

    public function test_missing_answer_returns_404(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->postJson('/api/student-answers/99999/rubric-alignment')->assertStatus(404);
    }

    // ------------------------------------------------------------- AI failures

    public function test_malformed_ai_response_is_not_saved(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi(['status' => 'success', 'overall_alignment_score' => 'high']);
        $this->postJson($this->url())->assertStatus(202);

        $alignment = AnswerRubricAlignment::first();
        $this->assertSame('FAILED', $alignment->analysis_status);
        $this->assertNull($alignment->overall_alignment_score);
        $this->assertSame(0, $alignment->criterionAlignments()->count());
        $this->assertStringContainsString('could not validate', $alignment->error_message);
        $this->assertDatabaseHas('audit_logs', ['action' => 'RUBRIC_ALIGNMENT_FAILED']);
    }

    public function test_score_inconsistent_with_criteria_is_rejected(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse(['STRONG', 'STRONG', 'NOT_ALIGNED', 'NOT_ALIGNED', 'NOT_ALIGNED'], ['overall_alignment_score' => 90]));
        $this->postJson($this->url())->assertStatus(202);
        $this->assertSame('FAILED', AnswerRubricAlignment::first()->analysis_status);
    }

    public function test_unknown_status_or_weight_mismatch_is_rejected(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse(['STRONG', 'STRONG', 'NOT_ALIGNED', 'NOT_ALIGNED', 'NOT_ALIGNED'], ['criterion_alignments' => [0 => ['alignment_status' => 'CORRECT']]]));
        $this->postJson($this->url())->assertStatus(202);
        $this->assertSame('FAILED', AnswerRubricAlignment::first()->analysis_status);

        $this->fakeAi($this->aiResponse(['STRONG', 'STRONG', 'NOT_ALIGNED', 'NOT_ALIGNED', 'NOT_ALIGNED'], ['criterion_alignments' => [0 => ['alignment_score' => 0.8]]]));
        $this->postJson($this->url())->assertStatus(202);
        $this->assertSame('FAILED', AnswerRubricAlignment::current()->first()->analysis_status);
    }

    public function test_unknown_or_missing_criterion_is_rejected(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse(['STRONG', 'STRONG', 'NOT_ALIGNED', 'NOT_ALIGNED', 'NOT_ALIGNED'], ['criterion_alignments' => [0 => ['rubric_criterion_id' => 99999]]]));
        $this->postJson($this->url())->assertStatus(202);
        $this->assertSame('FAILED', AnswerRubricAlignment::first()->analysis_status);
    }

    public function test_ai_unavailable_marks_failed_without_result(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/analyze-answer-rubric-alignment' => fn () => throw new ConnectionException('Connection refused')]);
        $this->postJson($this->url())->assertStatus(202);
        $a = AnswerRubricAlignment::first();
        $this->assertSame('FAILED', $a->analysis_status);
        $this->assertNull($a->overall_alignment_score);
        $this->assertStringContainsString('temporarily unavailable', $a->error_message);
    }

    public function test_ai_timeout_marks_failed(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/analyze-answer-rubric-alignment' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $this->postJson($this->url())->assertStatus(202);
        $this->assertStringContainsString('took too long', AnswerRubricAlignment::current()->first()->error_message);
    }

    public function test_ai_500_marks_failed(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi(['detail' => 'boom'], 500);
        $this->postJson($this->url())->assertStatus(202);
        $this->assertSame('FAILED', AnswerRubricAlignment::first()->analysis_status);
    }

    // ------------------------------------------------------- regenerate/review

    public function test_regenerate_preserves_history_and_marks_old_not_current(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/analyze-answer-rubric-alignment' => Http::sequence()
            ->push($this->aiResponse(), 200)
            ->push($this->aiResponse(['STRONG', 'STRONG', 'PARTIAL', 'WEAK', 'NOT_ALIGNED'], ['counts' => ['strong' => 2, 'partial' => 1, 'weak' => 1, 'not_aligned' => 1]]), 200)]);

        $firstId = $this->postJson($this->url())->json('data.id');
        $this->postJson("/api/rubric-alignments/{$firstId}/regenerate")->assertStatus(202);

        $this->assertSame(2, AnswerRubricAlignment::count());
        $old = AnswerRubricAlignment::find($firstId);
        $this->assertFalse($old->is_current);
        $this->assertEquals(40.0, (float) $old->overall_alignment_score);
        $new = AnswerRubricAlignment::current()->first();
        $this->assertEquals(55.0, (float) $new->overall_alignment_score); // (2+2+1+0.5+0)/10
        $this->assertSame('PARTIAL', $new->alignment_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'RUBRIC_ALIGNMENT_REGENERATED', 'entity_id' => $new->id]);

        $this->getJson($this->url() . '/history')->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_faculty_can_mark_reviewed_without_changing_marks(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $id = $this->postJson($this->url())->json('data.id');

        $this->postJson("/api/rubric-alignments/{$id}/review")
            ->assertStatus(200)
            ->assertJsonPath('data.analysis_status', 'REVIEWED')
            ->assertJsonPath('data.reviewed_by', $this->faculty->id);

        $this->assertNull($this->answer->fresh()->awarded_marks);
        $this->assertDatabaseHas('audit_logs', ['action' => 'RUBRIC_ALIGNMENT_REVIEWED', 'entity_id' => $id]);
    }

    public function test_pending_alignment_cannot_be_reviewed(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->faculty);
        $id = $this->postJson($this->url())->json('data.id');
        $this->postJson("/api/rubric-alignments/{$id}/review")->assertStatus(422);
    }

    // -------------------------------------------------------------- staleness

    public function test_stale_when_answer_changes_and_can_be_re_requested(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->url())->assertStatus(202);

        $this->putJson("/api/student-answers/{$this->answer->id}", ['answer_text' => 'A different answer.'])->assertStatus(200);
        $this->getJson($this->url())->assertJsonPath('data.is_stale', true)
            ->assertJsonPath('data.stale_reasons.0', 'The student answer was modified after this analysis.');

        $this->postJson($this->url())->assertStatus(202);
        $this->assertSame(2, AnswerRubricAlignment::count());
    }

    public function test_stale_when_rubric_version_changes(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->url())->assertStatus(202);

        $this->rubric->update(['status' => 'ARCHIVED']);
        $this->makeRubric('APPROVED', 2);

        $res = $this->getJson($this->url())->assertJsonPath('data.is_stale', true)->assertJsonPath('data.rubric_version', 1);
        $this->assertStringContainsString('rubric was modified', $res->json('data.stale_reasons.0'));
    }

    // ----------------------------------------------------------- authorization

    public function test_cross_faculty_access_is_blocked(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $id = $this->postJson($this->url())->json('data.id');

        Sanctum::actingAs($this->other);
        $this->postJson($this->url())->assertStatus(403);
        $this->getJson($this->url())->assertStatus(403);
        $this->getJson($this->url() . '/history')->assertStatus(403);
        $this->postJson("/api/rubric-alignments/{$id}/regenerate")->assertStatus(403);
        $this->postJson("/api/rubric-alignments/{$id}/review")->assertStatus(403);

        Http::assertSentCount(1);
        $this->assertSame(1, AnswerRubricAlignment::count());
    }

    public function test_admin_can_access(): void
    {
        Sanctum::actingAs(User::factory()->create(['email' => 'admin@university.edu', 'role' => 'admin']));
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->url())->assertStatus(202);
    }

    // ------------------------------------------------------------ privacy/cleanup

    public function test_audit_logs_never_contain_answer_text(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->url())->assertStatus(202);
        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString('atomic values', json_encode($log->metadata));
        }
    }

    public function test_deleting_answer_removes_alignments(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->url())->assertStatus(202);
        $this->deleteJson("/api/student-answers/{$this->answer->id}")->assertStatus(200);
        $this->assertSame(0, AnswerRubricAlignment::count());
        $this->assertDatabaseCount('answer_rubric_criterion_alignments', 0);
    }
}
