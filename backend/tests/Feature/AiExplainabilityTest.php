<?php

namespace Tests\Feature;

use App\Models\AcademicChatMessage;
use App\Models\AcademicChatSession;
use App\Models\AcademicChatSource;
use App\Models\AiEvaluationDataset;
use App\Models\AiEvaluationRun;
use App\Models\AiGradingCriterionResult;
use App\Models\AiGradingResult;
use App\Models\AnalysisReport;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\CourseCollaborator;
use App\Models\GeneratedQuestion;
use App\Models\LearningOutcome;
use App\Models\PreviousQuestion;
use App\Models\Question;
use App\Models\QuestionCoMapping;
use App\Models\QuestionGenerationRequest;
use App\Models\QuestionLearningOutcomeAlignment;
use App\Models\QuestionSimilarityMatch;
use App\Models\Recommendation;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\Student;
use App\Models\StudentAnswer;
use App\Models\StudentSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * STEP 45: AI explainability — authorization, result mapping, threshold/score explanations, model metadata,
 * missing confidence/evidence/evaluation, historical version isolation, student privacy, review/override + audit.
 */
class AiExplainabilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $faculty;
    protected User $outsider;
    protected User $reviewer;
    protected Course $course;
    protected Assessment $assessment;
    protected Question $question;
    protected LearningOutcome $lo1;
    protected LearningOutcome $lo2;
    protected AnalysisReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->faculty = User::factory()->create(['email' => 'faculty@university.edu']);
        $this->outsider = User::factory()->create(['email' => 'outsider@university.edu']);
        $this->reviewer = User::factory()->create(['email' => 'reviewer@university.edu']);

        $this->course = Course::create(['user_id' => $this->faculty->id, 'course_code' => 'CSE301', 'course_name' => 'Algorithms', 'semester' => 'Fall', 'academic_year' => '2026']);
        CourseCollaborator::create(['course_id' => $this->course->id, 'user_id' => $this->reviewer->id, 'invited_by' => $this->faculty->id, 'role' => 'REVIEWER', 'status' => 'ACTIVE', 'accepted_at' => now()]);

        $this->lo1 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'LO1', 'description' => 'Understand graph traversal algorithms', 'cognitive_level' => 'Understand', 'sort_order' => 1]);
        $this->lo2 = LearningOutcome::create(['course_id' => $this->course->id, 'code' => 'LO2', 'description' => 'Apply database normalization concepts', 'cognitive_level' => 'Apply', 'sort_order' => 2]);

        $this->assessment = Assessment::create(['course_id' => $this->course->id, 'title' => 'Midterm', 'type' => 'midterm', 'total_marks' => 10, 'status' => 'draft']);
        $this->question = Question::create([
            'assessment_id' => $this->assessment->id, 'question_number' => 1, 'question_text' => 'Compare BFS and DFS and analyze their memory trade-offs.',
            'question_type' => 'descriptive', 'marks' => 10, 'difficulty_level' => 'medium', 'cognitive_level' => 'Understand', 'learning_outcome_id' => $this->lo1->id,
            'ai_question_type' => 'ANALYTICAL', 'ai_difficulty_level' => 'HARD', 'ai_cognitive_level' => 'ANALYZE', 'ai_topics' => ['Graph Traversal'], 'ai_analysis_status' => 'completed', 'ai_analyzed_at' => now(),
        ]);

        $this->report = AnalysisReport::create([
            'assessment_id' => $this->assessment->id, 'analysis_version' => 2, 'is_current' => true, 'overall_score' => 72.0, 'analysis_status' => 'completed', 'analyzed_at' => now(),
            'findings' => [
                'quality' => [
                    'overall_quality_score' => 72.0, 'rating' => 'FAIR',
                    'weights_applied' => ['topic' => 0.0, 'learning_outcome' => 25.0, 'difficulty' => 18.75, 'cognitive' => 18.75, 'question_diversity' => 18.75, 'marks' => 18.75],
                    'excluded_components' => ['topic'],
                    'components' => ['topic_coverage' => null, 'learning_outcome_coverage' => 80.0, 'difficulty_balance' => 60.0, 'cognitive_diversity' => 70.0, 'question_diversity' => 75.0, 'marks_distribution' => 70.0],
                    'topic_analysis' => ['status' => 'UNAVAILABLE', 'methodology' => 'n/a'],
                    'difficulty_analysis' => ['status' => 'AVAILABLE', 'score' => 60.0, 'methodology' => 'deviation', 'distribution' => [
                        ['level' => 'Easy', 'question_percentage' => 20.0, 'target_percentage' => 30.0], ['level' => 'Medium', 'question_percentage' => 45.0, 'target_percentage' => 50.0], ['level' => 'Hard', 'question_percentage' => 35.0, 'target_percentage' => 20.0],
                    ]],
                ],
                'alignment' => ['thresholds' => ['strong' => 0.70, 'weak' => 0.50]],
                'similarity' => ['thresholds' => ['duplicate' => 0.85, 'high' => 0.70, 'moderate' => 0.50], 'model' => 'sentence-transformers/all-MiniLM-L6-v2'],
            ],
        ]);
    }

    protected function fakeExplainQuestion(array $overrides = []): void
    {
        Http::fake(['*/api/v1/explain-question' => Http::response(array_replace_recursive([
            'status' => 'success',
            'question_type' => ['label' => 'ANALYTICAL', 'detected_label' => 'ANALYTICAL', 'consistent' => true, 'method' => 'RULE_BASED', 'confidence' => ['available' => true, 'value' => 0.87],
                'evidence' => [['type' => 'question_text', 'text' => 'Compare', 'label' => 'analytical directive', 'position' => 0]], 'summary' => 'FacultyLens classified this question as ANALYTICAL because its wording contains analytical directive such as "Compare".', 'factors' => ['Directive verbs', 'Length']],
            'difficulty' => ['label' => 'HARD', 'detected_label' => 'HARD', 'consistent' => true, 'method' => 'RULE_BASED', 'confidence' => ['available' => false, 'value' => null],
                'evidence' => [['type' => 'question_text', 'text' => 'analyze', 'label' => 'medium cue', 'position' => 20]], 'summary' => 'FacultyLens estimated the difficulty as HARD because it asks for comparison.', 'factors' => ['word_count' => 9, 'has_subclauses' => false, 'hard_cue_count' => 0, 'medium_cue_count' => 2, 'easy_cue_count' => 0], 'factor_names' => ['Cue words', 'Question length', 'Sub-clause structure']],
            'cognitive_level' => ['label' => 'ANALYZE', 'detected_label' => 'ANALYZE', 'consistent' => true, 'method' => 'RULE_BASED', 'confidence' => ['available' => false, 'value' => null],
                'evidence' => [['type' => 'question_text', 'text' => 'Compare', 'label' => 'leading directive verb', 'position' => 0], ['type' => 'question_text', 'text' => 'analyze', 'label' => 'Analyze-level verb', 'position' => 20]], 'summary' => 'FacultyLens classified this question at the ANALYZE level because it uses Analyze-level directive wording ("Compare", "analyze").', 'leading_verb' => true, 'factors' => ['Leading directive verb']],
            'topics' => ['labels' => ['Graph Traversal'], 'method' => 'RULE_BASED', 'evidence' => [], 'context' => 'keywords extracted from the question text', 'summary' => 'FacultyLens detected the topic(s) Graph Traversal.'],
            'model' => ['rule_version' => 'step10-rules-1.0.0', 'embedding_model' => null],
            'limitations' => [], 'explanation_version' => '1.0.0', 'untrusted_content_detected' => false,
        ], $overrides))]);
    }

    // ------------------------------------------------------------------ question labels

    public function test_bloom_explanation_uses_question_evidence_and_reports_no_fabricated_confidence(): void
    {
        $this->fakeExplainQuestion();
        Sanctum::actingAs($this->faculty);

        $res = $this->getJson("/api/ai-results/bloom/{$this->question->id}/explanation");
        $res->assertOk()
            ->assertJsonPath('data.result.label', 'ANALYZE')
            ->assertJsonPath('data.method.type', 'RULE_BASED')
            ->assertJsonPath('data.confidence.available', false)
            ->assertJsonPath('data.confidence.value', null)
            ->assertJsonPath('data.evidence.0.text', '"Compare"')
            ->assertJsonPath('data.related.analysis_version', 2)
            ->assertJsonPath('data.review.overridable', true)
            ->assertJsonPath('data.evaluation.status', 'NOT_EVALUATED');

        $this->assertStringContainsString('FacultyLens classified', $res->json('data.explanation.summary'));
        $this->assertNotEmpty($res->json('data.limitations'));
        $this->assertStringNotContainsString('model thought', strtolower(json_encode($res->json('data'))));
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_EXPLANATION_VIEWED', 'entity_type' => 'Question', 'entity_id' => $this->question->id]);
    }

    public function test_question_type_confidence_is_only_shown_when_the_rule_provides_it(): void
    {
        $this->fakeExplainQuestion();
        Sanctum::actingAs($this->faculty);

        $this->getJson("/api/ai-results/question_type/{$this->question->id}/explanation")
            ->assertOk()->assertJsonPath('data.confidence.available', true)->assertJsonPath('data.confidence.value', 0.87)
            ->assertJsonPath('data.result.label', 'ANALYTICAL');
        $this->getJson("/api/ai-results/difficulty/{$this->question->id}/explanation")
            ->assertOk()->assertJsonPath('data.confidence.available', false)->assertJsonPath('data.result.label', 'HARD');
    }

    public function test_explanation_degrades_honestly_when_ai_service_is_unavailable(): void
    {
        Http::fake(['*/api/v1/explain-question' => Http::response(null, 503)]);
        Sanctum::actingAs($this->faculty);

        $res = $this->getJson("/api/ai-results/difficulty/{$this->question->id}/explanation");
        $res->assertOk()->assertJsonPath('data.result.label', 'HARD')->assertJsonPath('data.evidence_status', 'unavailable')->assertJsonPath('data.confidence.available', false);
        $this->assertSame([], $res->json('data.evidence'));
        $this->assertStringContainsString('HARD', $res->json('data.explanation.summary'));
    }

    public function test_stale_label_is_disclosed_when_question_text_no_longer_produces_it(): void
    {
        $this->fakeExplainQuestion(['cognitive_level' => ['consistent' => false, 'detected_label' => 'REMEMBER']]);
        Sanctum::actingAs($this->faculty);

        $this->getJson("/api/ai-results/bloom/{$this->question->id}/explanation")
            ->assertOk()->assertJsonPath('data.evidence_status', 'stale')->assertJsonPath('data.result.label', 'ANALYZE');
    }

    public function test_unanalyzed_question_reports_no_result_rather_than_inventing_one(): void
    {
        $q = Question::create(['assessment_id' => $this->assessment->id, 'question_number' => 2, 'question_text' => 'Define a heap.', 'question_type' => 'short_answer', 'marks' => 2, 'difficulty_level' => 'easy', 'cognitive_level' => 'Remember']);
        Sanctum::actingAs($this->faculty);

        $this->getJson("/api/ai-results/bloom/{$q->id}/explanation")->assertOk()->assertJsonPath('data.result.label', null)->assertJsonPath('data.evidence_status', 'none');
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ authorization

    public function test_outsider_cannot_view_explanations_and_unknown_types_or_ids_are_404(): void
    {
        Sanctum::actingAs($this->outsider);
        $this->getJson("/api/ai-results/bloom/{$this->question->id}/explanation")->assertStatus(403);
        $this->getJson("/api/ai-results/assessment_quality/{$this->report->id}/explanation")->assertStatus(403);

        Sanctum::actingAs($this->faculty);
        $this->getJson('/api/ai-results/bloom/999999/explanation')->assertStatus(404);
        $this->getJson("/api/ai-results/system_prompt/{$this->question->id}/explanation")->assertStatus(404);
    }

    public function test_reviewer_collaborator_can_view_analysis_explanations_but_cannot_override(): void
    {
        $this->fakeExplainQuestion();
        Sanctum::actingAs($this->reviewer);

        $this->getJson("/api/ai-results/bloom/{$this->question->id}/explanation")->assertOk()->assertJsonPath('data.review.can_review', false)->assertJsonPath('data.review.actions', []);
        $this->postJson("/api/ai-results/bloom/{$this->question->id}/override", ['value' => ['label' => 'EVALUATE'], 'reason' => 'ACADEMIC_JUDGMENT'])->assertStatus(403);
        $this->postJson("/api/ai-results/bloom/{$this->question->id}/review", ['action' => 'ACCEPTED'])->assertStatus(403);
    }

    // ------------------------------------------------------------------ LO alignment

    public function test_lo_alignment_explanation_shows_actual_score_threshold_band_and_drops_contradictory_reasoning(): void
    {
        $row = QuestionLearningOutcomeAlignment::create(['analysis_report_id' => $this->report->id, 'question_id' => $this->question->id, 'learning_outcome_id' => $this->lo1->id,
            'similarity_score' => 0.82, 'alignment' => 'STRONG_ALIGNMENT', 'reasoning' => 'The question is weak and matches LO9 with score 0.31.']);
        Sanctum::actingAs($this->faculty);

        $res = $this->getJson("/api/ai-results/lo_alignment/{$row->id}/explanation");
        $res->assertOk()->assertJsonPath('data.result.label', 'STRONG')->assertJsonPath('data.result.score', 0.82)->assertJsonPath('data.result.similarity_display', '0.82 / 1.00')
            ->assertJsonPath('data.method.type', 'EMBEDDING_BASED')->assertJsonPath('data.model.embedding_model', 'sentence-transformers/all-MiniLM-L6-v2')
            ->assertJsonPath('data.confidence.available', false)->assertJsonPath('data.related.analysis_report_id', $this->report->id);
        $summary = $res->json('data.explanation.summary');
        $this->assertStringContainsString('0.82', $summary);
        $this->assertStringContainsString('0.70', $summary);
        $this->assertStringContainsString('LO1', $summary);
        $texts = array_column($res->json('data.evidence'), 'text');
        $this->assertNotContains('The question is weak and matches LO9 with score 0.31.', $texts, 'contradictory reasoning must not be displayed');
        $labels = array_column($res->json('data.review.override_options'), 'label');
        $this->assertTrue(collect($labels)->contains(fn ($l) => str_starts_with($l, 'LO1')));
        $this->assertTrue(collect($labels)->contains(fn ($l) => str_starts_with($l, 'LO2')));
    }

    public function test_lo_alignment_override_sets_faculty_mapping_without_touching_ai_alignment(): void
    {
        $row = QuestionLearningOutcomeAlignment::create(['analysis_report_id' => $this->report->id, 'question_id' => $this->question->id, 'learning_outcome_id' => $this->lo1->id, 'similarity_score' => 0.82, 'alignment' => 'STRONG_ALIGNMENT']);
        Sanctum::actingAs($this->faculty);

        $this->postJson("/api/ai-results/lo_alignment/{$row->id}/override", ['value' => ['learning_outcome_id' => $this->lo2->id], 'reason' => 'COURSE_SPECIFIC_INTERPRETATION', 'comment' => 'This question targets normalization.'])
            ->assertOk()->assertJsonPath('data.applied.code', 'LO2');

        $this->assertSame($this->lo2->id, $this->question->fresh()->learning_outcome_id);
        $this->assertSame($this->lo1->id, $row->fresh()->learning_outcome_id);
        $this->assertDatabaseHas('ai_result_reviews', ['ai_result_type' => 'lo_alignment', 'ai_result_id' => $row->id, 'action' => 'OVERRIDDEN', 'override_reason' => 'COURSE_SPECIFIC_INTERPRETATION', 'analysis_report_id' => $this->report->id]);

        $otherCourse = Course::create(['user_id' => $this->outsider->id, 'course_code' => 'X1', 'course_name' => 'Other', 'semester' => 'Fall', 'academic_year' => '2026']);
        $foreignLo = LearningOutcome::create(['course_id' => $otherCourse->id, 'code' => 'LO1', 'description' => 'Foreign', 'sort_order' => 1]);
        $this->postJson("/api/ai-results/lo_alignment/{$row->id}/override", ['value' => ['learning_outcome_id' => $foreignLo->id], 'reason' => 'OTHER'])->assertStatus(422);
    }

    // ------------------------------------------------------------------ similarity

    public function test_similarity_explanation_never_claims_exact_duplicate_and_shows_score_out_of_one(): void
    {
        $prev = PreviousQuestion::create(['user_id' => $this->faculty->id, 'course_id' => $this->course->id, 'question_text' => 'Compare breadth-first and depth-first search.', 'source' => 'upload', 'source_year' => '2025']);
        $match = QuestionSimilarityMatch::create(['analysis_report_id' => $this->report->id, 'current_question_id' => $this->question->id, 'previous_question_id' => $prev->id, 'similarity_score' => 0.88, 'similarity_status' => 'POTENTIAL_DUPLICATE', 'reasoning' => 'This is an exact duplicate.']);
        Sanctum::actingAs($this->faculty);

        $res = $this->getJson("/api/ai-results/similarity/{$match->id}/explanation");
        $res->assertOk()->assertJsonPath('data.result.label', 'POTENTIAL_DUPLICATE')->assertJsonPath('data.result.display', 'Potential Duplicate')->assertJsonPath('data.result.similarity_display', '0.88 / 1.00')
            ->assertJsonPath('data.method.type', 'EMBEDDING_BASED')->assertJsonPath('data.confidence.available', false);
        $blob = strtolower(json_encode($res->json('data')));
        $this->assertStringNotContainsString('exact duplicate', $blob);
        $this->assertStringContainsString('0.88', $res->json('data.explanation.summary'));
        $this->assertStringContainsString('0.85', $res->json('data.explanation.summary'));
        $this->assertStringContainsString('breadth-first', strtolower(json_encode($res->json('data.evidence'))));
    }

    // ------------------------------------------------------------------ quality & recommendations

    public function test_quality_explanation_states_the_real_score_weights_and_normalization(): void
    {
        Sanctum::actingAs($this->faculty);
        $res = $this->getJson("/api/ai-results/assessment_quality/{$this->report->id}/explanation");
        $res->assertOk()->assertJsonPath('data.result.score', 72)->assertJsonPath('data.method.type', 'RULE_BASED')->assertJsonPath('data.related.is_current', true);
        $summary = $res->json('data.explanation.summary');
        $this->assertStringContainsString('72', $summary);
        $this->assertStringNotContainsString('91', $summary);
        $this->assertStringContainsString('redistributed', $summary);
        $dims = collect($res->json('data.evidence'))->keyBy('label');
        $this->assertSame('EXCLUDED', $dims['Topic Coverage']['meta']['status']);
        $this->assertEquals(25.0, $dims['LO Coverage']['meta']['applied_weight']);
        $this->assertEquals(20.0, $dims['LO Coverage']['meta']['configured_weight']);
        $this->assertStringContainsString('Higher proportion of hard questions than target (+15.0 percentage points)', $dims['Difficulty Balance']['text']);
    }

    public function test_recommendation_explanation_traces_source_and_review_reuses_feedback_workflow(): void
    {
        $rec = Recommendation::create(['analysis_report_id' => $this->report->id, 'category' => 'difficulty', 'problem' => 'Difficulty distribution deviates from target', 'title' => 'Difficulty distribution deviates from target',
            'description' => 'You must reduce hard questions.', 'recommendation' => 'You must reduce hard questions.', 'explanation' => 'The current distribution differs from the configured blueprint target.',
            'evidence' => ['35% of questions are HARD', 'Target: 20% HARD'], 'source_metric' => 'difficulty_distribution', 'priority' => 'high', 'status' => 'pending']);
        Sanctum::actingAs($this->faculty);

        $res = $this->getJson("/api/ai-results/recommendation/{$rec->id}/explanation");
        $res->assertOk()->assertJsonPath('data.result.priority', 'HIGH')->assertJsonPath('data.method.type', 'RULE_BASED')->assertJsonPath('data.evidence.0.text', '35% of questions are HARD');
        $details = collect($res->json('data.explanation.details'))->keyBy('label');
        $this->assertSame('Difficulty Analysis', $details['Source']['value']);
        $this->assertStringNotContainsString('You must', $details['Suggested consideration']['value']);

        $this->postJson("/api/ai-results/recommendation/{$rec->id}/review", ['action' => 'ACCEPTED', 'comment' => 'Agreed.'])->assertOk()->assertJsonPath('data.review.action', 'ACCEPTED');
        $this->assertSame('accepted', $rec->fresh()->status);
        $this->assertDatabaseHas('recommendation_feedback', ['recommendation_id' => $rec->id, 'decision' => 'ACCEPTED']);
        $this->assertDatabaseHas('ai_improvement_signals', ['recommendation_id' => $rec->id, 'signal_type' => 'AI_RESULT_ACCEPTED', 'source' => 'explainability']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_RESULT_ACCEPTED', 'entity_type' => 'Recommendation', 'entity_id' => $rec->id]);
    }

    // ------------------------------------------------------------------ review / override / audit

    public function test_override_changes_only_the_faculty_field_and_records_review_signal_and_audit(): void
    {
        $this->fakeExplainQuestion();
        Sanctum::actingAs($this->faculty);

        $this->postJson("/api/ai-results/bloom/{$this->question->id}/override", ['value' => ['label' => 'evaluate'], 'reason' => 'AI_CLASSIFICATION_INCORRECT', 'comment' => 'It asks for a judgement.'])
            ->assertOk()->assertJsonPath('data.applied.label', 'EVALUATE')->assertJsonPath('data.review.override_reason_label', 'AI classification incorrect');

        $q = $this->question->fresh();
        $this->assertSame('Evaluate', $q->cognitive_level);
        $this->assertSame('ANALYZE', $q->ai_cognitive_level, 'AI column must never be modified by an override');
        $this->assertDatabaseHas('ai_result_reviews', ['ai_result_type' => 'bloom', 'ai_result_id' => $q->id, 'action' => 'OVERRIDDEN', 'user_id' => $this->faculty->id, 'analysis_report_id' => $this->report->id]);
        $this->assertDatabaseHas('ai_improvement_signals', ['signal_type' => 'AI_RESULT_OVERRIDDEN', 'signal_value' => 'negative', 'source' => 'explainability', 'assessment_id' => $this->assessment->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_RESULT_OVERRIDDEN', 'entity_type' => 'Question', 'entity_id' => $q->id, 'user_id' => $this->faculty->id]);

        $explanation = $this->getJson("/api/ai-results/bloom/{$q->id}/explanation")->assertOk();
        $explanation->assertJsonPath('data.review.latest.action', 'OVERRIDDEN')->assertJsonPath('data.review.history_count', 1)->assertJsonPath('data.review.faculty_value', 'Evaluate')->assertJsonPath('data.result.label', 'ANALYZE');
        $this->getJson("/api/ai-results/bloom/{$q->id}/reviews")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_override_validation_rejects_bad_values_reasons_and_non_overridable_types(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->postJson("/api/ai-results/bloom/{$this->question->id}/override", ['value' => ['label' => 'GENIUS'], 'reason' => 'OTHER'])->assertStatus(422);
        $this->postJson("/api/ai-results/bloom/{$this->question->id}/override", ['value' => ['label' => 'APPLY'], 'reason' => 'BECAUSE'])->assertStatus(422);
        $this->postJson("/api/ai-results/topic/{$this->question->id}/override", ['value' => ['label' => 'Graphs'], 'reason' => 'OTHER'])->assertStatus(422);
        $this->postJson("/api/ai-results/assessment_quality/{$this->report->id}/override", ['value' => ['label' => '90'], 'reason' => 'OTHER'])->assertStatus(422);
        $this->assertSame('Understand', $this->question->fresh()->cognitive_level);
    }

    public function test_view_events_are_audited_only_for_whitelisted_actions(): void
    {
        Sanctum::actingAs($this->faculty);
        $this->postJson("/api/ai-results/assessment_quality/{$this->report->id}/events", ['action' => 'AI_EVIDENCE_VIEWED', 'meta' => ['level' => 3, 'password' => 'x']])->assertStatus(202);
        $this->assertDatabaseHas('audit_logs', ['action' => 'AI_EVIDENCE_VIEWED', 'entity_type' => 'AnalysisReport', 'entity_id' => $this->report->id]);
        $log = \App\Models\AuditLog::where('action', 'AI_EVIDENCE_VIEWED')->first();
        $this->assertArrayNotHasKey('password', $log->metadata);
        $this->postJson("/api/ai-results/assessment_quality/{$this->report->id}/events", ['action' => 'DROP_TABLE'])->assertStatus(422);
        Sanctum::actingAs($this->outsider);
        $this->postJson("/api/ai-results/assessment_quality/{$this->report->id}/events", ['action' => 'AI_RESULT_VIEWED'])->assertStatus(403);
    }

    // ------------------------------------------------------------------ historical versions

    public function test_historical_alignment_keeps_its_own_version_context(): void
    {
        $old = AnalysisReport::create(['assessment_id' => $this->assessment->id, 'analysis_version' => 1, 'is_current' => false, 'overall_score' => 55.0, 'analysis_status' => 'completed', 'analyzed_at' => now()->subDay(),
            'findings' => ['alignment' => ['thresholds' => ['strong' => 0.75, 'weak' => 0.55]]]]);
        $row = QuestionLearningOutcomeAlignment::create(['analysis_report_id' => $old->id, 'question_id' => $this->question->id, 'learning_outcome_id' => $this->lo1->id, 'similarity_score' => 0.72, 'alignment' => 'WEAK_ALIGNMENT']);
        Sanctum::actingAs($this->faculty);

        $res = $this->getJson("/api/ai-results/lo_alignment/{$row->id}/explanation");
        $res->assertOk()->assertJsonPath('data.related.analysis_report_id', $old->id)->assertJsonPath('data.related.analysis_version', 1)->assertJsonPath('data.related.is_current', false)->assertJsonPath('data.result.label', 'WEAK');
        $this->assertStringContainsString('0.75', $res->json('data.explanation.summary'), 'thresholds must come from the historical report, not the current config');
        $this->getJson("/api/ai-results/assessment_quality/{$old->id}/explanation")->assertOk()->assertJsonPath('data.result.score', 55)->assertJsonPath('data.related.is_current', false);
    }

    // ------------------------------------------------------------------ student privacy (AI grading)

    public function test_ai_grading_explanation_requires_view_student_data_and_shows_faculty_comparison(): void
    {
        $rubric = Rubric::create(['question_id' => $this->question->id, 'assessment_id' => $this->assessment->id, 'created_by' => $this->faculty->id, 'title' => 'Rubric', 'total_marks' => 10, 'status' => 'APPROVED', 'version' => 1, 'generation_method' => 'template_based']);
        $c1 = RubricCriterion::create(['rubric_id' => $rubric->id, 'criterion' => 'Concept A', 'description' => 'Explains BFS', 'max_marks' => 5, 'sort_order' => 1]);
        $c2 = RubricCriterion::create(['rubric_id' => $rubric->id, 'criterion' => 'Concept B', 'description' => 'Explains DFS', 'max_marks' => 5, 'sort_order' => 2]);
        $student = Student::create(['created_by' => $this->faculty->id, 'student_identifier' => 'S1', 'name' => 'Student']);
        $submission = StudentSubmission::create(['assessment_id' => $this->assessment->id, 'student_id' => $student->id, 'status' => 'UNDER_REVIEW', 'grading_status' => 'AI_ASSISTED', 'submitted_at' => now(), 'total_marks' => 10]);
        $answer = StudentAnswer::create(['student_submission_id' => $submission->id, 'question_id' => $this->question->id, 'answer_type' => 'TEXT', 'answer_text' => 'BFS uses a queue. DFS uses a stack.', 'awarded_marks' => 8, 'answer_status' => 'REVIEWED']);
        $result = AiGradingResult::create(['student_answer_id' => $answer->id, 'student_submission_id' => $submission->id, 'question_id' => $this->question->id, 'rubric_id' => $rubric->id, 'rubric_version' => 1,
            'answer_fingerprint' => $answer->contentFingerprint(), 'suggested_marks' => 7, 'maximum_marks' => 10, 'grading_status' => 'COMPLETED', 'is_current' => true, 'model_name' => 'facultylens-grading-engine', 'model_version' => '1.0.0', 'requested_by' => $this->faculty->id, 'generated_at' => now()]);
        AiGradingCriterionResult::create(['ai_grading_result_id' => $result->id, 'rubric_criterion_id' => $c1->id, 'criterion' => 'Concept A', 'suggested_marks' => 5, 'maximum_marks' => 5, 'evaluation' => 'Demonstrated', 'evidence' => ['BFS uses a queue.'], 'coverage_level' => 'STRONG', 'sort_order' => 1]);
        AiGradingCriterionResult::create(['ai_grading_result_id' => $result->id, 'rubric_criterion_id' => $c2->id, 'criterion' => 'Concept B', 'suggested_marks' => 2, 'maximum_marks' => 5, 'evaluation' => 'Partially demonstrated', 'evidence' => ['DFS uses a stack.'], 'missing_elements' => ['recursion'], 'coverage_level' => 'PARTIAL', 'sort_order' => 2]);

        Sanctum::actingAs($this->reviewer);
        $this->getJson("/api/ai-results/ai_grading/{$result->id}/explanation")->assertStatus(403);
        Sanctum::actingAs($this->outsider);
        $this->getJson("/api/ai-results/ai_grading/{$result->id}/explanation")->assertStatus(403);

        Sanctum::actingAs($this->faculty);
        $res = $this->getJson("/api/ai-results/ai_grading/{$result->id}/explanation");
        $res->assertOk()->assertJsonPath('data.result.display', '7 / 10')->assertJsonPath('data.method.type', 'HYBRID')->assertJsonPath('data.model.name', 'facultylens-grading-engine')->assertJsonPath('data.confidence.available', false)->assertJsonPath('data.review.overridable', false);
        $details = collect($res->json('data.explanation.details'))->keyBy('label');
        $this->assertSame('8 / 10', $details['Faculty final']['value']);
        $this->assertSame('+1 mark(s)', $details['Difference (faculty − AI)']['value']);
        $this->assertSame('BFS uses a queue.', $res->json('data.evidence.0.meta.answer_evidence.0'));
    }

    // ------------------------------------------------------------------ RAG

    public function test_rag_explanation_is_private_to_the_session_owner_and_reports_grounding_state(): void
    {
        $session = AcademicChatSession::create(['user_id' => $this->faculty->id, 'scope_type' => 'COURSE', 'course_id' => $this->course->id, 'title' => 'Chat', 'status' => 'ACTIVE', 'message_count' => 2]);
        $msg = AcademicChatMessage::create(['academic_chat_session_id' => $session->id, 'role' => 'ASSISTANT', 'content' => 'BFS explores level by level [S1].', 'grounded' => true, 'generation_method' => 'extractive',
            'generation_model' => 'facultylens-extractive', 'embedding_model' => 'sentence-transformers/all-MiniLM-L6-v2', 'prompt_version' => '1.0.0', 'retrieval_metadata' => ['top_k' => 5, 'retrieved_chunk_count' => 3, 'min_relevance_score' => 0.35], 'status' => 'COMPLETED']);
        AcademicChatSource::create(['academic_chat_message_id' => $msg->id, 'document_processing_id' => null, 'document_chunk_id' => null, 'document_name' => 'Lecture Notes', 'document_type' => 'material', 'similarity_score' => 0.61, 'page_number' => 12, 'section_title' => 'Graph search', 'excerpt' => 'BFS explores level by level.', 'source_order' => 1]);
        $insufficient = AcademicChatMessage::create(['academic_chat_session_id' => $session->id, 'role' => 'ASSISTANT', 'content' => "I couldn't find enough information.", 'grounded' => false, 'generation_method' => 'insufficient_evidence', 'status' => 'COMPLETED']);

        Sanctum::actingAs($this->reviewer);
        $this->getJson("/api/ai-results/rag_answer/{$msg->id}/explanation")->assertStatus(403);

        Sanctum::actingAs($this->faculty);
        $res = $this->getJson("/api/ai-results/rag_answer/{$msg->id}/explanation");
        $res->assertOk()->assertJsonPath('data.result.label', 'PARTIALLY_SUPPORTED')->assertJsonPath('data.evidence.0.document_page', 12)->assertJsonPath('data.evidence.0.text', 'Page 12 · Section: Graph search')
            ->assertJsonPath('data.model.embedding_model', 'sentence-transformers/all-MiniLM-L6-v2')->assertJsonPath('data.model.prompt_version', '1.0.0')->assertJsonPath('data.method.type', 'EMBEDDING_BASED');
        $this->assertStringNotContainsString('system prompt', strtolower(json_encode($res->json('data'))));

        $this->getJson("/api/ai-results/rag_answer/{$insufficient->id}/explanation")->assertOk()->assertJsonPath('data.result.label', 'INSUFFICIENT_EVIDENCE')->assertJsonPath('data.evidence_status', 'none');
    }

    // ------------------------------------------------------------------ rubric / generated question / CO-PO / inter-grader / evaluation

    public function test_rubric_explanation_reports_deterministic_marks_constraint(): void
    {
        $rubric = Rubric::create(['question_id' => $this->question->id, 'assessment_id' => $this->assessment->id, 'created_by' => $this->faculty->id, 'title' => 'Rubric', 'total_marks' => 10, 'status' => 'DRAFT', 'version' => 1, 'generation_method' => 'template_based', 'ai_model' => 'facultylens-rubric-template-engine', 'ai_model_version' => '1.0.0']);
        foreach ([4, 3, 3] as $i => $m) {
            RubricCriterion::create(['rubric_id' => $rubric->id, 'criterion' => "Criterion {$i}", 'description' => 'd', 'max_marks' => $m, 'sort_order' => $i + 1, 'expected_indicators' => ['x']]);
        }
        Sanctum::actingAs($this->faculty);
        $res = $this->getJson("/api/ai-results/rubric/{$rubric->id}/explanation");
        $res->assertOk()->assertJsonPath('data.result.status', 'DRAFT')->assertJsonPath('data.method.type', 'RULE_BASED')->assertJsonPath('data.review.overridable', false);
        $details = collect($res->json('data.explanation.details'))->keyBy('label');
        $this->assertSame('PASS', $details['Constraint']['value']);
        $this->assertEquals(10, $details['Sum of criteria']['value']);
        $this->assertCount(4, $res->json('data.evidence')); // 3 criteria + question wording
    }

    public function test_generated_question_explanation_lists_constraints_and_flags_unverified_sources(): void
    {
        $req = QuestionGenerationRequest::create(['user_id' => $this->faculty->id, 'course_id' => $this->course->id, 'topic' => 'Database Indexing', 'question_type' => 'DESCRIPTIVE', 'difficulty_level' => 'MEDIUM', 'cognitive_level' => 'APPLY', 'marks' => 5, 'number_of_questions' => 1, 'language' => 'en',
            'generation_status' => 'COMPLETED', 'generation_method' => 'template', 'generation_model' => 'facultylens-constrained-question-template-engine', 'generation_model_version' => '1.0.0', 'embedding_model' => 'sentence-transformers/all-MiniLM-L6-v2', 'prompt_version' => '1.0.0']);
        $g = GeneratedQuestion::create(['generation_request_id' => $req->id, 'sequence' => 1, 'question_text' => 'Apply B-tree indexing to speed up range queries.', 'original_question_text' => 'Apply B-tree indexing to speed up range queries.', 'question_type' => 'DESCRIPTIVE', 'marks' => 5, 'difficulty_level' => 'MEDIUM', 'cognitive_level' => 'APPLY', 'topic' => 'Database Indexing',
            'source_chunk_ids' => [999999], 'validation_status' => 'PASSED', 'review_status' => 'DRAFT', 'version' => 1,
            'validation' => ['constraints' => ['topic' => true, 'question_type' => true, 'difficulty' => true, 'cognitive_level' => true, 'co_alignment' => null, 'similarity' => true, 'marks' => true], 'detected_difficulty' => 'MEDIUM', 'detected_cognitive_level' => 'APPLY', 'detected_question_type' => 'DESCRIPTIVE', 'warnings' => [], 'similar_questions' => []]]);
        Sanctum::actingAs($this->faculty);
        $res = $this->getJson("/api/ai-results/generated_question/{$g->id}/explanation");
        $res->assertOk()->assertJsonPath('data.method.type', 'RULE_BASED')->assertJsonPath('data.model.prompt_version', '1.0.0');
        $validation = collect($res->json('data.explanation.details'))->firstWhere('label', 'Validation')['value'];
        $this->assertSame('PASS', collect($validation)->firstWhere('check', 'Difficulty constraint')['status']);
        $this->assertSame('NOT_CHECKED', collect($validation)->firstWhere('check', 'CO alignment')['status']);
        $this->assertStringContainsString('Source support not verified', json_encode($res->json('data.evidence')));
        $this->assertStringContainsString('does not make the question academically correct', $res->json('data.explanation.summary'));
        Sanctum::actingAs($this->reviewer);
        $this->getJson("/api/ai-results/generated_question/{$g->id}/explanation")->assertStatus(403);
    }

    public function test_co_po_mapping_explanation_distinguishes_ai_suggested_from_faculty_confirmed(): void
    {
        $m = QuestionCoMapping::create(['question_id' => $this->question->id, 'learning_outcome_id' => $this->lo1->id, 'mapping_source' => 'AI_SUGGESTED', 'mapping_level' => 3, 'status' => 'PENDING', 'similarity_score' => 0.81, 'created_by' => $this->faculty->id]);
        Sanctum::actingAs($this->faculty);
        $res = $this->getJson("/api/ai-results/co_po_mapping/{$m->id}/explanation");
        $res->assertOk()->assertJsonPath('data.method.type', 'EMBEDDING_BASED')->assertJsonPath('data.result.status', 'PENDING')->assertJsonPath('data.review.actions', ['ACCEPTED', 'REJECTED']);
        $this->assertStringContainsString('accreditation', strtolower(json_encode($res->json('data.limitations'))));

        $this->postJson("/api/ai-results/co_po_mapping/{$m->id}/review", ['action' => 'ACCEPTED'])->assertOk();
        $this->assertSame('CONFIRMED', $m->fresh()->status);
        $this->getJson("/api/ai-results/co_po_mapping/{$m->id}/explanation")->assertOk()->assertJsonPath('data.method.type', 'HUMAN_CONFIRMED');
    }

    public function test_inter_grader_explanation_is_honest_about_unavailable_data(): void
    {
        Sanctum::actingAs($this->faculty);
        $res = $this->getJson("/api/ai-results/inter_grader/{$this->assessment->id}/explanation");
        $res->assertOk()->assertJsonPath('data.result.label', 'NOT_AVAILABLE')->assertJsonPath('data.evidence_status', 'none')->assertJsonPath('data.review.actions', []);
        $this->assertStringContainsString('not an official reliability statistic', json_encode($res->json('data.limitations')));
        Sanctum::actingAs($this->reviewer);
        $this->getJson("/api/ai-results/inter_grader/{$this->assessment->id}/explanation")->assertStatus(403);
    }

    public function test_evaluation_status_reflects_latest_completed_run(): void
    {
        $dataset = AiEvaluationDataset::create(['name' => 'Bloom v1', 'task' => 'BLOOM_CLASSIFICATION', 'version' => '1.0', 'status' => 'READY', 'created_by' => $this->faculty->id]);
        AiEvaluationRun::create(['dataset_id' => $dataset->id, 'task' => 'BLOOM_CLASSIFICATION', 'status' => 'COMPLETED', 'gate_status' => 'PASSED', 'example_count' => 60, 'processed_count' => 60, 'completed_at' => now(), 'created_by' => $this->faculty->id,
            'summary' => ['headline_metric' => 'macro_f1', 'headline_value' => 0.83]]);
        $this->fakeExplainQuestion();
        Sanctum::actingAs($this->faculty);
        $this->getJson("/api/ai-results/bloom/{$this->question->id}/explanation")->assertOk()
            ->assertJsonPath('data.evaluation.status', 'EVALUATED')->assertJsonPath('data.evaluation.label', 'Evaluated')->assertJsonPath('data.evaluation.headline_metric', 'macro_f1')->assertJsonPath('data.evaluation.headline_value', 0.83);
        $this->getJson("/api/ai-results/difficulty/{$this->question->id}/explanation")->assertOk()->assertJsonPath('data.evaluation.status', 'NOT_EVALUATED');
    }
}
