<?php

namespace App\Services\Explainability\Explainers;

use App\Models\AiGradingResult;
use App\Models\Course;
use App\Models\User;
use App\Services\Explainability\AbstractExplainer;
use App\Services\Explainability\ExplanationBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * AI-assisted grading: question → student answer → rubric → AI suggested mark → evidence → faculty final mark (STEP 45 §25–26).
 * Requires view_student_data; the faculty mark is never changed here (use the grading workflow).
 */
class AiGradingExplainer extends AbstractExplainer
{
    public function type(): string
    {
        return 'ai_grading';
    }

    public function find(int $id): ?Model
    {
        return AiGradingResult::with(['studentAnswer.submission.assessment.course', 'question', 'rubric', 'criterionResults'])->find($id);
    }

    public function course(Model $target): ?Course
    {
        return $target->studentAnswer?->submission?->assessment?->course ?? $target->submission?->assessment?->course;
    }

    public function viewAbility(): string
    {
        return 'view_student_data';
    }

    public function reviewAbility(): ?string
    {
        return 'view_student_data';
    }

    public function aiValue(Model $target): array
    {
        return ['suggested_marks' => $target->suggested_marks !== null ? (float) $target->suggested_marks : null, 'maximum_marks' => (float) $target->maximum_marks, 'rubric_version' => $target->rubric_version];
    }

    public function explain(User $user, Model $target, ExplanationBuilder $b): ExplanationBuilder
    {
        /** @var AiGradingResult $g */
        $g = $target;
        $answer = $g->studentAnswer;
        $suggested = $g->suggested_marks !== null ? round((float) $g->suggested_marks, 2) : null;
        $max = round((float) $g->maximum_marks, 2);
        $final = $answer && $answer->awarded_marks !== null ? round((float) $answer->awarded_marks, 2) : null;
        $diff = ($suggested !== null && $final !== null) ? round($final - $suggested, 2) : null;
        $stale = $answer && $g->answer_fingerprint && $g->answer_fingerprint !== $answer->contentFingerprint();

        $criteria = $g->criterionResults;
        $covered = $criteria->filter(fn ($c) => in_array(strtoupper((string) $c->coverage_level), ['STRONG'], true))->count();
        $partial = $criteria->filter(fn ($c) => in_array(strtoupper((string) $c->coverage_level), ['PARTIAL', 'LIMITED'], true))->count();
        $missing = $criteria->filter(fn ($c) => strtoupper((string) $c->coverage_level) === 'NOT_ADDRESSED')->count();

        $summary = $suggested === null
            ? 'FacultyLens has not produced a suggested mark for this answer' . ($g->error_message ? ' (the grading request failed).' : '.')
            : sprintf('FacultyLens suggested %s / %s marks by checking the student answer against each rubric criterion: %d criterion(s) demonstrated, %d partially demonstrated, %d missing. The suggestion is advisory — the faculty mark is final.',
                $this->fmt($suggested), $this->fmt($max), $covered, $partial, $missing);

        $b->result($g->grading_status, $suggested, $suggested !== null ? "{$this->fmt($suggested)} / {$this->fmt($max)}" : 'No suggestion', ['suggested_marks' => $suggested, 'maximum_marks' => $max, 'faculty_decision' => $g->faculty_decision])
            ->summary($summary)
            ->detail('AI suggested', $suggested !== null ? "{$this->fmt($suggested)} / {$this->fmt($max)}" : 'Not available')
            ->detail('Faculty final', $final !== null ? "{$this->fmt($final)} / {$this->fmt($max)}" : 'Not yet entered (faculty-controlled)')
            ->detail('Difference (faculty − AI)', $diff !== null ? (($diff > 0 ? '+' : ($diff < 0 ? '−' : '')) . $this->fmt(abs($diff)) . ' mark(s)') : 'n/a')
            ->detail('Rubric', $g->rubric ? "{$g->rubric->title} (v{$g->rubric_version})" : 'Rubric unavailable')
            ->detail('Faculty decision', $g->faculty_decision ?? 'Pending');
        if ($stale) {
            $b->evidenceStatus('stale', 'The student answer changed after this suggestion was produced; regenerate before relying on it.');
        }

        foreach ($criteria as $c) {
            $b->evidence(['type' => 'criterion', 'label' => $c->criterion, 'score' => $c->suggested_marks !== null ? (float) $c->suggested_marks : null,
                'text' => sprintf('%s / %s — %s', $this->fmt((float) $c->suggested_marks), $this->fmt((float) $c->maximum_marks), $this->humanize($c->coverage_level ?? 'UNKNOWN')),
                'meta' => ['coverage_level' => $c->coverage_level, 'evaluation' => $this->excerpt($c->evaluation, 300),
                    'answer_evidence' => array_map(fn ($e) => $this->excerpt(is_scalar($e) ? (string) $e : json_encode($e), 240), array_slice($c->evidence ?? [], 0, 3)),
                    'missing_elements' => array_slice($c->missing_elements ?? [], 0, 5)]]);
        }
        foreach (array_slice($g->strengths ?? [], 0, 5) as $s) {
            $b->evidence(['type' => 'strength', 'label' => 'Demonstrated', 'text' => $this->excerpt(is_scalar($s) ? (string) $s : json_encode($s), 240)]);
        }
        foreach (array_slice($g->missing_elements ?? [], 0, 5) as $s) {
            $b->evidence(['type' => 'gap', 'label' => 'Missing', 'text' => $this->excerpt(is_scalar($s) ? (string) $s : json_encode($s), 240)]);
        }

        return $b->method('HYBRID', 'For each rubric criterion the answer sentences are compared with the criterion description and expected indicators using the embedding model (lexical fallback), coverage is converted to a mark by deterministic rules, and the best-matching answer sentences are returned as evidence.',
                ['Sentence embeddings (configured model)', 'Indicator coverage rules', 'Deterministic mark conversion', 'Marks validator'])
            ->model(['name' => $g->model_name, 'version' => $g->model_version, 'embedding_model' => config('ai_explainability.embedding_model')])
            ->confidence(null, 'The grading engine reports observable evidence only; it does not produce a confidence value.')
            ->limitations($this->limitations('ai_grading'))
            ->link('Open submission', 'submission', $g->student_submission_id, ['answer_id' => $g->student_answer_id])
            ->review(['overridable' => false, 'actions' => ['REVIEWED'], 'note' => 'Enter or change the final mark in the grading workflow; AI suggestions are never finalized automatically.', 'faculty_value' => $final]);
    }

    protected function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
