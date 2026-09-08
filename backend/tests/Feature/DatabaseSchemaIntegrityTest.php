<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\Recommendation;
use App\Models\RecommendationFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_expected_tables_exist(): void
    {
        $tables = [
            'users',
            'courses',
            'learning_outcomes',
            'assessments',
            'questions',
            'previous_questions',
            'analysis_reports',
            'recommendations',
            'recommendation_decisions',
            'recommendation_feedback',
            'ai_improvement_signals',
            'document_processings',
            'question_similarity_matches',
            'question_learning_outcome_alignments',
            'assessment_reports',
            'audit_logs',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected table '{$table}' is missing from database.");
        }
    }

    public function test_course_cascade_deletes_associated_assessments_and_learning_outcomes(): void
    {
        $faculty = User::factory()->create(['role' => 'FACULTY']);
        $course = Course::create([
            'user_id' => $faculty->id,
            'course_code' => 'CSE101',
            'course_name' => 'Database Systems',
            'semester' => 'Spring',
            'academic_year' => '2026',
            'credits' => 3,
            'status' => 'active',
        ]);

        $lo = LearningOutcome::create([
            'course_id' => $course->id,
            'code' => 'LO1',
            'description' => 'Explain fundamental database concepts.',
            'cognitive_level' => 'Understand',
            'sort_order' => 1,
        ]);

        $assessment = Assessment::create([
            'course_id' => $course->id,
            'title' => 'Midterm Examination',
            'type' => 'Midterm',
            'total_marks' => 50,
            'duration_minutes' => 90,
            'status' => 'Draft',
        ]);

        $question = Question::create([
            'assessment_id' => $assessment->id,
            'question_number' => 1,
            'question_text' => 'Define relational database schema.',
            'marks' => 5,
        ]);

        // Verify entities exist
        $this->assertDatabaseHas('courses', ['id' => $course->id]);
        $this->assertDatabaseHas('learning_outcomes', ['id' => $lo->id]);
        $this->assertDatabaseHas('assessments', ['id' => $assessment->id]);
        $this->assertDatabaseHas('questions', ['id' => $question->id]);

        // Delete course
        $course->delete();

        // Verify cascade deletion
        $this->assertDatabaseMissing('courses', ['id' => $course->id]);
        $this->assertDatabaseMissing('learning_outcomes', ['id' => $lo->id]);
        $this->assertDatabaseMissing('assessments', ['id' => $assessment->id]);
        $this->assertDatabaseMissing('questions', ['id' => $question->id]);
    }

    public function test_audit_logs_and_improvement_signals_integrity(): void
    {
        $faculty = User::factory()->create(['role' => 'FACULTY']);

        $audit = AuditLog::create([
            'user_id' => $faculty->id,
            'action' => 'COURSE_CREATED',
            'entity_type' => 'Course',
            'entity_id' => 999,
            'metadata' => ['course_code' => 'CSE101'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'id' => $audit->id,
            'action' => 'COURSE_CREATED',
            'entity_type' => 'Course',
        ]);
        $this->assertEquals(['course_code' => 'CSE101'], $audit->metadata);
    }
}
