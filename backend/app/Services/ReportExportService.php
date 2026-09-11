<?php

namespace App\Services;

use App\Models\InstitutionalReport;
use App\Services\Reports\XlsxWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * STEP 39: turns the common report document {metadata, summary, sections, tables, warnings} into
 * PDF (STEP 18 DomPDF infrastructure), CSV (UTF-8, structured rows) or XLSX (one sheet per table).
 * Files go to the private disk; nothing under public/ is ever written.
 */
class ReportExportService
{
    /** @return array{path:string, name:string, size:int} */
    public function store(InstitutionalReport $report, array $document): array
    {
        $format = strtoupper($report->format);
        $content = match ($format) {
            'PDF' => $this->pdf($document),
            'CSV' => $this->csv($document),
            'XLSX' => $this->xlsx($document),
            default => throw new \InvalidArgumentException('Unsupported export format.'),
        };
        $ext = strtolower($format);
        $name = $this->fileName($report, $ext);
        $path = trim((string) config('institutional_reports.directory'), '/').'/'.$report->created_by.'/'.$report->report_uuid.'.'.$ext;
        Storage::disk($this->disk())->put($path, $content);

        return ['path' => $path, 'name' => $name, 'size' => strlen($content)];
    }

    public function disk(): string
    {
        return (string) config('institutional_reports.disk', 'local');
    }

    public function contentType(string $format): string
    {
        return match (strtoupper($format)) {
            'PDF' => 'application/pdf',
            'CSV' => 'text/csv; charset=UTF-8',
            'XLSX' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };
    }

    public function delete(InstitutionalReport $report): void
    {
        if ($report->file_path && Storage::disk($this->disk())->exists($report->file_path)) {
            Storage::disk($this->disk())->delete($report->file_path);
        }
    }

    protected function fileName(InstitutionalReport $report, string $ext): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', (string) $report->title) ?: 'Report';

        return 'FacultyLens_'.trim($slug, '_').'_'.now()->format('Ymd_His').'.'.$ext;
    }

    // --------------------------------------------------------------- formatters

    public function pdf(array $document): string
    {
        $limit = (int) config('institutional_reports.pdf_row_limit', 300);
        $tables = [];
        foreach ($document['tables'] as $t) {
            $total = count($t['rows']);
            $t['truncated'] = $total > $limit ? $total - $limit : 0;
            $t['rows'] = array_slice($t['rows'], 0, $limit);
            $tables[] = $t;
        }
        $document['tables'] = $tables;
        $footer = (string) config('institutional_reports.footer');
        if ($document['metadata']['contains_student_data'] ?? false) {
            $footer .= "\n".config('institutional_reports.sensitive_footer');
        }

        return Pdf::loadView('reports.institutional-pdf', ['doc' => $document, 'footer' => $footer])->setPaper('a4', 'portrait')->output();
    }

    /**
     * UTF-8 CSV. Summary block (metric,value) followed by every table as its own block with a
     * `# <Table title>` marker line and its own header row. Nested values are flattened by the builders.
     */
    public function csv(array $document): string
    {
        $out = fopen('php://temp', 'r+');
        $m = $document['metadata'];
        fputcsv($out, ['# FacultyLens '.$m['report_label'].' — '.$m['scope_description']]);
        fputcsv($out, ['# Generated '.$m['generated_at'].' by '.($m['generated_by']['name'] ?? '').' · Data as of '.$m['data_as_of']]);
        fputcsv($out, ['# Summary']);
        fputcsv($out, ['metric', 'value']);
        foreach ($document['summary'] as $kv) {
            fputcsv($out, [$kv['label'], $this->scalar($kv['value'])]);
        }
        foreach ($document['tables'] as $t) {
            fputcsv($out, []);
            fputcsv($out, ['# '.$t['title']]);
            fputcsv($out, array_column($t['columns'], 'key'));
            $keys = array_column($t['columns'], 'key');
            foreach ($t['rows'] as $row) {
                fputcsv($out, array_map(fn ($k) => $this->scalar($row[$k] ?? null), $keys));
            }
        }
        if ($document['warnings']) {
            fputcsv($out, []);
            fputcsv($out, ['# Warnings']);
            foreach ($document['warnings'] as $w) {
                fputcsv($out, [$w]);
            }
        }
        fputcsv($out, []);
        foreach (explode("\n", (string) config('institutional_reports.footer')) as $line) {
            fputcsv($out, ['# '.$line]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** Summary sheet + one sheet per table (only tables present in the document). */
    public function xlsx(array $document): string
    {
        $w = new XlsxWriter;
        $m = $document['metadata'];
        $summary = [['Field', 'Value'], ['Product', 'FacultyLens'], ['Report', $m['report_label']], ['Scope', $m['scope_description']], ['Generated By', $m['generated_by']['name'] ?? ''], ['Generated At', $m['generated_at']], ['Data As Of', $m['data_as_of']]];
        foreach (['assessment' => 'Assessment', 'assessment_version' => 'Assessment Version', 'academic_year' => 'Academic Year', 'semester' => 'Semester', 'data_period' => 'Data Period'] as $k => $label) {
            $v = $m[$k] ?? null;
            if ($v !== null) {
                $summary[] = [$label, is_array($v) ? ($v['title'] ?? $v['version_label'] ?? json_encode($v)) : $v];
            }
        }
        if (! empty($m['filters'])) {
            $summary[] = ['Filters', collect($m['filters'])->map(fn ($v, $k) => "$k=$v")->implode('; ')];
        }
        $summary[] = [];
        $summary[] = ['Metric', 'Value'];
        foreach ($document['summary'] as $kv) {
            $summary[] = [$kv['label'], $this->scalar($kv['value'])];
        }
        if ($document['warnings']) {
            $summary[] = [];
            $summary[] = ['Warnings'];
            foreach ($document['warnings'] as $wn) {
                $summary[] = [$wn];
            }
        }
        $summary[] = [];
        foreach (explode("\n", (string) config('institutional_reports.footer')) as $line) {
            $summary[] = [$line];
        }
        $w->addSheet('Summary', $summary);
        foreach ($document['tables'] as $t) {
            $rows = [array_column($t['columns'], 'label')];
            $keys = array_column($t['columns'], 'key');
            foreach ($t['rows'] as $row) {
                $rows[] = array_map(fn ($k) => $this->scalar($row[$k] ?? null), $keys);
            }
            if (! empty($t['note'])) {
                $rows[] = [];
                $rows[] = [$t['note']];
            }
            $w->addSheet($t['title'], $rows);
        }

        return $w->toString();
    }

    protected function scalar(mixed $v): mixed
    {
        if (is_bool($v)) {
            return $v ? 'Yes' : 'No';
        }
        if (is_array($v)) {
            return implode('; ', array_map(fn ($x) => is_scalar($x) ? (string) $x : json_encode($x), $v));
        }

        return $v;
    }
}
