<?php

namespace Tests\Feature\Performance;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\CourseCollaborator;
use App\Models\Question;
use App\Models\Recommendation;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 43 §15/§41 — cache correctness after the performance changes:
 *  * the analytics data-version fingerprint no longer scans student_answers; it must still change for every
 *    answer write path that reaches the API (grade, status-only change, new answer, deleted answer);
 *  * the analysis payload cache is shared per assessment; a recommendation decision or a re-run by one faculty
 *    member must be visible to a collaborator immediately (was stale for up to 5 min per user before).
 */
class CacheCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $editor;
    protected Assessment $assessment;
    protected Question $q1;
    protected Question $q2;
    protected StudentSubmission $submission;
    protected StudentAnswer $answer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->editor = User::factory()->create();
        $course = Course::create(['user_id' => $this->owner->id, 'course_code' => 'CC-1', 'course_name' => 'Cache', 'semester' => 'Fall', 'academic_year' => '2026']);
        CourseCollaborator::create(['course_id' => $course->id, 'user_id' => $this->editor->id, 'invited_by' => $this->owner->id, 'role' => 'EDITOR', 'status' => 'ACTIVE', 'accepted_at' => now()]);
        $this->assessment = Assessment::create(['course_id' => $course->id, 'title' => 'Quiz', 'type' => 'quiz', 'total_marks' => 20, 'status' => 'completed']);
        $this->q1 = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Explain indexing.', 'question_type' => 'descriptive', 'marks' => 10]);
        $this->q2 = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 2, 'question_text' => 'Explain normalization.', 'question_type' => 'descriptive', 'marks' => 10]);
        $student = Student::create(['created_by' => $this->owner->id, 'student_identifier' => 'S1', 'name' => 'S']);
        $this->submission = StudentSubmission::create(['assessment_id' => $this->assessment->id, 'student_id' => $student->id, 'status' => 'UNDER_REVIEW', 'grading_status' => 'IN_PROGRESS', 'submitted_at' => now(), 'total_marks' => 20]);
        $this->answer = StudentAnswer::create(['student_submission_id' => $this->submission->id, 'question_id' => $this->q1->id, 'answer_type' => 'TEXT', 'answer_text' => 'a', 'answer_status' => 'NOT_REVIEWED']);
        Sanctum::actingAs($this->owner);
    }

    protected function overviewVersion(): string
    {
        return (string) $this->getJson('/api/analytics/overview')->assertOk()->json('data.meta.data_version');
    }

    protected function performanceSummary(): array
    {
        return $this->getJson('/api/analytics/overview')->assertOk()->json('data.performance');
    }

    public function test_every_answer_write_path_changes_the_analytics_fingerprint(): void
    {
        $this->travelTo(now()->addSeconds(1));
        $v0 = $this->overviewVersion();

        // 1. faculty grade via generic update
        $this->travelTo(now()->addSeconds(2));
        $this->putJson("/api/student-answers/{$this->answer->id}", ['awarded_marks' => 6])->assertOk();
        $v1 = $this->overviewVersion();
        $this->assertNotSame($v0, $v1, 'grading an answer must invalidate analytics');

        // 2. status-only change (marks unchanged) — the old answers scan caught it via updated_at; the new one must too
        $this->travelTo(now()->addSeconds(2));
        $this->putJson("/api/student-answers/{$this->answer->id}", ['answer_status' => 'UNDER_REVIEW'])->assertOk();
        $v2 = $this->overviewVersion();
        $this->assertNotSame($v1, $v2, 'an answer status change must invalidate analytics');

        // 3. new answer with no marks
        $this->travelTo(now()->addSeconds(2));
        $this->postJson("/api/submissions/{$this->submission->id}/answers", ['question_id' => $this->q2->id, 'answer_text' => 'b'])->assertStatus(201);
        $v3 = $this->overviewVersion();
        $this->assertNotSame($v2, $v3, 'adding an answer must invalidate analytics');

        // 4. finalize-grade + GRADED status changes the performance section itself
        $this->travelTo(now()->addSeconds(2));
        $this->postJson("/api/student-answers/{$this->answer->id}/finalize-grade", ['final_marks' => 9])->assertOk();
        $a2 = StudentAnswer::where('student_submission_id', $this->submission->id)->where('question_id', $this->q2->id)->firstOrFail();
        $this->postJson("/api/student-answers/{$a2->id}/finalize-grade", ['final_marks' => 5])->assertOk();
        $this->patchJson("/api/submissions/{$this->submission->id}/status", ['status' => 'GRADED'])->assertOk();
        $perf = $this->performanceSummary();
        $this->assertNotEmpty($perf, 'finalized grades must appear in analytics');

        // 5. deleting an answer of a reopened submission
        $this->travelTo(now()->addSeconds(2));
        $this->patchJson("/api/submissions/{$this->submission->id}/status", ['status' => 'UNDER_REVIEW'])->assertOk();
        $v4 = $this->overviewVersion();
        $this->deleteJson("/api/student-answers/{$a2->id}")->assertOk();
        $this->assertNotSame($v4, $this->overviewVersion(), 'deleting an answer must invalidate analytics');
    }

    public function test_analysis_payload_cache_is_shared_and_invalidated_for_collaborators(): void
    {
        $report = AnalysisReport::create(['assessment_id' => $this->assessment->id, 'analysis_version' => 1, 'is_current' => true, 'overall_score' => 80, 'analysis_status' => 'completed', 'analyzed_at' => now()]);
        $rec = Recommendation::create(['analysis_report_id' => $report->id, 'category' => 'difficulty', 'title' => 'Rebalance', 'problem' => 'p', 'description' => 'd', 'explanation' => 'e', 'recommendation' => 'r', 'priority' => 'medium', 'status' => 'pending']);

        // owner warms the cache, editor reads the same cached payload (one Redis entry, not one per user)
        $this->getJson("/api/ai/assessments/{$this->assessment->id}/analysis")->assertOk()->assertJsonPath('data.recommendations.0.status', 'pending');
        $this->assertNotNull(Cache::get("assessment:{$this->assessment->id}:analysis"));
        $this->assertNull(Cache::get("user:{$this->owner->id}:assessment:{$this->assessment->id}:analysis"), 'per-user cache keys must no longer be written');

        Sanctum::actingAs($this->editor);
        $this->getJson("/api/ai/assessments/{$this->assessment->id}/analysis")->assertOk()->assertJsonPath('data.recommendations.0.status', 'pending');

        // editor accepts the recommendation → owner must see it immediately, not after 5 minutes
        $this->postJson("/api/recommendations/{$rec->id}/feedback", ['decision' => 'ACCEPTED'])->assertOk();
        Sanctum::actingAs($this->owner);
        $this->getJson("/api/ai/assessments/{$this->assessment->id}/analysis")->assertOk()->assertJsonPath('data.recommendations.0.status', 'accepted');

        // status endpoint path (PATCH) invalidates too
        $this->patchJson("/api/ai/recommendations/{$rec->id}/status", ['status' => 'dismissed'])->assertOk();
        Sanctum::actingAs($this->editor);
        $this->getJson("/api/ai/assessments/{$this->assessment->id}/analysis")->assertOk()->assertJsonPath('data.recommendations.0.status', 'dismissed');

        // a stranger still gets 403 before any cache is consulted
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/ai/assessments/{$this->assessment->id}/analysis")->assertStatus(403);
    }
}
