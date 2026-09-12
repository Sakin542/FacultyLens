<?php

namespace Tests\Feature\Regression;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\AssessmentBlueprint;
use App\Models\AssessmentVersion;
use App\Models\Course;
use App\Models\DocumentProcessing;
use App\Models\GeneratedQuestion;
use App\Models\InstitutionalReport;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\QuestionGenerationRequest;
use App\Models\Recommendation;
use App\Models\Rubric;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 42 §7 — IDOR matrix. Faculty B (no membership on Faculty A's course) must be refused
 * on every object-level endpoint that reaches Faculty A's course, assessment, documents,
 * reports, students, submissions, grades, rubrics, versions, blueprints and AI artefacts.
 * Anonymous requests must be rejected with 401 on the same routes.
 */
class IdorMatrixRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected User $a;
    protected User $b;
    protected array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = User::factory()->create(['email' => 'a@university.edu']);
        $this->b = User::factory()->create(['email' => 'b@university.edu']);

        $course = Course::create(['user_id' => $this->a->id, 'course_code' => 'IDOR-101', 'course_name' => 'Owned by A', 'semester' => 'Fall', 'academic_year' => '2026']);
        $lo = LearningOutcome::create(['course_id' => $course->id, 'code' => 'LO1', 'description' => 'Explain B+ tree indexing.']);
        $assessment = Assessment::create(['course_id' => $course->id, 'title' => 'Midterm', 'type' => 'midterm', 'total_marks' => 10, 'status' => 'draft']);
        $question = Question::create(['assessment_id' => $assessment->id, 'question_number' => 1, 'question_text' => 'Explain the structure of a B+ tree index.', 'marks' => 10, 'question_type' => 'descriptive', 'learning_outcome_id' => $lo->id]);
        $report = AnalysisReport::create(['assessment_id' => $assessment->id, 'analysis_version' => 1, 'is_current' => true, 'overall_score' => 80, 'analysis_status' => 'completed', 'analyzed_at' => now()]);
        $rec = Recommendation::create(['analysis_report_id' => $report->id, 'category' => 'difficulty', 'title' => 'Rebalance', 'problem' => 'Too easy', 'description' => 'd', 'explanation' => 'e', 'recommendation' => 'Consider adding harder items.', 'priority' => 'medium', 'status' => 'pending']);
        $rubric = Rubric::create(['question_id' => $question->id, 'assessment_id' => $assessment->id, 'created_by' => $this->a->id, 'title' => 'Rubric', 'total_marks' => 10, 'status' => 'DRAFT', 'version' => 1, 'generation_method' => 'manual']);
        $doc = DocumentProcessing::create(['user_id' => $this->a->id, 'course_id' => $course->id, 'document_type' => 'syllabus', 'original_file_name' => 'a.pdf', 'stored_file_name' => 'a.pdf', 'file_path' => 'documents/a.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10, 'processing_status' => 'completed']);
        $student = Student::create(['created_by' => $this->a->id, 'student_identifier' => 'A-STU-1', 'name' => 'Student of A']);
        $submission = StudentSubmission::create(['assessment_id' => $assessment->id, 'student_id' => $student->id, 'status' => 'UNDER_REVIEW', 'grading_status' => 'IN_PROGRESS', 'submitted_at' => now(), 'total_marks' => 10]);
        $answer = StudentAnswer::create(['student_submission_id' => $submission->id, 'question_id' => $question->id, 'answer_type' => 'TEXT', 'answer_text' => 'Private answer text', 'answer_status' => 'NOT_REVIEWED']);
        $blueprint = AssessmentBlueprint::create(['assessment_id' => $assessment->id, 'created_by' => $this->a->id, 'version' => 1, 'status' => 'DRAFT', 'is_current' => true, 'title' => 'BP', 'total_marks' => 10, 'total_questions' => 1]);
        $version = AssessmentVersion::create(['assessment_id' => $assessment->id, 'version_number' => 1, 'version_label' => 'v1', 'version_type' => 'MAJOR', 'status' => 'DRAFT', 'title' => 'Midterm', 'assessment_type' => 'midterm', 'total_marks' => 10, 'question_count' => 1, 'created_by' => $this->a->id]);
        $genReq = QuestionGenerationRequest::create(['user_id' => $this->a->id, 'course_id' => $course->id, 'assessment_id' => $assessment->id, 'topic' => 'Indexing', 'question_type' => 'descriptive', 'difficulty_level' => 'medium', 'cognitive_level' => 'Understand', 'marks' => 5, 'number_of_questions' => 1, 'language' => 'en', 'document_scope' => 'course', 'generation_status' => 'COMPLETED']);
        $gen = GeneratedQuestion::create(['generation_request_id' => $genReq->id, 'sequence' => 1, 'question_text' => 'Draft question', 'original_question_text' => 'Draft question', 'question_type' => 'descriptive', 'marks' => 5, 'difficulty_level' => 'medium', 'cognitive_level' => 'Understand', 'validation_status' => 'PASSED', 'review_status' => 'DRAFT', 'version' => 1]);
        $instReport = InstitutionalReport::create(['report_uuid' => (string) \Illuminate\Support\Str::uuid(), 'created_by' => $this->a->id, 'report_type' => 'ASSESSMENT_QUALITY', 'scope_type' => 'ASSESSMENT', 'course_id' => $course->id, 'assessment_id' => $assessment->id, 'title' => 'Quality', 'format' => 'CSV', 'status' => 'COMPLETED', 'file_path' => 'reports/x.csv', 'file_name' => 'x.csv']);

        $this->ids = compact('course', 'lo', 'assessment', 'question', 'report', 'rec', 'rubric', 'doc', 'student', 'submission', 'answer', 'blueprint', 'version', 'genReq', 'gen', 'instReport');
    }

    /** @return array<string, array{string, string, array}> label => [method, path, body] */
    protected function foreignRoutes(): array
    {
        $i = $this->ids;
        return [
            'course show' => ['GET', "/api/courses/{$i['course']->id}", []],
            'course update' => ['PUT', "/api/courses/{$i['course']->id}", ['course_name' => 'Hijacked']],
            'course delete' => ['DELETE', "/api/courses/{$i['course']->id}", []],
            'course LOs' => ['GET', "/api/courses/{$i['course']->id}/learning-outcomes", []],
            'LO update' => ['PUT', "/api/learning-outcomes/{$i['lo']->id}", ['description' => 'Hijacked']],
            'course assessments' => ['GET', "/api/courses/{$i['course']->id}/assessments", []],
            'assessment show' => ['GET', "/api/assessments/{$i['assessment']->id}", []],
            'assessment update' => ['PUT', "/api/assessments/{$i['assessment']->id}", ['title' => 'Hijacked']],
            'assessment delete' => ['DELETE', "/api/assessments/{$i['assessment']->id}", []],
            'question paper' => ['GET', "/api/assessments/{$i['assessment']->id}/question-paper", []],
            'document show' => ['GET', "/api/documents/{$i['doc']->id}", []],
            'document download' => ['GET', "/api/documents/{$i['doc']->id}/download", []],
            'document delete' => ['DELETE', "/api/documents/{$i['doc']->id}", []],
            'analysis run' => ['POST', "/api/ai/assessments/{$i['assessment']->id}/analyze", []],
            'analysis read' => ['GET', "/api/ai/assessments/{$i['assessment']->id}/analysis", []],
            'analysis status' => ['GET', "/api/ai/assessments/{$i['assessment']->id}/analysis-status", []],
            'analysis history' => ['GET', "/api/assessments/{$i['assessment']->id}/analysis-history", []],
            'analysis detail' => ['GET', "/api/analysis/{$i['report']->id}", []],
            'recommendations list' => ['GET', "/api/ai/assessments/{$i['assessment']->id}/recommendations", []],
            'recommendation status' => ['PATCH', "/api/ai/recommendations/{$i['rec']->id}/status", ['status' => 'accepted']],
            'recommendation feedback' => ['POST', "/api/recommendations/{$i['rec']->id}/feedback", ['decision' => 'ACCEPTED']],
            'assessment report' => ['GET', "/api/assessments/{$i['assessment']->id}/report", []],
            'rubric list' => ['GET', "/api/questions/{$i['question']->id}/rubrics", []],
            'rubric show' => ['GET', "/api/rubrics/{$i['rubric']->id}", []],
            'rubric update' => ['PUT', "/api/rubrics/{$i['rubric']->id}", ['title' => 'Hijacked', 'criteria' => [['criterion' => 'x', 'description' => 'y', 'max_marks' => 10]]]],
            'rubric approve' => ['POST', "/api/rubrics/{$i['rubric']->id}/approve", []],
            'rubric delete' => ['DELETE', "/api/rubrics/{$i['rubric']->id}", []],
            'student show' => ['GET', "/api/students/{$i['student']->id}", []],
            'student update' => ['PUT', "/api/students/{$i['student']->id}", ['name' => 'Hijacked']],
            'student delete' => ['DELETE', "/api/students/{$i['student']->id}", []],
            'submissions list' => ['GET', "/api/assessments/{$i['assessment']->id}/submissions", []],
            'submission show' => ['GET', "/api/submissions/{$i['submission']->id}", []],
            'submission status' => ['PATCH', "/api/submissions/{$i['submission']->id}/status", ['status' => 'GRADED']],
            'submission delete' => ['DELETE', "/api/submissions/{$i['submission']->id}", []],
            'answer add' => ['POST', "/api/submissions/{$i['submission']->id}/answers", ['question_id' => $i['question']->id, 'answer_text' => 'x']],
            'answer update' => ['PUT', "/api/student-answers/{$i['answer']->id}", ['awarded_marks' => 10]],
            'answer delete' => ['DELETE', "/api/student-answers/{$i['answer']->id}", []],
            'answer download' => ['GET', "/api/student-answers/{$i['answer']->id}/download", []],
            'ai grade request' => ['POST', "/api/student-answers/{$i['answer']->id}/ai-grade", []],
            'ai grade read' => ['GET', "/api/student-answers/{$i['answer']->id}/ai-grading", []],
            'finalize grade' => ['POST', "/api/student-answers/{$i['answer']->id}/finalize-grade", ['final_marks' => 10]],
            'performance' => ['GET', "/api/assessments/{$i['assessment']->id}/performance", []],
            'performance analyze' => ['POST', "/api/assessments/{$i['assessment']->id}/performance/analyze", []],
            'student performance' => ['GET', "/api/students/{$i['student']->id}/assessments/{$i['assessment']->id}/performance", []],
            'co-po mapping' => ['GET', "/api/courses/{$i['course']->id}/co-po-mapping", []],
            'blueprint show' => ['GET', "/api/assessments/{$i['assessment']->id}/blueprint", []],
            'blueprint update' => ['PUT', "/api/blueprints/{$i['blueprint']->id}", ['title' => 'Hijacked']],
            'blueprint finalize' => ['POST', "/api/blueprints/{$i['blueprint']->id}/finalize", []],
            'blueprint delete' => ['DELETE', "/api/blueprints/{$i['blueprint']->id}", []],
            'versions list' => ['GET', "/api/assessments/{$i['assessment']->id}/versions", []],
            'version show' => ['GET', "/api/assessments/{$i['assessment']->id}/versions/{$i['version']->id}", []],
            'version update' => ['PUT', "/api/assessment-versions/{$i['version']->id}", ['title' => 'Hijacked']],
            'version finalize' => ['POST', "/api/assessment-versions/{$i['version']->id}/finalize", []],
            'version analysis' => ['GET', "/api/assessment-versions/{$i['version']->id}/analysis", []],
            'generation show' => ['GET', "/api/question-generation/{$i['genReq']->id}", []],
            'generated question update' => ['PUT', "/api/generated-questions/{$i['gen']->id}", ['question_text' => 'Hijacked draft']],
            'generated question approve' => ['POST', "/api/generated-questions/{$i['gen']->id}/approve", []],
            'generated question add' => ['POST', "/api/generated-questions/{$i['gen']->id}/add-to-assessment", []],
            'institutional report show' => ['GET', "/api/reports/{$i['instReport']->id}", []],
            'institutional report download' => ['GET', "/api/reports/{$i['instReport']->id}/download", []],
            'institutional report delete' => ['DELETE', "/api/reports/{$i['instReport']->id}", []],
            'collaboration overview' => ['GET', "/api/courses/{$i['course']->id}/collaboration", []],
            'collaboration invite' => ['POST', "/api/courses/{$i['course']->id}/collaborators/invite", ['email' => 'x@university.edu', 'role' => 'EDITOR']],
            'comments' => ['GET', "/api/courses/{$i['course']->id}/comments", []],
            'analytics course' => ['GET', "/api/analytics/courses/{$i['course']->id}", []],
        ];
    }

    public function test_faculty_b_is_denied_on_every_foreign_object_route(): void
    {
        Sanctum::actingAs($this->b);
        $leaks = [];

        foreach ($this->foreignRoutes() as $label => [$method, $path, $body]) {
            $status = $this->json($method, $path, $body)->getStatusCode();
            if (!in_array($status, [403, 404], true)) {
                $leaks[] = "{$label} ({$method} {$path}) → {$status}";
            }
        }

        $this->assertSame([], $leaks, "Faculty B reached Faculty A's data:\n" . implode("\n", $leaks));

        // Nothing was modified or destroyed by the attempts above.
        $this->assertDatabaseHas('courses', ['id' => $this->ids['course']->id, 'course_name' => 'Owned by A']);
        $this->assertDatabaseHas('assessments', ['id' => $this->ids['assessment']->id, 'title' => 'Midterm']);
        $this->assertDatabaseHas('students', ['id' => $this->ids['student']->id, 'name' => 'Student of A']);
        $this->assertDatabaseHas('student_answers', ['id' => $this->ids['answer']->id, 'awarded_marks' => null]);
        $this->assertDatabaseHas('rubrics', ['id' => $this->ids['rubric']->id, 'status' => 'DRAFT']);
        $this->assertDatabaseHas('assessment_versions', ['id' => $this->ids['version']->id, 'status' => 'DRAFT']);
        $this->assertDatabaseHas('generated_questions', ['id' => $this->ids['gen']->id, 'review_status' => 'DRAFT']);
        $this->assertDatabaseHas('recommendations', ['id' => $this->ids['rec']->id, 'status' => 'pending']);
        $this->assertDatabaseHas('institutional_reports', ['id' => $this->ids['instReport']->id]);
    }

    public function test_anonymous_requests_are_rejected_on_every_route(): void
    {
        $open = [];
        foreach ($this->foreignRoutes() as $label => [$method, $path, $body]) {
            $status = $this->json($method, $path, $body)->getStatusCode();
            if ($status !== 401) {
                $open[] = "{$label} → {$status}";
            }
        }
        $this->assertSame([], $open, "Unauthenticated access reached:\n" . implode("\n", $open));
    }

    public function test_list_endpoints_never_include_foreign_rows(): void
    {
        Sanctum::actingAs($this->b);

        $this->assertSame([], $this->getJson('/api/courses')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/assessments')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/students')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/documents')->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/reports')->assertOk()->json('data.items'));
        $this->assertSame([], $this->getJson('/api/question-generation')->assertOk()->json('data'));
        $history = $this->getJson('/api/analysis/history')->assertOk()->json('data');
        $this->assertSame([], $history['data'] ?? $history['items'] ?? $history);
    }
}
