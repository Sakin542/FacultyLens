<?php

namespace App\Services\Explainability;

/**
 * Common explainability structure shared by every AI component (STEP 45 §4).
 *
 * result → explanation → evidence → method → confidence → limitations → related → model → evaluation → review.
 * Builders only add facts that exist; nothing is fabricated (confidence defaults to "not available").
 */
class ExplanationBuilder
{
    protected array $data;

    public function __construct(string $resultType, int $resultId)
    {
        $this->data = [
            'result_type' => $resultType,
            'result_id' => $resultId,
            'result' => ['label' => null, 'score' => null, 'display' => null],
            'explanation' => ['summary' => '', 'details' => []],
            'evidence' => [],
            'evidence_status' => 'available',
            'method' => ['type' => 'RULE_BASED', 'description' => '', 'components' => []],
            'model' => ['name' => null, 'version' => null, 'prompt_version' => null, 'embedding_model' => null, 'rule_version' => null],
            'confidence' => ['available' => false, 'value' => null, 'note' => 'Confidence is not available for this result.'],
            'limitations' => [],
            'related' => ['links' => [], 'analysis_report_id' => null, 'analysis_version' => null, 'is_current' => null],
            'evaluation' => ['status' => 'NOT_EVALUATED', 'label' => 'Not evaluated yet', 'task' => null],
            'review' => [
                'overridable' => false,
                'actions' => [],
                'override_options' => [],
                'override_reasons' => [],
                'latest' => null,
                'history_count' => 0,
                'faculty_value' => null,
            ],
            'version' => ['explanation_version' => config('ai_explainability.explanation_version', '1.0.0'), 'generated_at' => now()->toIso8601String()],
            'disclaimer' => 'AI assists. Faculty decides. This result is AI-assisted and should be reviewed.',
        ];
    }

    public function result(?string $label, ?float $score = null, ?string $display = null, array $extra = []): self
    {
        $this->data['result'] = array_merge(['label' => $label, 'score' => $score, 'display' => $display ?? $label], $extra);
        return $this;
    }

    public function summary(string $summary): self
    {
        $this->data['explanation']['summary'] = $summary;
        return $this;
    }

    public function detail(string $label, mixed $value): self
    {
        $this->data['explanation']['details'][] = ['label' => $label, 'value' => $value];
        return $this;
    }

    /** @param array{type:string,label:string,text?:?string,score?:?float,source_type?:?string,source_id?:mixed,document_id?:mixed,document_page?:mixed,chunk_id?:mixed,meta?:array} $item */
    public function evidence(array $item): self
    {
        $this->data['evidence'][] = array_merge(['type' => 'fact', 'label' => '', 'text' => null, 'score' => null], $item);
        return $this;
    }

    public function evidenceStatus(string $status, ?string $note = null): self
    {
        $this->data['evidence_status'] = $status;
        if ($note) {
            $this->data['evidence_note'] = $note;
        }
        return $this;
    }

    public function method(string $type, string $description, array $components = []): self
    {
        $this->data['method'] = ['type' => $type, 'description' => $description, 'components' => $components];
        return $this;
    }

    public function model(array $model): self
    {
        $this->data['model'] = array_merge($this->data['model'], array_filter($model, fn ($v) => $v !== null));
        return $this;
    }

    public function confidence(?float $value, ?string $note = null): self
    {
        if ($value === null) {
            $this->data['confidence'] = ['available' => false, 'value' => null, 'note' => $note ?? 'Confidence is not available for this result.'];
        } else {
            $this->data['confidence'] = ['available' => true, 'value' => round($value, 4),
                'note' => $note ?? 'Model confidence according to the configured method. It does not guarantee that the result is correct.'];
        }
        return $this;
    }

    public function limitations(array $items): self
    {
        $this->data['limitations'] = array_values(array_unique(array_merge($this->data['limitations'], $items)));
        return $this;
    }

    public function link(string $label, string $type, mixed $id, array $extra = []): self
    {
        $this->data['related']['links'][] = array_merge(['label' => $label, 'type' => $type, 'id' => $id], $extra);
        return $this;
    }

    public function analysisContext(?int $reportId, ?int $version, ?bool $isCurrent): self
    {
        $this->data['related']['analysis_report_id'] = $reportId;
        $this->data['related']['analysis_version'] = $version;
        $this->data['related']['is_current'] = $isCurrent;
        return $this;
    }

    public function evaluation(array $evaluation): self
    {
        $this->data['evaluation'] = array_merge($this->data['evaluation'], $evaluation);
        return $this;
    }

    public function review(array $review): self
    {
        $this->data['review'] = array_merge($this->data['review'], $review);
        return $this;
    }

    public function disclaimer(string $text): self
    {
        $this->data['disclaimer'] = $text;
        return $this;
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
