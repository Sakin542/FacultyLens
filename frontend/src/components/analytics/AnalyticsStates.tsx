import React, { useId, useState } from 'react';
import { AlertTriangle, BarChart3, Download, Info, Loader2, RefreshCw } from 'lucide-react';
import { Badge, BadgeVariant } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { Card } from '@/components/common/Card';
import { ApiError } from '@/services/api';
import { cn } from '@/utils/cn';
import { AnalyticsFilters as Filters, AnalyticsMeta, FilterOptions, TrendPeriod } from '@/types/analytics';

/** STEP 36: shared header, filter bar, state components and small chart primitives (no external chart library). */

export const fmtPct = (v: number | null | undefined, digits = 1): string => (v === null || v === undefined || Number.isNaN(v) ? 'N/A' : `${v.toFixed(digits)}%`);
export const fmtNum = (v: number | null | undefined, digits = 1): string => (v === null || v === undefined || Number.isNaN(v) ? 'N/A' : Number.isInteger(v) ? String(v) : v.toFixed(digits));
export const humanize = (s: string | null | undefined): string => (s ?? '').replace(/_/g, ' ').toLowerCase().replace(/^\w/, (c) => c.toUpperCase());

export const statusVariant = (status: string | null | undefined): BadgeVariant => {
  switch (status) {
    case 'STRONG': case 'ON_TARGET': case 'EXCELLENT': case 'GOOD': case 'BALANCED': case 'COVERED': case 'ASSESSED': case 'PASSED': case 'NOT_SIMILAR': return 'Good';
    case 'MINOR_GAP': case 'FAIR': case 'SLIGHTLY_UNBALANCED': case 'WEAK': case 'LIMITED_EVIDENCE': case 'PASSED_WITH_WARNINGS': case 'SOMEWHAT_SIMILAR': case 'NEEDS_REVIEW': case 'MODERATE_GAP': case 'HIGHLY_SIMILAR': return 'Attention';
    case 'HIGH_GAP': case 'REQUIRES_ATTENTION': case 'SIGNIFICANTLY_UNBALANCED': case 'NOT_ALIGNED': case 'FAILED': case 'POTENTIAL_DUPLICATE': return 'Critical';
    case 'INSUFFICIENT_DATA': case 'NOT_ASSESSED': case 'NOT_MAPPED': return 'neutral';
    default: return 'neutral';
  }
};

export function getAnalyticsErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.status === 401) return 'Your session has expired. Please sign in again.';
    if (err.status === 403) return err.message || 'You do not have access to this analytics scope.';
    if (err.status === 404) return 'The requested analytics resource was not found.';
    if (err.status === 422) return err.message || 'Please check the selected filters.';
    if (err.status === 429) return 'Too many requests. Please wait a moment and try again.';
    if (err.status >= 500) return 'Analytics are temporarily unavailable. Please try again.';
    return err.message || 'Something went wrong.';
  }
  return err instanceof Error ? err.message : 'Something went wrong.';
}

export const Explain: React.FC<{ text?: string; label?: string }> = ({ text, label = 'Explanation' }) => {
  if (!text) return null;
  return (
    <span className="relative inline-flex group align-middle ml-1">
      <button type="button" aria-label={label} className="text-[#A3A3A3] hover:text-[#111111] dark:hover:text-white focus:outline-none focus:ring-2 focus:ring-[#111111] rounded-full"><Info className="w-3.5 h-3.5" aria-hidden="true" /></button>
      <span role="tooltip" className="pointer-events-none absolute z-20 left-1/2 -translate-x-1/2 top-full mt-1 hidden group-hover:block group-focus-within:block w-64 rounded-md border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] p-2 text-xs text-left text-[#525252] dark:text-[#A3A3A3] shadow-lg">{text}</span>
    </span>
  );
};

export const Section: React.FC<{ title: string; explanation?: string; testId: string; children: React.ReactNode; actions?: React.ReactNode; className?: string; subtitle?: string }> = ({ title, explanation, testId, children, actions, className, subtitle }) => (
  <Card data-testid={testId} className={cn('p-4 space-y-3', className)}>
    <div className="flex items-start justify-between gap-2">
      <div><h3 className="text-sm font-semibold text-[#111111] dark:text-white inline-flex items-center">{title}<Explain text={explanation} /></h3>{subtitle && <p className="text-xs text-[#737373] mt-0.5">{subtitle}</p>}</div>
      {actions}
    </div>
    {children}
  </Card>
);

export const SectionEmpty: React.FC<{ title: string; description: string }> = ({ title, description }) => (
  <div data-testid="section-empty" className="rounded-lg border border-dashed border-[#E5E5E5] dark:border-[#2A2A2A] px-4 py-6 text-center">
    <p className="text-sm font-medium text-[#111111] dark:text-white">{title}</p>
    <p className="text-xs text-[#737373] mt-1 max-w-md mx-auto">{description}</p>
  </div>
);

/** Accessible horizontal bars with a textual value next to each bar. */
export const Bars: React.FC<{ rows: { label: string; value: number | null; display?: string; target?: number | null; tone?: 'default' | 'good' | 'warn' | 'bad'; count?: number }[]; max?: number; ariaLabel: string }> = ({ rows, max = 100, ariaLabel }) => (
  <ul aria-label={ariaLabel} className="space-y-1.5">
    {rows.map((r) => {
      const pct = r.value === null ? 0 : Math.min(100, Math.max(0, (r.value / max) * 100));
      const tone = r.tone === 'good' ? 'bg-[#16A34A]' : r.tone === 'warn' ? 'bg-amber-500' : r.tone === 'bad' ? 'bg-[#DC2626]' : 'bg-[#111111] dark:bg-white';
      return (
        <li key={r.label} className="grid grid-cols-[7rem_1fr_5rem] items-center gap-2 text-sm">
          <span className="truncate" title={r.label}>{r.label}</span>
          <span className="relative h-3 rounded bg-[#F0F0F0] dark:bg-[#2A2A2A]" aria-hidden="true">
            <span className={cn('absolute left-0 top-0 h-3 rounded', tone)} style={{ width: `${pct}%` }} />
            {r.target !== null && r.target !== undefined && <span className="absolute top-[-2px] h-4 w-0.5 bg-[#737373]" style={{ left: `${Math.min(100, (r.target / max) * 100)}%` }} title={`Target ${r.target}%`} />}
          </span>
          <span className="text-right tabular-nums text-xs">{r.display ?? (r.value === null ? 'N/A' : `${r.value.toFixed(1)}%`)}{r.count !== undefined && <span className="text-[#A3A3A3]"> ({r.count})</span>}</span>
        </li>
      );
    })}
  </ul>
);

/** Minimal SVG line chart with an accessible data table fallback. */
export const LineChart: React.FC<{ points: { label: string; date: string | null; value: number | null; note?: string }[]; max?: number; min?: number; unit?: string; ariaLabel: string; benchmark?: number | null }> = ({ points, max = 100, min = 0, unit = '', ariaLabel, benchmark }) => {
  const valid = points.filter((p) => p.value !== null);
  const w = 600; const h = 160; const pad = 24;
  const x = (i: number) => pad + (points.length <= 1 ? (w - 2 * pad) / 2 : (i / (points.length - 1)) * (w - 2 * pad));
  const y = (v: number) => h - pad - ((v - min) / (max - min || 1)) * (h - 2 * pad);
  const path = points.map((p, i) => (p.value === null ? null : `${x(i)},${y(p.value)}`)).filter(Boolean).join(' ');
  return (
    <div>
      {valid.length === 0 ? <p className="text-xs text-[#737373]">No dated data points.</p> : (
        <svg viewBox={`0 0 ${w} ${h}`} role="img" aria-label={ariaLabel} className="w-full h-40">
          <line x1={pad} y1={h - pad} x2={w - pad} y2={h - pad} stroke="#E5E5E5" />
          <line x1={pad} y1={pad} x2={pad} y2={h - pad} stroke="#E5E5E5" />
          {benchmark !== null && benchmark !== undefined && <line x1={pad} y1={y(benchmark)} x2={w - pad} y2={y(benchmark)} stroke="#A3A3A3" strokeDasharray="4 4" />}
          {path && <polyline points={path} fill="none" stroke="#111111" strokeWidth="2" className="dark:stroke-white" />}
          {points.map((p, i) => (p.value === null ? null : <circle key={i} cx={x(i)} cy={y(p.value)} r="3.5" fill={p.note ? '#A3A3A3' : '#111111'} className="dark:fill-white"><title>{`${p.label}${p.date ? ` (${p.date})` : ''}: ${p.value}${unit}${p.note ? ` — ${p.note}` : ''}`}</title></circle>))}
        </svg>
      )}
      <details className="mt-1">
        <summary className="text-xs text-[#737373] cursor-pointer">Data table</summary>
        <table className="w-full text-xs mt-1"><caption className="sr-only">{ariaLabel}</caption>
          <thead><tr className="text-left text-[#737373]"><th className="py-0.5 pr-2">Item</th><th className="py-0.5 pr-2">Date</th><th className="py-0.5 pr-2 text-right">Value</th><th className="py-0.5">Note</th></tr></thead>
          <tbody>{points.map((p, i) => <tr key={i} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A]"><td className="py-0.5 pr-2">{p.label}</td><td className="py-0.5 pr-2">{p.date ?? 'No date'}</td><td className="py-0.5 pr-2 text-right tabular-nums">{p.value === null ? 'N/A' : `${p.value}${unit}`}</td><td className="py-0.5 text-[#737373]">{p.note ?? ''}</td></tr>)}</tbody>
        </table>
      </details>
    </div>
  );
};

export const PERIODS: TrendPeriod[] = ['7D', '30D', '3M', '6M', '1Y', 'ALL'];
export const periodStart = (p: TrendPeriod, now = new Date()): Date | null => {
  const d = new Date(now);
  switch (p) {
    case '7D': d.setDate(d.getDate() - 7); return d;
    case '30D': d.setDate(d.getDate() - 30); return d;
    case '3M': d.setMonth(d.getMonth() - 3); return d;
    case '6M': d.setMonth(d.getMonth() - 6); return d;
    case '1Y': d.setFullYear(d.getFullYear() - 1); return d;
    default: return null;
  }
};
export const PeriodPicker: React.FC<{ value: TrendPeriod; onChange: (p: TrendPeriod) => void; label: string }> = ({ value, onChange, label }) => (
  <div role="group" aria-label={label} className="flex gap-1">
    {PERIODS.map((p) => <button key={p} type="button" aria-pressed={value === p} onClick={() => onChange(p)} className={cn('px-2 py-0.5 rounded text-xs border', value === p ? 'bg-[#111111] text-white border-[#111111] dark:bg-white dark:text-black' : 'border-[#E5E5E5] dark:border-[#2A2A2A] text-[#525252] dark:text-[#A3A3A3]')}>{p}</button>)}
  </div>
);

export const freshness = (meta: AnalyticsMeta | null | undefined): string => {
  if (!meta?.generated_at) return '';
  const generated = new Date(meta.generated_at);
  const mins = Math.max(0, Math.round((Date.now() - generated.getTime()) / 60000));
  const ago = mins < 1 ? 'just now' : mins === 1 ? '1 minute ago' : mins < 60 ? `${mins} minutes ago` : generated.toLocaleString();
  const ttl = meta.cache_ttl_seconds ? ` Data may be up to ${Math.ceil(meta.cache_ttl_seconds / 60)} minutes old.` : '';
  return `Last calculated ${ago}.${meta.cached ? ttl : ''}`;
};

export const AnalyticsHeader: React.FC<{ meta: AnalyticsMeta | null; onRefresh: () => void; onExport: (f: 'pdf' | 'csv') => void; busy?: boolean }> = ({ meta, onRefresh, onExport, busy }) => (
  <header data-testid="analytics-header" className="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
    <div>
      <div className="flex items-center gap-2 mb-1"><BarChart3 className="w-5 h-5" aria-hidden="true" /><h1 className="text-2xl font-bold text-[#111111] dark:text-white">Academic Analytics</h1></div>
      <p className="text-sm text-[#737373] max-w-2xl">Evidence, trends and signals from your assessments, outcomes, student performance and AI activity. Analytics never change assessments, grades, mappings or models.</p>
      {meta && <p className="text-xs text-[#A3A3A3] mt-1" data-testid="freshness" role="status">{freshness(meta)}</p>}
    </div>
    <div className="flex items-center gap-2">
      <Button variant="outline" size="sm" onClick={() => onExport('pdf')} disabled={busy}><Download className="w-3.5 h-3.5 mr-1" aria-hidden="true" />PDF</Button>
      <Button variant="outline" size="sm" onClick={() => onExport('csv')} disabled={busy}><Download className="w-3.5 h-3.5 mr-1" aria-hidden="true" />CSV</Button>
      <Button variant="outline" size="sm" onClick={onRefresh} disabled={busy} aria-label="Recalculate analytics"><RefreshCw className={cn('w-3.5 h-3.5 mr-1', busy && 'animate-spin')} aria-hidden="true" />Refresh</Button>
    </div>
  </header>
);

const Select: React.FC<{ id: string; label: string; value: string; onChange: (v: string) => void; options: { value: string; label: string }[]; allLabel: string }> = ({ id, label, value, onChange, options, allLabel }) => (
  <div className="space-y-1">
    <label htmlFor={id} className="block text-xs font-medium uppercase tracking-wider text-[#525252] dark:text-[#A3A3A3]">{label}</label>
    <select id={id} value={value} onChange={(e) => onChange(e.target.value)} className="w-full rounded-md border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-2 py-1.5 text-sm">
      <option value="">{allLabel}</option>{options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
    </select>
  </div>
);

export const AnalyticsFilters: React.FC<{ options: FilterOptions | null; value: Filters; onApply: (f: Filters) => void; onReset: () => void; disabled?: boolean }> = ({ options, value, onApply, onReset, disabled }) => {
  const [draft, setDraft] = useState<Filters>(value);
  const id = useId();
  React.useEffect(() => setDraft(value), [value]);
  const courseId = draft.course_id ? Number(draft.course_id) : null;
  const assessments = (options?.assessments ?? []).filter((a) => !courseId || a.course_id === courseId);
  return (
    <form data-testid="analytics-filters" noValidate aria-label="Analytics filters" onSubmit={(e) => { e.preventDefault(); onApply(draft); }} className="rounded-xl border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] p-4">
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 gap-3">
        <Select id={`${id}-course`} label="Course" value={draft.course_id ? String(draft.course_id) : ''} onChange={(v) => setDraft({ ...draft, course_id: v ? Number(v) : '', assessment_id: '' })} allLabel="All Courses"
          options={(options?.courses ?? []).map((c) => ({ value: String(c.id), label: `${c.code} · ${c.name}${c.semester ? ` (${c.semester} ${c.academic_year ?? ''})` : ''}` }))} />
        <Select id={`${id}-assessment`} label="Assessment" value={draft.assessment_id ? String(draft.assessment_id) : ''} onChange={(v) => setDraft({ ...draft, assessment_id: v ? Number(v) : '' })} allLabel="All Assessments"
          options={assessments.map((a) => ({ value: String(a.id), label: a.title }))} />
        <Select id={`${id}-semester`} label="Semester" value={draft.semester ?? ''} onChange={(v) => setDraft({ ...draft, semester: v })} allLabel="All Semesters" options={(options?.semesters ?? []).map((s) => ({ value: s, label: s }))} />
        <Select id={`${id}-year`} label="Academic Year" value={draft.academic_year ?? ''} onChange={(v) => setDraft({ ...draft, academic_year: v })} allLabel="All Years" options={(options?.academic_years ?? []).map((s) => ({ value: s, label: s }))} />
        <Select id={`${id}-type`} label="Assessment Type" value={draft.assessment_type ?? ''} onChange={(v) => setDraft({ ...draft, assessment_type: v })} allLabel="All Types" options={(options?.assessment_types ?? []).map((s) => ({ value: s, label: humanize(s) }))} />
        <div className="space-y-1"><label htmlFor={`${id}-start`} className="block text-xs font-medium uppercase tracking-wider text-[#525252] dark:text-[#A3A3A3]">Start</label><input id={`${id}-start`} type="date" value={draft.start_date ?? ''} onChange={(e) => setDraft({ ...draft, start_date: e.target.value })} className="w-full rounded-md border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-2 py-1.5 text-sm" /></div>
        <div className="space-y-1"><label htmlFor={`${id}-end`} className="block text-xs font-medium uppercase tracking-wider text-[#525252] dark:text-[#A3A3A3]">End</label><input id={`${id}-end`} type="date" value={draft.end_date ?? ''} onChange={(e) => setDraft({ ...draft, end_date: e.target.value })} className="w-full rounded-md border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-2 py-1.5 text-sm" /></div>
      </div>
      <div className="flex justify-end gap-2 mt-3">
        <Button type="button" variant="ghost" size="sm" onClick={() => { setDraft({}); onReset(); }} disabled={disabled}>Reset</Button>
        <Button type="submit" size="sm" disabled={disabled}>Apply Filters</Button>
      </div>
    </form>
  );
};

export const AnalyticsLoading: React.FC<{ label?: string }> = ({ label = 'Loading analytics…' }) => (
  <div data-testid="analytics-loading" role="status" className="flex items-center gap-2 rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-4 py-6 text-sm text-[#525252] dark:text-[#A3A3A3]">
    <Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" /><span>{label}</span>
  </div>
);

export const AnalyticsError: React.FC<{ message: string; onRetry?: () => void; className?: string }> = ({ message, onRetry, className }) => (
  <div data-testid="analytics-error" role="alert" className={cn('flex items-start gap-2 rounded-lg border border-red-200 dark:border-red-900/50 bg-red-50 dark:bg-red-950/30 px-3 py-2 text-sm text-red-700 dark:text-red-300', className)}>
    <AlertTriangle className="w-4 h-4 mt-0.5 flex-shrink-0" aria-hidden="true" />
    <div className="flex-1"><p>{message}</p>{onRetry && <button type="button" onClick={onRetry} className="mt-1 text-xs font-medium underline underline-offset-2">Retry</button>}</div>
  </div>
);

export const AnalyticsEmptyState: React.FC<{ title?: string; description?: string }> = ({ title = 'No assessment data available.', description = 'Create and analyze an assessment to see academic quality analytics here.' }) => (
  <div data-testid="analytics-empty-state" className="flex flex-col items-center justify-center text-center py-12 px-6 rounded-xl border border-dashed border-[#E5E5E5] dark:border-[#2A2A2A]">
    <div className="w-12 h-12 rounded-full bg-[#F7F7F5] dark:bg-[#1F1F1F] border border-[#E5E5E5] dark:border-[#2A2A2A] flex items-center justify-center text-[#737373] mb-4"><BarChart3 className="w-6 h-6" aria-hidden="true" /></div>
    <h4 className="text-base font-semibold text-[#111111] dark:text-white mb-1">{title}</h4>
    <p className="text-sm text-[#737373] max-w-md">{description}</p>
  </div>
);

export const StatusBadge: React.FC<{ status: string | null | undefined }> = ({ status }) => (status ? <Badge variant={statusVariant(status)} size="sm">{humanize(status)}</Badge> : <span className="text-xs text-[#A3A3A3]">N/A</span>);
