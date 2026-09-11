<?php

namespace Database\Seeders;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\LearningOutcomePerformanceResult;
use App\Models\PerformanceAnalysisRun;
use App\Models\PreviousQuestion;
use App\Models\Question;
use App\Models\QuestionPerformanceResult;
use App\Models\Recommendation;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Services\AssessmentBlueprintService;
use App\Services\AssessmentVersionService;
use App\Services\InstitutionalReportService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DevelopmentSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Development / test fixtures only. All people are synthetic ("Student 001", faculty@example.com);
     * grades and analyses are illustrative. Never run against production data.
     */
    public function run(): void
    {
        // 1. Create / Update Test Faculty User
        $user = User::updateOrCreate(
            ['email' => 'faculty@example.com'],
            [
                'name' => 'Dr. John Doe',
                'department' => 'Computer Science & Engineering',
                'designation' => 'Lecturer',
                'password' => Hash::make('password123'),
            ]
        );

        // 2. Create Realistic Academic Courses
        $course1 = Course::create([
            'user_id' => $user->id,
            'course_code' => 'CSE311',
            'course_name' => 'Database Management Systems',
            'description' => 'Relational models, SQL, indexing, transaction processing, and database normalization.',
            'semester' => 'Spring',
            'academic_year' => '2026',
            'credits' => 3,
            'status' => 'active',
        ]);

        $course2 = Course::create([
            'user_id' => $user->id,
            'course_code' => 'CSE422',
            'course_name' => 'Artificial Intelligence & Machine Learning',
            'description' => 'Search algorithms, supervised learning, neural networks, and decision support systems.',
            'semester' => 'Spring',
            'academic_year' => '2026',
            'credits' => 3,
            'status' => 'active',
        ]);

        // 3. Create Learning Outcomes for Course 1 (CSE311)
        $lo1 = LearningOutcome::create([
            'course_id' => $course1->id,
            'code' => 'LO1',
            'description' => 'Understand fundamental relational data models, ER diagrams, and relational algebra.',
            'cognitive_level' => 'Understand',
            'sort_order' => 1,
        ]);

        $lo2 = LearningOutcome::create([
            'course_id' => $course1->id,
            'code' => 'LO2',
            'description' => 'Apply normalization techniques (1NF, 2NF, 3NF, BCNF) to design robust schemas.',
            'cognitive_level' => 'Apply',
            'sort_order' => 2,
        ]);

        $lo3 = LearningOutcome::create([
            'course_id' => $course1->id,
            'code' => 'LO3',
            'description' => 'Analyze query execution plans, B+ tree indexes, and transaction concurrency.',
            'cognitive_level' => 'Analyze',
            'sort_order' => 3,
        ]);

        $lo4 = LearningOutcome::create([
            'course_id' => $course1->id,
            'code' => 'LO4',
            'description' => 'Create complex SQL queries, analytical views, stored procedures, and triggers.',
            'cognitive_level' => 'Create',
            'sort_order' => 4,
        ]);

        // Learning Outcomes for Course 2 (CSE422)
        LearningOutcome::create([
            'course_id' => $course2->id,
            'code' => 'LO1',
            'description' => 'Understand heuristic search strategies, A* search, and game playing algorithms.',
            'cognitive_level' => 'Understand',
            'sort_order' => 1,
        ]);

        LearningOutcome::create([
            'course_id' => $course2->id,
            'code' => 'LO2',
            'description' => 'Apply supervised and unsupervised machine learning algorithms to academic datasets.',
            'cognitive_level' => 'Apply',
            'sort_order' => 2,
        ]);

        LearningOutcome::create([
            'course_id' => $course2->id,
            'code' => 'LO3',
            'description' => 'Evaluate deep neural network architectures and loss functions for classification tasks.',
            'cognitive_level' => 'Evaluate',
            'sort_order' => 3,
        ]);

        // 4. Create Assessments
        $assessment1 = Assessment::create([
            'course_id' => $course1->id,
            'title' => 'Midterm Examination - Spring 2026',
            'type' => 'midterm',
            'description' => 'Comprehensive midterm assessment covering Relational Algebra, Normalization, and B+ Trees.',
            'assessment_date' => '2026-03-15',
            'total_marks' => 50.00,
            'duration_minutes' => 90,
            'status' => 'completed',
        ]);

        $assessment2 = Assessment::create([
            'course_id' => $course2->id,
            'title' => 'Final Examination - Spring 2026',
            'type' => 'final',
            'description' => 'Comprehensive final assessment on heuristic search, probabilistic reasoning, and ML models.',
            'assessment_date' => '2026-05-10',
            'total_marks' => 100.00,
            'duration_minutes' => 120,
            'status' => 'draft',
        ]);

        // 5. Create Questions for Assessment 1
        Question::create([
            'assessment_id' => $assessment1->id,
            'question_number' => 1,
            'question_text' => 'Explain the key distinctions between primary keys, candidate keys, and foreign keys with relational schema examples.',
            'question_type' => 'descriptive',
            'marks' => 10.00,
            'difficulty_level' => 'easy',
            'cognitive_level' => 'Understand',
            'learning_outcome_id' => $lo1->id,
            'expected_answer' => 'Primary key uniquely identifies a row, candidate keys are candidate unique sets, foreign keys reference primary keys.',
        ]);

        Question::create([
            'assessment_id' => $assessment1->id,
            'question_number' => 2,
            'question_text' => 'Given the relation R(A, B, C, D, E) with FDs {A->BC, CD->E, B->D}, decompose R into 3NF and prove lossless join property.',
            'question_type' => 'problem_solving',
            'marks' => 15.00,
            'difficulty_level' => 'hard',
            'cognitive_level' => 'Apply',
            'learning_outcome_id' => $lo2->id,
            'expected_answer' => 'Find canonical cover, identify candidate keys (AD), form relations from FDs, add candidate key relation if needed.',
        ]);

        Question::create([
            'assessment_id' => $assessment1->id,
            'question_number' => 3,
            'question_text' => 'Construct a B+ tree of order 4 (maximum 3 keys per node) by sequentially inserting keys [10, 20, 5, 15, 30, 25]. Show intermediate node splits.',
            'question_type' => 'problem_solving',
            'marks' => 15.00,
            'difficulty_level' => 'medium',
            'cognitive_level' => 'Apply',
            'learning_outcome_id' => $lo3->id,
            'expected_answer' => 'Step-by-step insertion diagrams with leaf node chaining.',
        ]);

        Question::create([
            'assessment_id' => $assessment1->id,
            'question_number' => 4,
            'question_text' => 'Write a standard SQL query using window functions (e.g. DENSE_RANK) to retrieve top 3 highest scoring students in each department.',
            'question_type' => 'short_answer',
            'marks' => 10.00,
            'difficulty_level' => 'medium',
            'cognitive_level' => 'Create',
            'learning_outcome_id' => $lo4->id,
            'expected_answer' => 'WITH Ranked AS (SELECT student_id, dept_id, score, DENSE_RANK() OVER (PARTITION BY dept_id ORDER BY score DESC) as rnk FROM scores) SELECT * FROM Ranked WHERE rnk <= 3;',
        ]);

        // 6. Create Historical Previous Questions for Similarity Detection
        PreviousQuestion::create([
            'user_id' => $user->id,
            'course_id' => $course1->id,
            'question_text' => 'Define primary and foreign key constraints with schema diagrams and entity integrity rules.',
            'question_type' => 'descriptive',
            'marks' => 10.00,
            'difficulty_level' => 'easy',
            'cognitive_level' => 'Understand',
            'source' => 'previous_exam',
            'source_year' => '2025',
            'source_assessment' => 'Midterm Exam 2025',
        ]);

        PreviousQuestion::create([
            'user_id' => $user->id,
            'course_id' => $course1->id,
            'question_text' => 'Decompose relation R(W, X, Y, Z) with given functional dependencies into BCNF.',
            'question_type' => 'problem_solving',
            'marks' => 12.00,
            'difficulty_level' => 'hard',
            'cognitive_level' => 'Apply',
            'source' => 'question_bank',
            'source_year' => '2024',
            'source_assessment' => 'Question Bank Archive',
        ]);

        PreviousQuestion::create([
            'user_id' => $user->id,
            'course_id' => $course2->id,
            'question_text' => 'Trace the Minimax algorithm with Alpha-Beta pruning on the given 4-ply game tree.',
            'question_type' => 'problem_solving',
            'marks' => 15.00,
            'difficulty_level' => 'medium',
            'cognitive_level' => 'Analyze',
            'source' => 'previous_exam',
            'source_year' => '2025',
            'source_assessment' => 'Final Exam 2025',
        ]);

        // 7. Create Analysis Report for Assessment 1
        $report = AnalysisReport::create([
            'assessment_id' => $assessment1->id,
            'overall_score' => 86.50,
            'topic_coverage_score' => 89.00,
            'learning_outcome_alignment_score' => 84.00,
            'difficulty_balance_score' => 85.00,
            'cognitive_level_balance_score' => 88.00,
            'similarity_score' => 14.50,
            'total_questions' => 4,
            'similar_questions_count' => 1,
            'findings' => [
                'coverage' => [
                    'covered_topics' => ['Relational Model', 'Normalization', 'B+ Trees', 'SQL Window Functions'],
                    'coverage_percentage' => 89.0,
                ],
                'cognitive_distribution' => [
                    'Understand' => 20.0,
                    'Apply' => 60.0,
                    'Create' => 20.0,
                ],
                'difficulty_distribution' => [
                    'easy' => 20.0,
                    'medium' => 50.0,
                    'hard' => 30.0,
                ],
                'similarity_flags' => [
                    [
                        'question_number' => 1,
                        'matched_source' => 'Midterm Exam 2025 Q1',
                        'similarity_percentage' => 78.5,
                    ],
                ],
            ],
            'analysis_status' => 'completed',
            'analyzed_at' => '2026-03-16 10:30:00',
        ]);

        // 8. Create Recommendations for the Analysis Report
        Recommendation::create([
            'analysis_report_id' => $report->id,
            'category' => 'similarity',
            'title' => 'High similarity detected on Question 1',
            'description' => 'Question 1 shares 78.5% semantic similarity with Midterm Exam 2025 Q1. Consider reframing the question with a domain-specific scenario to prevent memorization.',
            'priority' => 'high',
            'status' => 'pending',
        ]);

        Recommendation::create([
            'analysis_report_id' => $report->id,
            'category' => 'difficulty',
            'title' => 'Scaffold 3NF Decomposition complexity',
            'description' => 'Question 2 has a high difficulty load. Adding a sub-part for 2NF verification before 3NF decomposition will scaffold student comprehension.',
            'priority' => 'medium',
            'status' => 'reviewed',
        ]);

        Recommendation::create([
            'analysis_report_id' => $report->id,
            'category' => 'learning_outcome',
            'title' => 'Reinforce Transaction ACID properties before Finals',
            'description' => 'Ensure subsequent quizzes cover Transaction Concurrency (LO3) prior to the Final Examination.',
            'priority' => 'low',
            'status' => 'accepted',
        ]);

        $this->seedDownstreamChain($user, $course1, $assessment1, $lo1, $lo2, $lo3);
    }

    /**
     * Blueprint → version → rubric → students → finalized grades → performance run → institutional report,
     * so every STEP 37–39 screen has real relational data in development.
     */
    protected function seedDownstreamChain(User $user, Course $course, Assessment $assessment, LearningOutcome $lo1, LearningOutcome $lo2, LearningOutcome $lo3): void
    {
        $questions = Question::where('assessment_id', $assessment->id)->orderBy('question_number')->get();
        if ($questions->isEmpty()) {
            return;
        }
        $totalMarks = (float) $questions->sum('marks');

        // STEP 37: blueprint mirroring the seeded question set
        $blueprints = app(AssessmentBlueprintService::class);
        $bp = $blueprints->create($user, $assessment, [
            'title' => 'Midterm blueprint', 'total_marks' => $totalMarks, 'total_questions' => $questions->count(), 'duration_minutes' => $assessment->duration_minutes ?? 90,
            'sections' => [['title' => 'Section A', 'question_type' => 'descriptive', 'question_count' => $questions->count(), 'marks_per_question' => round($totalMarks / $questions->count(), 2)]],
            'constraints' => [
                'difficulty' => [['key' => 'easy', 'target_percentage' => 25], ['key' => 'medium', 'target_percentage' => 50], ['key' => 'hard', 'target_percentage' => 25]],
                'learning_outcomes' => [['learning_outcome_id' => $lo1->id, 'target_percentage' => 25], ['learning_outcome_id' => $lo2->id, 'target_percentage' => 50], ['learning_outcome_id' => $lo3->id, 'target_percentage' => 25]],
            ],
        ]);
        $blueprints->validate($user, $bp, false);

        // STEP 25: an approved rubric for the first question
        $q1 = $questions->first();
        $rubric = Rubric::create(['question_id' => $q1->id, 'assessment_id' => $assessment->id, 'created_by' => $user->id, 'title' => 'Rubric for Q1', 'total_marks' => $q1->marks, 'status' => 'APPROVED', 'version' => 1,
            'generation_method' => 'manual', 'approved_at' => now(), 'approved_by' => $user->id]);
        RubricCriterion::create(['rubric_id' => $rubric->id, 'criterion' => 'Correctness', 'description' => 'Answer is technically correct.', 'max_marks' => round((float) $q1->marks * 0.6, 2), 'scoring_guidance' => 'Full marks for a complete, correct answer.', 'sort_order' => 1]);
        RubricCriterion::create(['rubric_id' => $rubric->id, 'criterion' => 'Explanation', 'description' => 'Reasoning is clear and justified.', 'max_marks' => round((float) $q1->marks * 0.4, 2), 'scoring_guidance' => 'Partial marks for incomplete justification.', 'sort_order' => 2]);

        // STEP 38: v1.0 snapshot of the live paper, finalized
        $versions = app(AssessmentVersionService::class);
        $v1 = $versions->createVersion($user, $assessment, ['change_summary' => 'Initial paper']);
        $v1 = $versions->finalizeVersion($user, $v1->fresh());

        // STEP 26–28: six synthetic students with finalized faculty grades (no real people)
        $pattern = [0.9, 0.8, 0.75, 0.7, 0.6, 0.55];
        foreach ($pattern as $i => $ratio) {
            $n = str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $student = Student::create(['created_by' => $user->id, 'student_identifier' => "DEV-STU-{$n}", 'name' => "Student {$n}", 'section' => 'A']);
            $submission = StudentSubmission::create(['assessment_id' => $assessment->id, 'assessment_version_id' => $v1->id, 'student_id' => $student->id, 'submission_identifier' => "MID-{$n}", 'status' => 'GRADED', 'grading_status' => 'FINALIZED', 'submitted_at' => now()->subDays(10), 'total_marks' => $totalMarks]);
            $awarded = 0.0;
            foreach ($questions as $q) {
                $marks = round((float) $q->marks * $ratio, 2);
                $awarded += $marks;
                StudentAnswer::create(['student_submission_id' => $submission->id, 'question_id' => $q->id, 'answer_type' => 'TEXT', 'answer_text' => 'Synthetic development answer.', 'awarded_marks' => $marks, 'faculty_feedback' => 'Seeded feedback.', 'answer_status' => 'REVIEWED']);
            }
            $submission->update(['awarded_marks' => $awarded]);
        }

        // STEP 30: a completed performance run consistent with the grades above (average of the ratio pattern = 71.67%)
        $avg = round(array_sum($pattern) / count($pattern) * 100, 2);
        $run = PerformanceAnalysisRun::create(['assessment_id' => $assessment->id, 'course_id' => $course->id, 'expected_performance_percent' => 70, 'minimum_responses' => 5, 'status' => 'COMPLETED', 'is_current' => true,
            'requested_by' => $user->id, 'finalized_answer_count' => count($pattern) * $questions->count(), 'overall_average_percentage' => $avg, 'overall_status' => 'ON_TARGET', 'analyzed_at' => now()->subDays(9)]);
        foreach ($questions as $q) {
            QuestionPerformanceResult::create(['performance_analysis_run_id' => $run->id, 'question_id' => $q->id, 'question_number' => $q->question_number, 'question_text_excerpt' => mb_substr((string) $q->question_text, 0, 120),
                'difficulty_level' => $q->difficulty_level, 'cognitive_level' => $q->cognitive_level, 'topics' => [], 'response_count' => count($pattern), 'submission_count' => count($pattern), 'maximum_marks' => $q->marks, 'average_marks' => round((float) $q->marks * $avg / 100, 2),
                'average_percentage' => $avg, 'performance_gap' => round(70 - $avg, 2), 'performance_status' => 'ON_TARGET']);
        }
        foreach ([[$lo1, $avg, 'ON_TARGET', count($pattern) * 2], [$lo2, $avg, 'ON_TARGET', count($pattern)], [$lo3, null, 'INSUFFICIENT_DATA', 0]] as [$lo, $pct, $status, $responses]) {
            LearningOutcomePerformanceResult::create(['performance_analysis_run_id' => $run->id, 'learning_outcome_id' => $lo->id, 'lo_code' => $lo->code, 'lo_description' => $lo->description, 'question_count' => $questions->where('learning_outcome_id', $lo->id)->count(),
                'question_ids' => $questions->where('learning_outcome_id', $lo->id)->pluck('id')->values()->all(), 'response_count' => $responses, 'total_marks' => (float) $questions->where('learning_outcome_id', $lo->id)->sum('marks'),
                'average_percentage' => $pct, 'performance_gap' => $pct === null ? null : round(70 - $pct, 2), 'performance_status' => $status]);
        }

        // STEP 39: one generated institutional report bound to v1.0 (private file on the configured disk)
        try {
            app(InstitutionalReportService::class)->create($user, ['report_type' => 'ASSESSMENT', 'scope_type' => 'ASSESSMENT_VERSION',
                'filters' => ['course_id' => $course->id, 'assessment_id' => $assessment->id, 'assessment_version_id' => $v1->id], 'format' => 'CSV']);
        } catch (\Throwable $e) {
            $this->command?->warn('Institutional report fixture skipped: '.class_basename($e));
        }
    }
}
