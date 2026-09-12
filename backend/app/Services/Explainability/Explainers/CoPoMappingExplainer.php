<?php

namespace App\Services\Explainability\Explainers;

use App\Models\CoPoMapping;
use App\Models\Course;
use App\Models\QuestionCoMapping;
use App\Models\User;
use App\Services\CoPoMappingException;
use App\Services\CoPoMappingValidatorService;
use App\Services\Explainability\AbstractExplainer;
use App\Services\Explainability\ExplainabilityException;
use App\Services\Explainability\ExplanationBuilder;
use Illuminate\Database\Eloquent\Model;

/** Question → CO → PO mapping: strength, evidence, method (AI suggested vs faculty confirmed), review status (STEP 45 §11). */
class CoPoMappingExplainer extends AbstractExplainer
{
    public function __construct(protected CoPoMappingValidatorService $coPo)
    {
    }

    public function type(): string
    {
        return 'co_po_mapping';
    }

    public function find(int $id): ?Model
    {
        return QuestionCoMapping::with(['question.assessment.course', 'learningOutcome'])->find($id);
    }

    public function course(Model $target): ?Course
    {
        return $target->question?->assessment?->course;
    }

    public function reviewAbility(): ?string
    {
        return 'edit_course';
    }

    public function aiValue(Model $target): array
    {
        return ['learning_outcome_id' => $target->learning_outcome_id, 'mapping_source' => $target->mapping_source, 'status' => $target->status, 'similarity_score' => $target->similarity_score !== null ? (float) $target->similarity_score : null];
    }

    /** Accept → CONFIRMED, Reject → REJECTED via the STEP 31 decision workflow. */
    public function afterReview(User $user, Model $target, string $action, ?string $comment): void
    {
        if (!in_array($action, ['ACCEPTED', 'REJECTED'], true)) {
            return;
        }
        try {
            $this->coPo->decideQuestionMapping($target->question, $target->learningOutcome, $user, $action === 'ACCEPTED');
        } catch (CoPoMappingException $e) {
            throw new ExplainabilityException($e->getMessage(), $e->getStatus());
        }
    }

    public function explain(User $user, Model $target, ExplanationBuilder $b): ExplanationBuilder
    {
        /** @var QuestionCoMapping $m */
        $m = $target;
        $lo = $m->learningOutcome;
        $course = $this->course($m);
        $levels = config('co_po.mapping_levels', [0 => 'NONE', 1 => 'LOW', 2 => 'MEDIUM', 3 => 'HIGH']);
        $level = $levels[(int) $m->mapping_level] ?? (string) $m->mapping_level;
        $isAi = $m->mapping_source === QuestionCoMapping::SOURCE_AI;
        $score = $m->similarity_score !== null ? round((float) $m->similarity_score, 4) : null;
        $thresholds = config('ai_explainability.alignment_thresholds');
        $status = strtoupper((string) $m->status);
        $code = $lo?->code ?? ('CO #' . $m->learning_outcome_id);

        $why = $isAi
            ? sprintf('FacultyLens suggested mapping this question to %s because the semantic similarity between the question and the outcome description is %s (strong ≥ %s, weak ≥ %s).', $code, $this->fmtScore($score), number_format((float) $thresholds['strong'], 2), number_format((float) $thresholds['weak'], 2))
            : sprintf('This mapping to %s was %s by faculty; FacultyLens records it as evidence for CO/PO attainment but did not infer it.', $code, $m->mapping_source === QuestionCoMapping::SOURCE_IMPORTED ? 'imported' : 'set');

        $b->result($level, $score, "{$level} · " . ($isAi ? 'AI Suggested' : 'Faculty') . " · {$this->humanize($status)}", ['mapping_level' => (int) $m->mapping_level, 'mapping_source' => $m->mapping_source, 'status' => $status])
            ->summary($why)
            ->detail('Course outcome', $code . ($lo?->description ? ' — ' . $this->excerpt($lo->description, 140) : ''))
            ->detail('Mapping strength', $level)
            ->detail('Mapping method', $isAi ? 'Semantic similarity (AI suggested) — pending faculty decision' : 'Faculty mapping')
            ->detail('Review status', $status === 'CONFIRMED' ? 'Faculty confirmed' : ($status === 'REJECTED' ? 'Rejected by faculty' : 'AI suggested — awaiting faculty review'));

        if ($score !== null) {
            $b->evidence(['type' => 'score', 'label' => 'Question ↔ CO similarity', 'score' => $score, 'text' => $this->fmtScore($score)]);
        }
        $b->evidence(['type' => 'learning_outcome', 'label' => "Course outcome {$code}", 'text' => $this->excerpt($lo?->description), 'source_type' => 'learning_outcome', 'source_id' => $lo?->id])
            ->evidence(['type' => 'question_text', 'label' => 'Question wording', 'text' => $this->excerpt($m->question?->question_text), 'source_type' => 'question', 'source_id' => $m->question_id]);

        if ($lo && $course) {
            $poRows = CoPoMapping::with('programOutcome')->where('course_id', $course->id)->where('learning_outcome_id', $lo->id)->get();
            foreach ($poRows as $row) {
                $b->evidence(['type' => 'co_po_link', 'label' => "{$code} → " . ($row->programOutcome?->code ?? 'PO'), 'text' => ($levels[(int) $row->mapping_level] ?? $row->mapping_level) . ' (faculty CO→PO mapping' . ($row->justification ? ': ' . $this->excerpt($row->justification, 120) : '') . ')',
                    'source_type' => 'program_outcome', 'source_id' => $row->program_outcome_id]);
            }
            if ($poRows->isEmpty()) {
                $b->evidence(['type' => 'co_po_link', 'label' => "{$code} → PO", 'text' => 'No CO→PO mapping defined by faculty for this outcome.']);
            }
        }

        return $b->method($isAi && $status !== 'CONFIRMED' ? 'EMBEDDING_BASED' : 'HUMAN_CONFIRMED', $isAi
                ? 'Embedding similarity between the question and the course outcome description proposes the CO; the CO→PO link always comes from the faculty-defined CO/PO matrix.'
                : 'Faculty-defined mapping; the CO→PO link comes from the faculty-defined CO/PO matrix.', ['Question ↔ CO similarity (AI)', 'Faculty CO→PO matrix', 'Faculty confirmation'])
            ->model(['embedding_model' => $isAi ? config('ai_explainability.embedding_model') : null])
            ->confidence(null, 'Similarity is a distance measure, not a confidence or probability.')
            ->limitations(array_merge($this->limitations('co_po_mapping'), ['A mapping does not by itself demonstrate accreditation compliance.']))
            ->link('Open CO/PO mapping', 'co_po_mapping', $course?->id)
            ->review(['overridable' => false, 'actions' => $status === 'PENDING' ? ['ACCEPTED', 'REJECTED'] : ['REVIEWED'], 'accept_label' => 'Confirm mapping', 'reject_label' => 'Reject suggestion', 'faculty_value' => $status]);
    }
}
