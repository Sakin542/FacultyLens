<?php

namespace App\Services;

use App\Jobs\GenerateInstitutionalReportJob;
use App\Models\InstitutionalReport;
use App\Models\User;
use App\Services\Reports\AbstractReportBuilder;
use App\Services\Reports\ReportContext;
use App\Services\Reports\ReportValidationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * STEP 39 orchestration: preview → validate → create (sync or queued) → generate → store → audit.
 * No database transaction is held while a report is generated.
 */
class InstitutionalReportService
{
    public function __construct(
        protected ReportAuthorizationService $authorization,
        protected ReportExportService $export,
        protected AuditLogService $audit,
    ) {}

    // ------------------------------------------------------------------ preview

    public function preview(User $user, array $input): array
    {
        $ctx = $this->authorization->resolve($user, $input);
        $document = $this->builder($ctx)->build($ctx);
        $limit = (int) config('institutional_reports.preview_row_limit', 10);
        $tables = array_map(fn ($t) => ['key' => $t['key'], 'title' => $t['title'], 'columns' => $t['columns'], 'total_rows' => count($t['rows']), 'rows' => array_slice($t['rows'], 0, $limit), 'note' => $t['note'] ?? null], $document['tables']);
        $this->audit->log('REPORT_PREVIEWED', 'InstitutionalReport', null, $this->auditMeta($ctx) + ['record_count' => $document['record_count']], $user);

        return [
            'report_type' => $ctx->type,
            'report_label' => $ctx->label(),
            'scope_type' => $ctx->scope,
            'scope_description' => $ctx->scopeDescription(),
            'filters' => $ctx->filters,
            'record_count' => $document['record_count'],
            'estimated_size' => $this->estimateSize($document),
            'will_queue' => $this->shouldQueue($ctx, $document['record_count']),
            'contains_student_data' => $ctx->usesStudentData(),
            'metadata' => $document['metadata'],
            'summary' => $document['summary'],
            'sections' => $document['sections'],
            'tables' => $tables,
            'warnings' => $document['warnings'],
            'has_data' => $this->hasData($document),
        ];
    }

    // ------------------------------------------------------------------- create

    public function create(User $user, array $input): InstitutionalReport
    {
        $ctx = $this->authorization->resolve($user, $input);
        $format = strtoupper((string) ($input['format'] ?? 'PDF'));
        if (!in_array($format, (array) config('institutional_reports.formats'), true)) {
            throw new ReportValidationException('The selected export format is not supported.', ['format' => ['Unsupported format.']]);
        }
        // Validate data presence before persisting anything: an empty report would be misleading.
        $document = $this->builder($ctx)->build($ctx);
        if (!$this->hasData($document)) {
            throw new ReportValidationException('No data available for the selected filters.', ['filters' => ['No data available for the selected filters.']]);
        }
        $queue = $this->shouldQueue($ctx, $document['record_count']);
        $days = (int) config('institutional_reports.expiration_days', 7);

        $report = InstitutionalReport::create([
            'report_uuid' => (string) Str::uuid(),
            'created_by' => $user->id,
            'report_type' => $ctx->type,
            'scope_type' => $ctx->scope,
            'course_id' => $ctx->course?->id,
            'assessment_id' => $ctx->assessment?->id,
            'assessment_version_id' => $ctx->version?->id,
            'department' => $ctx->department,
            'program_id' => $ctx->programId,
            'filters' => $ctx->filters,
            'title' => $ctx->label() . ' — ' . $ctx->scopeDescription(),
            'format' => $format,
            'status' => InstitutionalReport::STATUS_PENDING,
            'is_async' => $queue,
            'contains_student_data' => $ctx->usesStudentData(),
            'record_count' => $document['record_count'],
            'expires_at' => $days > 0 ? now()->addDays($days) : null,
        ]);
        $this->audit->log('REPORT_REQUESTED', $report, $report->id, $this->auditMeta($ctx) + ['format' => $format, 'async' => $queue], $user);

        if ($queue) {
            GenerateInstitutionalReportJob::dispatch($report->id);
        } else {
            $this->generate($report, $document);
        }

        return $report->refresh();
    }

    // ----------------------------------------------------------------- generate

    /** Build (unless a freshly built document is supplied), export and store. Safe messages only on failure. */
    public function generate(InstitutionalReport $report, ?array $document = null): InstitutionalReport
    {
        if ($report->status === InstitutionalReport::STATUS_CANCELLED) {
            return $report;
        }
        $report->update(['status' => InstitutionalReport::STATUS_PROCESSING, 'started_at' => now(), 'error_message' => null]);
        $this->audit->log('REPORT_GENERATION_STARTED', $report, $report->id, ['report_type' => $report->report_type, 'scope' => $report->scope_type], $report->creator);

        try {
            $user = $report->creator;
            if (!$user) {
                throw new ReportValidationException('The requesting user no longer exists.');
            }
            if ($document === null) {
                $ctx = $this->authorization->resolve($user, ['report_type' => $report->report_type, 'scope_type' => $report->scope_type, 'filters' => $report->filters ?? []]);
                $document = $this->builder($ctx)->build($ctx);
            }
            $file = $this->export->store($report, $document);
            $report->update([
                'status' => InstitutionalReport::STATUS_COMPLETED,
                'file_path' => $file['path'],
                'file_name' => $file['name'],
                'file_size' => $file['size'],
                'record_count' => $document['record_count'],
                'summary' => ['items' => $document['summary'], 'warnings' => $document['warnings'], 'tables' => array_map(fn ($t) => ['key' => $t['key'], 'title' => $t['title'], 'rows' => count($t['rows'])], $document['tables'])],
                'data_as_of' => $document['metadata']['data_as_of'] ?? now(),
                'generated_at' => now(),
            ]);
            $this->audit->log('REPORT_GENERATION_COMPLETED', $report, $report->id, ['report_type' => $report->report_type, 'scope' => $report->scope_type, 'format' => $report->format, 'record_count' => $report->record_count, 'file_size' => $file['size']], $user);
        } catch (Throwable $e) {
            Log::error('Institutional report generation failed', ['report_id' => $report->id, 'error' => $e->getMessage()]);
            $safe = $e instanceof ReportValidationException ? $e->getMessage() : 'Report generation failed. Please try again.';
            $report->update(['status' => InstitutionalReport::STATUS_FAILED, 'error_message' => $safe]);
            $this->audit->log('REPORT_GENERATION_FAILED', $report, $report->id, ['report_type' => $report->report_type, 'scope' => $report->scope_type, 'reason' => $safe], $report->creator);
        }

        return $report->refresh();
    }

    // ------------------------------------------------------------------- delete

    public function delete(User $user, InstitutionalReport $report): void
    {
        $this->export->delete($report);
        $this->audit->log('REPORT_DELETED', $report, $report->id, ['report_type' => $report->report_type, 'scope' => $report->scope_type, 'format' => $report->format], $user);
        $report->delete();
    }

    /** Remove expired files (audit rows and the report record are kept for traceability). */
    public function purgeExpired(): int
    {
        $n = 0;
        InstitutionalReport::whereNotNull('file_path')->whereNull('file_deleted_at')->whereNotNull('expires_at')->where('expires_at', '<', now())->chunkById(100, function ($reports) use (&$n) {
            foreach ($reports as $r) {
                $this->export->delete($r);
                $r->update(['file_deleted_at' => now(), 'file_size' => null]);
                $n++;
            }
        });

        return $n;
    }

    // ---------------------------------------------------------------- present

    public function present(InstitutionalReport $report): array
    {
        $report->loadMissing(['course:id,course_code,course_name,semester,academic_year', 'assessment:id,title', 'assessmentVersion:id,version_label,status', 'creator:id,name']);
        $def = config('institutional_reports.types.' . $report->report_type);

        return [
            'id' => $report->id, 'uuid' => $report->report_uuid, 'title' => $report->title,
            'report_type' => $report->report_type, 'report_label' => $def['label'] ?? $report->report_type, 'scope_type' => $report->scope_type,
            'course' => $report->course ? ['id' => $report->course->id, 'code' => $report->course->course_code, 'name' => $report->course->course_name, 'semester' => $report->course->semester, 'academic_year' => $report->course->academic_year] : null,
            'assessment' => $report->assessment ? ['id' => $report->assessment->id, 'title' => $report->assessment->title] : null,
            'assessment_version' => $report->assessmentVersion ? ['id' => $report->assessmentVersion->id, 'version_label' => $report->assessmentVersion->version_label, 'status' => $report->assessmentVersion->status] : null,
            'department' => $report->department, 'program_id' => $report->program_id, 'filters' => $report->filters ?? [],
            'format' => $report->format, 'status' => $report->status, 'is_async' => $report->is_async, 'contains_student_data' => $report->contains_student_data,
            'file_name' => $report->file_name, 'file_size' => $report->file_size, 'record_count' => $report->record_count, 'summary' => $report->summary,
            'data_as_of' => $report->data_as_of?->toISOString(), 'started_at' => $report->started_at?->toISOString(), 'generated_at' => $report->generated_at?->toISOString(),
            'expires_at' => $report->expires_at?->toISOString(), 'is_expired' => $report->isExpired(), 'downloadable' => $report->isDownloadable(), 'file_deleted_at' => $report->file_deleted_at?->toISOString(),
            'error_message' => $report->error_message, 'created_by' => $report->creator ? ['id' => $report->creator->id, 'name' => $report->creator->name] : null,
            'created_at' => $report->created_at?->toISOString(), 'updated_at' => $report->updated_at?->toISOString(),
        ];
    }

    // ---------------------------------------------------------------- helpers

    protected function builder(ReportContext $ctx): AbstractReportBuilder
    {
        return app($ctx->definition['builder']);
    }

    protected function shouldQueue(ReportContext $ctx, int $records): bool
    {
        return in_array($ctx->scope, ['DEPARTMENT', 'INSTITUTION'], true) || $records > (int) config('institutional_reports.async_threshold_records', 2000);
    }

    /** A report has data when any table holds rows beyond pure "not configured / not available" placeholders (builders may override). */
    protected function hasData(array $document): bool
    {
        if (array_key_exists('has_data', $document)) {
            return (bool) $document['has_data'];
        }
        foreach ($document['tables'] as $t) {
            if (count($t['rows']) > 0 && !(count($t['columns']) === 1 && ($t['columns'][0]['key'] ?? '') === 'message')) {
                return true;
            }
        }

        return false;
    }

    protected function estimateSize(array $document): string
    {
        $bytes = strlen(json_encode($document['tables'])) + 4096;

        return $bytes > 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }

    protected function auditMeta(ReportContext $ctx): array
    {
        return ['report_type' => $ctx->type, 'scope' => $ctx->scope, 'course_id' => $ctx->course?->id, 'assessment_id' => $ctx->assessment?->id, 'assessment_version_id' => $ctx->version?->id, 'filters' => $ctx->filters];
    }
}
