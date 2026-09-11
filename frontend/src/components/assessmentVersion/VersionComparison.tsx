import React from 'react';
import { Card } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import { VersionComparison as VersionComparisonData } from '@/types/assessmentVersion';
import { QuestionChangeList } from './QuestionChangeList';
import { BlueprintChangeList } from './BlueprintChangeList';
import { VersionStatusBadge } from './VersionStatusBadge';
import { fmtMarks, fmtSigned, statusVariant } from './versionUtils';

const show = (v: unknown): string => (v === null || v === undefined || v === '' ? '—' : typeof v === 'number' ? fmtMarks(v) : String(v));

/** STEP 38: full comparison view — summary, metadata, marks, questions, blueprint, mappings and STEP 13 analysis. */
export const VersionComparison: React.FC<{ comparison: VersionComparisonData }> = ({ comparison: c }) => {
  const from = c.from.version_label;
  const to = c.to.version_label;
  return (
    <div data-testid="version-comparison" className="space-y-4">
      <Card className="p-4 space-y-3">
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <span className="font-semibold">{from}</span><VersionStatusBadge status={c.from.status} /><span className="text-sage-500">vs</span><span className="font-semibold">{to}</span><VersionStatusBadge status={c.to.status} />
          <Badge variant={c.summary.detected_change_type === 'NONE' ? 'neutral' : c.summary.detected_change_type === 'MAJOR' ? 'Attention' : 'Good'} className="ml-auto">Detected: {c.summary.detected_change_type === 'NONE' ? 'no changes' : `${c.summary.detected_change_type.toLowerCase()} change`}</Badge>
        </div>
        <dl data-testid="comparison-summary" className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-2 text-xs">
          {([
            ['Changed', c.summary.modified, 'MODIFIED'], ['Added', c.summary.added, 'ADDED'], ['Removed', c.summary.removed, 'REMOVED'], ['Unchanged', c.summary.unchanged, 'UNCHANGED'],
          ] as const).map(([l, n, s]) => (
            <div key={l} className="rounded-md border border-sage-200 dark:border-[#2A2A2A] px-2 py-1.5"><dt className="text-sage-500">{l}</dt><dd className="font-semibold tabular-nums"><Badge variant={statusVariant(s)} size="sm">{n}</Badge></dd></div>
          ))}
          <div className="rounded-md border border-sage-200 dark:border-[#2A2A2A] px-2 py-1.5"><dt className="text-sage-500">Questions</dt><dd className="font-semibold tabular-nums">{c.summary.total_from} → {c.summary.total_to} ({fmtSigned(c.summary.question_count_difference)})</dd></div>
          <div className="rounded-md border border-sage-200 dark:border-[#2A2A2A] px-2 py-1.5"><dt className="text-sage-500">Total marks</dt><dd className="font-semibold tabular-nums">{fmtMarks(c.marks.total.from)} → {fmtMarks(c.marks.total.to)} ({fmtSigned(c.marks.total.difference)})</dd></div>
          <div className="rounded-md border border-sage-200 dark:border-[#2A2A2A] px-2 py-1.5"><dt className="text-sage-500">Metadata fields</dt><dd className="font-semibold tabular-nums">{c.summary.metadata_changes} changed</dd></div>
        </dl>
      </Card>

      <Card data-testid="metadata-comparison" className="p-4">
        <h3 className="text-sm font-semibold text-sage-800 dark:text-white mb-2">Metadata</h3>
        <table className="w-full text-xs">
          <thead><tr className="text-sage-500"><th className="text-left font-medium py-0.5">Field</th><th className="text-left font-medium">{from}</th><th className="text-left font-medium">{to}</th></tr></thead>
          <tbody>{c.metadata.map((m) => <tr key={m.field} className={`border-t border-sage-100 dark:border-[#2A2A2A] ${m.changed ? 'font-medium' : 'text-sage-500'}`}><td className="py-0.5">{m.label}{m.changed && <span className="sr-only"> changed</span>}</td><td className="truncate max-w-[16rem]">{show(m.from)}</td><td className="truncate max-w-[16rem]">{show(m.to)}</td></tr>)}</tbody>
        </table>
      </Card>

      <Card data-testid="marks-comparison" className="p-4 space-y-2">
        <h3 className="text-sm font-semibold text-sage-800 dark:text-white">Marks</h3>
        <p className="text-sm">Total marks: <strong>{from}</strong> {fmtMarks(c.marks.total.from)} · <strong>{to}</strong> {fmtMarks(c.marks.total.to)} · Difference <strong className={c.marks.total.difference === 0 ? '' : c.marks.total.difference > 0 ? 'text-[#166534]' : 'text-[#991B1B]'}>{fmtSigned(c.marks.total.difference)}</strong></p>
        {c.marks.items.length > 0 && (
          <ul className="flex flex-wrap gap-2 text-xs">{c.marks.items.map((m) => <li key={`${m.status}-${m.question_number}`} className="rounded-md border border-sage-200 dark:border-[#2A2A2A] px-2 py-1">Q{m.question_number}: {show(m.from)} → {show(m.to)} <span className="text-sage-500">({m.status === 'ADDED' ? 'added' : m.status === 'REMOVED' ? 'removed' : fmtSigned(m.difference)})</span></li>)}</ul>
        )}
      </Card>

      <QuestionChangeList items={c.questions.items} fromLabel={from} toLabel={to} />
      <BlueprintChangeList blueprint={c.blueprint} fromLabel={from} toLabel={to} />

      <Card data-testid="mappings-comparison" className="p-4 space-y-2">
        <h3 className="text-sm font-semibold text-sage-800 dark:text-white">CO / PO coverage</h3>
        {(['learning_outcomes', 'program_outcomes'] as const).map((k) => {
          const d = c.mappings[k];
          return (
            <p key={k} className="text-xs text-sage-600 dark:text-sage-400"><span className="font-medium text-sage-700 dark:text-sage-300">{k === 'learning_outcomes' ? 'Course outcomes' : 'Program outcomes'}:</span> {d.to.length ? d.to.join(', ') : 'none'}
              {d.added.length > 0 && <span className="text-[#166534]"> · added {d.added.join(', ')}</span>}{d.removed.length > 0 && <span className="text-[#991B1B]"> · removed {d.removed.join(', ')}</span>}</p>
          );
        })}
        {c.mappings.items.length > 0 && <p className="text-xs text-sage-500">{c.mappings.items.length} question mapping change{c.mappings.items.length === 1 ? '' : 's'} (see question changes).</p>}
      </Card>

      <Card data-testid="analysis-comparison" className="p-4 space-y-2">
        <h3 className="text-sm font-semibold text-sage-800 dark:text-white">Analysis (STEP 13 metrics)</h3>
        {!c.analysis.available ? <p className="text-sm text-sage-500">{c.analysis.note}</p> : (
          <>
            <table className="w-full text-xs">
              <thead><tr className="text-sage-500"><th className="text-left font-medium py-0.5">Metric</th><th className="text-right font-medium">{from}{c.analysis.from?.status === 'STALE' ? ' (stale)' : ''}</th><th className="text-right font-medium">{to}{c.analysis.to?.status === 'STALE' ? ' (stale)' : ''}</th><th className="text-right font-medium">Change</th></tr></thead>
              <tbody>{c.analysis.metrics.map((m) => <tr key={m.key} className="border-t border-sage-100 dark:border-[#2A2A2A]"><td className="py-0.5">{m.label}</td><td className="text-right tabular-nums">{show(m.from)}</td><td className="text-right tabular-nums">{show(m.to)}</td><td className={`text-right tabular-nums ${m.difference && m.difference !== 0 ? (m.difference > 0 ? 'text-[#166534]' : 'text-[#991B1B]') : 'text-sage-500'}`}>{m.difference === null ? '—' : fmtSigned(m.difference)}</td></tr>)}</tbody>
            </table>
            <p className="text-[11px] text-sage-500">{c.analysis.note}</p>
          </>
        )}
      </Card>
    </div>
  );
};
