<?php

namespace Tests\Feature\Performance;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\PreviousQuestion;
use App\Models\Question;
use App\Models\Recommendation;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 43 §12 — N+1 guard. For every major list/detail endpoint the number of SQL queries must not grow with
 * the number of rows returned (small dataset vs. large dataset → identical query count).
 * The measured counts are printed so they can be recorded in docs/PERFORMANCE_TEST_REPORT.md.
 */
class QueryCountTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->faculty = User::factory()->create();
        Sanctum::actingAs($this->faculty);
    }

    protected function seed_courses(int $courses, int $assessmentsPerCourse, int $questionsPerAssessment): array
    {
        $ids = [];
        for ($c = 1; $c <= $courses; $c++) {
            $course = Course::create(['user_id' => $this->faculty->id, 'course_code' => "QC-{$c}-" . uniqid(), 'course_name' => "Course {$c}", 'semester' => 'Fall', 'academic_year' => '2026']);
            $los = [];
            for ($l = 1; $l <= 3; $l++) {
                $los[] = LearningOutcome::create(['course_id' => $course->id, 'code' => "LO{$l}", 'description' => "Outcome {$l}"]);
            }
            for ($a = 1; $a <= $assessmentsPerCourse; $a++) {
                $assessment = Assessment::create(['course_id' => $course->id, 'title' => "A{$a}", 'type' => 'quiz', 'total_marks' => $questionsPerAssessment * 5, 'status' => 'completed']);
                for ($q = 1; $q <= $questionsPerAssessment; $q++) {
                    Question::create(['assessment_id' => $assessment->id, 'question_number' => $q, 'question_text' => "Question {$q} about indexing and normalization.", 'question_type' => 'descriptive', 'marks' => 5, 'difficulty_level' => 'medium', 'cognitive_level' => 'Apply', 'learning_outcome_id' => $los[$q % 3]->id]);
                }
                $report = AnalysisReport::create(['assessment_id' => $assessment->id, 'analysis_version' => 1, 'is_current' => true, 'overall_score' => 75, 'analysis_status' => 'completed', 'total_questions' => $questionsPerAssessment, 'analyzed_at' => now()]);
                Recommendation::create(['analysis_report_id' => $report->id, 'category' => 'difficulty', 'title' => 'Rebalance', 'problem' => 'p', 'description' => 'd', 'explanation' => 'e', 'recommendation' => 'r', 'priority' => 'medium', 'status' => 'pending']);
                $ids[] = ['course' => $course->id, 'assessment' => $assessment->id];
            }
        }

        return $ids;
    }

    protected function countQueries(callable $fn): int
    {
        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    /** @param callable(int): int $measure returns query count for dataset size N */
    protected function assertFlat(string $label, int $small, int $large, callable $measure): void
    {
        $a = $measure($small);
        $b = $measure($large);
        fwrite(STDOUT, sprintf("\n  [query-count] %-40s N=%-4d → %3d queries   N=%-4d → %3d queries", $label, $small, $a, $large, $b));
        $this->assertLessThanOrEqual($a + 2, $b, "{$label}: query count grows with N ({$a} → {$b}) — N+1");
    }

    public function test_course_list_and_assessment_list_do_not_scale_with_rows(): void
    {
        $this->assertFlat('GET /courses', 3, 30, function (int $n) {
            $this->seed_courses($n, 1, 2);
            return $this->countQueries(fn () => $this->getJson('/api/courses')->assertOk());
        });
        $this->assertFlat('GET /assessments?per_page=100', 3, 40, function (int $n) {
            $this->seed_courses(1, $n, 2);
            return $this->countQueries(fn () => $this->getJson('/api/assessments?per_page=100')->assertOk());
        });
        $this->assertFlat('GET /assessments/history', 3, 40, function (int $n) {
            $this->seed_courses(1, $n, 2);
            return $this->countQueries(fn () => $this->getJson('/api/assessments/history')->assertOk());
        });
    }

    public function test_assessment_detail_and_analysis_do_not_scale_with_questions(): void
    {
        $this->assertFlat('GET /assessments/{id} (questions)', 5, 200, function (int $n) {
            $id = $this->seed_courses(1, 1, $n)[0]['assessment'];
            return $this->countQueries(fn () => $this->getJson("/api/assessments/{$id}")->assertOk());
        });
        $this->assertFlat('GET /ai/assessments/{id}/analysis', 5, 200, function (int $n) {
            $id = $this->seed_courses(1, 1, $n)[0]['assessment'];
            return $this->countQueries(fn () => $this->getJson("/api/ai/assessments/{$id}/analysis")->assertOk());
        });
        $this->assertFlat('GET /assessments/{id}/analysis-history', 2, 15, function (int $n) {
            $id = $this->seed_courses(1, 1, 3)[0]['assessment'];
            for ($v = 2; $v <= $n; $v++) {
                AnalysisReport::create(['assessment_id' => $id, 'analysis_version' => $v, 'is_current' => $v === $n, 'overall_score' => 70 + $v, 'analysis_status' => 'completed', 'analyzed_at' => now()]);
            }
            return $this->countQueries(fn () => $this->getJson("/api/assessments/{$id}/analysis-history")->assertOk());
        });
    }

    public function test_analytics_overview_does_not_scale_with_courses(): void
    {
        $this->assertFlat('GET /analytics/overview', 2, 10, function (int $n) {
            $this->seed_courses($n, 3, 5);
            return $this->countQueries(fn () => $this->getJson('/api/analytics/overview')->assertOk());
        });
        $this->assertFlat('GET /analytics/courses/{id}', 2, 12, function (int $n) {
            $courseId = $this->seed_courses(1, $n, 5)[0]['course'];
            return $this->countQueries(fn () => $this->getJson("/api/analytics/courses/{$courseId}")->assertOk());
        });
    }

    public function test_question_bank_submissions_and_reports_lists_do_not_scale(): void
    {
        $this->assertFlat('GET /courses/{id}/previous-questions', 5, 100, function (int $n) {
            $courseId = $this->seed_courses(1, 1, 2)[0]['course'];
            for ($i = 1; $i <= $n; $i++) {
                PreviousQuestion::create(['user_id' => $this->faculty->id, 'course_id' => $courseId, 'question_text' => "Old question {$i}", 'source_year' => '2024']);
            }
            return $this->countQueries(fn () => $this->getJson("/api/courses/{$courseId}/previous-questions?per_page=100")->assertOk());
        });
        $this->assertFlat('GET /assessments/{id}/submissions', 3, 40, function (int $n) {
            $ids = $this->seed_courses(1, 1, 3)[0];
            $questions = Question::where('assessment_id', $ids['assessment'])->get();
            for ($i = 1; $i <= $n; $i++) {
                $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'S' . uniqid(), 'name' => "Student {$i}"]);
                $sub = StudentSubmission::create(['assessment_id' => $ids['assessment'], 'student_id' => $student->id, 'status' => 'GRADED', 'grading_status' => 'FACULTY_REVIEWED', 'submitted_at' => now(), 'total_marks' => 15, 'awarded_marks' => 10]);
                foreach ($questions as $q) {
                    StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $q->id, 'answer_type' => 'TEXT', 'answer_text' => 'a', 'awarded_marks' => 3, 'answer_status' => 'REVIEWED']);
                }
            }
            return $this->countQueries(fn () => $this->getJson("/api/assessments/{$ids['assessment']}/submissions?per_page=100")->assertOk());
        });
        $this->assertFlat('GET /assessments/{id}/submissions/summary', 3, 40, function (int $n) {
            $ids = $this->seed_courses(1, 1, 3)[0];
            for ($i = 1; $i <= $n; $i++) {
                $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'T' . uniqid(), 'name' => "Student {$i}"]);
                StudentSubmission::create(['assessment_id' => $ids['assessment'], 'student_id' => $student->id, 'status' => 'GRADED', 'grading_status' => 'FACULTY_REVIEWED', 'submitted_at' => now(), 'total_marks' => 15, 'awarded_marks' => 10]);
            }
            return $this->countQueries(fn () => $this->getJson("/api/assessments/{$ids['assessment']}/submissions/summary")->assertOk());
        });
        $this->assertFlat('GET /assessments/{id}/performance', 3, 40, function (int $n) {
            $ids = $this->seed_courses(1, 1, 5)[0];
            $questions = Question::where('assessment_id', $ids['assessment'])->get();
            for ($i = 1; $i <= $n; $i++) {
                $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'P' . uniqid(), 'name' => "Student {$i}"]);
                $sub = StudentSubmission::create(['assessment_id' => $ids['assessment'], 'student_id' => $student->id, 'status' => 'GRADED', 'grading_status' => 'FACULTY_REVIEWED', 'submitted_at' => now(), 'total_marks' => 25, 'awarded_marks' => 15]);
                foreach ($questions as $q) {
                    StudentAnswer::create(['student_submission_id' => $sub->id, 'question_id' => $q->id, 'answer_type' => 'TEXT', 'answer_text' => 'a', 'awarded_marks' => 3, 'answer_status' => 'REVIEWED']);
                }
            }
            return $this->countQueries(fn () => $this->getJson("/api/assessments/{$ids['assessment']}/performance")->assertOk());
        });
    }
}
