<?php

namespace App\Services\Reports;

use App\Models\AnalysisReport;

/**
 * STEP 39 common report data contract. Every builder returns
 *   { metadata, summary, sections, tables, warnings, record_count }
 * and the PDF/CSV/XLSX formatters render that same structure — business logic lives once.
 */
abstract class AbstractReportBuilder
{
    abstract public function build(ReportContext $ctx): array;

    /** @param array<int, array{label:string,value:mixed}> $summary */
    protected function document(ReportContext $ctx, array $summary, array $sections, array $tables, array $warnings = [], ?string $dataAsOf = null): array
    {
        $tables = array_values(array_filter($tables));
        $sections = array_values(array_filter($sections));
        $recordCount = array_sum(array_map(fn ($t) => count($t['rows']), $tables));
        $filters = $ctx->filters;
        $course = $ctx->course;

        return [
            'metadata' => [
                'product' => 'FacultyLens',
                'note' => 'Generated from FacultyLens',
                'report_type' => $ctx->type,
                'report_label' => $ctx->label(),
                'scope' => $ctx->scope,
                'scope_description' => $ctx->scopeDescription(),
                'filters' => $filters,
                'generated_by' => ['id' => $ctx->user->id, 'name' => $ctx->user->name, 'department' => $ctx->user->department, 'designation' => $ctx->user->designation],
                'generated_at' => now()->toISOString(),
                'data_as_of' => $dataAsOf ?? now()->toISOString(),
                'course' => $course ? ['id' => $course->id, 'code' => $course->course_code, 'name' => $course->course_name, 'semester' => $course->semester, 'academic_year' => $course->academic_year] : null,
                'assessment' => $ctx->assessment ? ['id' => $ctx->assessment->id, 'title' => $ctx->assessment->title, 'type' => $ctx->assessment->type] : null,
                'assessment_version' => $ctx->version ? ['id' => $ctx->version->id, 'version_label' => $ctx->version->version_label, 'status' => $ctx->version->status] : null,
                'academic_year' => $course?->academic_year ?? ($filters['academic_year'] ?? null),
                'semester' => $course?->semester ?? ($filters['semester'] ?? null),
                'data_period' => $this->dataPeriod($filters),
                'course_count' => count($ctx->courseIds),
                'assessment_count' => count($ctx->assessmentIds),
                'contains_student_data' => $ctx->usesStudentData(),
                'privacy' => $ctx->usesStudentData() ? 'Aggregated finalized grades only; no student identity or individual answers.' : null,
            ],
            'summary' => array_values($summary),
            'sections' => $sections,
            'tables' => $tables,
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'record_count' => $recordCount,
        ];
    }

    protected function dataPeriod(array $filters): ?string
    {
        $start = $filters['start_date'] ?? null;
        $end = $filters['end_date'] ?? null;
        if (! $start && ! $end) {
            return null;
        }

        return ($start ?? 'Beginning').' → '.($end ?? 'Now');
    }

    /** @param array<int, array{key:string,label:string}|string> $columns */
    protected function table(string $key, string $title, array $columns, array $rows, ?string $note = null): ?array
    {
        $cols = [];
        foreach ($columns as $k => $c) {
            $cols[] = is_array($c) ? $c : ['key' => is_string($k) ? $k : $c, 'label' => is_string($k) ? $c : ucwords(str_replace('_', ' ', $c))];
        }
        $keys = array_column($cols, 'key');
        $rows = array_map(function ($row) use ($keys) {
            $out = [];
            foreach ($keys as $k) {
                $v = $row[$k] ?? null;
                $out[$k] = is_array($v) ? implode('; ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $v)) : $v;
            }

            return $out;
        }, array_values($rows));

        return ['key' => $key, 'title' => $title, 'columns' => $cols, 'rows' => $rows, 'note' => $note];
    }

    protected function section(string $key, string $title, array $items = [], ?string $text = null, ?string $description = null): array
    {
        return ['key' => $key, 'title' => $title, 'description' => $description, 'text' => $text,
            'items' => array_map(fn ($v, $k) => is_array($v) && isset($v['label']) ? $v : ['label' => is_string($k) ? $k : (string) $k, 'value' => $v], $items, array_keys($items))];
    }

    protected function kv(string $label, mixed $value): array
    {
        return ['label' => $label, 'value' => $value];
    }

    /** Human wording for absent data — never a fake zero. */
    protected function na(mixed $v, string $suffix = '', string $absent = 'N/A'): string
    {
        if ($v === null || $v === '') {
            return $absent;
        }

        return (is_float($v) ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : (string) $v).$suffix;
    }

    protected function pct(mixed $v): ?string
    {
        return $v === null ? null : $this->na((float) $v, '%');
    }

    protected function humanize(?string $v): ?string
    {
        return $v === null ? null : ucwords(strtolower(str_replace('_', ' ', $v)));
    }

    /**
     * Completed STEP 13 analysis for the report scope. For a version: the analysis attached to that version,
     * else the current analysis whose analyzed content hash equals the version snapshot (STEP 38 "CURRENT").
     * Never falls back to an analysis of different content.
     */
    protected function analysisFor(ReportContext $ctx): ?AnalysisReport
    {
        if ($ctx->version) {
            $own = AnalysisReport::where('assessment_version_id', $ctx->version->id)->where('analysis_status', 'completed')->orderByDesc('analysis_version')->orderByDesc('id')->first();
            if ($own || ! $ctx->version->content_hash) {
                return $own;
            }

            return AnalysisReport::where('assessment_id', $ctx->version->assessment_id)->where('analysis_status', 'completed')
                ->where('version_content_hash', $ctx->version->content_hash)->orderByDesc('analysis_version')->orderByDesc('id')->first();
        }

        return AnalysisReport::where('assessment_id', $ctx->assessment->id)->where('is_current', true)->where('analysis_status', 'completed')->first();
    }
}
