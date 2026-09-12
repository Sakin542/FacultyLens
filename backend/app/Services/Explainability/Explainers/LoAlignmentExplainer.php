<?php

namespace App\Services\Explainability\Explainers;

use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\QuestionLearningOutcomeAlignment;
use App\Models\User;
use App\Services\Explainability\AbstractExplainer;
use App\Services\Explainability\ExplainabilityException;
use App\Services\Explainability\ExplanationBuilder;
use App\Services\Explainability\ExplanationValidator;
use Illuminate\Database\Eloquent\Model;

/** LO alignment: question ↔ selected LO, cosine similarity, threshold band, evidence (STEP 45 §10). */
class LoAlignmentExplainer extends AbstractExplainer
{
    public function __construct(protected ExplanationValidator $validator)
    {
    }

    public function type(): string
    {
        return 'lo_alignment';
    }

    public function find(int $id): ?Model
    {
        return QuestionLearningOutcomeAlignment::with(['question.assessment.course', 'learningOutcome', 'analysisReport'])->find($id);
    }

    public function course(Model $target): ?Course
    {
        return $target->question?->assessment?->course;
    }

    public function reviewAbility(): ?string
    {
        return 'edit_question';
    }

    public function analysisReportId(Model $target): ?int
    {
        return $target->analysis_report_id;
    }

    public function aiValue(Model $target): array
    {
        return ['learning_outcome_id' => $target->learning_outcome_id, 'similarity_score' => (float) $target->similarity_score, 'alignment' => $target->alignment];
    }

    public function overrideOptions(Model $target): array
    {
        $course = $this->course($target);
        return $course ? $course->learningOutcomes()->orderBy('sort_order')->get(['id', 'code', 'description'])
            ->map(fn ($lo) => ['value' => $lo->id, 'label' => trim(($lo->code ?? 'LO') . ' — ' . $this->excerpt($lo->description, 90))])->all() : [];
    }

    public function applyOverride(User $user, Model $target, array $value): array
    {
        $loId = (int) ($value['learning_outcome_id'] ?? 0);
        $course = $this->course($target);
        $lo = $loId ? LearningOutcome::find($loId) : null;
        if (!$course || !$lo || (int) $lo->course_id !== (int) $course->id) {
            throw new ExplainabilityException('The selected learning outcome does not belong to this course.', 422);
        }
        $question = $target->question;
        $question->learning_outcome_id = $lo->id;   // faculty mapping; the AI alignment row is untouched
        $question->save();

        return ['learning_outcome_id' => $lo->id, 'code' => $lo->code];
    }

    public function explain(User $user, Model $target, ExplanationBuilder $b): ExplanationBuilder
    {
        /** @var QuestionLearningOutcomeAlignment $a */
        $a = $target;
        $score = round((float) $a->similarity_score, 4);
        $status = strtoupper((string) $a->alignment);
        $band = str_replace('_ALIGNMENT', '', $status);
        $thresholds = $a->analysisReport?->findings['alignment']['thresholds'] ?? config('ai_explainability.alignment_thresholds');
        $strong = (float) ($thresholds['strong'] ?? 0.70);
        $weak = (float) ($thresholds['weak'] ?? 0.50);
        $lo = $a->learningOutcome;
        $q = $a->question;
        $code = $lo?->code ?? ('LO #' . $a->learning_outcome_id);

        $why = match ($band) {
            'STRONG' => "The question is semantically aligned with {$code}: its similarity score {$this->fmt($score)} is at or above the strong threshold ({$this->fmt($strong)}).",
            'WEAK' => "The question is only weakly aligned with {$code}: its similarity score {$this->fmt($score)} is between the weak ({$this->fmt($weak)}) and strong ({$this->fmt($strong)}) thresholds.",
            default => "The question does not appear aligned with {$code}: its similarity score {$this->fmt($score)} is below the weak threshold ({$this->fmt($weak)}).",
        };

        $facts = ['scores' => ['similarity' => $score], 'labels' => ['alignment' => $band], 'allowed_numbers' => [$strong, $weak], 'codes' => $lo?->code ? [$lo->code] : []];
        $reasoning = $this->validator->validatedOrFallback($a->reasoning, $facts, $why);

        $b->result($band, $score, $this->humanize($band), ['similarity_display' => $this->fmtScore($score), 'learning_outcome' => ['id' => $lo?->id, 'code' => $lo?->code, 'description' => $lo?->description]])
            ->summary($why)
            ->detail('Similarity score', $this->fmtScore($score))
            ->detail('Thresholds', "≥ {$this->fmt($strong)} → STRONG · {$this->fmt($weak)}–<{$this->fmt($strong)} → WEAK · < {$this->fmt($weak)} → NOT ALIGNED")
            ->detail('Faculty-mapped outcome', $q?->learningOutcome?->code ?? 'None')
            ->evidence(['type' => 'score', 'label' => 'Cosine similarity between question and LO description', 'score' => $score, 'text' => $this->fmtScore($score)])
            ->evidence(['type' => 'learning_outcome', 'label' => "Learning outcome {$code}", 'text' => $this->excerpt($lo?->description), 'source_type' => 'learning_outcome', 'source_id' => $lo?->id])
            ->evidence(['type' => 'question_text', 'label' => 'Question wording', 'text' => $this->excerpt($q?->question_text), 'source_type' => 'question', 'source_id' => $q?->id]);
        if ($reasoning['validated'] && $a->reasoning) {
            $b->evidence(['type' => 'analysis_note', 'label' => 'Analysis note', 'text' => $a->reasoning]);
        }
        $b->method('EMBEDDING_BASED', 'Dense sentence embeddings of the question and the learning outcome description compared with cosine similarity, then classified with configured thresholds.',
                ['Configured embedding model', 'Cosine similarity', 'FacultyLens threshold bands'])
            ->model(['embedding_model' => config('ai_explainability.embedding_model'), 'name' => 'facultylens-lo-alignment', 'version' => '1.0.0'])
            ->confidence(null, 'Similarity is a distance measure, not a confidence or probability.')
            ->analysisContext($a->analysis_report_id, $a->analysisReport?->analysis_version, $a->analysisReport?->is_current)
            ->limitations($this->limitations('lo_alignment'))
            ->link('View learning outcome', 'learning_outcome', $lo?->id, ['course_id' => $lo?->course_id])
            ->link('View question', 'question', $q?->id, ['assessment_id' => $q?->assessment_id])
            ->review(['overridable' => true, 'actions' => ['ACCEPTED', 'REJECTED', 'REVIEWED', 'OVERRIDE'], 'override_options' => $this->overrideOptions($a),
                'override_field' => 'learning_outcome_id', 'faculty_value' => $q?->learningOutcome?->code]);

        return $b;
    }

    protected function fmt(float $v): string
    {
        return number_format($v, 2);
    }
}
