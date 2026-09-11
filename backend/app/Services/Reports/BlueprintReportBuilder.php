<?php

namespace App\Services\Reports;

use App\Services\AssessmentBlueprintService;

/** Assessment Blueprint Report — STEP 37 targets, validation and target-vs-actual comparison. */
class BlueprintReportBuilder extends AbstractReportBuilder
{
    public function __construct(protected AssessmentBlueprintService $blueprints) {}

    public function build(ReportContext $ctx): array
    {
        $assessment = $ctx->assessment;
        $section = $this->blueprints->reportSection($assessment);
        if (! $section) {
            return $this->document($ctx, [$this->kv('Assessment', $assessment->title), $this->kv('Blueprint', 'Not configured')], [], [], ['No blueprint exists for this assessment.']);
        }
        $cmp = $section['comparison'];
        $hasQuestions = (bool) ($cmp['actual']['has_questions'] ?? false);

        $summary = [
            $this->kv('Assessment', $assessment->title),
            $this->kv('Blueprint Version', 'v'.$section['version']),
            $this->kv('Blueprint Status', $section['status']),
            $this->kv('Validation Status', $section['validation_status'] ?? 'Not validated'),
            $this->kv('Completeness', $this->na($section['completeness'], '%')),
            $this->kv('Total Marks (target)', $section['total_marks']),
            $this->kv('Question Count (target)', $section['total_questions']),
            $this->kv('Duration (minutes)', $section['duration_minutes'] ?? 'N/A'),
            $this->kv('Compliance', $hasQuestions ? $this->na($cmp['compliance_percent'], '%') : 'No questions yet'),
        ];

        $tables = [
            $this->table('sections', 'Sections', ['title' => 'Section', 'question_type' => 'Question Type', 'question_count' => 'Questions', 'marks_per_question' => 'Marks / Question', 'total_marks' => 'Total Marks'], $section['sections']),
            $this->table('structure', 'Structure: Target vs Actual', ['label' => 'Dimension', 'target' => 'Target', 'actual' => 'Actual', 'difference' => 'Difference', 'status' => 'Status'], $cmp['structure']),
        ];
        foreach (['difficulty' => 'Difficulty Targets', 'cognitive' => 'Bloom Targets', 'question_types' => 'Question Type Targets', 'learning_outcomes' => 'CO Targets', 'program_outcomes' => 'PO Targets', 'topics' => 'Topic Targets'] as $key => $title) {
            $dim = $cmp['dimensions'][$key] ?? null;
            if (! $dim) {
                continue;
            }
            if (! ($dim['configured'] ?? false)) {
                $tables[] = $this->table($key, $title, ['message' => 'Status'], [['message' => $dim['message'] ?? 'Not configured in this blueprint.']]);

                continue;
            }
            $tables[] = $this->table($key, $title.' ('.($dim['basis'] ?? 'count').' basis)', ['label' => 'Target', 'target_percentage' => 'Target %', 'actual_percentage' => 'Actual %', 'actual_raw' => 'Actual', 'difference' => 'Difference', 'status' => 'Status'], $dim['rows']);
        }
        $warnings = array_map(fn ($w) => is_array($w) ? ($w['message'] ?? json_encode($w)) : (string) $w, (array) ($section['warnings'] ?? []));
        if (! $hasQuestions) {
            $warnings[] = 'The assessment has no questions yet; the comparison shows targets only.';
        }
        $tables[] = $this->table('warnings', 'Validation Warnings', ['warning' => 'Warning'], array_map(fn ($w) => ['warning' => $w], $warnings));

        return $this->document($ctx, $summary, [$this->section('note', 'Note', [], $cmp['note'] ?? null)], $tables, [], $cmp['compared_at'] ?? null);
    }
}
