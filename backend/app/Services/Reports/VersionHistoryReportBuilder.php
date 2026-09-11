<?php

namespace App\Services\Reports;

use App\Services\AssessmentVersionComparisonService;
use App\Services\AssessmentVersionService;

/** Assessment Version History — STEP 38 versions with approvals, change summaries and successive diffs. */
class VersionHistoryReportBuilder extends AbstractReportBuilder
{
    public function __construct(protected AssessmentVersionService $versions, protected AssessmentVersionComparisonService $comparison) {}

    public function build(ReportContext $ctx): array
    {
        $assessment = $ctx->assessment;
        $history = $this->versions->history($assessment)->load(['creator:id,name', 'approver:id,name', 'finalizer:id,name', 'basedOn:id,version_label']);
        $ordered = $history->sortBy('version_number')->values();

        $rows = $ordered->map(fn ($v) => [
            'version' => $v->version_label, 'type' => $v->version_type, 'status' => $v->status, 'created_by' => $v->creator?->name, 'created_at' => $v->created_at?->toDateTimeString(),
            'approved_at' => $v->approved_at?->toDateTimeString(), 'approved_by' => $v->approver?->name, 'finalized_at' => $v->finalized_at?->toDateTimeString(), 'finalized_by' => $v->finalizer?->name,
            'based_on' => $v->basedOn?->version_label, 'total_marks' => (float) $v->total_marks, 'question_count' => (int) $v->question_count, 'change_summary' => $v->change_summary,
        ])->all();

        $diffs = [];
        for ($i = 1; $i < $ordered->count(); $i++) {
            $from = $ordered[$i - 1];
            $to = $ordered[$i];
            $cmp = $this->comparison->compare($from, $to);
            $s = $cmp['summary'];
            $diffs[] = ['from' => $from->version_label, 'to' => $to->version_label, 'questions_added' => $s['added'], 'questions_removed' => $s['removed'], 'questions_modified' => $s['modified'],
                'marks_changed' => $s['marks_difference'], 'blueprint_changed' => $s['blueprint_changed'] ? 'Yes' : 'No', 'detected_change_type' => $s['detected_change_type']];
        }
        $current = $this->versions->currentVersion($assessment);

        $summary = [
            $this->kv('Assessment', $assessment->title),
            $this->kv('Total Versions', $history->count()),
            $this->kv('Current Version', $current?->version_label ?? 'None'),
            $this->kv('Finalized Versions', $history->where('status', 'FINALIZED')->count()),
            $this->kv('Archived Versions', $history->where('status', 'ARCHIVED')->count()),
        ];
        $warnings = $history->isEmpty() ? ['No versions have been recorded for this assessment.'] : [];

        return $this->document($ctx, $summary, [], [
            $this->table('versions', 'Versions', ['version' => 'Version', 'type' => 'Type', 'status' => 'Status', 'created_by' => 'Created By', 'created_at' => 'Created At', 'approved_at' => 'Approved At', 'approved_by' => 'Approved By',
                'finalized_at' => 'Finalized At', 'finalized_by' => 'Finalized By', 'based_on' => 'Based On', 'total_marks' => 'Total Marks', 'question_count' => 'Questions', 'change_summary' => 'Change Summary'], $rows),
            $this->table('changes', 'Version-to-Version Changes', ['from' => 'From', 'to' => 'To', 'questions_added' => 'Added', 'questions_removed' => 'Removed', 'questions_modified' => 'Modified', 'marks_changed' => 'Marks Δ', 'blueprint_changed' => 'Blueprint Changed', 'detected_change_type' => 'Change Type'], $diffs),
        ], $warnings, $history->max('updated_at')?->toISOString());
    }
}
