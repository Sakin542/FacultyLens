<?php

namespace App\Services\Reports;

use App\Models\Rubric;

/** Rubric Report — STEP 25 rubrics, criteria, maximum marks and scoring guidance for the questions in scope. */
class RubricReportBuilder extends AbstractReportBuilder
{
    public function build(ReportContext $ctx): array
    {
        $rubrics = Rubric::whereIn('assessment_id', $ctx->assessmentIds ?: [-1])->where('status', '!=', Rubric::STATUS_ARCHIVED)
            ->with(['question:id,question_number,question_text,marks', 'assessment:id,title', 'criteria' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('assessment_id')->orderBy('question_id')->orderByDesc('version')->get()
            // Only the latest version per question is authoritative
            ->unique(fn ($r) => $r->assessment_id . ':' . $r->question_id)->values();

        $rubricRows = $rubrics->map(fn ($r) => ['assessment' => $r->assessment?->title, 'question' => $r->question?->question_number, 'rubric' => $r->title, 'version' => $r->version, 'status' => $r->status,
            'criteria' => $r->criteria->count(), 'total_marks' => (float) $r->total_marks, 'question_marks' => $r->question ? (float) $r->question->marks : null, 'generation_method' => $r->generation_method, 'approved_at' => $r->approved_at?->toDateTimeString()])->all();
        $criteriaRows = [];
        foreach ($rubrics as $r) {
            foreach ($r->criteria as $c) {
                $criteriaRows[] = ['assessment' => $r->assessment?->title, 'question' => $r->question?->question_number, 'rubric' => $r->title, 'version' => $r->version, 'criterion' => $c->criterion, 'description' => mb_substr((string) $c->description, 0, 160),
                    'max_marks' => (float) $c->max_marks, 'scoring_guidance' => mb_substr((string) $c->scoring_guidance, 0, 200), 'status' => $r->status];
            }
        }

        $summary = [
            $this->kv('Assessments in Scope', count($ctx->assessmentIds)),
            $this->kv('Rubrics', $rubrics->count()),
            $this->kv('Approved', $rubrics->where('status', Rubric::STATUS_APPROVED)->count()),
            $this->kv('Draft', $rubrics->where('status', Rubric::STATUS_DRAFT)->count()),
            $this->kv('Criteria', count($criteriaRows)),
        ];
        $warnings = $rubrics->isEmpty() ? ['No rubrics exist for the selected scope.'] : [];
        $drafts = $rubrics->where('status', Rubric::STATUS_DRAFT)->count();
        if ($drafts > 0) {
            $warnings[] = "{$drafts} rubric(s) are still DRAFT and have not been approved by faculty.";
        }

        return $this->document($ctx, $summary, [], [
            $this->table('rubrics', 'Rubrics', ['assessment' => 'Assessment', 'question' => 'Question', 'rubric' => 'Rubric', 'version' => 'Version', 'status' => 'Status', 'criteria' => 'Criteria', 'total_marks' => 'Maximum Marks', 'question_marks' => 'Question Marks', 'generation_method' => 'Generation Method', 'approved_at' => 'Approved At'], $rubricRows),
            $this->table('criteria', 'Criteria', ['assessment' => 'Assessment', 'question' => 'Question', 'rubric' => 'Rubric', 'version' => 'Version', 'criterion' => 'Criterion', 'description' => 'Description', 'max_marks' => 'Maximum Marks', 'scoring_guidance' => 'Scoring Guidance', 'status' => 'Status'], $criteriaRows),
        ], $warnings, $rubrics->max('updated_at')?->toISOString());
    }
}
