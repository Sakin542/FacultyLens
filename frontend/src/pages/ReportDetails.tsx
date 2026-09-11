import React, { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Clock, Copy, Lock, RefreshCw, Trash2 } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/common/Card';
import { institutionalReportService } from '@/services/institutionalReportService';
import { InstitutionalReport } from '@/types/report';
import { ReportHeader } from '@/components/reports/institutional/ReportHeader';
import { ReportStatusBadge } from '@/components/reports/institutional/ReportStatusBadge';
import { ReportDownloadButton } from '@/components/reports/institutional/ReportDownloadButton';
import { ReportMetadataPanel } from '@/components/reports/institutional/ReportMetadata';
import { ReportSummary } from '@/components/reports/institutional/ReportSummary';
import { ReportLoading } from '@/components/reports/institutional/ReportLoading';
import { ReportError } from '@/components/reports/institutional/ReportError';

const ACTIVE = new Set(['PENDING', 'PROCESSING']);
const when = (iso: string | null): string => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—');
const bytes = (n: number | null): string => (n === null ? '—' : n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);

/** /reports/:reportId — status (polled while generating), metadata, stored summary, download and delete. */
export const ReportDetails: React.FC = () => {
  const { reportId } = useParams<{ reportId: string }>();
  const navigate = useNavigate();
  const [report, setReport] = useState<InstitutionalReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const load = useCallback(async (silent = false) => {
    if (!reportId) return;
    if (!silent) setLoading(true);
    try {
      const res = await institutionalReportService.getReport(reportId);
      setReport(res.data);
      setError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The report could not be loaded.');
    } finally {
      if (!silent) setLoading(false);
    }
  }, [reportId]);

  useEffect(() => { void load(); }, [load]);

  useEffect(() => {
    if (!report || !ACTIVE.has(report.status)) return;
    const t = window.setInterval(() => { void load(true); }, 3000);
    return () => window.clearInterval(t);
  }, [report, load]);

  const handleDelete = async () => {
    if (!report) return;
    setDeleting(true);
    try {
      await institutionalReportService.deleteReport(report.id);
      navigate('/reports', { replace: true });
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'The report could not be deleted.');
      setConfirmDelete(false);
    } finally {
      setDeleting(false);
    }
  };

  if (loading && !report) return <ReportLoading message="Loading report…" />;
  if (error && !report) return <ReportError message={error} onRetry={() => load()} />;
  if (!report) return null;

  const active = ACTIVE.has(report.status);

  return (
    <div className="space-y-5">
      <ReportHeader
        title={report.report_label}
        subtitle={report.title}
        badge={<ReportStatusBadge status={report.status} expired={report.is_expired} />}
        backTo={{ to: '/reports', label: 'Back to My Reports' }}
        actions={
          <>
            <Button type="button" variant="outline" size="sm" onClick={() => navigate(`/reports/create?type=${report.report_type}&scope=${report.scope_type}${report.course ? `&course_id=${report.course.id}` : ''}${report.assessment ? `&assessment_id=${report.assessment.id}` : ''}${report.assessment_version ? `&assessment_version_id=${report.assessment_version.id}` : ''}`)} leftIcon={<Copy className="w-4 h-4" />}>
              Regenerate
            </Button>
            <ReportDownloadButton report={report} size="sm" onError={(m) => setNotice(m)} />
            <Button type="button" variant="ghost" size="sm" onClick={() => setConfirmDelete(true)} disabled={report.status === 'PROCESSING'} leftIcon={<Trash2 className="w-4 h-4" />} className="text-red-700 hover:bg-red-50">Delete</Button>
          </>
        }
      />

      {notice && <p role="status" className="text-xs text-sage-700 bg-sage-50 border border-sage-200 rounded-lg px-3 py-2">{notice}</p>}

      {active && (
        <div role="status" className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 flex items-center gap-3">
          <RefreshCw className="w-4 h-4 text-amber-800 animate-spin" />
          <div className="text-sm text-amber-900">
            <p className="font-semibold">{report.status === 'PENDING' ? 'Queued for generation' : 'Generating report…'}</p>
            <p className="text-xs">{report.is_async ? 'Large and institution-wide reports are generated in the background. This page refreshes automatically.' : 'This page refreshes automatically.'}</p>
          </div>
        </div>
      )}
      {report.status === 'FAILED' && <ReportError message={report.error_message ?? 'Report generation failed. Please try again.'} compact onRetry={() => navigate(`/reports/create?type=${report.report_type}&scope=${report.scope_type}`)} />}
      {report.status === 'COMPLETED' && report.is_expired && (
        <div role="status" className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 flex items-center gap-2"><Clock className="w-4 h-4" />This report expired on {when(report.expires_at)}. Use <strong>Regenerate</strong> to produce a fresh copy with current data.</div>
      )}
      {report.contains_student_data && (
        <p className="text-xs text-sage-600 inline-flex items-center gap-1.5"><Lock className="w-3.5 h-3.5" />Contains aggregated student performance only — no names, identifiers or individual answers.</p>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <Card className="lg:col-span-2">
          <CardHeader><CardTitle>File</CardTitle></CardHeader>
          <CardContent>
            <dl className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs">
              {[
                ['Format', report.format], ['File name', report.file_name ?? '—'], ['Size', bytes(report.file_size)],
                ['Records', report.record_count === null ? '—' : String(report.record_count)], ['Generated', when(report.generated_at)], ['Data as of', when(report.data_as_of)],
                ['Expires', report.file_deleted_at ? 'File removed' : when(report.expires_at)], ['Requested', when(report.created_at)], ['Mode', report.is_async ? 'Background (queued)' : 'Immediate'],
              ].map(([k, v]) => (
                <div key={k}><dt className="text-sage-500">{k}</dt><dd className="text-sage-800 font-medium break-all">{v}</dd></div>
              ))}
            </dl>
          </CardContent>
        </Card>
        <Card>
          <CardHeader><CardTitle>Contents</CardTitle></CardHeader>
          <CardContent>
            {report.summary?.tables?.length ? (
              <ul className="space-y-1 text-xs">
                {report.summary.tables.map((t) => <li key={t.key} className="flex justify-between gap-2"><span className="text-sage-700 truncate">{t.title}</span><span className="text-sage-500 tabular-nums">{t.rows} rows</span></li>)}
              </ul>
            ) : <p className="text-xs text-sage-500">Available after generation.</p>}
          </CardContent>
        </Card>
      </div>

      <ReportMetadataPanel
        fallback={{
          report_label: report.report_label, scope_type: report.scope_type, filters: report.filters, generated_by: report.created_by?.name ?? null,
          generated_at: report.generated_at, data_as_of: report.data_as_of, assessment: report.assessment?.title ?? null,
          assessment_version: report.assessment_version?.version_label ?? null, course: report.course ? `${report.course.code} — ${report.course.name}` : null,
          academic_year: (report.filters.academic_year as string | undefined) ?? report.course?.academic_year ?? null, semester: (report.filters.semester as string | undefined) ?? report.course?.semester ?? null,
        }}
        recordCount={report.record_count}
      />

      {report.summary?.items && <ReportSummary items={report.summary.items} title="Report Summary (as generated)" />}
      {report.summary?.warnings?.length ? (
        <Card variant="muted"><CardContent className="py-3"><p className="text-xs font-semibold text-sage-700 mb-1">Notes recorded at generation</p><ul className="list-disc pl-5 space-y-0.5">{report.summary.warnings.map((w, i) => <li key={i} className="text-xs text-sage-600">{w}</li>)}</ul></CardContent></Card>
      ) : null}

      {confirmDelete && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-sage-800/40 backdrop-blur-[2px] p-4" role="dialog" aria-modal="true" aria-labelledby="delete-report-title">
          <div className="w-full max-w-sm rounded-2xl bg-white border border-sage-200 shadow-2xl p-6 space-y-4">
            <h2 id="delete-report-title" className="font-serif text-2xl text-sage-800">Delete report?</h2>
            <p className="text-sm text-sage-600">The generated file will be removed. The audit trail is retained.</p>
            <div className="grid grid-cols-2 gap-3">
              <Button type="button" variant="outline" onClick={() => setConfirmDelete(false)} disabled={deleting}>No, keep it</Button>
              <Button type="button" variant="danger" onClick={handleDelete} isLoading={deleting}>Yes, delete</Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
