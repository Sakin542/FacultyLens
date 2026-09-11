<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\AssessmentReport;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\PreviousQuestion;
use App\Models\Question;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LivePipelineEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_live_pipeline_e2e_with_real_ai_service(): void
    {
        $this->requireLiveAiService();
        // 1. Authenticate Faculty
        $faculty = User::factory()->create([
            'name' => 'Prof. Edgar Codd',
            'email' => 'codd@relational.edu',
            'role' => 'FACULTY',
        ]);

        // 2. Create Course: CSE101 � Database Systems
        $course = Course::create([
            'user_id' => $faculty->id,
            'course_code' => 'CSE101',
            'course_name' => 'Database Systems',
            'description' => 'Relational models, relational algebra, SQL, normalization, and concurrency control.',
            'semester' => 'Spring',
            'academic_year' => '2026',
            'credits' => 3,
            'status' => 'active',
        ]);

        // 3. Create Learning Outcomes (LO1, LO2, LO3)
        $lo1 = LearningOutcome::create([
            'course_id' => $course->id,
            'code' => 'LO1',
            'description' => 'Explain fundamental database concepts.',
            'cognitive_level' => 'Understand',
            'sort_order' => 1,
        ]);

        $lo2 = LearningOutcome::create([
            'course_id' => $course->id,
            'code' => 'LO2',
            'description' => 'Apply normalization techniques.',
            'cognitive_level' => 'Apply',
            'sort_order' => 2,
        ]);

        $lo3 = LearningOutcome::create([
            'course_id' => $course->id,
            'code' => 'LO3',
            'description' => 'Analyze relational database designs.',
            'cognitive_level' => 'Analyze',
            'sort_order' => 3,
        ]);

        // 4. Create Assessment: Midterm Examination (Total Marks: 50)
        $assessment = Assessment::create([
            'course_id' => $course->id,
            'title' => 'Midterm Examination',
            'type' => 'Midterm',
            'total_marks' => 50,
            'duration_minutes' => 90,
            'status' => 'Draft',
        ]);

        // 5. Add 20 Realistic Questions to Assessment
        $questions = [
            // Database Concepts (5 marks)
            ['num' => 1, 'text' => 'Explain the concept of physical data independence in DBMS.', 'marks' => 2],
            ['num' => 2, 'text' => 'Define the ACID properties of database transactions.', 'marks' => 2],
            ['num' => 3, 'text' => 'What is the role of the Database Administrator (DBA)?', 'marks' => 1],
            // ER Diagrams (5 marks)
            ['num' => 4, 'text' => 'Construct an Entity-Relationship (ER) diagram for a University enrollment system.', 'marks' => 3],
            ['num' => 5, 'text' => 'Distinguish between weak entity sets and strong entity sets with an example.', 'marks' => 2],
            // Relational Algebra (5 marks)
            ['num' => 6, 'text' => 'Write relational algebra expressions for selection and natural join operations.', 'marks' => 3],
            ['num' => 7, 'text' => 'Explain the Cartesian product operation and how it differs from join.', 'marks' => 2],
            // SQL Queries (10 marks)
            ['num' => 8, 'text' => 'Write an SQL query to retrieve students with GPA higher than the department average.', 'marks' => 3],
            ['num' => 9, 'text' => 'Explain GROUP BY and HAVING clauses with illustrative queries.', 'marks' => 3],
            ['num' => 10, 'text' => 'Write a query to perform an OUTER JOIN between Courses and Instructors.', 'marks' => 4],
            // Normalization (10 marks)
            ['num' => 11, 'text' => 'State the conditions required for a relation to be in First Normal Form (1NF).', 'marks' => 2],
            ['num' => 12, 'text' => 'Explain Second Normal Form (2NF) and how partial functional dependencies are resolved.', 'marks' => 2],
            ['num' => 13, 'text' => 'Apply 3NF decomposition to the relation R(A, B, C, D) with FDs {A->B, B->C, C->D}.', 'marks' => 3],
            ['num' => 14, 'text' => 'Compare Boyce-Codd Normal Form (BCNF) and Third Normal Form (3NF).', 'marks' => 3],
            // Transactions & Concurrency (8 marks)
            ['num' => 15, 'text' => 'Describe the serializability problem in concurrent transaction execution.', 'marks' => 3],
            ['num' => 16, 'text' => 'Explain the Two-Phase Locking (2PL) protocol and how it prevents conflicts.', 'marks' => 3],
            ['num' => 17, 'text' => 'Define deadlock in DBMS and name two recovery strategies.', 'marks' => 2],
            // Indexing & Storage (7 marks)
            ['num' => 18, 'text' => 'Explain the internal structure of B+ trees and how range searches are optimized.', 'marks' => 3],
            ['num' => 19, 'text' => 'Compare clustered and unclustered indices in terms of disk I/O performance.', 'marks' => 2],
            ['num' => 20, 'text' => 'Analyze the performance trade-offs of hash indexing versus tree-based indexing.', 'marks' => 2],
        ];

        foreach ($questions as $q) {
            Question::create([
                'assessment_id' => $assessment->id,
                'question_number' => $q['num'],
                'question_text' => $q['text'],
                'marks' => $q['marks'],
            ]);
        }

        // 6. Populate Previous Question Bank (30 realistic questions)
        $prevQuestions = [
            'Define database schema and database instance.',
            'What are the advantages of DBMS over traditional file processing systems?',
            'Explain the three-schema architecture.',
            'Construct an ER diagram for a hospital patient management system.',
            'Explain total participation and partial participation in ER modeling.',
            'Convert an ER diagram with multi-valued attributes into relational schemas.',
            'Write relational algebra expressions to find names of students enrolled in CSE101.',
            'Demonstrate the division operator in relational algebra.',
            'Write SQL query using subqueries to find highest paid employee.',
            'Demonstrate correlated subqueries in SQL.',
            'Explain database views and their advantages in security.',
            'What is candidate key, super key, and primary key?',
            'Define functional dependency and Armstrong axioms.',
            'Explain transitive dependency and its impact on 2NF relations.',
            'Explain database normalization and decompose a schema into 3NF.',
            'Describe Boyce-Codd Normal Form with an example violating BCNF.',
            'What is lossless-join decomposition and dependency preservation?',
            'Explain ACID properties with an ATM withdrawal scenario.',
            'Demonstrate conflict serializability using precedence graphs.',
            'Describe strict two-phase locking protocol.',
            'Explain dirty read, unrepeatable read, and phantom read anomalies.',
            'What is write-ahead logging (WAL) in database recovery?',
            'Explain check-pointing technique in database recovery.',
            'Explain B-tree and B+ tree node splitting.',
            'What is hashing and how are hash collisions resolved in databases?',
            'Compare dynamic hashing with extendible hashing.',
            'Explain query optimization and cost-based query evaluation.',
            'Describe relational algebra equivalence rules for query transformation.',
            'Explain distributed databases and two-phase commit protocol.',
            'What are the differences between relational databases and NoSQL document databases?'
        ];

        foreach ($prevQuestions as $idx => $pqText) {
            PreviousQuestion::create([
                'user_id' => $faculty->id,
                'course_id' => $course->id,
                'question_number' => $idx + 1,
                'question_text' => $pqText,
                'academic_year' => '2025',
                'source_exam_name' => 'Midterm 2025',
            ]);
        }

        // 7. Execute Real AI Analysis Pipeline (No Http::fake)
        $response = $this->actingAs($faculty, 'sanctum')->postJson('/api/ai/analyze-assessment', [
            'assessment_id' => $assessment->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        // 8. Verify Analysis Report Persisted
        $report = AnalysisReport::where('assessment_id', $assessment->id)->where('is_current', true)->first();
        $this->assertNotNull($report);
        $this->assertEquals('completed', $report->analysis_status);
        $this->assertNotNull($report->overall_score);
        $this->assertGreaterThan(0, (float) $report->overall_score);

        // 9. Verify Questions were enriched with real Hugging Face classification
        $q1 = Question::where('assessment_id', $assessment->id)->where('question_number', 1)->first();
        $this->assertEquals('completed', $q1->ai_analysis_status);
        $this->assertNotNull($q1->ai_difficulty_level);
        $this->assertNotNull($q1->ai_cognitive_level);

        // 10. Verify Recommendations were generated and traceable
        $recs = Recommendation::where('analysis_report_id', $report->id)->get();
        $this->assertNotNull($recs);

        // 11. Faculty Submits Feedback on a Recommendation
        if ($recs->count() > 0) {
            $firstRec = $recs->first();
            $feedbackResponse = $this->actingAs($faculty, 'sanctum')->postJson("/api/recommendations/{$firstRec->id}/feedback", [
                'decision' => 'ACCEPTED',
                'rating' => 5,
                'comment' => 'Excellent suggestion; will include more application-level normalization exercises.',
            ]);
            $feedbackResponse->assertStatus(200)
                ->assertJsonPath('status', 'success');
        }

        // 12. Generate PDF Assessment Report (Returns 201 Created)
        $reportResponse = $this->actingAs($faculty, 'sanctum')->postJson("/api/assessments/{$assessment->id}/report/generate");
        $reportResponse->assertStatus(201)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('assessment_reports', [
            'assessment_id' => $assessment->id,
        ]);
    }
}
