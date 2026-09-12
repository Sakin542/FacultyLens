<?php

namespace App\Services\Explainability\Explainers;

use App\Models\Course;
use App\Models\QuestionSimilarityMatch;
use App\Models\User;
use App\Services\Explainability\AbstractExplainer;
use App\Services\Explainability\ExplanationBuilder;
use App\Services\Explainability\ExplanationValidator;
use Illuminate\Database\Eloquent\Model;

/** Semantic similarity between a current question and a previous question (STEP 45 §12–13). */
class SimilarityExplainer extends AbstractExplainer
{
    protected const HUMAN = ['POTENTIAL_DUPLICATE' => 'Potential Duplicate', 'HIGHLY_SIMILAR' => 'Highly Similar', 'SOMEWHAT_SIMILAR' => 'Somewhat Similar', 'NOT_SIMILAR' => 'Not Similar'];

    public function __construct(protected ExplanationValidator $validator)
    {
    }

    public function type(): string
    {
        return 'similarity';
    }

    public function find(int $id): ?Model
    {
        return QuestionSimilarityMatch::with(['currentQuestion.assessment.course', 'previousQuestion', 'analysisReport'])->find($id);
    }

    public function course(Model $target): ?Course
    {
        return $target->currentQuestion?->assessment?->course;
    }

    public function analysisReportId(Model $target): ?int
    {
        return $target->analysis_report_id;
    }

    public function aiValue(Model $target): array
    {
        return ['similarity_score' => (float) $target->similarity_score, 'similarity_status' => $target->similarity_status, 'previous_question_id' => $target->previous_question_id];
    }

    public function explain(User $user, Model $target, ExplanationBuilder $b): ExplanationBuilder
    {
        /** @var QuestionSimilarityMatch $m */
        $m = $target;
        $score = round((float) $m->similarity_score, 4);
        $status = strtoupper((string) $m->similarity_status);
        $human = self::HUMAN[$status] ?? $this->humanize($status);
        $t = $m->analysisReport?->findings['similarity']['thresholds'] ?? [];
        $dup = (float) ($t['duplicate'] ?? $t['potential_duplicate'] ?? config('ai_explainability.similarity_thresholds.duplicate'));
        $high = (float) ($t['high'] ?? $t['high_similarity'] ?? config('ai_explainability.similarity_thresholds.high'));
        $mod = (float) ($t['moderate'] ?? $t['moderate_similarity'] ?? config('ai_explainability.similarity_thresholds.moderate'));
        $prev = $m->previousQuestion;
        $cur = $m->currentQuestion;
        $course = $this->course($m);
        // Previous questions are shown only when they belong to the same course the viewer is authorized for.
        $prevVisible = $prev && $course && (int) $prev->course_id === (int) $course->id;

        $why = match ($status) {
            'POTENTIAL_DUPLICATE' => "Both questions appear to address the same underlying concept: the similarity score {$this->fmt($score)} is at or above the potential-duplicate threshold ({$this->fmt($dup)}).",
            'HIGHLY_SIMILAR' => "The questions share most of their meaning: the similarity score {$this->fmt($score)} is between the highly-similar ({$this->fmt($high)}) and potential-duplicate ({$this->fmt($dup)}) thresholds.",
            'SOMEWHAT_SIMILAR' => "The questions overlap partially: the similarity score {$this->fmt($score)} is between the somewhat-similar ({$this->fmt($mod)}) and highly-similar ({$this->fmt($high)}) thresholds.",
            default => "The questions are not considered similar: the similarity score {$this->fmt($score)} is below the somewhat-similar threshold ({$this->fmt($mod)}).",
        };
        $facts = ['scores' => ['similarity' => $score], 'labels' => ['similarity_status' => $status], 'allowed_numbers' => [$dup, $high, $mod]];
        $reasoning = $this->validator->validatedOrFallback($m->reasoning, $facts, $why);

        $b->result($status, $score, $human, ['similarity_display' => $this->fmtScore($score)])
            ->summary($why)
            ->detail('Similarity score', $this->fmtScore($score))
            ->detail('Thresholds', "≥ {$this->fmt($dup)} Potential Duplicate · ≥ {$this->fmt($high)} Highly Similar · ≥ {$this->fmt($mod)} Somewhat Similar")
            ->detail('Score meaning', 'Cosine similarity of sentence embeddings. It is not a probability that the questions are duplicates.')
            ->evidence(['type' => 'score', 'label' => 'Semantic similarity', 'score' => $score, 'text' => $this->fmtScore($score)])
            ->evidence(['type' => 'question_text', 'label' => 'Question A (current)', 'text' => $this->excerpt($cur?->question_text), 'source_type' => 'question', 'source_id' => $cur?->id]);
        if ($prevVisible) {
            $b->evidence(['type' => 'question_text', 'label' => 'Question B (previous' . ($prev->source_year ? ", {$prev->source_year}" : '') . ($prev->source_assessment ? ", {$prev->source_assessment}" : '') . ')',
                'text' => $this->excerpt($prev->question_text), 'source_type' => 'previous_question', 'source_id' => $prev->id]);
        } else {
            $b->evidence(['type' => 'question_text', 'label' => 'Question B (previous)', 'text' => $prev ? 'Not available in your authorization scope.' : 'Previous question no longer exists.']);
        }
        if ($reasoning['validated'] && $m->reasoning) {
            $b->evidence(['type' => 'analysis_note', 'label' => 'Analysis note', 'text' => $m->reasoning]);
        }

        return $b->method('EMBEDDING_BASED', 'Sentence embeddings of both questions compared with cosine similarity, then banded with the configured thresholds on the reported (rounded) score.',
                ['Configured embedding model', 'Cosine similarity', 'FacultyLens threshold bands'])
            ->model(['embedding_model' => $m->analysisReport?->findings['similarity']['model'] ?? config('ai_explainability.embedding_model'), 'name' => 'facultylens-semantic-similarity', 'version' => '1.0.0'])
            ->confidence(null, 'Similarity is a distance measure, not a confidence or probability.')
            ->analysisContext($m->analysis_report_id, $m->analysisReport?->analysis_version, $m->analysisReport?->is_current)
            ->limitations($this->limitations('similarity'))
            ->link('View question', 'question', $cur?->id, ['assessment_id' => $cur?->assessment_id])
            ->link('View question bank', 'question_bank', $course?->id)
            ->review(['overridable' => false, 'actions' => ['ACCEPTED', 'REJECTED', 'REVIEWED'], 'reject_label' => 'Not a duplicate']);
    }

    protected function fmt(float $v): string
    {
        return number_format($v, 2);
    }
}
