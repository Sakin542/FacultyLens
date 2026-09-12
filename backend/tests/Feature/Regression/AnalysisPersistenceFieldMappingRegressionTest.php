<?php

namespace Tests\Feature\Regression;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\PreviousQuestion;
use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUG-013 regression (found by the STEP 45 transparency checks): the unified analysis persisted every
 * question→LO alignment with similarity_score = 0.0 (it read `alignment_score`, which the AI service never
 * sends) and never persisted similarity matches (it read `matches`; the AI service returns `results`).
 * The stored score must equal the reported score so explanations never contradict the analysis.
 */
class AnalysisPersistenceFieldMappingRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_alignment_scores_and_similarity_matches_are_persisted_from_the_ai_payload(): void
    {
        $faculty = User::factory()->create();
        $course = Course::create(['user_id' => $faculty->id, 'course_code' => 'REG-113', 'course_name' => 'Regression', 'semester' => 'Fall', 'academic_year' => '2026']);
        $lo = LearningOutcome::create(['course_id' => $course->id, 'code' => 'LO1', 'description' => 'Understand graph traversal', 'sort_order' => 1]);
        $assessment = Assessment::create(['course_id' => $course->id, 'title' => 'Midterm', 'type' => 'midterm', 'total_marks' => 10, 'status' => 'draft']);
        $q = Question::create(['assessment_id' => $assessment->id, 'question_number' => 1, 'question_text' => 'Compare BFS and DFS.', 'marks' => 10, 'question_type' => 'descriptive']);
        $prev = PreviousQuestion::create(['user_id' => $faculty->id, 'course_id' => $course->id, 'question_text' => 'Compare BFS with DFS.', 'source' => 'upload']);

        Http::fake(['*/api/v1/analyze-assessment' => Http::response([
            'status' => 'success',
            'questions_analysis' => ['questions' => []],
            'alignment_analysis' => ['overall_alignment_score' => 60.0, 'question_alignment' => [[
                'question_id' => $q->id, 'question_number' => 1, 'similarity_score' => 0.6024, 'alignment_status' => 'WEAK', 'reasoning' => 'Moderate semantic alignment (60.2%).',
                'matched_learning_outcome' => ['id' => $lo->id, 'code' => 'LO1', 'similarity' => 0.6024, 'alignment_level' => 'WEAK'],
            ]]],
            'similarity_analysis' => ['potential_duplicates_count' => 1, 'average_similarity_score' => 0.9537, 'results' => [[
                'current_question_id' => $q->id, 'current_question_number' => 1, 'max_similarity_score' => 0.9537, 'max_similarity_status' => 'POTENTIAL_DUPLICATE',
                'reasoning' => 'Potential duplicate detected: 95.4% semantic match with historical item.',
                'matches' => [['previous_question_id' => $prev->id, 'similarity_score' => 0.9537, 'similarity_status' => 'POTENTIAL_DUPLICATE']],
            ]]],
            'quality_analysis' => ['overall_quality_score' => 70.0, 'rating' => 'FAIR', 'components' => []],
            'recommendations' => ['recommendations' => []],
            'summary' => ['overall_quality_score' => 70.0],
        ])]);

        Sanctum::actingAs($faculty);
        $this->postJson('/api/ai/analyze-assessment', ['assessment_id' => $assessment->id])->assertOk();

        $this->assertDatabaseHas('question_learning_outcome_alignments', ['question_id' => $q->id, 'learning_outcome_id' => $lo->id, 'alignment' => 'WEAK']);
        $this->assertEquals(0.6024, (float) \App\Models\QuestionLearningOutcomeAlignment::where('question_id', $q->id)->value('similarity_score'), 'stored alignment score must equal the reported score, not 0.0');
        $this->assertDatabaseHas('question_similarity_matches', ['current_question_id' => $q->id, 'previous_question_id' => $prev->id, 'similarity_status' => 'POTENTIAL_DUPLICATE']);
        $this->assertEquals(0.9537, (float) \App\Models\QuestionSimilarityMatch::where('current_question_id', $q->id)->value('similarity_score'));

        // The analysis payload must expose the same numbers the explanation will show.
        $payload = $this->getJson("/api/ai/assessments/{$assessment->id}/analysis")->assertOk()->json('data');
        $this->assertEquals(0.6024, $payload['learning_outcome_alignments'][0]['similarity_score']);
        $this->assertEquals(0.9537, $payload['similarity_matches'][0]['similarity_score']);
    }
}
