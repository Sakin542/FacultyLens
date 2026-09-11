<?php

namespace App\Services\Reports;

use App\Services\Analytics\AiAnalyticsService;

/**
 * Inter-Grader Consistency Report — the "FacultyLens Agreement Indicator" (not an official reliability
 * statistic). STEP 29 multi-grader tables are not part of this deployment, so the report states that
 * honestly rather than fabricating consistency values.
 */
class InterGraderReportBuilder extends AbstractReportBuilder
{
    public function __construct(protected AiAnalyticsService $ai) {}

    public function build(ReportContext $ctx): array
    {
        $ig = $this->ai->interGrader();
        $summary = [
            $this->kv('Indicator', $ig['indicator_name'] ?? 'FacultyLens Agreement Indicator'),
            $this->kv('Availability', ($ig['available'] ?? false) ? 'Available' : 'Not available'),
            $this->kv('Assessments in Scope', count($ctx->studentDataAssessmentIds)),
        ];
        $columns = ['question' => 'Question', 'grader_count' => 'Grader Count', 'mean' => 'Mean', 'median' => 'Median', 'minimum' => 'Minimum', 'maximum' => 'Maximum', 'range' => 'Range', 'normalized_difference' => 'Normalized Difference', 'agreement_indicator' => 'Agreement Indicator', 'consistency_status' => 'Consistency Status'];
        $rows = (array) ($ig['rows'] ?? []);
        $warnings = ($ig['available'] ?? false) ? [] : [$ig['message'] ?? 'Inter-grader consistency data is not available in this deployment.'];

        return $this->document($ctx, $summary, [$this->section('note', 'Interpretation', [], 'Statuses: CONSISTENT, MINOR_VARIATION, SIGNIFICANT_VARIATION, REVIEW_RECOMMENDED. The indicator supports faculty review and is not an official reliability statistic.')],
            [$this->table('inter_grader', 'Inter-Grader Consistency', $columns, $rows)], $warnings);
    }
}
