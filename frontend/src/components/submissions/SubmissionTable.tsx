import React from 'react';
import { Link } from 'react-router-dom';
import { ChevronLeft, ChevronRight, Eye, Trash2 } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { PaginationMeta, StudentSubmission } from '@/types/submission';
import { GradingStatusBadge, SubmissionStatusBadge } from './SubmissionStatusBadge';

interface SubmissionTableProps {
  submissions: StudentSubmission[];
  meta: PaginationMeta;
  onPageChange: (page: number) => void;
  onDelete?: (submission: StudentSubmission) => void;
  deletingId?: number | null;
}

export const formatDate = (iso?: string | null) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' });
};

export const formatMarksPair = (awarded?: number | null, total?: number | null) => {
  const t = total !== null && total !== undefined ? String(total) : '—';
  if (awarded === null || awarded === undefined) return `Not graded / ${t}`;
  return `${awarded} / ${t}`;
};

export const SubmissionTable: React.FC<SubmissionTableProps> = ({ submissions, meta, onPageChange, onDelete, deletingId }) => (
  <div className="space-y-3" data-testid="submission-table">
    {/* Desktop table */}
    <div className="hidden md:block overflow-x-auto rounded-xl border border-[#E5E5E5] dark:border-[#2C2C2E] bg-white dark:bg-[#1C1C1E]">
      <table className="w-full text-xs">
        <thead className="bg-[#F7F7F5] dark:bg-[#2C2C2E] text-[10px] uppercase tracking-wider text-[#737373]">
          <tr>
            <th className="text-left px-4 py-2.5 font-semibold">Student</th>
            <th className="text-left px-4 py-2.5 font-semibold">Submission</th>
            <th className="text-left px-4 py-2.5 font-semibold">Submitted</th>
            <th className="text-left px-4 py-2.5 font-semibold">Answers</th>
            <th className="text-left px-4 py-2.5 font-semibold">Status</th>
            <th className="text-left px-4 py-2.5 font-semibold">Grading</th>
            <th className="text-right px-4 py-2.5 font-semibold">Marks</th>
            <th className="px-4 py-2.5" />
          </tr>
        </thead>
        <tbody className="divide-y divide-[#E5E5E5] dark:divide-[#2C2C2E]">
          {submissions.map((s) => (
            <tr key={s.id} className="hover:bg-[#F7F7F5]/60 dark:hover:bg-[#2C2C2E]/50" data-testid="submission-row">
              <td className="px-4 py-3">
                <div className="font-semibold text-[#111111] dark:text-white">{s.student?.name ?? 'Unknown student'}</div>
                <div className="font-mono text-[11px] text-[#737373]">{s.student?.student_identifier}</div>
              </td>
              <td className="px-4 py-3 font-mono text-[#262626] dark:text-[#E5E5E5]">{s.submission_identifier || `#${s.id}`}</td>
              <td className="px-4 py-3 text-[#262626] dark:text-[#E5E5E5]">{formatDate(s.submitted_at)}</td>
              <td className="px-4 py-3 font-mono text-[#262626] dark:text-[#E5E5E5]">
                {s.answers_count}
                {s.reviewed_answers_count !== undefined && s.answers_count > 0 && (
                  <span className="text-[#737373]"> ({s.reviewed_answers_count} reviewed)</span>
                )}
              </td>
              <td className="px-4 py-3"><SubmissionStatusBadge status={s.status} /></td>
              <td className="px-4 py-3"><GradingStatusBadge status={s.grading_status} /></td>
              <td className="px-4 py-3 text-right font-mono font-semibold text-[#111111] dark:text-white">{formatMarksPair(s.awarded_marks, s.total_marks)}</td>
              <td className="px-4 py-3">
                <div className="flex items-center justify-end gap-1">
                  <Link to={`/submissions/${s.id}`}>
                    <Button variant="outline" size="sm" leftIcon={<Eye className="w-3.5 h-3.5" />}>View</Button>
                  </Link>
                  {onDelete && (
                    <button
                      type="button"
                      onClick={() => onDelete(s)}
                      disabled={deletingId === s.id}
                      aria-label={`Delete submission of ${s.student?.student_identifier ?? s.id}`}
                      className="p-1.5 rounded-lg text-[#737373] hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30 disabled:opacity-40"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                  )}
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>

    {/* Mobile cards */}
    <ul className="md:hidden space-y-2">
      {submissions.map((s) => (
        <li key={s.id} className="p-3 rounded-xl border border-[#E5E5E5] dark:border-[#2C2C2E] bg-white dark:bg-[#1C1C1E] space-y-2" data-testid="submission-card">
          <div className="flex items-start justify-between gap-2">
            <div>
              <p className="text-xs font-semibold text-[#111111] dark:text-white">{s.student?.name ?? 'Unknown student'}</p>
              <p className="font-mono text-[11px] text-[#737373]">{s.student?.student_identifier} · {s.submission_identifier || `#${s.id}`}</p>
            </div>
            <Link to={`/submissions/${s.id}`}>
              <Button variant="outline" size="sm">View</Button>
            </Link>
          </div>
          <div className="flex flex-wrap items-center gap-1.5">
            <SubmissionStatusBadge status={s.status} />
            <GradingStatusBadge status={s.grading_status} />
            <span className="text-[11px] text-[#737373]">{formatDate(s.submitted_at)}</span>
            <span className="ml-auto text-[11px] font-mono font-semibold text-[#111111] dark:text-white">{formatMarksPair(s.awarded_marks, s.total_marks)}</span>
          </div>
        </li>
      ))}
    </ul>

    {meta.last_page > 1 && (
      <div className="flex items-center justify-between p-3 bg-white dark:bg-[#1C1C1E] rounded-xl border border-[#E5E5E5] dark:border-[#2C2C2E] text-xs" data-testid="submission-pagination">
        <div className="text-[#737373]">
          Page <span className="font-semibold text-[#111111] dark:text-white">{meta.current_page}</span> of{' '}
          <span className="font-semibold text-[#111111] dark:text-white">{meta.last_page}</span> ({meta.total} total)
        </div>
        <div className="flex items-center gap-2">
          <Button variant="secondary" size="sm" disabled={meta.current_page <= 1} onClick={() => onPageChange(meta.current_page - 1)}>
            <ChevronLeft className="w-3.5 h-3.5 mr-1" /> Previous
          </Button>
          <Button variant="secondary" size="sm" disabled={meta.current_page >= meta.last_page} onClick={() => onPageChange(meta.current_page + 1)}>
            Next <ChevronRight className="w-3.5 h-3.5 ml-1" />
          </Button>
        </div>
      </div>
    )}
  </div>
);
