<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\Question;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use App\Services\QuestionPaperParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssessmentQuestionTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected Course $course;
    protected Assessment $assessment;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->faculty = User::factory()->create();
        $this->course = Course::query()->create(['user_id' => $this->faculty->id, 'course_code' => 'CSE3201', 'course_name' => 'Data Structures', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        $this->assessment = Assessment::query()->create(['course_id' => $this->course->id, 'title' => 'Midterm', 'total_marks' => 30]);
    }

    public function test_faculty_adds_edits_and_deletes_questions_with_automatic_numbering(): void
    {
        $co = LearningOutcome::query()->create(['course_id' => $this->course->id, 'code' => 'CO1', 'description' => 'Apply graph traversal.', 'cognitive_level' => 'Apply']);
        Sanctum::actingAs($this->faculty);

        $first = $this->postJson("/api/assessments/{$this->assessment->id}/questions", ['question_text' => 'Define a graph.', 'marks' => 5])
            ->assertCreated()->assertJsonPath('data.question_number', 1)->assertJsonPath('meta.marks_sum', 5)->json('data.id');
        $second = $this->postJson("/api/assessments/{$this->assessment->id}/questions", [
            'question_text' => 'Explain BFS.', 'marks' => 10, 'question_type' => 'descriptive', 'difficulty_level' => 'medium', 'cognitive_level' => 'Understand', 'learning_outcome_id' => $co->id,
        ])->assertCreated()->assertJsonPath('data.question_number', 2)->assertJsonPath('data.learning_outcome.code', 'CO1')->json('data.id');

        $this->putJson("/api/questions/{$second}", ['marks' => 12, 'cognitive_level' => 'Apply'])->assertOk()->assertJsonPath('data.cognitive_level', 'Apply')->assertJsonPath('meta.marks_sum', 17);
        $this->deleteJson("/api/questions/{$first}")->assertOk()->assertJsonPath('meta.questions', 1);
        $this->getJson("/api/assessments/{$this->assessment->id}/questions")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.learning_outcome.code', 'CO1');
        $this->assertDatabaseHas('audit_logs', ['action' => 'QUESTION_CREATED']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'QUESTION_DELETED']);
    }

    public function test_validation_and_outcomes_from_other_courses_are_refused(): void
    {
        $otherCourse = Course::query()->create(['user_id' => $this->faculty->id, 'course_code' => 'CSE1101', 'course_name' => 'Intro', 'semester' => 'Fall', 'academic_year' => '2026', 'credits' => 3, 'status' => 'active']);
        $foreignCo = LearningOutcome::query()->create(['course_id' => $otherCourse->id, 'code' => 'CO9', 'description' => 'x', 'cognitive_level' => 'Apply']);
        Sanctum::actingAs($this->faculty);
        $url = "/api/assessments/{$this->assessment->id}/questions";

        $this->postJson($url, ['marks' => 5])->assertStatus(422)->assertJsonValidationErrors('question_text');
        $this->postJson($url, ['question_text' => 'Explain BFS.', 'marks' => -1])->assertStatus(422)->assertJsonValidationErrors('marks');
        $this->postJson($url, ['question_text' => 'Explain BFS.', 'marks' => 5, 'cognitive_level' => 'Memorise'])->assertStatus(422)->assertJsonValidationErrors('cognitive_level');
        $this->postJson($url, ['question_text' => 'Explain BFS.', 'marks' => 5, 'learning_outcome_id' => $foreignCo->id])->assertStatus(422)->assertJsonValidationErrors('learning_outcome_id');
    }

    public function test_other_faculty_cannot_change_questions(): void
    {
        $q = Question::query()->create(['assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Define a graph.', 'marks' => 5]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/assessments/{$this->assessment->id}/questions")->assertForbidden();
        $this->postJson("/api/assessments/{$this->assessment->id}/questions", ['question_text' => 'Explain BFS.', 'marks' => 5])->assertForbidden();
        $this->putJson("/api/questions/{$q->id}", ['marks' => 1])->assertForbidden();
        $this->deleteJson("/api/questions/{$q->id}")->assertForbidden();
        $this->assertSame('5.00', $q->fresh()->marks);
    }

    public function test_answered_questions_cannot_be_deleted_or_go_below_awarded_marks(): void
    {
        $q = Question::query()->create(['assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Explain BFS.', 'marks' => 10]);
        $student = Student::query()->create(['created_by' => $this->faculty->id, 'student_identifier' => 'S1', 'name' => 'Student One']);
        $sub = StudentSubmission::query()->create(['assessment_id' => $this->assessment->id, 'student_id' => $student->id, 'submission_identifier' => 'S-1', 'status' => 'SUBMITTED']);
        StudentAnswer::query()->create(['student_submission_id' => $sub->id, 'question_id' => $q->id, 'answer_type' => 'TEXT', 'answer_text' => 'BFS uses a queue.', 'awarded_marks' => 7]);
        Sanctum::actingAs($this->faculty);

        $this->deleteJson("/api/questions/{$q->id}")->assertStatus(409)->assertJsonPath('code', 'QUESTION_HAS_ANSWERS');
        $this->putJson("/api/questions/{$q->id}", ['marks' => 6])->assertStatus(422)->assertJsonValidationErrors('marks');
        $this->putJson("/api/questions/{$q->id}", ['marks' => 8])->assertOk();
    }

    public function test_questions_are_read_from_the_uploaded_paper_then_saved_after_review(): void
    {
        Question::query()->create(['assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Existing question.', 'marks' => 5]);
        Sanctum::actingAs($this->faculty);
        $paper = "CSE 3201 Midterm\nAnswer all questions.\n\n1. Define a graph. [5]\n2) Explain how BFS works.\n(a) visiting order\n(b) data structure used (10 marks)\nQ3. Insert 12, 22, 32 using linear probing. [10]\n";
        $this->post("/api/assessments/{$this->assessment->id}/question-paper", ['file' => UploadedFile::fake()->createWithContent('midterm.txt', $paper)], ['Accept' => 'application/json'])->assertCreated();

        $preview = $this->postJson("/api/assessments/{$this->assessment->id}/question-paper/extract")->assertOk()->json('data');
        $this->assertCount(3, $preview['questions']);
        $this->assertSame([5.0, 10.0, 10.0], array_map(fn ($q) => (float) $q['marks'], $preview['questions']));
        $this->assertStringContainsString('(b) data structure used', $preview['questions'][1]['text']);
        $this->assertSame(1, $preview['existing_questions']);
        $this->assertSame(1, Question::query()->where('assessment_id', $this->assessment->id)->count(), 'preview saves nothing');

        $rows = array_map(fn ($q) => ['question_text' => $q['text'], 'marks' => $q['marks']], $preview['questions']);
        $this->postJson("/api/assessments/{$this->assessment->id}/questions/bulk", ['questions' => $rows, 'source' => 'question_paper'])
            ->assertCreated()->assertJsonPath('meta.questions', 4);
        $this->assertSame([1, 2, 3, 4], Question::query()->where('assessment_id', $this->assessment->id)->orderBy('question_number')->pluck('question_number')->all());

        // One bad row rejects the whole batch with its number
        $this->postJson("/api/assessments/{$this->assessment->id}/questions/bulk", ['questions' => [['question_text' => 'Fine question', 'marks' => 2], ['question_text' => 'No marks']]])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Question 2:'));
        $this->assertSame(4, Question::query()->where('assessment_id', $this->assessment->id)->count());
    }

    public function test_extract_needs_a_paper_in_a_readable_format(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->postJson("/api/assessments/{$this->assessment->id}/question-paper/extract")->assertStatus(422)->assertJsonPath('message', 'Upload the question paper first.');
        $this->post("/api/assessments/{$this->assessment->id}/question-paper", ['file' => UploadedFile::fake()->create('slides.pptx', 10)], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson("/api/assessments/{$this->assessment->id}/question-paper/extract")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '.pptx'));
    }

    public function test_parser_handles_numbering_styles_sub_parts_and_marks(): void
    {
        $r = (new QuestionPaperParser())->parse("Title line\nFull marks: 30\n1. Define a graph. [5]\nQ2: Explain BFS.\n1) show the order\n(5 marks)\nQuestion 3 - Compare BFS and DFS. (10 marks)\nNote: 10 marks are for clarity.\n4) Define hashing.", 30);

        $this->assertSame([1, 2, 3, 4], array_column($r['questions'], 'number'));
        $this->assertSame([5.0, 5.0, 10.0, null], array_column($r['questions'], 'marks'));
        $this->assertSame("Explain BFS.\n1) show the order", $r['questions'][1]['text'], 'a "1)" inside question 2 is a sub-part');
        $this->assertStringContainsString('Note: 10 marks are for clarity.', $r['questions'][2]['text']);
        $this->assertStringContainsString('No marks found for question 4', $r['warnings'][0]);
        $this->assertNotEmpty((new QuestionPaperParser())->parse('Just a paragraph with no numbering.')['warnings']);
    }
}
