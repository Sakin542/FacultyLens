<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * STEP 43 — synthetic large dataset for performance & load testing.
 *
 * Bulk inserts only (no model events) so 100k+ rows seed in seconds. Everything is clearly fake
 * (PERF-STU-…, perf.faculty@example.com) and idempotent: re-running removes the previous perf users first.
 *
 * Scale is controlled with environment variables (defaults = STEP 43 §14 targets):
 *   PERF_COURSES=10  PERF_ASSESSMENTS_PER_COURSE=10  PERF_STUDENTS=5000  PERF_SUBMISSIONS_PER_ASSESSMENT=200
 *   PERF_ANSWERS_PER_SUBMISSION=5  PERF_PREVIOUS_QUESTIONS=5000  PERF_LIST_COURSES=1000  PERF_USERS=100
 *
 * PERF_USERS extra faculty accounts (perf.user.001@example.com …) are EDITOR collaborators on every perf course, so each
 * virtual user in k6 has its own session and its own per-user rate-limit bucket (120 req/min) like real faculty would.
 *
 * Question sizes per course: 10, 20, 30, 50, 75, 100, 120, 150, 180, 200 (so one 200-question assessment exists per course).
 *
 *   php artisan db:seed --class=PerformanceDatasetSeeder --force
 */
class PerformanceDatasetSeeder extends Seeder
{
    public const FACULTY_EMAIL = 'perf.faculty@example.com';
    public const LIST_FACULTY_EMAIL = 'perf.courses@example.com';
    public const USER_EMAIL_PATTERN = 'perf.user.%03d@example.com';
    public const PASSWORD = 'PerfTest#2026';

    protected const QUESTION_SIZES = [10, 20, 30, 50, 75, 100, 120, 150, 180, 200];
    protected const TYPES = ['mcq', 'short_answer', 'descriptive', 'problem_solving', 'true_false'];
    protected const DIFF = ['easy', 'medium', 'hard'];
    protected const BLOOM = ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'];
    protected const TOPICS = ['normalization', 'indexing', 'transactions', 'query optimisation', 'ER modelling', 'concurrency control', 'recovery', 'distributed databases', 'NoSQL stores', 'data warehousing'];

    public function run(): void
    {
        if (app()->environment('production') && ! filter_var(env('ALLOW_DEV_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->command?->error('PerformanceDatasetSeeder refuses to run in production without ALLOW_DEV_SEED=true.');
            return;
        }

        $courses = (int) env('PERF_COURSES', 10);
        $perCourse = (int) env('PERF_ASSESSMENTS_PER_COURSE', 10);
        $students = (int) env('PERF_STUDENTS', 5000);
        $subsPerAssessment = (int) env('PERF_SUBMISSIONS_PER_ASSESSMENT', 200);
        $answersPerSub = (int) env('PERF_ANSWERS_PER_SUBMISSION', 5);
        $previous = (int) env('PERF_PREVIOUS_QUESTIONS', 5000);
        $listCourses = (int) env('PERF_LIST_COURSES', 1000);
        $extraUsers = (int) env('PERF_USERS', 100);
        $now = now()->toDateTimeString();
        $t0 = microtime(true);

        DB::table('users')->whereIn('email', [self::FACULTY_EMAIL, self::LIST_FACULTY_EMAIL])->orWhere('email', 'like', 'perf.user.%@example.com')->delete();
        $userId = DB::table('users')->insertGetId(['name' => 'Dr. Perf Faculty', 'email' => self::FACULTY_EMAIL, 'password' => Hash::make(self::PASSWORD), 'department' => 'CSE', 'designation' => 'Professor', 'created_at' => $now, 'updated_at' => $now]);
        $listUserId = DB::table('users')->insertGetId(['name' => 'Dr. Thousand Courses', 'email' => self::LIST_FACULTY_EMAIL, 'password' => Hash::make(self::PASSWORD), 'department' => 'CSE', 'designation' => 'Professor', 'created_at' => $now, 'updated_at' => $now]);

        // ---------------------------------------------------------------- courses + LOs
        $courseIds = [];
        for ($c = 1; $c <= $courses; $c++) {
            $courseIds[] = DB::table('courses')->insertGetId(['user_id' => $userId, 'course_code' => sprintf('PERF-%03d', $c), 'course_name' => "Performance Course {$c}", 'semester' => $c % 2 ? 'Spring' : 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        }
        $loRows = [];
        foreach ($courseIds as $cid) {
            for ($l = 1; $l <= 5; $l++) {
                $loRows[] = ['course_id' => $cid, 'code' => "LO{$l}", 'description' => ucfirst(self::BLOOM[$l]) . ' ' . self::TOPICS[$l] . ' in relational database systems.', 'cognitive_level' => self::BLOOM[$l], 'sort_order' => $l, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('learning_outcomes')->insert($loRows);
        $losByCourse = DB::table('learning_outcomes')->whereIn('course_id', $courseIds)->get(['id', 'course_id'])->groupBy('course_id')->map(fn ($g) => $g->pluck('id')->all())->all();

        // ---------------------------------------------------------------- assessments + questions
        $assessmentIds = [];
        $questionIdsByAssessment = [];
        foreach ($courseIds as $ci => $cid) {
            for ($a = 0; $a < $perCourse; $a++) {
                $n = self::QUESTION_SIZES[$a % count(self::QUESTION_SIZES)];
                $aid = DB::table('assessments')->insertGetId(['course_id' => $cid, 'title' => "Assessment {$a} ({$n} questions)", 'type' => $a % 3 === 0 ? 'midterm' : ($a % 3 === 1 ? 'quiz' : 'final'), 'assessment_date' => now()->subDays(90 - $a * 7)->toDateString(), 'total_marks' => $n * 5, 'duration_minutes' => 90, 'status' => 'completed', 'created_at' => $now, 'updated_at' => $now]);
                $assessmentIds[] = $aid;
                $rows = [];
                for ($q = 1; $q <= $n; $q++) {
                    $rows[] = ['assessment_id' => $aid, 'question_number' => $q, 'question_text' => $this->questionText($q, $ci), 'question_type' => self::TYPES[$q % 5], 'marks' => 5, 'difficulty_level' => self::DIFF[$q % 3], 'cognitive_level' => self::BLOOM[$q % 6], 'learning_outcome_id' => $losByCourse[$cid][$q % 5], 'ai_difficulty_level' => self::DIFF[$q % 3], 'ai_cognitive_level' => self::BLOOM[$q % 6], 'ai_topics' => json_encode([self::TOPICS[$q % 10]]), 'ai_analysis_status' => 'completed', 'created_at' => $now, 'updated_at' => $now];
                }
                DB::table('questions')->insert($rows);
                $questionIdsByAssessment[$aid] = DB::table('questions')->where('assessment_id', $aid)->orderBy('question_number')->pluck('id')->all();

                // a completed, current analysis report so analytics/history have data
                $reportId = DB::table('analysis_reports')->insertGetId(['assessment_id' => $aid, 'analysis_version' => 1, 'is_current' => 1, 'overall_score' => 60 + ($a * 3) % 40, 'topic_coverage_score' => 70, 'learning_outcome_alignment_score' => 65, 'difficulty_balance_score' => 75, 'cognitive_level_balance_score' => 68, 'similarity_score' => 0.3, 'total_questions' => $n, 'similar_questions_count' => 1, 'findings' => json_encode(['summary' => ['overall_quality_score' => 70]]), 'analysis_status' => 'completed', 'analyzed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('recommendations')->insert([
                    ['analysis_report_id' => $reportId, 'category' => 'difficulty', 'title' => 'Rebalance difficulty', 'problem' => 'Rebalance difficulty', 'description' => 'd', 'explanation' => 'e', 'recommendation' => 'Consider adding harder items.', 'priority' => 'medium', 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
                    ['analysis_report_id' => $reportId, 'category' => 'coverage', 'title' => 'Cover LO5', 'problem' => 'Cover LO5', 'description' => 'd', 'explanation' => 'e', 'recommendation' => 'Consider adding an item for LO5.', 'priority' => 'high', 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
                ]);
            }
        }

        // ---------------------------------------------------------------- previous questions (question bank)
        $prevRows = [];
        for ($p = 1; $p <= $previous; $p++) {
            $prevRows[] = ['user_id' => $userId, 'course_id' => $courseIds[$p % count($courseIds)], 'question_text' => $this->questionText($p, 99), 'question_type' => self::TYPES[$p % 5], 'marks' => 5, 'difficulty_level' => self::DIFF[$p % 3], 'cognitive_level' => self::BLOOM[$p % 6], 'source' => 'previous_exam', 'source_year' => (string) (2020 + $p % 6), 'source_assessment' => 'Exam ' . (2020 + $p % 6), 'created_at' => $now, 'updated_at' => $now];
            if (count($prevRows) === 1000) {
                DB::table('previous_questions')->insert($prevRows);
                $prevRows = [];
            }
        }
        if ($prevRows) {
            DB::table('previous_questions')->insert($prevRows);
        }

        // ---------------------------------------------------------------- students
        $studentRows = [];
        for ($s = 1; $s <= $students; $s++) {
            $studentRows[] = ['created_by' => $userId, 'student_identifier' => sprintf('PERF-STU-%05d', $s), 'name' => "Perf Student {$s}", 'section' => chr(65 + $s % 4), 'created_at' => $now, 'updated_at' => $now];
            if (count($studentRows) === 1000) {
                DB::table('students')->insert($studentRows);
                $studentRows = [];
            }
        }
        if ($studentRows) {
            DB::table('students')->insert($studentRows);
        }
        $studentIds = DB::table('students')->where('created_by', $userId)->pluck('id')->all();

        // ---------------------------------------------------------------- submissions + finalized answers
        $subCount = 0;
        $ansCount = 0;
        foreach ($assessmentIds as $ai => $aid) {
            $qids = $questionIdsByAssessment[$aid];
            $subRows = [];
            $chosen = [];
            for ($k = 0; $k < $subsPerAssessment; $k++) {
                $sid = $studentIds[($ai * 37 + $k) % count($studentIds)];
                if (isset($chosen[$sid])) {
                    continue;
                }
                $chosen[$sid] = true;
                $subRows[] = ['assessment_id' => $aid, 'student_id' => $sid, 'submission_identifier' => "SUB-{$aid}-{$k}", 'status' => 'GRADED', 'grading_status' => 'FINALIZED', 'submitted_at' => $now, 'total_marks' => count($qids) * 5, 'awarded_marks' => null, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('student_submissions')->insert($subRows);
            $subIds = DB::table('student_submissions')->where('assessment_id', $aid)->pluck('id')->all();
            $subCount += count($subIds);

            $ansRows = [];
            foreach ($subIds as $si => $subId) {
                $awarded = 0;
                for ($j = 0; $j < min($answersPerSub, count($qids)); $j++) {
                    $marks = [5, 4, 3.5, 2, 4.5, 1, 3][($si + $j) % 7];
                    $awarded += $marks;
                    $ansRows[] = ['student_submission_id' => $subId, 'question_id' => $qids[($si + $j) % count($qids)], 'answer_type' => 'TEXT', 'answer_text' => 'Synthetic performance answer text.', 'awarded_marks' => $marks, 'answer_status' => 'REVIEWED', 'created_at' => $now, 'updated_at' => $now];
                }
                if (count($ansRows) >= 2000) {
                    DB::table('student_answers')->insert($ansRows);
                    $ansCount += count($ansRows);
                    $ansRows = [];
                }
            }
            if ($ansRows) {
                DB::table('student_answers')->insert($ansRows);
                $ansCount += count($ansRows);
            }
            DB::statement('UPDATE student_submissions s SET awarded_marks = (SELECT COALESCE(SUM(a.awarded_marks),0) FROM student_answers a WHERE a.student_submission_id = s.id) WHERE s.assessment_id = ?', [$aid]);
        }

        // ---------------------------------------------------------------- 1000-course faculty for list/pagination tests
        $listRows = [];
        for ($c = 1; $c <= $listCourses; $c++) {
            $listRows[] = ['user_id' => $listUserId, 'course_code' => sprintf('LIST-%04d', $c), 'course_name' => "List Course {$c}", 'semester' => $c % 2 ? 'Spring' : 'Fall', 'academic_year' => (string) (2020 + $c % 7), 'credits' => 3, 'status' => $c % 5 ? 'active' : 'archived', 'created_at' => $now, 'updated_at' => $now];
            if (count($listRows) === 500) {
                DB::table('courses')->insert($listRows);
                $listRows = [];
            }
        }
        if ($listRows) {
            DB::table('courses')->insert($listRows);
        }

        // ---------------------------------------------------------------- virtual faculty (one per k6 VU)
        $hash = Hash::make(self::PASSWORD);
        $userRows = [];
        for ($u = 1; $u <= $extraUsers; $u++) {
            $userRows[] = ['name' => sprintf('Perf User %03d', $u), 'email' => sprintf(self::USER_EMAIL_PATTERN, $u), 'password' => $hash, 'department' => 'CSE', 'designation' => 'Lecturer', 'created_at' => $now, 'updated_at' => $now];
        }
        if ($userRows) {
            DB::table('users')->insert($userRows);
            $extraIds = DB::table('users')->where('email', 'like', 'perf.user.%@example.com')->pluck('id')->all();
            $collabRows = [];
            foreach ($extraIds as $uid) {
                foreach ($courseIds as $cid) {
                    $collabRows[] = ['course_id' => $cid, 'user_id' => $uid, 'invited_by' => $userId, 'role' => 'EDITOR', 'status' => 'ACTIVE', 'invited_at' => $now, 'accepted_at' => $now, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            foreach (array_chunk($collabRows, 1000) as $chunk) {
                DB::table('course_collaborators')->insert($chunk);
            }
        }

        $this->command?->info(sprintf(
            'PerformanceDatasetSeeder: %d courses, %d assessments, %d questions, %d previous questions, %d students, %d submissions, %d answers, %d list courses, %d virtual faculty in %.1fs',
            $courses, count($assessmentIds), array_sum(array_map('count', $questionIdsByAssessment)), $previous, count($studentIds), $subCount, $ansCount, $listCourses, $extraUsers, microtime(true) - $t0
        ));
    }

    protected function questionText(int $n, int $salt): string
    {
        $topic = self::TOPICS[($n + $salt) % 10];
        $verbs = ['Explain', 'Compare', 'Design', 'Evaluate', 'Describe', 'Analyse', 'Justify', 'Derive'];
        $objects = ['a B+ tree index', 'a 3NF decomposition', 'a two-phase locking schedule', 'a write-ahead log', 'a star schema', 'a hash join', 'an ER diagram', 'a cost-based plan'];

        return sprintf('%s %s for %s, and discuss the trade-offs in a workload with %d concurrent transactions (variant %d).', $verbs[$n % 8], $objects[($n + $salt) % 8], $topic, 10 + ($n % 90), $n);
    }
}
