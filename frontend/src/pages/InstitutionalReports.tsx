import React, { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus, RefreshCw } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { institutionalReportService } from '@/services/institutionalReportService';
import { InstitutionalReport, ReportListResponse } from '@/types/report';
import { ReportHeader } from '@/components/reports/institutional/ReportHeader';
import { ReportHistory } from '@/components/reports/institutional/ReportHistory';
import { ReportEmptyState } from '@/components/reports/institutional/ReportEmptyState';
import { ReportLoading } from '@/components/reports/institutional/ReportLoading';
import { ReportError } from '@/components/reports/institutional/ReportError';

const ACTIVE = new Set(['PENDING', 'PROCESSING']);

/** /reports — "My Reports" history with polling while any report is still generating. */
export const InstitutionalReports: React.FC = () => {
  const navigate = useNavigate();
  const [data, setData] = useState<ReportListResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState('');
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [confirm, setConfirm] = useState<InstitutionalReport | null>(null);

  const load = useCallback(async (silent = false) => {
    if (!silent) setLoading(true);
    setError(null);
    try {
      const res = await institutionalReportService.getReports({ page, status: statusFilter || undefined, per_page: 20 });
      setData(res.data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Reports could not be loaded.');
    } finally {
      if (!silent) setLoading(false);
    }
  }, [page, statusFilter]);

  useEffect(() => { void load(); }, [load]);

  // Poll while background generations are running
  useEffect(() => {
    if (!data?.items.some((r) => ACTIVE.has(r.status))) return;
    const t = window.setInterval(() => { void load(true); }, 4000);
    return () => window.clearInterval(t);
  }, [data, load]);

  const handleDelete = async (report: InstitutionalReport) => {
    setDeletingId(report.id);
    setNotice(null);
    try {
      await institutionalReportService.deleteReport(report.id);
      setConfirm(null);
      setNotice('Report deleted.');
      await load(true);
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'The report could not be deleted.');
    } finally {
      setDeletingId(null);
    }
  };

  return (
    <div className="space-y-5">
      <ReportHeader
        title="Institutional Reports"
        subtitle="Generate, preview and export institution-ready evidence: quality, coverage, performance, rubrics, grading, AI evaluation and version history."
        actions={
          <>
            <Button type="button" variant="outline" size="sm" onClick={() => load()} leftIcon={<RefreshCw className="w-4 h-4" />} disabled={loading}>Refresh</Button>
            <Button type="button" variant="primary" size="sm" onClick={() => navigate('/reports/create')} leftIcon={<Plus className="w-4 h-4" />}>Create report</Button>
          </>
        }
      />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <h3 className="text-sm font-semibold text-sage-800">My Reports {data ? <span className="text-sage-400 font-normal">({data.pagination.total})</span> : null}</h3>
        <label className="text-xs text-sage-600 inline-flex items-center gap-2">
          Status
          <select value={statusFilter} onChange={(e) => { setPage(1); setStatusFilter(e.target.value); }} className="rounded-lg border border-sage-200 bg-white px-2.5 py-1.5 text-xs text-sage-800" aria-label="Filter by status">
            <option value="">All</option>
            {['PENDING', 'PROCESSING', 'COMPLETED', 'FAILED', 'CANCELLED'].map((s) => <option key={s} value={s}>{s.charAt(0) + s.slice(1).toLowerCase()}</option>)}
          </select>
        </label>
      </div>

      {notice && <p role="status" className="text-xs text-sage-700 bg-sage-50 border border-sage-200 rounded-lg px-3 py-2">{notice}</p>}

      {loading && !data ? (
        <ReportLoading />
      ) : error ? (
        <ReportError message={error} onRetry={() => load()} />
      ) : !data || data.items.length === 0 ? (
        statusFilter ? (
          <ReportEmptyState title="No reports match this status" description="Try another status filter or create a new report." />
        ) : (
          <ReportEmptyState />
        )
      ) : (
        <>
          <ReportHistory reports={data.items} onDelete={(r) => setConfirm(r)} deletingId={deletingId} onError={(m) => setNotice(m)} />
          {data.pagination.last_page > 1 && (
            <div className="flex items-center justify-between text-xs text-sage-600">
              <span>Page {data.pagination.current_page} of {data.pagination.last_page}</span>
              <div className="flex gap-2">
                <Button type="button" variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
                <Button type="button" variant="outline" size="sm" disabled={page >= data.pagination.last_page} onClick={() => setPage((p) => p + 1)}>Next</Button>
              </div>
            </div>
          )}
        </>
      )}

      {confirm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-sage-800/40 backdrop-blur-[2px] p-4" role="dialog" aria-modal="true" aria-labelledby="delete-report-title">
          <div className="w-full max-w-sm rounded-2xl bg-white border border-sage-200 shadow-2xl p-6 space-y-4">
            <h2 id="delete-report-title" className="font-serif text-2xl text-sage-800">Delete report?</h2>
            <p className="text-sm text-sage-600">“{confirm.report_label}” and its generated file will be removed. The audit trail is retained.</p>
            <div className="grid grid-cols-2 gap-3">
              <Button type="button" variant="outline" onClick={() => setConfirm(null)} disabled={deletingId !== null}>No, keep it</Button>
              <Button type="button" variant="danger" onClick={() => handleDelete(confirm)} isLoading={deletingId === confirm.id}>Yes, delete</Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
