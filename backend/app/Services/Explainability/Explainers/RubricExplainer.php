<?php

namespace App\Services\Explainability\Explainers;

use App\Models\Course;
use App\Models\Rubric;
use App\Models\User;
use App\Services\Explainability\AbstractExplainer;
use App\Services\Explainability\ExplanationBuilder;
use Illuminate\Database\Eloquent\Model;

/** Rubric: question → criteria → marks allocation → expected indicators, with deterministic marks-constraint validation (STEP 45 §18–19). */
class RubricExplainer extends AbstractExplainer
{
    public function type(): string
    {
        return 'rubric';
    }

    public function find(int $id): ?Model
    {
        return Rubric::with(['question.assessment.course', 'assessment.course', 'criteria'])->find($id);
    }

    public function course(Model $target): ?Course
    {
        return $target->question?->assessment?->course ?? $target->assessment?->course;
    }

    public function viewAbility(): string
    {
        return 'view';
    }

    public function reviewAbility(): ?string
    {
        return 'approve_rubric';
    }

    public function aiValue(Model $target): array
    {
        return ['version' => $target->version, 'status' => $target->status, 'total_marks' => (float) $target->total_marks, 'criteria_count' => $target->criteria->count()];
    }

    public function explain(User $user, Model $target, ExplanationBuilder $b): ExplanationBuilder
    {
        /** @var Rubric $r */
        $r = $target;
        $q = $r->question;
        $questionMarks = $q ? (float) $q->marks : null;
        $sum = round((float) $r->criteria->sum(fn ($c) => (float) $c->max_marks), 2);
        $total = round((float) $r->total_marks, 2);
        $sumMatchesTotal = abs($sum - $total) <= 0.005;
        $totalMatchesQuestion = $questionMarks === null || abs($total - $questionMarks) <= 0.005;
        $pass = $sumMatchesTotal && $totalMatchesQuestion;
        $isAi = $r->generation_method && $r->generation_method !== 'manual';
        $generative = $isAi && $r->ai_model && !str_contains(strtolower((string) $r->ai_model), 'template');
        $method = !$isAi ? 'HUMAN_CONFIRMED' : ($generative ? 'HYBRID' : 'RULE_BASED');

        $b->result($r->status, $total, "{$r->status} · v{$r->version} · {$this->fmt($total)} marks", ['status' => $r->status, 'version' => $r->version, 'total_marks' => $total])
            ->summary($isAi
                ? 'This rubric was generated from the question requirements and configured assessment metadata. Each criterion reflects a component of the question wording; marks were distributed so that they sum to the question marks. It remains a DRAFT until faculty approve it.'
                : 'This rubric was authored by faculty; FacultyLens only validates that criterion marks sum to the rubric total.')
            ->detail('Question marks', $questionMarks)
            ->detail('Rubric total', $total)
            ->detail('Sum of criteria', $sum)
            ->detail('Constraint', $pass ? 'PASS' : 'FAIL')
            ->detail('Constraint check', ($sumMatchesTotal ? 'Criterion marks sum to the rubric total.' : "Criterion marks ({$this->fmt($sum)}) do not equal the rubric total ({$this->fmt($total)}).")
                . ' ' . ($totalMatchesQuestion ? 'Rubric total matches the question marks.' : "Rubric total ({$this->fmt($total)}) differs from the question marks ({$this->fmt((float) $questionMarks)})."));
        foreach ($r->criteria as $c) {
            $b->evidence(['type' => 'criterion', 'label' => $c->criterion, 'score' => (float) $c->max_marks, 'text' => $this->excerpt($c->description, 300),
                'meta' => ['max_marks' => (float) $c->max_marks, 'scoring_guidance' => $c->scoring_guidance, 'expected_indicators' => $c->expected_indicators ?? [], 'sort_order' => $c->sort_order]]);
        }
        if ($q) {
            $b->evidence(['type' => 'question_text', 'label' => 'Question wording', 'text' => $this->excerpt($q->question_text), 'source_type' => 'question', 'source_id' => $q->id]);
        }

        return $b->method($method, $isAi
                ? 'Template engine extracts the question\'s action verbs and applies question-type criterion templates; an optional generation model may propose criterion names, and the embedding model ranks criteria by relevance. Marks are allocated deterministically.'
                : 'Faculty-authored rubric.', $isAi ? ['Question action verbs', 'Question-type criterion templates', 'Deterministic marks allocation', 'Marks-sum validator'] : [])
            ->model(['name' => $r->ai_model, 'version' => $r->ai_model_version, 'embedding_model' => $isAi ? config('ai_explainability.embedding_model') : null])
            ->confidence(null, 'Rubric generation does not produce a confidence value.')
            ->limitations($this->limitations('rubric'))
            ->link('View question', 'question', $q?->id, ['assessment_id' => $q?->assessment_id])
            ->review(['overridable' => false, 'actions' => ['REVIEWED'], 'note' => 'Approve or edit this rubric from the rubric editor; approval is a faculty decision.', 'faculty_value' => $r->status]);
    }

    protected function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
