<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\AssessmentBlueprint;
use App\Models\AssessmentVersion;
use App\Models\AssessmentVersionQuestion;
use App\Models\Course;
use App\Models\GeneratedQuestion;
use App\Models\InstitutionalReport;
use App\Models\LearningOutcome;
use App\Models\PerformanceAnalysisRun;
use App\Models\Program;
use App\Models\ProgramOutcome;
use App\Models\Question;
use App\Models\QuestionGenerationRequest;
use App\Models\Recommendation;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Services\AssessmentBlueprintValidator;
use App\Services\AssessmentVersionService;
use App\Services\AssessmentVersionValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 48 — Academic Workflow Validation
 *
 * Validates that FacultyLens follows a logically correct academic assessment lifecycle:
 * Faculty → Course → Learning Outcomes → CO/PO Mapping → Assessment → Blueprint →
 * Question Selection/Generation → AI Analysis → Faculty Review → Rubric →
 * Assessment Version → Student Submission → Faculty Grading → Inter-Grader Review →
 * Student Performance → Learning Gaps → Academic Analytics → Recommendations → Reports.
 */
class AcademicWorkflowValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected Course $course;
    protected LearningOutcome $lo1;
    protected LearningOutcome $lo2;
    protected Program $program;
    protected ProgramOutcome $po1;
    protected ProgramOutcome $po2;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('testing');

        $this->faculty = User::factory()->create([
            'role' => 'FACULTY',
            'department' => 'CSE',
            'email' => 'prof.codd@university.edu',
            'name' => 'Prof. Edgar F. Codd',
        ]);

        Sanctum::actingAs($this->faculty);

        $this->program = Program::create([
            'code' => 'BSC-CSE',
            'name' => 'B.Sc. in Computer Science and Engineering',
            'created_by' => $this->faculty->id,
        ]);

        $this->course = Course::create([
            'user_id' => $this->faculty->id,
            'program_id' => $this->program->id,
            'course_code' => 'CSE-301',
            'course_name' => 'Database Systems',
            'semester' => 'Fall',
            'academic_year' => '2026',
            'credits' => 3,
            'status' => 'active',
            'description' => 'Relational models, relational algebra, SQL, normalization, and transaction processing.',
        ]);

        $this->lo1 = LearningOutcome::create([
            'course_id' => $this->course->id,
            'code' => 'LO1',
            'description' => 'Understand relational database concepts, relational algebra, and SQL query formulation.',
            'cognitive_level' => 'Understand',
            'sort_order' => 1,
        ]);

        $this->lo2 = LearningOutcome::create([
            'course_id' => $this->course->id,
            'code' => 'LO2',
            'description' => 'Apply normalization techniques (1NF, 2NF, 3NF, BCNF) to eliminate anomalies and ensure relational schema integrity.',
            'cognitive_level' => 'Apply',
            'sort_order' => 2,
        ]);

        $this->po1 = ProgramOutcome::create([
            'program_id' => $this->program->id,
            'code' => 'PO1',
            'title' => 'Engineering Knowledge',
        ]);

        $this->po2 = ProgramOutcome::create([
            'program_id' => $this->program->id,
            'code' => 'PO2',
            'title' => 'Problem Analysis',
        ]);
    }

    /**
     * 1. Complete realistic academic lifecycle scenario
     */
    public function test_complete_realistic_academic_lifecycle_scenario(): void
    {
        // 1. Course & LOs verified
        $this->assertDatabaseHas('courses', ['id' => $this->course->id, 'course_code' => 'CSE-301']);
        $this->assertSame(2, $this->course->learningOutcomes()->count());

        // 2. CO-PO Mappings
        $this->postJson("/api/courses/{$this->course->id}/co-po-mappings", [
            'learning_outcome_id' => $this->lo1->id,
            'program_outcome_id' => $this->po1->id,
            'mapping_level' => 3,
        ])->assertStatus(201);

        $this->postJson("/api/courses/{$this->course->id}/co-po-mappings", [
            'learning_outcome_id' => $this->lo2->id,
            'program_outcome_id' => $this->po2->id,
            'mapping_level' => 3,
        ])->assertStatus(201);

        $this->assertDatabaseCount('co_po_mappings', 2);

        // 3. Assessment: Midterm Examination (50 marks total, 10 questions)
        $assessmentRes = $this->postJson("/api/courses/{$this->course->id}/assessments", [
            'title' => 'Midterm Examination',
            'type' => 'midterm',
            'total_marks' => 50,
            'duration_minutes' => 90,
            'status' => 'draft',
            'assessment_date' => '2026-10-20',
        ])->assertStatus(201);
        $assessmentId = $assessmentRes->json('data.id');

        // 4. Assessment Blueprint: 10 questions, 50 marks total
        $blueprintRes = $this->postJson("/api/assessments/{$assessmentId}/blueprint", [
            'title' => 'Database Systems Midterm Blueprint',
            'total_marks' => 50,
            'total_questions' => 10,
            'duration_minutes' => 90,
            'sections' => [
                [
                    'title' => 'Relational Model & SQL',
                    'question_type' => 'descriptive',
                    'question_count' => 5,
                    'marks_per_question' => 5,
                ],
                [
                    'title' => 'Relational Normalization',
                    'question_type' => 'problem_solving',
                    'question_count' => 5,
                    'marks_per_question' => 5,
                ],
            ],
            'constraints' => [
                'difficulty' => [
                    ['key' => 'easy', 'target_percentage' => 30],
                    ['key' => 'medium', 'target_percentage' => 50],
                    ['key' => 'hard', 'target_percentage' => 20],
                ],
                'learning_outcomes' => [
                    ['learning_outcome_id' => $this->lo1->id, 'target_percentage' => 50],
                    ['learning_outcome_id' => $this->lo2->id, 'target_percentage' => 50],
                ],
            ],
            'items' => [],
        ])->assertStatus(201);
        $blueprintId = $blueprintRes->json('data.blueprint.id');

        // Validate and finalize blueprint
        $this->postJson("/api/blueprints/{$blueprintId}/validate")->assertOk();
        $this->postJson("/api/blueprints/{$blueprintId}/finalize")->assertOk();
        $this->assertDatabaseHas('assessment_blueprints', ['id' => $blueprintId, 'status' => 'FINALIZED']);

        // 5. Add 10 Questions matching blueprint (5 marks each = 50 marks total)
        $questionsData = [
            ['Q1: Define a relation and explain referential integrity.', 'descriptive', 5, 'easy', 'Remember', $this->lo1->id],
            ['Q2: Differentiate primary keys, candidate keys, and foreign keys.', 'descriptive', 5, 'easy', 'Understand', $this->lo1->id],
            ['Q3: Explain the fundamental operations of relational algebra.', 'descriptive', 5, 'medium', 'Understand', $this->lo1->id],
            ['Q4: Write SQL to perform an inner join and group by aggregation.', 'descriptive', 5, 'medium', 'Apply', $this->lo1->id],
            ['Q5: Analyze query execution and indexing strategies in DBMS.', 'descriptive', 5, 'hard', 'Analyze', $this->lo1->id],
            ['Q6: Define functional dependency and give an example.', 'problem_solving', 5, 'easy', 'Remember', $this->lo2->id],
            ['Q7: Explain update, insertion, and deletion anomalies with an unnormalized table.', 'problem_solving', 5, 'medium', 'Understand', $this->lo2->id],
            ['Q8: Convert an unnormalized relation into First and Second Normal Form.', 'problem_solving', 5, 'medium', 'Apply', $this->lo2->id],
            ['Q9: Decompose a relation with functional dependencies into Third Normal Form.', 'problem_solving', 5, 'medium', 'Apply', $this->lo2->id],
            ['Q10: Determine whether a given decomposition satisfies Boyce-Codd Normal Form and lossless join property.', 'problem_solving', 5, 'hard', 'Evaluate', $this->lo2->id],
        ];

        $questionIds = [];
        foreach ($questionsData as $i => [$text, $type, $marks, $difficulty, $bloom, $loId]) {
            $q = Question::create([
                'assessment_id' => $assessmentId,
                'question_number' => $i + 1,
                'question_text' => $text,
                'question_type' => $type,
                'marks' => $marks,
                'difficulty_level' => $difficulty,
                'cognitive_level' => $bloom,
                'learning_outcome_id' => $loId,
                'ai_analysis_status' => 'completed',
                'ai_cognitive_level' => $bloom,
                'ai_difficulty_level' => $difficulty,
            ]);
            $questionIds[] = $q->id;
        }

        $this->assertSame(10, Question::where('assessment_id', $assessmentId)->count());
        $this->assertSame(50, (int) Question::where('assessment_id', $assessmentId)->sum('marks'));

        // 6. Validate questions against blueprint
        $validateQ = $this->postJson("/api/blueprints/{$blueprintId}/validate-questions", ['question_ids' => $questionIds])->assertOk();
        $this->assertSame(10, $validateQ->json('data.evaluated'));

        // 7. Mock / persist AI Analysis Report & Recommendations
        $report = AnalysisReport::create([
            'assessment_id' => $assessmentId,
            'is_current' => true,
            'analysis_status' => 'completed',
            'overall_score' => 88.5,
            'bloom_distribution' => ['Remember' => 20, 'Understand' => 30, 'Apply' => 30, 'Analyze' => 10, 'Evaluate' => 10],
            'difficulty_distribution' => ['easy' => 30, 'medium' => 50, 'hard' => 20],
            'quality_metrics' => ['overall_quality_score' => 88.5],
        ]);

        $rec = Recommendation::create([
            'analysis_report_id' => $report->id,
            'category' => 'BLOOM_BALANCE',
            'priority' => 'LOW',
            'title' => 'Good Higher-Order Cognitive Balance',
            'description' => 'The midterm includes appropriate analytical and evaluation questions.',
            'recommendation' => 'Maintain the balance across sections.',
            'problem' => 'Ensure advanced questions are scaffolded.',
            'status' => 'PENDING',
        ]);

        // Faculty reviews recommendation
        $this->postJson("/api/recommendations/{$rec->id}/feedback", [
            'decision' => 'ACCEPTED',
            'usefulness_rating' => 5,
            'comment' => 'Agreed, good balance for 3rd year database systems students.',
        ])->assertOk();
        $this->assertSame('accepted', strtolower(Recommendation::find($rec->id)->status));

        // 8. Rubric Generation & Approval for Question 10 (5 marks)
        $q10 = Question::find($questionIds[9]);
        $rubric = Rubric::create([
            'assessment_id' => $assessmentId,
            'question_id' => $q10->id,
            'created_by' => $this->faculty->id,
            'status' => 'DRAFT',
            'title' => 'BCNF & Lossless Join Scoring Rubric',
            'total_marks' => 5.0,
            'generation_method' => 'AI',
        ]);

        RubricCriterion::create([
            'rubric_id' => $rubric->id,
            'criterion' => 'Correct identification of candidate keys and BCNF violations',
            'description' => 'Evaluates identification of candidate keys and anomalies.',
            'max_marks' => 2.0,
            'sort_order' => 1,
        ]);
        RubricCriterion::create([
            'rubric_id' => $rubric->id,
            'criterion' => 'Accurate dependency preservation and lossless join proof',
            'description' => 'Evaluates proof of dependency preservation.',
            'max_marks' => 3.0,
            'sort_order' => 2,
        ]);

        // Verify rubric criteria sum equals question marks (2 + 3 = 5)
        $this->assertEqualsWithDelta(5.0, (float) $rubric->criteria()->sum('max_marks'), 0.01);
        $this->postJson("/api/rubrics/{$rubric->id}/approve")->assertOk();
        $this->assertSame('APPROVED', Rubric::find($rubric->id)->status);

        // 9. Assessment Version Snapshot Creation & Finalization
        $versionService = app(AssessmentVersionService::class);
        $version = $versionService->createVersion($this->faculty, Assessment::find($assessmentId), [
            'change_summary' => 'Initial approved midterm examination paper with 10 questions',
        ]);

        $this->assertSame('DRAFT', $version->status);
        $this->assertSame(10, $version->questions()->count());

        // Validate version
        $validator = app(AssessmentVersionValidationService::class);
        $vRes = $validator->validateVersion($version);
        $this->assertNotSame('INVALID', $vRes['status'] ?? 'VALID');

        // Finalize version
        $this->postJson("/api/assessment-versions/{$version->id}/finalize")->assertOk();
        $version->refresh();
        $this->assertSame('FINALIZED', $version->status);

        // Immutability check: modifying finalized version must return 409
        $this->putJson("/api/assessment-versions/{$version->id}", ['title' => 'Tampered Title'])->assertStatus(409);

        // 10. Student Submissions & Faculty Grading
        $students = [];
        for ($s = 1; $s <= 5; $s++) {
            $student = Student::create([
                'created_by' => $this->faculty->id,
                'student_identifier' => sprintf('STU-DB-%03d', $s),
                'name' => "Database Student {$s}",
                'section' => 'A',
            ]);
            $students[] = $student;

            $submission = StudentSubmission::create([
                'assessment_id' => $assessmentId,
                'assessment_version_id' => $version->id,
                'student_id' => $student->id,
                'status' => 'UNDER_REVIEW',
                'grading_status' => 'NOT_STARTED',
                'total_marks' => 50,
                'submitted_at' => now(),
            ]);

            // Create 10 answers per student mapped to version snapshots
            $versionQuestions = $version->questions->keyBy('question_number');
            $awardedMarksTotal = 0;
            foreach ($questionIds as $idx => $qid) {
                $qNum = $idx + 1;
                $vq = $versionQuestions[$qNum];
                $marksEarned = min(5, max(1, 3 + (($s + $idx) % 3))); // marks between 3 and 5

                $answer = StudentAnswer::create([
                    'student_submission_id' => $submission->id,
                    'question_id' => $qid,
                    'assessment_version_question_id' => $vq->id,
                    'answer_type' => 'TEXT',
                    'answer_text' => "Student {$s} answer for question {$qNum}.",
                    'answer_status' => 'NOT_REVIEWED',
                ]);

                // Faculty finalizes grade
                $this->postJson("/api/student-answers/{$answer->id}/finalize-grade", [
                    'final_marks' => $marksEarned,
                    'faculty_feedback' => 'Good demonstration of database principles.',
                ])->assertOk();

                $awardedMarksTotal += $marksEarned;
            }

            // Mark submission as graded
            $this->patchJson("/api/submissions/{$submission->id}/status", [
                'status' => 'GRADED',
            ])->assertOk();

            $submission->refresh();
            $this->assertEqualsWithDelta((float) $awardedMarksTotal, (float) $submission->awarded_marks, 0.01);
        }

        $this->assertSame(5, StudentSubmission::where('assessment_id', $assessmentId)->where('status', 'GRADED')->count());
        $this->assertSame(50, StudentAnswer::whereNotNull('awarded_marks')->count());

        // 11. Student Performance & Gap Analysis
        $perfRes = $this->postJson("/api/assessments/{$assessmentId}/performance/analyze")->assertOk();
        $this->assertSame('COMPLETED', $perfRes->json('data.status'));

        $run = PerformanceAnalysisRun::where('assessment_id', $assessmentId)->where('is_current', true)->firstOrFail();
        $this->assertSame(50, $run->finalized_answer_count);
        $this->assertGreaterThan(0, $run->overall_average_percentage);
        $this->assertSame(2, $run->learningOutcomeResults()->count());

        // Verify LO performance reflects both outcomes
        $loResults = $this->getJson("/api/assessments/{$assessmentId}/performance/learning-outcomes")->assertOk()->json('data');
        $this->assertCount(2, $loResults);

        // 12. Academic Analytics Overview & Course Section
        $overview = $this->getJson('/api/analytics/overview?fresh=1')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(1, $overview['kpis']['courses']['value']);
        $this->assertGreaterThanOrEqual(1, $overview['kpis']['assessments']['value']);
        $this->assertGreaterThanOrEqual(10, $overview['kpis']['questions']['value']);

        // 13. Institutional Report Generation
        $reportRes = $this->postJson('/api/reports', [
            'report_type' => 'ASSESSMENT_QUALITY',
            'scope_type' => 'ASSESSMENT',
            'filters' => [
                'course_id' => $this->course->id,
                'assessment_id' => $assessmentId,
            ],
            'format' => 'PDF',
        ]);
        $this->assertContains($reportRes->status(), [201, 202]);
        $reportRowId = $reportRes->json('data.id');
        $instReport = InstitutionalReport::findOrFail($reportRowId);
        $this->assertSame('COMPLETED', $instReport->status);

        // Download report
        $download = $this->actingAs($this->faculty, 'sanctum')->get("/api/reports/{$reportRowId}/download");
        $download->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
    }

    /**
     * 2. Academic Consistency: Questions cannot reference an LO from another course
     */
    public function test_question_cannot_reference_lo_from_another_course(): void
    {
        $otherCourse = Course::create([
            'user_id' => $this->faculty->id,
            'course_code' => 'CSE-402',
            'course_name' => 'Computer Networks',
            'semester' => 'Fall',
            'academic_year' => '2026',
            'credits' => 3,
            'status' => 'active',
        ]);

        $foreignLo = LearningOutcome::create([
            'course_id' => $otherCourse->id,
            'code' => 'NET-LO1',
            'description' => 'Understand OSI 7-layer architecture.',
            'sort_order' => 1,
        ]);

        $assessment = Assessment::create([
            'course_id' => $this->course->id,
            'title' => 'Relational Quiz',
            'type' => 'quiz',
            'total_marks' => 10,
            'status' => 'draft',
        ]);

        // Attempting to attach foreign LO to question
        $q = Question::create([
            'assessment_id' => $assessment->id,
            'question_number' => 1,
            'question_text' => 'Explain packet switching.',
            'question_type' => 'descriptive',
            'marks' => 10,
            'learning_outcome_id' => $foreignLo->id,
        ]);

        // Create version
        $version = app(AssessmentVersionService::class)->createVersion($this->faculty, $assessment, ['change_summary' => 'Test foreign LO']);

        // AssessmentVersionValidationService must detect LO_OUTSIDE_COURSE
        $validator = app(AssessmentVersionValidationService::class);
        $validation = $validator->validateVersion($version);

        $this->assertSame('INVALID', $validation['status']);
        $errorCodes = collect($validation['errors'])->pluck('code')->all();
        $this->assertContains('LO_OUTSIDE_COURSE', $errorCodes);

        // Finalize must be rejected
        $this->postJson("/api/assessment-versions/{$version->id}/finalize")->assertStatus(422);
    }

    /**
     * 3. Historical Integrity: Editing questions in v2 preserves v1 snapshots and student submissions
     */
    public function test_historical_integrity_preserves_v1_and_student_submissions_when_v2_evolves(): void
    {
        $assessment = Assessment::create([
            'course_id' => $this->course->id,
            'title' => 'Midterm Evolution Exam',
            'type' => 'midterm',
            'total_marks' => 10,
            'status' => 'draft',
        ]);

        $q = Question::create([
            'assessment_id' => $assessment->id,
            'question_number' => 1,
            'question_text' => 'Original Question: Define 1NF.',
            'question_type' => 'descriptive',
            'marks' => 10,
            'difficulty_level' => 'easy',
            'cognitive_level' => 'Remember',
            'learning_outcome_id' => $this->lo2->id,
        ]);

        // Create and finalize version 1
        $versionService = app(AssessmentVersionService::class);
        $v1 = $versionService->createVersion($this->faculty, $assessment, ['change_summary' => 'v1 initial']);
        $v1->update(['status' => 'FINALIZED']);
        $v1Question = $v1->questions()->first();

        // Create student submission against v1
        $student = Student::create([
            'created_by' => $this->faculty->id,
            'student_identifier' => 'HIST-STU-001',
            'name' => 'Historical Student',
        ]);

        $submission = StudentSubmission::create([
            'assessment_id' => $assessment->id,
            'assessment_version_id' => $v1->id,
            'student_id' => $student->id,
            'status' => 'GRADED',
            'grading_status' => 'FINALIZED',
            'total_marks' => 10,
            'awarded_marks' => 9.5,
            'submitted_at' => now(),
        ]);

        $answer = StudentAnswer::create([
            'student_submission_id' => $submission->id,
            'question_id' => $q->id,
            'assessment_version_question_id' => $v1Question->id,
            'answer_type' => 'TEXT',
            'answer_text' => '1NF requires all attribute values to be atomic.',
            'awarded_marks' => 9.5,
            'answer_status' => 'REVIEWED',
        ]);

        // Now mutate original question on the live assessment
        $q->update([
            'question_text' => 'Revised Question: Compare 1NF and BCNF with examples.',
            'marks' => 15,
            'difficulty_level' => 'hard',
            'cognitive_level' => 'Analyze',
        ]);
        $assessment->update(['total_marks' => 15]);

        // Create version 2 (cloned from v1)
        $v2 = $versionService->createVersion($this->faculty, $assessment, ['change_summary' => 'v2 updated question']);
        $v2Question = $v2->questions()->first();

        // Mutate question in v2 draft
        $v2Question->update([
            'question_text' => 'Revised Question: Compare 1NF and BCNF with examples.',
            'marks' => 15,
            'difficulty_level' => 'hard',
            'cognitive_level' => 'Analyze',
        ]);
        $v2->refresh();

        // ASSERT HISTORICAL INTEGRITY:
        // 1. v1 remains completely unchanged
        $v1Question->refresh();
        $this->assertSame('Original Question: Define 1NF.', $v1Question->question_text);
        $this->assertEqualsWithDelta(10.0, (float) $v1Question->marks, 0.01);
        $this->assertSame('easy', $v1Question->difficulty_level);
        $this->assertSame('Remember', $v1Question->cognitive_level);

        // 2. v2 carries the revised question
        $v2Question->refresh();
        $this->assertSame('Revised Question: Compare 1NF and BCNF with examples.', $v2Question->question_text);
        $this->assertEqualsWithDelta(15.0, (float) $v2Question->marks, 0.01);
        $this->assertSame('hard', $v2Question->difficulty_level);
        $this->assertSame('Analyze', $v2Question->cognitive_level);

        // 3. Historical student submission remains tied to v1 and unchanged
        $answer->refresh();
        $submission->refresh();
        $this->assertSame($v1->id, $submission->assessment_version_id);
        $this->assertSame($v1Question->id, $answer->assessment_version_question_id);
        $this->assertEqualsWithDelta(9.5, (float) $answer->awarded_marks, 0.01);
        $this->assertEqualsWithDelta(9.5, (float) $submission->awarded_marks, 0.01);
    }

    /**
     * 4. Academic Decision Boundaries: AI suggestions never automatically become final grades
     */
    public function test_academic_decision_boundaries_prevent_automated_grading_without_faculty_authorization(): void
    {
        $assessment = Assessment::create([
            'course_id' => $this->course->id,
            'title' => 'Decision Boundary Test',
            'type' => 'quiz',
            'total_marks' => 10,
            'status' => 'draft',
        ]);

        $q = Question::create([
            'assessment_id' => $assessment->id,
            'question_number' => 1,
            'question_text' => 'What is ACID?',
            'question_type' => 'descriptive',
            'marks' => 10,
        ]);

        $student = Student::create([
            'created_by' => $this->faculty->id,
            'student_identifier' => 'DEC-STU-001',
            'name' => 'Decision Student',
        ]);

        $submission = StudentSubmission::create([
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'status' => 'UNDER_REVIEW',
            'grading_status' => 'NOT_STARTED',
            'total_marks' => 10,
        ]);

        $answer = StudentAnswer::create([
            'student_submission_id' => $submission->id,
            'question_id' => $q->id,
            'answer_type' => 'TEXT',
            'answer_text' => 'Atomicity, Consistency, Isolation, Durability.',
            'answer_status' => 'NOT_REVIEWED',
        ]);

        // AI suggests 9.0 marks via AiGradingResult
        \App\Models\AiGradingResult::create([
            'student_answer_id' => $answer->id,
            'student_submission_id' => $submission->id,
            'question_id' => $q->id,
            'suggested_marks' => 9.0,
            'maximum_marks' => 10.0,
            'confidence_score' => 0.95,
            'reasoning' => 'Comprehensive definition of ACID properties.',
            'is_current' => true,
            'status' => 'COMPLETED',
        ]);

        // VERIFY: Answer awarded_marks is STILL NULL, submission awarded_marks is STILL NULL
        $answer->refresh();
        $submission->refresh();
        $this->assertNull($answer->awarded_marks);
        $this->assertNull($submission->awarded_marks);
        $this->assertSame('NOT_REVIEWED', $answer->answer_status);
        $this->assertNotSame('FINALIZED', $submission->grading_status);

        // Only after faculty explicitly calls finalize-grade does it become official
        $this->postJson("/api/student-answers/{$answer->id}/finalize-grade", [
            'final_marks' => 9.5, // faculty may even override the AI recommendation
            'faculty_feedback' => 'Approved by faculty with bonus for succinctness.',
        ])->assertOk();

        $answer->refresh();
        $this->assertEqualsWithDelta(9.5, (float) $answer->awarded_marks, 0.01);
        $this->assertSame('REVIEWED', $answer->answer_status);
    }

    /**
     * 5. AI Question Generation: Generated questions remain DRAFT and require explicit approval
     */
    public function test_ai_generated_questions_remain_draft_until_faculty_review_and_approval(): void
    {
        $genReq = QuestionGenerationRequest::create([
            'user_id' => $this->faculty->id,
            'course_id' => $this->course->id,
            'learning_outcome_id' => $this->lo1->id,
            'question_type' => 'descriptive',
            'difficulty_level' => 'medium',
            'bloom_level' => 'Apply',
            'topic' => 'SQL Joins',
            'target_count' => 1,
            'status' => 'COMPLETED',
        ]);

        $genQ = GeneratedQuestion::create([
            'generation_request_id' => $genReq->id,
            'sequence' => 1,
            'learning_outcome_id' => $this->lo1->id,
            'question_text' => 'Given tables Students and Enrollments, write an SQL query to find students with no enrollments.',
            'original_question_text' => 'Given tables Students and Enrollments, write an SQL query to find students with no enrollments.',
            'question_type' => 'descriptive',
            'difficulty_level' => 'medium',
            'cognitive_level' => 'Apply',
            'marks' => 5,
            'review_status' => 'DRAFT',
            'validation_status' => 'PASSED',
        ]);

        // Verify initial state is DRAFT
        $this->assertSame('DRAFT', $genQ->review_status);

        // Approve question
        $this->postJson("/api/generated-questions/{$genQ->id}/approve")->assertOk();
        $genQ->refresh();
        $this->assertSame('APPROVED', $genQ->review_status);

        // Assessment
        $assessment = Assessment::create([
            'course_id' => $this->course->id,
            'title' => 'Generated Question Exam',
            'type' => 'quiz',
            'total_marks' => 5,
            'status' => 'draft',
        ]);

        // Add to assessment
        $this->postJson("/api/generated-questions/{$genQ->id}/add-to-assessment", [
            'assessment_id' => $assessment->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('questions', [
            'assessment_id' => $assessment->id,
            'question_text' => $genQ->question_text,
            'marks' => 5,
        ]);
    }

    /**
     * 6. Workflow Exceptions: Actionable warnings when blueprint section marks mismatch total
     */
    public function test_blueprint_validation_catches_marks_mismatch_with_actionable_errors(): void
    {
        $assessment = Assessment::create([
            'course_id' => $this->course->id,
            'title' => 'Mismatched Blueprint Exam',
            'type' => 'quiz',
            'total_marks' => 50,
            'status' => 'draft',
        ]);

        // Blueprint total = 50, but sections total = 30 (10 + 20)
        $res = $this->postJson("/api/assessments/{$assessment->id}/blueprint", [
            'title' => 'Invalid Blueprint',
            'total_marks' => 50,
            'total_questions' => 2,
            'sections' => [
                ['title' => 'Part A', 'question_type' => 'mcq', 'question_count' => 1, 'marks_per_question' => 10],
                ['title' => 'Part B', 'question_type' => 'descriptive', 'question_count' => 1, 'marks_per_question' => 20],
            ],
        ])->assertStatus(201);

        $blueprintId = $res->json('data.blueprint.id');

        // Validation must flag mismatch
        $valRes = $this->postJson("/api/blueprints/{$blueprintId}/validate")->assertOk();
        $this->assertSame('INVALID', $valRes->json('data.validation.status'));
        $dimensions = collect($valRes->json('data.validation.errors'))->pluck('dimension')->all();
        $this->assertContains('MARKS', $dimensions);

        // Finalize must be rejected
        $this->postJson("/api/blueprints/{$blueprintId}/finalize")->assertStatus(422);
    }
}
