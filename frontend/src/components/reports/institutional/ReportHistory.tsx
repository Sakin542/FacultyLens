import React from 'react';
import { Link } from 'react-router-dom';
import { Eye, Trash2 } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { InstitutionalReport } from '@/types/report';
import { ReportStatusBadge } from './ReportStatusBadge';
import { ReportDownloadButton } from './ReportDownloadButton';
import { SCOPE_LABELS } from './ReportScopeSelector';

const when = (iso: string | null): string => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—');

const scopeText = (r: InstitutionalReport): string => {
  switch (r.scope_type) {
    case 'ASSESSMENT_VERSION': return `${r.course?.code ?? ''} · ${r.assessment?.title ?? ''} · ${r.assessment_version?.version_label ?? ''}`;
    case 'ASSESSMENT': return `${r.course?.code ?? ''} · ${r.assessment?.title ?? ''}`;
    case 'COURSE': return `${r.course?.code ?? ''} — ${r.course?.name ?? ''}`;
    case 'DEPARTMENT': return `Department: ${r.department ?? ''}`;
    default: return SCOPE_LABELS[r.scope_type];
  }
};

interface ReportHistoryProps {
  reports: InstitutionalReport[];
  onDelete: (report: InstitutionalReport) => void;
  deletingId?: number | null;
  onError?: (message: string) => void;
}

/** "My Reports": Report · Scope · Format · Status · Generated · Expires · Actions (View / Download / Delete). */
export const ReportHistory: React.FC<ReportHistoryProps> = ({ reports, onDelete, deletingId, onError }) => (
  <div className="rounded-xl border border-sage-200 bg-white overflow-hidden" data-testid="report-history">
    <div className="overflow-x-auto">
      <table className="min-w-full text-left">
        <thead>
          <tr className="bg-sage-50 border-b border-sage-200">
            {['Report', 'Scope', 'Format', 'Status', 'Generated', 'Expires', 'Actions'].map((h) => (
              <th key={h} scope="col" className={`px-4 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-sage-500 whitespace-nowrap ${h === 'Actions' ? 'text-right' : ''}`}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {reports.map((r) => (
            <tr key={r.id} className="border-b border-sage-100 last:border-0 hover:bg-sage-50/60" data-testid={`report-row-${r.id}`}>
              <td className="px-4 py-3 align-top min-w-[14rem]">
                <Link to={`/reports/${r.id}`} className="text-sm font-medium text-sage-800 hover:text-sage-600 hover:underline">{r.report_label}</Link>
                <p className="text-[11px] text-sage-500 mt-0.5">{r.record_count !== null ? `${r.record_count} records` : 'Pending'}{r.contains_student_data ? ' · aggregated student data' : ''}</p>
              </td>
              <td className="px-4 py-3 align-top text-xs text-sage-700 max-w-[18rem]"><span className="block truncate" title={scopeText(r)}>{scopeText(r)}</span><span className="text-[11px] text-sage-400">{SCOPE_LABELS[r.scope_type]}</span></td>
              <td className="px-4 py-3 align-top text-xs font-mono text-sage-700">{r.format}</td>
              <td className="px-4 py-3 align-top"><ReportStatusBadge status={r.status} expired={r.is_expired} /></td>
              <td className="px-4 py-3 align-top text-xs text-sage-700 whitespace-nowrap">{when(r.generated_at)}</td>
              <td className="px-4 py-3 align-top text-xs text-sage-700 whitespace-nowrap">{r.file_deleted_at ? 'File removed' : when(r.expires_at)}</td>
              <td className="px-4 py-3 align-top">
                <div className="flex items-center justify-end gap-1.5">
                  <Link to={`/reports/${r.id}`} className="inline-flex items-center gap-1 rounded-lg border border-sage-200 bg-white px-2.5 py-1.5 text-xs font-medium text-sage-800 hover:bg-sage-100" aria-label={`View report ${r.id}`}><Eye className="w-3.5 h-3.5" />View</Link>
                  <ReportDownloadButton report={r} onError={onError} />
                  <Button type="button" variant="ghost" size="sm" onClick={() => onDelete(r)} isLoading={deletingId === r.id} disabled={r.status === 'PROCESSING'} aria-label={`Delete report ${r.id}`} title={r.status === 'PROCESSING' ? 'Cannot delete while generating' : 'Delete'} className="text-red-700 hover:bg-red-50">
                    <Trash2 className="w-4 h-4" />
                  </Button>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  </div>
);
