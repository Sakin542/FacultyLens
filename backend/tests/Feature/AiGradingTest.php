<?php

namespace Tests\Feature;

use App\Jobs\GradeAnswerJob;
use App\Models\AiGradingResult;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 27: AI Grading Assistance. Queue runs synchronously in tests (QUEUE_CONNECTION=sync),
 * so a request completes the whole pipeline unless Queue::fake() is used.
 */
class AiGradingTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected User $other;
    protected Course $course;
    protected Assessment $assessment;
    protected Question $question;
    protected Rubric $rubric;
    protected array $criteria = [];
    protected Student $student;
    protected StudentSubmission $submission;
    protected StudentAnswer $answer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->faculty = User::factory()->create(['email' => 'faculty@university.edu']);
        $this->other = User::factory()->create(['email' => 'other@university.edu']);

        $this->course = Course::create([
            'user_id' => $this->faculty->id,
            'course_code' => 'CSE101',
            'course_name' => 'Database Systems',
            'semester' => 'Fall',
            'academic_year' => '2026',
        ]);
        $this->assessment = Assessment::create([
            'course_id' => $this->course->id,
            'title' => 'Midterm Examination',
            'type' => 'midterm',
            'total_marks' => 10,
            'status' => 'draft',
        ]);
        $this->question = Question::create([
            'assessment_id' => $this->assessment->id,
            'question_number' => 1,
            'question_text' => 'Explain database normalization.',
            'question_type' => 'descriptive',
            'marks' => 10,
            'difficulty_level' => 'medium',
            'cognitive_level' => 'Understand',
        ]);

        $this->rubric = $this->makeRubric('APPROVED', 1);

        $this->student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'STU001', 'name' => 'Student One']);
        $this->submission = StudentSubmission::create([
            'assessment_id' => $this->assessment->id,
            'student_id' => $this->student->id,
            'status' => 'SUBMITTED',
            'grading_status' => 'NOT_STARTED',
            'submitted_at' => now(),
            'total_marks' => 10,
        ]);
        $this->answer = StudentAnswer::create([
            'student_submission_id' => $this->submission->id,
            'question_id' => $this->question->id,
            'answer_type' => 'TEXT',
            'answer_text' => 'Normalization is a process used to organize data and reduce redundancy. 1NF requires atomic values.',
            'original_answer_text' => 'Normalization is a process used to organize data and reduce redundancy. 1NF requires atomic values.',
            'answer_status' => 'NOT_REVIEWED',
        ]);
    }

    protected function makeRubric(string $status, int $version, array $marks = [2, 2, 2, 2, 2]): Rubric
    {
        $rubric = Rubric::create([
            'question_id' => $this->question->id,
            'assessment_id' => $this->assessment->id,
            'created_by' => $this->faculty->id,
            'title' => 'Normalization rubric',
            'total_marks' => array_sum($marks),
            'status' => $status,
            'version' => $version,
            'generation_method' => 'template_based',
        ]);
        $names = ['Definition', '1NF', '2NF', '3NF', 'Example'];
        $this->criteria = [];
        foreach ($marks as $i => $m) {
            $this->criteria[] = RubricCriterion::create([
                'rubric_id' => $rubric->id,
                'criterion' => $names[$i] ?? "Criterion {$i}",
                'description' => 'Description ' . $i,
                'max_marks' => $m,
                'expected_indicators' => ['indicator a', 'indicator b'],
                'sort_order' => $i + 1,
            ]);
        }

        return $rubric->load('criteria');
    }

    protected function aiResponse(array $criterionMarks = [2, 2, 1, 1, 1.5], array $overrides = []): array
    {
        $results = [];
        foreach ($this->criteria as $i => $c) {
            $results[] = [
                'rubric_criterion_id' => $c->id,
                'criterion' => $c->criterion,
                'suggested_marks' => $criterionMarks[$i],
                'maximum_marks' => (float) $c->max_marks,
                'evaluation' => "Evaluation for {$c->criterion}.",
                'evidence' => ['Student mentions reduction of redundancy'],
                'missing_elements' => $criterionMarks[$i] < $c->max_marks ? ['More detail needed'] : [],
                'coverage_level' => $criterionMarks[$i] >= $c->max_marks ? 'STRONG' : 'PARTIAL',
            ];
        }

        return array_replace_recursive([
            'status' => 'success',
            'suggested_marks' => array_sum($criterionMarks),
            'maximum_marks' => 10,
            'criterion_results' => $results,
            'overall_feedback' => 'The answer demonstrates a reasonable understanding.',
            'strengths' => ['Correct definition'],
            'missing_elements' => ['2NF explanation', '3NF explanation'],
            'evaluation_summary' => 'Suggested 7.5 of 10 marks.',
            'metadata' => [
                'model' => 'facultylens-grading-engine',
                'version' => '1.0.0',
                'generation_method' => 'embedding_rubric_alignment',
                'generative_model_used' => false,
            ],
        ], $overrides);
    }

    protected function fakeAi(array $response, int $status = 200): void
    {
        Http::fake(['*/api/v1/grade-answer' => Http::response($response, $status)]);
    }

    protected function gradeUrl(): string
    {
        return "/api/student-answers/{$this->answer->id}/ai-grade";
    }

    // ------------------------------------------------------------- requesting

    public function test_requires_authentication(): void
    {
        $this->postJson($this->gradeUrl())->assertStatus(401);
        $this->getJson("/api/student-answers/{$this->answer->id}/ai-grading")->assertStatus(401);
    }

    public function test_faculty_can_request_ai_grading_and_result_is_stored_with_criteria(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());

        $response = $this->postJson($this->gradeUrl(), ['question_id' => 999, 'maximum_marks' => 100]);
        $response->assertStatus(202)->assertJsonPath('status', 'processing');

        $result = AiGradingResult::where('student_answer_id', $this->answer->id)->current()->first();
        $this->assertNotNull($result);
        $this->assertSame('COMPLETED', $result->grading_status);
        $this->assertEquals(7.5, (float) $result->suggested_marks);
        $this->assertEquals(10, (float) $result->maximum_marks);
        $this->assertSame($this->rubric->id, $result->rubric_id);
        $this->assertSame(1, $result->rubric_version);
        $this->assertSame('facultylens-grading-engine', $result->model_name);
        $this->assertCount(5, $result->criterionResults);
        $this->assertEquals(2, (float) $result->criterionResults[0]->suggested_marks);
        $this->assertSame(['Student mentions reduction of redundancy'], $result->criterionResults[0]->evidence);

        // AI marks never touch faculty marks
        $this->answer->refresh();
        $this->assertNull($this->answer->awarded_marks);
        $this->assertSame('NOT_REVIEWED', $this->answer->answer_status);

        // Submission grading status reflects AI assistance
        $this->assertSame('AI_ASSISTED', $this->submission->fresh()->grading_status);

        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_GRADING_REQUESTED', 'entity_id' => $result->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_GRADING_COMPLETED', 'entity_id' => $result->id]);

        // Payload was built from trusted data and did not include student PII
        Http::assertSent(function ($request) {
            $body = $request->data();
            return $body['question']['total_marks'] == 10
                && $body['question']['text'] === 'Explain database normalization.'
                && $body['rubric']['id'] === $this->rubric->id
                && count($body['rubric']['criteria']) === 5
                && !isset($body['student'])
                && !str_contains(json_encode($body), 'Student One');
        });
    }

    public function test_result_endpoint_returns_current_result_with_criteria(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->gradeUrl())->assertStatus(202);

        $this->getJson("/api/student-answers/{$this->answer->id}/ai-grading")
            ->assertStatus(200)
            ->assertJsonPath('data.grading_status', 'COMPLETED')
            ->assertJsonPath('data.suggested_marks', 7.5)
            ->assertJsonPath('data.maximum_marks', 10)
            ->assertJsonPath('data.is_stale', false)
            ->assertJsonCount(5, 'data.criterion_results')
            ->assertJsonPath('data.criterion_results.0.criterion', 'Definition')
            ->assertJsonPath('data.strengths.0', 'Correct definition');
    }

    public function test_result_endpoint_404_when_no_result(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->getJson("/api/student-answers/{$this->answer->id}/ai-grading")->assertStatus(404);
    }

    public function test_submission_show_includes_ai_grading_for_answers(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->gradeUrl())->assertStatus(202);

        $this->getJson("/api/submissions/{$this->submission->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.questions.0.answer.ai_grading.suggested_marks', 7.5)
            ->assertJsonPath('data.questions.0.answer.ai_grading.grading_status', 'COMPLETED')
            ->assertJsonPath('data.questions.0.answer.awarded_marks', null);
    }

    public function test_request_is_queued_and_pending_before_processing(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->faculty);

        $this->postJson($this->gradeUrl())
            ->assertStatus(202)
            ->assertJsonPath('data.grading_status', 'PENDING');

        Queue::assertPushed(GradeAnswerJob::class, 1);
    }

    public function test_duplicate_request_while_pending_does_not_create_second_job(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->faculty);

        $first = $this->postJson($this->gradeUrl())->assertStatus(202)->json('data.id');
        $second = $this->postJson($this->gradeUrl())->assertStatus(202);
        $second->assertJsonPath('data.id', $first);
        $this->assertStringContainsString('already in progress', $second->json('message'));

        Queue::assertPushed(GradeAnswerJob::class, 1);
        $this->assertSame(1, AiGradingResult::where('student_answer_id', $this->answer->id)->count());
    }

    public function test_request_with_existing_completed_result_returns_409(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->gradeUrl())->assertStatus(202);

        $this->postJson($this->gradeUrl())->assertStatus(409);
        $this->assertSame(1, AiGradingResult::count());
    }

    // ------------------------------------------------------------ preconditions

    public function test_request_without_approved_rubric_is_rejected(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->rubric->update(['status' => 'DRAFT']);

        $this->postJson($this->gradeUrl())
            ->assertStatus(422)
            ->assertJsonPath('message', 'No approved rubric is available for this question. Create or approve a rubric before requesting AI grading assistance.');
        $this->assertSame(0, AiGradingResult::count());
    }

    public function test_request_with_rubric_total_mismatch_is_rejected(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->question->update(['marks' => 12]);

        $this->postJson($this->gradeUrl())->assertStatus(422);
    }

    public function test_image_only_answer_is_rejected_explicitly(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->answer->update([
            'answer_text' => null,
            'answer_type' => 'IMAGE',
            'answer_file_path' => 'student-answers/x/scan.png',
            'answer_file_name' => 'scan.png',
            'answer_file_type' => 'image/png',
        ]);

        $this->postJson($this->gradeUrl())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Image-based AI grading is not currently supported. Add the answer text to request AI grading assistance.');
        $this->assertSame(0, AiGradingResult::count());
    }

    public function test_txt_file_answer_is_extracted_and_graded(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());

        $path = 'student-answers/user_1/assessment_1/submission_1/answer.txt';
        Storage::disk('local')->put($path, 'Normalization organizes data into tables to reduce redundancy and dependency.');
        $this->answer->update([
            'answer_text' => null,
            'answer_type' => 'FILE',
            'answer_file_path' => $path,
            'answer_file_name' => 'answer.txt',
            'answer_file_type' => 'text/plain',
            'answer_file_size' => 80,
        ]);

        $this->postJson($this->gradeUrl())->assertStatus(202);
        $this->assertSame('COMPLETED', AiGradingResult::first()->grading_status);
        Http::assertSent(fn ($request) => str_contains($request->data()['student_answer']['text'], 'reduce redundancy'));
    }

    public function test_missing_answer_returns_404(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->postJson('/api/student-answers/99999/ai-grade')->assertStatus(404);
    }

    // ------------------------------------------------------------- AI failures

    public function test_malformed_ai_response_is_not_saved(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi(['status' => 'success', 'suggested_marks' => 'seven']);

        $this->postJson($this->gradeUrl())->assertStatus(202);
        $result = AiGradingResult::first();
        $this->assertSame('FAILED', $result->grading_status);
        $this->assertNull($result->suggested_marks);
        $this->assertSame(0, $result->criterionResults()->count());
        $this->assertStringContainsString('could not validate', $result->error_message);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_GRADING_FAILED']);
        $this->assertNull($this->answer->fresh()->awarded_marks);
    }

    public function test_criterion_total_mismatch_is_rejected(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse([2, 2, 2, 2, 0], ['suggested_marks' => 7]));

        $this->postJson($this->gradeUrl())->assertStatus(202);
        $this->assertSame('FAILED', AiGradingResult::first()->grading_status);
    }

    public function test_criterion_marks_above_maximum_are_rejected(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse([3, 2, 1, 1, 1], ['suggested_marks' => 8]));

        $this->postJson($this->gradeUrl())->assertStatus(202);
        $this->assertSame('FAILED', AiGradingResult::first()->grading_status);
    }

    public function test_negative_or_over_total_marks_are_rejected(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse([2, 2, 2, 2, 2], ['suggested_marks' => 11, 'criterion_results' => [4 => ['suggested_marks' => 3]]]));
        $this->postJson($this->gradeUrl())->assertStatus(202);
        $this->assertSame('FAILED', AiGradingResult::first()->grading_status);
    }

    public function test_unknown_criterion_in_ai_response_is_rejected(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse([2, 2, 1, 1, 1.5], ['criterion_results' => [0 => ['rubric_criterion_id' => 999999]]]));
        $this->postJson($this->gradeUrl())->assertStatus(202);
        $this->assertSame('FAILED', AiGradingResult::first()->grading_status);
    }

    public function test_ai_service_unavailable_marks_result_failed_without_fake_grade(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/grade-answer' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused')]);

        $this->postJson($this->gradeUrl())->assertStatus(202);
        $result = AiGradingResult::first();
        $this->assertSame('FAILED', $result->grading_status);
        $this->assertStringContainsString('temporarily unavailable', $result->error_message);
        $this->assertNull($result->suggested_marks);
    }

    public function test_ai_service_timeout_marks_result_failed(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/grade-answer' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out')]);

        $this->postJson($this->gradeUrl())->assertStatus(202);
        $this->assertStringContainsString('took too long', AiGradingResult::first()->error_message);
    }

    public function test_ai_service_500_marks_result_failed(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi(['detail' => 'boom'], 500);

        $this->postJson($this->gradeUrl())->assertStatus(202);
        $this->assertSame('FAILED', AiGradingResult::first()->grading_status);
    }

    public function test_failed_result_can_be_retried(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/grade-answer' => Http::sequence()
            ->push(['detail' => 'boom'], 500)
            ->push($this->aiResponse(), 200)]);

        $this->postJson($this->gradeUrl())->assertStatus(202);
        $this->assertSame('FAILED', AiGradingResult::first()->grading_status);

        $this->postJson($this->gradeUrl())->assertStatus(202);
        $this->assertSame(2, AiGradingResult::count());
        $this->assertSame('COMPLETED', AiGradingResult::current()->first()->grading_status);
    }

    // ------------------------------------------------------------ regenerate

    public function test_regenerate_creates_new_run_and_preserves_old(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/grade-answer' => Http::sequence()
            ->push($this->aiResponse([2, 2, 1, 1, 1.5]), 200)
            ->push($this->aiResponse([2, 2, 2, 1, 1]), 200)]);

        $firstId = $this->postJson($this->gradeUrl())->json('data.id');

        $this->postJson("/api/ai-grading/{$firstId}/regenerate")
            ->assertStatus(202)
            ->assertJsonPath('status', 'processing');

        $this->assertSame(2, AiGradingResult::count());
        $old = AiGradingResult::find($firstId);
        $this->assertFalse($old->is_current);
        $this->assertEquals(7.5, (float) $old->suggested_marks);
        $new = AiGradingResult::current()->first();
        $this->assertNotSame($firstId, $new->id);
        $this->assertEquals(8, (float) $new->suggested_marks);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_GRADING_REGENERATED', 'entity_id' => $new->id]);

        $this->getJson("/api/student-answers/{$this->answer->id}/ai-grading/history")
            ->assertStatus(200)->assertJsonCount(2, 'data');
    }

    // -------------------------------------------------------------- staleness

    public function test_result_is_stale_when_answer_changes(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->gradeUrl())->assertStatus(202);

        $this->putJson("/api/student-answers/{$this->answer->id}", ['answer_text' => 'A completely different answer.'])->assertStatus(200);

        $this->getJson("/api/student-answers/{$this->answer->id}/ai-grading")
            ->assertStatus(200)
            ->assertJsonPath('data.is_stale', true)
            ->assertJsonPath('data.stale_reasons.0', 'The student answer was modified after this evaluation.');

        // Stale result can be re-requested without 409
        $this->postJson($this->gradeUrl())->assertStatus(202);
        $this->assertSame(2, AiGradingResult::count());
    }

    public function test_result_is_stale_when_rubric_version_changes(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->gradeUrl())->assertStatus(202);

        $this->rubric->update(['status' => 'ARCHIVED']);
        $this->makeRubric('APPROVED', 2);

        $show = $this->getJson("/api/student-answers/{$this->answer->id}/ai-grading")->assertStatus(200);
        $show->assertJsonPath('data.is_stale', true)
            ->assertJsonPath('data.rubric_id', $this->rubric->id)
            ->assertJsonPath('data.rubric_version', 1);
        $this->assertStringContainsString('rubric was modified', $show->json('data.stale_reasons.0'));
    }

    public function test_result_is_stale_when_rubric_is_no_longer_approved(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->gradeUrl())->assertStatus(202);

        $this->rubric->update(['status' => 'DRAFT']);

        $this->getJson("/api/student-answers/{$this->answer->id}/ai-grading")
            ->assertJsonPath('data.is_stale', true)
            ->assertJsonPath('data.stale_reasons.0', 'The rubric used for this evaluation is no longer approved.');
    }

    // ----------------------------------------------------------- authorization

    public function test_other_faculty_cannot_request_view_or_regenerate(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $resultId = $this->postJson($this->gradeUrl())->json('data.id');

        Sanctum::actingAs($this->other);
        $this->postJson($this->gradeUrl())->assertStatus(403);
        $this->getJson("/api/student-answers/{$this->answer->id}/ai-grading")->assertStatus(403);
        $this->getJson("/api/student-answers/{$this->answer->id}/ai-grading/history")->assertStatus(403);
        $this->postJson("/api/ai-grading/{$resultId}/regenerate")->assertStatus(403);
        $this->postJson("/api/ai-grading/{$resultId}/reject")->assertStatus(403);
        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", ['final_marks' => 5])->assertStatus(403);

        Http::assertSentCount(1);
        $this->assertSame(1, AiGradingResult::count());
        $this->assertNull($this->answer->fresh()->awarded_marks);
    }

    public function test_admin_can_access(): void
    {
        $admin = User::factory()->create(['email' => 'admin@university.edu', 'role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->gradeUrl())->assertStatus(202);
    }

    // ------------------------------------------------------------ final grade

    public function test_faculty_can_accept_suggested_marks(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $resultId = $this->postJson($this->gradeUrl())->json('data.id');

        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", ['final_marks' => 7.5, 'decision' => 'ACCEPTED'])
            ->assertStatus(200)
            ->assertJsonPath('data.awarded_marks', 7.5)
            ->assertJsonPath('data.answer_status', 'REVIEWED')
            ->assertJsonPath('data.ai_grading.grading_status', 'FINALIZED')
            ->assertJsonPath('data.ai_grading.faculty_decision', 'ACCEPTED')
            ->assertJsonPath('data.ai_grading.suggested_marks', 7.5);

        $result = AiGradingResult::find($resultId);
        $this->assertEquals(7.5, (float) $result->suggested_marks);
        $this->assertSame($this->faculty->id, $result->reviewed_by);
        $this->assertNotNull($result->reviewed_at);
        $this->assertSame('FACULTY_REVIEWED', $this->submission->fresh()->grading_status);
        $this->assertEquals(7.5, (float) $this->submission->fresh()->awarded_marks);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_SUGGESTION_ACCEPTED', 'entity_id' => $resultId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'GRADE_FINALIZED', 'entity_id' => $this->answer->id]);
    }

    public function test_faculty_can_edit_final_marks_without_overwriting_ai_marks(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $resultId = $this->postJson($this->gradeUrl())->json('data.id');

        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", [
            'final_marks' => 8,
            'faculty_feedback' => 'Good understanding, but provide more detail on 2NF and 3NF.',
        ])->assertStatus(200)
            ->assertJsonPath('data.awarded_marks', 8)
            ->assertJsonPath('data.faculty_feedback', 'Good understanding, but provide more detail on 2NF and 3NF.')
            ->assertJsonPath('data.ai_grading.faculty_decision', 'MODIFIED')
            ->assertJsonPath('data.ai_grading.suggested_marks', 7.5);

        $this->assertEquals(7.5, (float) AiGradingResult::find($resultId)->suggested_marks);
        $this->assertEquals(8, (float) $this->answer->fresh()->awarded_marks);
        // AI feedback stays on the result, faculty feedback on the answer
        $this->assertSame('The answer demonstrates a reasonable understanding.', AiGradingResult::find($resultId)->overall_feedback);

        $log = AuditLog::where('action', 'AI_SUGGESTION_MODIFIED')->first();
        $this->assertEquals(0.5, $log->metadata['difference']);
    }

    public function test_final_marks_are_validated_against_question_maximum(): void
    {
        Sanctum::actingAs($this->faculty);

        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", ['final_marks' => 12])->assertStatus(422);
        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", ['final_marks' => -1])->assertStatus(422);
        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", ['final_marks' => 'abc'])->assertStatus(422);
        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", [])->assertStatus(422);
        $this->assertNull($this->answer->fresh()->awarded_marks);

        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", ['final_marks' => 6.5])
            ->assertStatus(200)->assertJsonPath('data.awarded_marks', 6.5);
    }

    public function test_finalize_without_ai_result_still_works(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", ['final_marks' => 5])
            ->assertStatus(200)
            ->assertJsonPath('data.awarded_marks', 5)
            ->assertJsonPath('data.ai_grading', null);
        $this->assertSame('FACULTY_REVIEWED', $this->submission->fresh()->grading_status);
    }

    public function test_final_grade_can_be_updated_via_put(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", ['final_marks' => 5])->assertStatus(200);
        $this->putJson("/api/student-answers/{$this->answer->id}/final-grade", ['final_marks' => 6])
            ->assertStatus(200)->assertJsonPath('data.awarded_marks', 6);
    }

    public function test_finalize_on_returned_submission_is_rejected(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->submission->update(['status' => 'RETURNED', 'grading_status' => 'FINALIZED']);
        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", ['final_marks' => 5])->assertStatus(422);
    }

    public function test_faculty_can_reject_ai_suggestion(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $resultId = $this->postJson($this->gradeUrl())->json('data.id');

        $this->postJson("/api/ai-grading/{$resultId}/reject")
            ->assertStatus(200)
            ->assertJsonPath('data.grading_status', 'REVIEWED')
            ->assertJsonPath('data.faculty_decision', 'REJECTED')
            ->assertJsonPath('data.suggested_marks', 7.5);

        $this->assertNull($this->answer->fresh()->awarded_marks);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_SUGGESTION_REJECTED', 'entity_id' => $resultId]);

        // Rejecting twice / rejecting a finalized suggestion is refused
        $this->postJson("/api/ai-grading/{$resultId}/reject")->assertStatus(200);
        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", ['final_marks' => 4, 'decision' => 'REJECTED'])->assertStatus(200);
        $this->postJson("/api/ai-grading/{$resultId}/reject")->assertStatus(422);
    }

    public function test_pending_result_cannot_be_rejected(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->faculty);
        $resultId = $this->postJson($this->gradeUrl())->json('data.id');
        $this->postJson("/api/ai-grading/{$resultId}/reject")->assertStatus(422);
    }

    // ------------------------------------------------------------ privacy

    public function test_audit_logs_never_contain_answer_text(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->gradeUrl())->assertStatus(202);
        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", ['final_marks' => 8, 'faculty_feedback' => 'Secret feedback text'])->assertStatus(200);

        foreach (AuditLog::all() as $log) {
            $json = json_encode($log->metadata);
            $this->assertStringNotContainsString('organize data and reduce redundancy', $json);
            $this->assertStringNotContainsString('Secret feedback text', $json);
        }
    }

    public function test_deleting_answer_removes_ai_results(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->aiResponse());
        $this->postJson($this->gradeUrl())->assertStatus(202);
        $this->assertSame(1, AiGradingResult::count());

        $this->deleteJson("/api/student-answers/{$this->answer->id}")->assertStatus(200);
        $this->assertSame(0, AiGradingResult::count());
        $this->assertDatabaseCount('ai_grading_criterion_results', 0);
    }
}
