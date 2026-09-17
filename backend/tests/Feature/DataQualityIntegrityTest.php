<?php

namespace Tests\Feature;

use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\AssessmentBlueprint;
use App\Models\AssessmentVersion;
use App\Models\AssessmentVersionQuestion;
use App\Models\Course;
use App\Models\DocumentChunk;
use App\Models\DocumentProcessing;
use App\Models\InstitutionalReport;
use App\Models\LearningOutcome;
use App\Models\Program;
use App\Models\ProgramOutcome;
use App\Models\Question;
use App\Models\Recommendation;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Services\AssessmentVersionService;
use App\Services\AssessmentVersionValidationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 49 — Data Quality & Integrity Validation
 *
 * Validates the quality, consistency, completeness, correctness, and historical integrity
 * of all FacultyLens academic data across:
 * - Completeness & Field Constraints
 * - Referential Integrity & Foreign Key Rejection
 * - Cross-Entity Consistency (Course ↔ LO ↔ Assessment ↔ Question ↔ Blueprint ↔ Submission)
 * - Marks Integrity & Bounds
 * - Question Metadata & Taxonomy Validation
 * - Assessment Version Immutability & Snapshots
 * - Student Historical Integrity
 * - AI Result & RAG Traceability
 * - Duplicate & Orphan Detection
 * - Authorization Isolation & Multi-Tenancy
 * - Transaction Atomicity & Rollback Safety
 */
class DataQualityIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected User $facultyA;
    protected User $facultyB;
    protected Course $courseA;
    protected Course $courseB;
    protected LearningOutcome $loA;
    protected LearningOutcome $loB;
    protected Assessment $assessmentA;
    protected Question $questionA;

    protected function setUp(): void
    {
        parent::setUp();

        // Faculty A
        $this->facultyA = User::factory()->create([
            'role' => 'FACULTY',
            'department' => 'CSE',
            'email' => 'faculty.a@university.edu',
        ]);

        // Faculty B
        $this->facultyB = User::factory()->create([
            'role' => 'FACULTY',
            'department' => 'EEE',
            'email' => 'faculty.b@university.edu',
        ]);

        Sanctum::actingAs($this->facultyA);

        $this->courseA = Course::create([
            'user_id' => $this->facultyA->id,
            'course_code' => 'CSE-201',
            'course_name' => 'Data Structures',
            'semester' => 'Spring',
            'academic_year' => '2026',
            'credits' => 3,
            'status' => 'active',
        ]);

        $this->loA = LearningOutcome::create([
            'course_id' => $this->courseA->id,
            'code' => 'LO1',
            'description' => 'Analyze algorithm complexity and asymptotic notations.',
            'sort_order' => 1,
        ]);

        $this->assessmentA = Assessment::create([
            'course_id' => $this->courseA->id,
            'title' => 'Data Structures Quiz 1',
            'type' => 'quiz',
            'total_marks' => 20,
            'status' => 'draft',
        ]);

        $this->questionA = Question::create([
            'assessment_id' => $this->assessmentA->id,
            'question_number' => 1,
            'question_text' => 'State the time complexity of binary search.',
            'question_type' => 'descriptive',
            'marks' => 10,
            'difficulty_level' => 'easy',
            'cognitive_level' => 'Remember',
            'learning_outcome_id' => $this->loA->id,
        ]);

        // Setup Course B under Faculty B
        $this->courseB = Course::create([
            'user_id' => $this->facultyB->id,
            'course_code' => 'EEE-101',
            'course_name' => 'Circuit Analysis',
            'semester' => 'Spring',
            'academic_year' => '2026',
            'credits' => 3,
            'status' => 'active',
        ]);

        $this->loB = LearningOutcome::create([
            'course_id' => $this->courseB->id,
            'code' => 'LO1',
            'description' => 'Apply Ohm\'s law and Kirchhoff\'s laws.',
            'sort_order' => 1,
        ]);
    }

    /**
     * 1. Completeness: Required fields cannot be omitted during API requests
     */
    public function test_completeness_rejects_missing_required_fields(): void
    {
        // Missing course_code and course_name
        $this->postJson('/api/courses', [
            'credits' => 3,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['course_code', 'course_name']);

        // Missing assessment title and type
        $this->postJson("/api/courses/{$this->courseA->id}/assessments", [
            'total_marks' => 50,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'type']);

        // Missing LO code and description
        $this->postJson("/api/courses/{$this->courseA->id}/learning-outcomes", [
            'sort_order' => 1,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'description']);
    }

    /**
     * 2. Referential Integrity: Dangling foreign keys are rejected by the database
     */
    public function test_referential_integrity_rejects_dangling_foreign_keys(): void
    {
        // Dangling assessment_id for Question
        $this->expectException(QueryException::class);
        Question::create([
            'assessment_id' => 999999,
            'question_number' => 99,
            'question_text' => 'Dangling question',
            'question_type' => 'descriptive',
            'marks' => 5,
        ]);
    }

    /**
     * 3. Cross-Entity Consistency: Question cannot map to an LO from another course
     */
    public function test_cross_entity_consistency_detects_lo_from_another_course(): void
    {
        // Map QuestionA (in Course A) to LOB (in Course B)
        $q = Question::create([
            'assessment_id' => $this->assessmentA->id,
            'question_number' => 2,
            'question_text' => 'Explain nodal analysis in data structures.',
            'question_type' => 'descriptive',
            'marks' => 10,
            'learning_outcome_id' => $this->loB->id, // Cross-course mismatch
        ]);

        $version = app(AssessmentVersionService::class)->createVersion($this->facultyA, $this->assessmentA, [
            'change_summary' => 'Cross-course test',
        ]);

        $validator = app(AssessmentVersionValidationService::class);
        $result = $validator->validateVersion($version);

        $this->assertSame('INVALID', $result['status']);
        $codes = collect($result['errors'])->pluck('code')->all();
        $this->assertContains('LO_OUTSIDE_COURSE', $codes);
    }

    /**
     * 4. Marks Integrity: Negative marks and mismatched criteria are rejected
     */
    public function test_marks_integrity_rejects_negative_marks_and_mismatched_criteria(): void
    {
        // Negative marks for assessment
        $this->postJson("/api/courses/{$this->courseA->id}/assessments", [
            'title' => 'Negative Marks Exam',
            'type' => 'quiz',
            'total_marks' => -10,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['total_marks']);

        // Negative marks in student answer finalize-grade
        $student = Student::create([
            'created_by' => $this->facultyA->id,
            'student_identifier' => 'STU-NEG-001',
            'name' => 'Student Neg',
        ]);
        $sub = StudentSubmission::create([
            'assessment_id' => $this->assessmentA->id,
            'student_id' => $student->id,
            'total_marks' => 10,
            'status' => 'UNDER_REVIEW',
        ]);
        $ans = StudentAnswer::create([
            'student_submission_id' => $sub->id,
            'question_id' => $this->questionA->id,
            'answer_type' => 'TEXT',
            'answer_text' => 'Answer text',
        ]);

        // Awarding negative marks
        $this->postJson("/api/student-answers/{$ans->id}/finalize-grade", [
            'final_marks' => -5,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['final_marks']);

        // Awarding marks exceeding question marks (10)
        $this->postJson("/api/student-answers/{$ans->id}/finalize-grade", [
            'final_marks' => 25,
        ])->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    /**
     * 5. Question Metadata & Taxonomy Validation: Invalid enums are rejected
     */
    public function test_question_metadata_validation_rejects_invalid_taxonomy_and_types(): void
    {
        $version = app(AssessmentVersionService::class)->createVersion($this->facultyA, $this->assessmentA, [
            'change_summary' => 'Taxonomy test',
        ]);
        $vQuestion = $version->questions()->first();

        // Invalid question type
        $vQuestion->update([
            'question_type' => 'INVALID_TYPE_XYZ',
            'difficulty_level' => 'SUPER_EXTREME',
            'cognitive_level' => 'TELEPATHIC',
        ]);

        $version->unsetRelation('questions');
        $validator = app(AssessmentVersionValidationService::class);
        $result = $validator->validateVersion($version);

        $this->assertSame('INVALID', $result['status']);
        $codes = collect($result['errors'])->pluck('code')->all();
        $this->assertContains('QUESTION_TYPE_INVALID', $codes);
        $this->assertContains('DIFFICULTY_INVALID', $codes);
        $this->assertContains('COGNITIVE_LEVEL_INVALID', $codes);
    }

    /**
     * 6. Assessment Version Immutability: Finalized versions cannot be tampered with
     */
    public function test_assessment_version_immutability(): void
    {
        // Align assessment total marks to question marks (10) for valid finalization
        $this->assessmentA->update(['total_marks' => 10]);

        $version = app(AssessmentVersionService::class)->createVersion($this->facultyA, $this->assessmentA, [
            'change_summary' => 'Initial approved paper',
        ]);

        $this->postJson("/api/assessment-versions/{$version->id}/finalize")->assertOk();
        $version->refresh();
        $this->assertSame('FINALIZED', $version->status);

        // Direct HTTP update must be rejected with 409 Conflict
        $this->putJson("/api/assessment-versions/{$version->id}", [
            'title' => 'Hacked Finalized Assessment',
        ])->assertStatus(409);

        // Version numbers must be unique within an assessment
        $this->expectException(QueryException::class);
        AssessmentVersion::create([
            'assessment_id' => $this->assessmentA->id,
            'version_number' => $version->version_number, // duplicate version number
            'version_label' => 'v1.0-duplicate',
            'version_type' => 'MAJOR',
            'status' => 'DRAFT',
            'created_by' => $this->facultyA->id,
            'title' => 'Duplicate Version',
        ]);
    }

    /**
     * 7. AI Result Traceability: Analysis reports and recommendations trace back to valid assessments
     */
    public function test_ai_result_traceability(): void
    {
        $report = AnalysisReport::create([
            'assessment_id' => $this->assessmentA->id,
            'is_current' => true,
            'analysis_status' => 'completed',
            'overall_score' => 91.0,
        ]);

        $rec = Recommendation::create([
            'analysis_report_id' => $report->id,
            'category' => 'COGNITIVE_ALIGNMENT',
            'priority' => 'HIGH',
            'title' => 'Alignment Check',
            'description' => 'Traceable recommendation description.',
            'recommendation' => 'Review alignment with course outcomes.',
            'problem' => 'Gap in higher order thinking.',
            'status' => 'PENDING',
        ]);

        $this->assertDatabaseHas('analysis_reports', ['id' => $report->id, 'assessment_id' => $this->assessmentA->id]);
        $this->assertDatabaseHas('recommendations', ['id' => $rec->id, 'analysis_report_id' => $report->id]);
        $this->assertSame($this->assessmentA->id, $rec->analysisReport->assessment_id);
    }

    /**
     * 8. RAG & Document Integrity: Document chunks belong to authorized documents
     */
    public function test_rag_document_and_chunk_integrity(): void
    {
        $doc = DocumentProcessing::create([
            'course_id' => $this->courseA->id,
            'user_id' => $this->facultyA->id,
            'original_file_name' => 'DataStructures_Syllabus.pdf',
            'stored_file_name' => 'ds_syllabus.pdf',
            'file_path' => 'documents/ds_syllabus.pdf',
            'file_size' => 10240,
            'mime_type' => 'application/pdf',
            'processing_status' => 'completed',
        ]);

        $chunk = DocumentChunk::create([
            'document_processing_id' => $doc->id,
            'user_id' => $this->facultyA->id,
            'course_id' => $this->courseA->id,
            'chunk_index' => 0,
            'content' => 'Module 1: Asymptotic Analysis and Big-O Notation.',
            'content_hash' => hash('sha256', 'Module 1: Asymptotic Analysis and Big-O Notation.'),
            'word_count' => 7,
        ]);

        $this->assertSame($doc->id, $chunk->document->id);
        $this->assertSame($this->courseA->id, $chunk->document->course_id);

        // Deleting document cascades and removes chunks
        $doc->delete();
        $this->assertDatabaseMissing('document_chunks', ['id' => $chunk->id]);
    }

    /**
     * 9. Authorization Integrity: Cross-tenant faculty access is denied
     */
    public function test_authorization_isolation_prevents_cross_faculty_access(): void
    {
        // Faculty B attempts to view or update Faculty A's course
        Sanctum::actingAs($this->facultyB);

        $this->getJson("/api/courses/{$this->courseA->id}")->assertStatus(403);
        $this->putJson("/api/courses/{$this->courseA->id}", [
            'course_name' => 'Hijacked Course Name',
        ])->assertStatus(403);

        // Faculty B attempts to access Faculty A's assessment
        $this->getJson("/api/assessments/{$this->assessmentA->id}")->assertStatus(403);
        $this->deleteJson("/api/assessments/{$this->assessmentA->id}")->assertStatus(403);
    }

    /**
     * 10. Transaction & Atomic Rollback: No partial invalid state when transaction fails
     */
    public function test_transaction_atomicity_rolls_back_on_failure(): void
    {
        $initialQuestionCount = Question::count();

        try {
            DB::transaction(function () {
                Question::create([
                    'assessment_id' => $this->assessmentA->id,
                    'question_number' => 99,
                    'question_text' => 'Atomic Question 1',
                    'question_type' => 'descriptive',
                    'marks' => 5,
                ]);

                // Simulate unexpected runtime failure
                throw new \RuntimeException('Simulated failure during multi-entity operation.');
            });
        } catch (\RuntimeException $e) {
            // caught
        }

        // Verify transaction was rolled back cleanly — no partial question created
        $this->assertSame($initialQuestionCount, Question::count());
        $this->assertDatabaseMissing('questions', ['question_text' => 'Atomic Question 1']);
    }
}

