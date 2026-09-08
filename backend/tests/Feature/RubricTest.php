<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 25: AI Rubric Generator feature tests.
 */
class RubricTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected User $otherFaculty;
    protected Course $course;
    protected LearningOutcome $lo;
    protected Assessment $assessment;
    protected Question $question;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculty = User::factory()->create(['email' => 'faculty@university.edu']);
        $this->otherFaculty = User::factory()->create(['email' => 'other@university.edu']);

        $this->course = Course::create([
            'user_id' => $this->faculty->id,
            'course_code' => 'CSE101',
            'course_name' => 'Database Systems',
            'semester' => 'Fall',
            'academic_year' => '2026',
        ]);

        $this->lo = LearningOutcome::create([
            'course_id' => $this->course->id,
            'code' => 'CO2',
            'description' => 'Explain fundamental database concepts.',
            'cognitive_level' => 'Understand',
            'sort_order' => 1,
        ]);

        $this->assessment = Assessment::create([
            'course_id' => $this->course->id,
            'title' => 'Midterm Examination',
            'type' => 'midterm',
            'total_marks' => 50,
            'status' => 'draft',
        ]);

        $this->question = Question::create([
            'assessment_id' => $this->assessment->id,
            'question_number' => 1,
            'question_text' => 'Explain database normalization with suitable examples.',
            'question_type' => 'descriptive',
            'marks' => 10,
            'difficulty_level' => 'medium',
            'cognitive_level' => 'Understand',
            'learning_outcome_id' => $this->lo->id,
        ]);
    }

    protected function fakeAiResponse(array $criteriaMarks = [2, 2, 2, 2, 2], array $overrides = []): array
    {
        $criteria = [];
        foreach ($criteriaMarks as $idx => $marks) {
            $criteria[] = [
                'criterion' => 'Criterion ' . ($idx + 1),
                'description' => 'Description for criterion ' . ($idx + 1),
                'max_marks' => $marks,
                'scoring_guidance' => 'Full marks for a complete answer.',
                'expected_indicators' => ['indicator a', 'indicator b'],
                'sort_order' => $idx + 1,
            ];
        }

        return array_replace_recursive([
            'status' => 'success',
            'generation_method' => 'template_based',
            'draft_status' => 'DRAFT',
            'rubric' => [
                'title' => 'Rubric: Database normalization',
                'question_text' => $this->question->question_text,
                'total_marks' => array_sum($criteriaMarks),
                'criteria' => $criteria,
                'general_guidance' => 'Award marks based on demonstrated understanding.',
            ],
            'metadata' => [
                'model' => 'facultylens-rubric-template-engine',
                'version' => '1.0.0',
                'generative_model_used' => false,
                'criteria_count' => count($criteria),
                'validation_passed' => true,
            ],
        ], $overrides);
    }

    protected function fakeAi(array $response, int $status = 200): void
    {
        Http::fake(['*/api/v1/generate-rubric' => Http::response($response, $status)]);
    }

    // ------------------------------------------------------------ generation

    public function test_generate_requires_authentication(): void
    {
        $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->assertStatus(401);
        $this->getJson("/api/questions/{$this->question->id}/rubrics")->assertStatus(401);
    }

    public function test_faculty_can_generate_draft_rubric_from_trusted_question_data(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse());

        $response = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate", [
            // Frontend-supplied values must be ignored.
            'total_marks' => 999,
            'user_id' => $this->otherFaculty->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.total_marks', 10)
            ->assertJsonPath('data.criteria_total', 10)
            ->assertJsonPath('data.is_ai_generated', true)
            ->assertJsonCount(5, 'data.criteria');

        $this->assertDatabaseCount('rubrics', 1);
        $this->assertDatabaseCount('rubric_criteria', 5);
        $rubric = Rubric::first();
        $this->assertEquals($this->faculty->id, $rubric->created_by);
        $this->assertEquals($this->assessment->id, $rubric->assessment_id);
        $this->assertEquals('facultylens-rubric-template-engine', $rubric->ai_model);
        $this->assertNotNull($rubric->generated_at);
        $this->assertNull($rubric->approved_at);

        // Payload sent to FastAPI is built from the database, not the request body.
        Http::assertSent(function ($request) {
            $data = $request->data();
            return str_ends_with($request->url(), '/api/v1/generate-rubric')
                && $data['question_id'] === $this->question->id
                && (float) $data['total_marks'] === 10.0
                && $data['question_type'] === 'DESCRIPTIVE'
                && $data['learning_outcome']['code'] === 'CO2'
                && $data['course_context']['course_code'] === 'CSE101';
        });
    }

    public function test_generate_rejects_ai_response_with_marks_mismatch(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse([2, 2, 3, 2, 2])); // total 11 != 10

        $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")
            ->assertStatus(502)
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseCount('rubrics', 0);
        $this->assertDatabaseCount('rubric_criteria', 0);
    }

    public function test_generate_rejects_ai_response_whose_total_differs_from_question_marks(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse([6, 6])); // criteria consistent (12) but question is 10

        $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->assertStatus(502);
        $this->assertDatabaseCount('rubrics', 0);
    }

    public function test_generate_rejects_malformed_ai_response(): void
    {
        Sanctum::actingAs($this->faculty);

        $this->fakeAi(['status' => 'success', 'rubric' => 'not-an-object']);
        $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->assertStatus(502);

        $this->fakeAi($this->fakeAiResponse([], ['rubric' => ['total_marks' => 10]]));
        $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->assertStatus(502);

        $this->fakeAi($this->fakeAiResponse([10], ['rubric' => ['criteria' => [['criterion' => '', 'description' => 'x', 'max_marks' => 10]]]]));
        $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->assertStatus(502);

        $this->fakeAi($this->fakeAiResponse([10], ['rubric' => ['criteria' => [['criterion' => 'A', 'description' => 'x', 'max_marks' => 'ten']]]]));
        $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->assertStatus(502);

        $this->assertDatabaseCount('rubrics', 0);
    }

    public function test_generate_rejects_negative_criterion_marks(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse([12, -2]));

        $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->assertStatus(502);
        $this->assertDatabaseCount('rubrics', 0);
    }

    public function test_generate_handles_ai_service_unavailable(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/generate-rubric' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused')]);

        $response = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate");
        $response->assertStatus(503)
            ->assertJsonPath('message', 'Rubric generation is temporarily unavailable. Please try again later.');
        $this->assertStringNotContainsString('Connection refused', $response->getContent());
    }

    public function test_generate_handles_ai_service_timeout(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/generate-rubric' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out')]);

        $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->assertStatus(504);
    }

    public function test_generate_handles_ai_service_server_error_without_leaking_internals(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi(['detail' => 'Traceback /srv/app/models.py line 42'], 500);

        $response = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate");
        $response->assertStatus(502);
        $this->assertStringNotContainsString('/srv/app', $response->getContent());
    }

    public function test_generate_requires_question_marks(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->question->update(['marks' => 0]);
        Http::fake();

        $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_generate_returns_404_for_missing_question(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->postJson('/api/questions/99999/rubrics/generate')->assertStatus(404);
    }

    // --------------------------------------------------------- authorization

    public function test_other_faculty_cannot_generate_view_edit_approve_or_delete(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse());
        $rubricId = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->json('data.id');

        Sanctum::actingAs($this->otherFaculty);

        $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->assertStatus(403);
        $this->getJson("/api/questions/{$this->question->id}/rubrics")->assertStatus(403);
        $this->getJson("/api/rubrics/{$rubricId}")->assertStatus(403);
        $this->putJson("/api/rubrics/{$rubricId}", ['title' => 'Hijacked'])->assertStatus(403);
        $this->postJson("/api/rubrics/{$rubricId}/approve")->assertStatus(403);
        $this->postJson("/api/rubrics/{$rubricId}/regenerate")->assertStatus(403);
        $this->deleteJson("/api/rubrics/{$rubricId}")->assertStatus(403);

        $this->assertDatabaseCount('rubrics', 1);
        $this->assertEquals('Rubric: Database normalization', Rubric::find($rubricId)->title);
        $this->assertEquals('DRAFT', Rubric::find($rubricId)->status);
    }

    public function test_assessment_ownership_is_enforced_through_course(): void
    {
        $foreignCourse = Course::create([
            'user_id' => $this->otherFaculty->id,
            'course_code' => 'EEE201',
            'course_name' => 'Circuits',
            'semester' => 'Fall',
            'academic_year' => '2026',
        ]);
        $foreignAssessment = Assessment::create([
            'course_id' => $foreignCourse->id,
            'title' => 'Quiz',
            'type' => 'quiz',
            'total_marks' => 10,
            'status' => 'draft',
        ]);
        $foreignQuestion = Question::create([
            'assessment_id' => $foreignAssessment->id,
            'question_number' => 1,
            'question_text' => 'Define Ohm\'s law.',
            'question_type' => 'short_answer',
            'marks' => 5,
        ]);

        Sanctum::actingAs($this->faculty);
        $this->postJson("/api/questions/{$foreignQuestion->id}/rubrics/generate")->assertStatus(403);
        $this->getJson("/api/questions/{$foreignQuestion->id}/rubrics")->assertStatus(403);
    }

    // ------------------------------------------------------------- listing

    public function test_list_rubrics_for_question_returns_all_versions_newest_first(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse());
        $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->assertStatus(201);
        $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->assertStatus(201);

        $response = $this->getJson("/api/questions/{$this->question->id}/rubrics");
        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.version', 2)
            ->assertJsonPath('data.1.version', 1)
            ->assertJsonPath('question.marks', 10);
    }

    public function test_show_rubric_includes_criteria(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse());
        $id = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->json('data.id');

        $this->getJson("/api/rubrics/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonCount(5, 'data.criteria')
            ->assertJsonPath('data.criteria.0.expected_indicators', ['indicator a', 'indicator b']);
    }

    // -------------------------------------------------------------- editing

    public function test_faculty_can_edit_draft_rubric(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse());
        $id = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->json('data.id');

        $response = $this->putJson("/api/rubrics/{$id}", [
            'title' => 'Faculty-adjusted rubric',
            'general_guidance' => 'Be lenient on notation.',
            'criteria' => [
                ['criterion' => 'Definition', 'description' => 'Defines normalization', 'max_marks' => 4, 'expected_indicators' => ['reduces redundancy']],
                ['criterion' => 'Examples', 'description' => 'Gives examples', 'max_marks' => 3.5, 'scoring_guidance' => 'One example = half'],
                ['criterion' => 'Clarity', 'description' => 'Clear writing', 'max_marks' => 2.5, 'expected_indicators' => []],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Faculty-adjusted rubric')
            ->assertJsonPath('data.general_guidance', 'Be lenient on notation.')
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.criteria_total', 10)
            ->assertJsonCount(3, 'data.criteria')
            ->assertJsonPath('data.criteria.0.criterion', 'Definition')
            ->assertJsonPath('data.criteria.0.sort_order', 1)
            ->assertJsonPath('data.criteria.1.max_marks', 3.5)
            ->assertJsonPath('data.criteria.2.sort_order', 3);

        $this->assertDatabaseCount('rubric_criteria', 3);
    }

    public function test_edit_rejects_criteria_total_mismatch(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse());
        $id = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->json('data.id');

        $response = $this->putJson("/api/rubrics/{$id}", [
            'criteria' => [
                ['criterion' => 'A', 'description' => 'a', 'max_marks' => 6],
                ['criterion' => 'B', 'description' => 'b', 'max_marks' => 6],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('12', $response->json('message'));
        $this->assertDatabaseCount('rubric_criteria', 5); // untouched
    }

    public function test_edit_validates_request_shape(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse());
        $id = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->json('data.id');

        $this->putJson("/api/rubrics/{$id}", ['criteria' => []])->assertStatus(422);
        $this->putJson("/api/rubrics/{$id}", ['criteria' => [['criterion' => 'A', 'description' => 'a', 'max_marks' => -1]]])->assertStatus(422);
        $this->putJson("/api/rubrics/{$id}", ['criteria' => [['criterion' => 'A', 'max_marks' => 10]]])->assertStatus(422);
        $this->putJson("/api/rubrics/{$id}", ['title' => ''])->assertStatus(422);
    }

    public function test_editing_an_approved_rubric_reverts_it_to_draft(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse());
        $id = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->json('data.id');
        $this->postJson("/api/rubrics/{$id}/approve")->assertStatus(200);

        $this->putJson("/api/rubrics/{$id}", ['title' => 'Revised'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.approved_at', null);
    }

    // ------------------------------------------------------------- approval

    public function test_faculty_can_approve_valid_draft(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse());
        $id = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->json('data.id');

        $response = $this->postJson("/api/rubrics/{$id}/approve");
        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.approved_by', $this->faculty->id);
        $this->assertNotNull($response->json('data.approved_at'));

        // The question itself is untouched.
        $this->assertEquals(10.0, (float) $this->question->fresh()->marks);
        $this->assertEquals('Explain database normalization with suitable examples.', $this->question->fresh()->question_text);
    }

    public function test_approval_blocked_when_criteria_do_not_match_question_marks(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse());
        $id = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->json('data.id');

        // Faculty later changes question marks -> rubric now inconsistent.
        $this->question->update(['marks' => 12]);

        $this->postJson("/api/rubrics/{$id}/approve")->assertStatus(422);
        $this->assertEquals('DRAFT', Rubric::find($id)->status);
    }

    public function test_approval_blocked_when_rubric_has_no_criteria(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse());
        $id = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->json('data.id');
        RubricCriterion::where('rubric_id', $id)->delete();

        $this->postJson("/api/rubrics/{$id}/approve")->assertStatus(422);
    }

    public function test_approving_a_new_version_archives_previous_approved_version_but_keeps_it_readable(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse());
        $v1 = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->json('data.id');
        $this->postJson("/api/rubrics/{$v1}/approve")->assertStatus(200);

        $v2 = $this->postJson("/api/rubrics/{$v1}/regenerate")->assertStatus(201)->json('data.id');
        $this->assertNotEquals($v1, $v2);

        // Regeneration alone never touches the approved rubric.
        $this->assertEquals('APPROVED', Rubric::find($v1)->status);
        $this->assertEquals('DRAFT', Rubric::find($v2)->status);
        $this->assertEquals(2, Rubric::find($v2)->version);

        $this->postJson("/api/rubrics/{$v2}/approve")->assertStatus(200);
        $this->assertEquals('ARCHIVED', Rubric::find($v1)->status);
        $this->assertEquals('APPROVED', Rubric::find($v2)->status);

        // Historical version remains readable with its criteria.
        $this->getJson("/api/rubrics/{$v1}")->assertStatus(200)->assertJsonCount(5, 'data.criteria');
        // Archived versions are read-only.
        $this->putJson("/api/rubrics/{$v1}", ['title' => 'x'])->assertStatus(403);
        $this->postJson("/api/rubrics/{$v1}/approve")->assertStatus(422);
    }

    // --------------------------------------------------------- regeneration

    public function test_regenerate_preserves_existing_drafts(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/generate-rubric' => Http::sequence()
            ->push($this->fakeAiResponse())
            ->push($this->fakeAiResponse([5, 5], ['rubric' => ['title' => 'Regenerated']]))]);

        $v1 = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->json('data.id');
        $this->putJson("/api/rubrics/{$v1}", ['title' => 'My edited draft'])->assertStatus(200);

        $this->postJson("/api/rubrics/{$v1}/regenerate")
            ->assertStatus(201)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.title', 'Regenerated');

        $this->assertDatabaseCount('rubrics', 2);
        $this->assertEquals('My edited draft', Rubric::find($v1)->title);
        $this->assertDatabaseCount('rubric_criteria', 7);
    }

    public function test_regenerate_failure_leaves_existing_rubrics_untouched(): void
    {
        Sanctum::actingAs($this->faculty);
        Http::fake(['*/api/v1/generate-rubric' => Http::sequence()
            ->push($this->fakeAiResponse())
            ->push($this->fakeAiResponse([9, 2]))]); // second response is invalid (11 != 10)

        $v1 = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->json('data.id');

        $this->postJson("/api/rubrics/{$v1}/regenerate")->assertStatus(502);

        $this->assertDatabaseCount('rubrics', 1);
        $this->assertDatabaseCount('rubric_criteria', 5);
    }

    // -------------------------------------------------------------- deletion

    public function test_delete_removes_rubric_and_criteria_but_not_question(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse());
        $id = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->json('data.id');

        $this->deleteJson("/api/rubrics/{$id}")->assertStatus(200);

        $this->assertDatabaseCount('rubrics', 0);
        $this->assertDatabaseCount('rubric_criteria', 0);
        $this->assertDatabaseHas('questions', ['id' => $this->question->id]);
        $this->getJson("/api/rubrics/{$id}")->assertStatus(404);
    }

    public function test_deleting_question_cascades_to_rubrics(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse());
        $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->assertStatus(201);

        $this->question->delete();

        $this->assertDatabaseCount('rubrics', 0);
        $this->assertDatabaseCount('rubric_criteria', 0);
    }

    // ------------------------------------------------------- transactions

    public function test_transaction_rolls_back_rubric_when_criteria_insert_fails(): void
    {
        Sanctum::actingAs($this->faculty);

        // A criterion name longer than the column allows on strict drivers would fail; simulate a
        // persistence failure deterministically by forcing the criterion model to throw on create.
        RubricCriterion::creating(function () {
            throw new \RuntimeException('simulated insert failure');
        });

        $this->fakeAi($this->fakeAiResponse());
        $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->assertStatus(500);

        $this->assertDatabaseCount('rubrics', 0);
        $this->assertDatabaseCount('rubric_criteria', 0);
    }

    // ------------------------------------------------------- relationships

    public function test_question_and_assessment_expose_rubric_relationships(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->fakeAi($this->fakeAiResponse());
        $id = $this->postJson("/api/questions/{$this->question->id}/rubrics/generate")->json('data.id');
        $this->postJson("/api/rubrics/{$id}/approve");

        $this->assertCount(1, $this->question->fresh()->rubrics);
        $this->assertEquals($id, $this->question->fresh()->approvedRubric->id);
        $this->assertCount(1, $this->assessment->fresh()->rubrics);
        $this->assertEquals($this->question->id, Rubric::find($id)->question->id);
        $this->assertEquals($this->faculty->id, Rubric::find($id)->creator->id);
    }
}
