import React, { useEffect, useId, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  AlertTriangle, ArrowDownRight, ArrowUpRight, CheckCircle2, ChevronLeft, ChevronRight, CircleDashed, CircleSlash, HelpCircle,
  Inbox, Loader2, Lock, Minus, RefreshCw, WifiOff, XCircle,
} from 'lucide-react';
import { cn } from '@/utils/cn';
import { ApiError } from '@/services/api';
import type { PageMeta, ServiceState } from '../types';

// ------------------------------------------------------------------------------------------- formatting

export const fmt = (n: number | null | undefined, digits = 0) =>
  n === null || n === undefined ? '—' : n.toLocaleString(undefined, { maximumFractionDigits: digits });

export const fmtDateTime = (iso: string | null | undefined) =>
  iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';

export const fmtDate = (iso: string | null | undefined) =>
  iso ? new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '—';

export const timeAgo = (iso: string | null | undefined): string => {
  if (!iso) return 'never';
  const s = (Date.now() - new Date(iso).getTime()) / 1000;
  if (s < 0) {
    const f = -s;
    if (f < 3600) return `in ${Math.max(1, Math.round(f / 60))} min`;
    if (f < 86400) return `in ${Math.round(f / 3600)} h`;
    return `in ${Math.round(f / 86400)} d`;
  }
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)} min ago`;
  if (s < 86400) return `${Math.floor(s / 3600)} h ago`;
  return `${Math.floor(s / 86400)} d ago`;
};

export const humanize = (value: string | null | undefined): string =>
  (value ?? '').toLowerCase().replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

export const formatBytes = (bytes: number | null | undefined): string => {
  if (bytes === null || bytes === undefined) return '—';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`;
  if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
  return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
};

// ------------------------------------------------------------------------------------------- layout primitives

export const PageHeader: React.FC<{ title: string; description?: React.ReactNode; actions?: React.ReactNode; meta?: React.ReactNode }> = ({ title, description, actions, meta }) => (
  <header className="flex flex-wrap items-end justify-between gap-4">
    <div className="min-w-0">
      <h1 className="font-serif text-2xl tracking-tight text-adm-ink">{title}</h1>
      {description && <p className="mt-1 max-w-3xl text-sm text-adm-muted">{description}</p>}
      {meta && <div className="mt-2 text-xs text-adm-muted">{meta}</div>}
    </div>
    {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
  </header>
);

export const Card: React.FC<{
  title?: React.ReactNode; description?: React.ReactNode; actions?: React.ReactNode; children: React.ReactNode;
  className?: string; bodyClassName?: string; testId?: string;
}> = ({ title, description, actions, children, className, bodyClassName, testId }) => (
  <section className={cn('rounded-xl border border-adm-line bg-adm-surface shadow-subtle', className)} data-testid={testId} aria-label={typeof title === 'string' ? title : undefined}>
    {(title || actions) && (
      <header className="flex flex-wrap items-start justify-between gap-3 border-b border-adm-line px-5 py-3.5">
        <div className="min-w-0">
          {title && <h2 className="text-sm font-semibold text-adm-ink">{title}</h2>}
          {description && <p className="mt-0.5 text-xs text-adm-muted">{description}</p>}
        </div>
        {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
      </header>
    )}
    <div className={cn('p-5', bodyClassName)}>{children}</div>
  </section>
);

// ------------------------------------------------------------------------------------------- tones, badges

export type Tone = 'neutral' | 'sage' | 'amber' | 'red' | 'blue';
const TONES: Record<Tone, string> = {
  neutral: 'border-adm-line bg-adm-warm text-adm-muted',
  sage: 'border-sage-300 bg-adm-sage-tint text-adm-sage-dark',
  amber: 'border-[#FDE68A] bg-adm-amber-tint text-adm-amber-text',
  red: 'border-[#FECACA] bg-adm-red-tint text-adm-red',
  blue: 'border-[#D4DEE8] bg-adm-blue-tint text-adm-blue',
};

export const Pill: React.FC<{ tone?: Tone; icon?: React.ElementType; children: React.ReactNode; className?: string }> = ({ tone = 'neutral', icon: Icon, children, className }) => (
  <span className={cn('inline-flex items-center gap-1 whitespace-nowrap rounded-md border px-1.5 py-0.5 text-[11px] font-medium', TONES[tone], className)}>
    {Icon && <Icon className="h-3 w-3" aria-hidden="true" />}
    {children}
  </span>
);

const STATE: Record<ServiceState, { tone: Tone; icon: React.ElementType; label: string }> = {
  healthy: { tone: 'sage', icon: CheckCircle2, label: 'Healthy' },
  degraded: { tone: 'amber', icon: AlertTriangle, label: 'Degraded' },
  unavailable: { tone: 'red', icon: XCircle, label: 'Unavailable' },
  unconfigured: { tone: 'neutral', icon: CircleSlash, label: 'Unconfigured' },
  unknown: { tone: 'blue', icon: HelpCircle, label: 'Unknown' },
};

/** Service state: icon + label, never color alone. */
export const StateBadge: React.FC<{ state: ServiceState | string }> = ({ state }) => {
  const s = STATE[state as ServiceState] ?? STATE.unknown;
  return <Pill tone={s.tone} icon={s.icon}>{s.label}</Pill>;
};
export const stateTone = (state: string): Tone => (STATE[state as ServiceState] ?? STATE.unknown).tone;

export const severityTone = (severity: string): Tone =>
  ({ critical: 'red', high: 'red', warning: 'amber', medium: 'amber', low: 'blue', info: 'blue' } as Record<string, Tone>)[severity] ?? 'neutral';

export const SeverityBadge: React.FC<{ severity: string }> = ({ severity }) => (
  <Pill tone={severityTone(severity)} icon={['critical', 'high'].includes(severity) ? AlertTriangle : undefined}>{humanize(severity)}</Pill>
);

export const statusTone = (status: string): Tone =>
  ({
    open: 'red', investigating: 'amber', mitigated: 'blue', acknowledged: 'amber', resolved: 'sage', closed: 'neutral', ignored: 'neutral',
    completed: 'sage', running: 'blue', failed: 'red', scheduled: 'blue', in_progress: 'amber', cancelled: 'neutral', active: 'sage',
    SENT: 'sage', FAILED: 'red', PENDING: 'amber', PROCESSING: 'blue', CANCELLED: 'neutral', verified: 'sage',
  } as Record<string, Tone>)[status] ?? 'neutral';

export const StatusBadge: React.FC<{ status: string; label?: string }> = ({ status, label }) => (
  <Pill tone={statusTone(status)}>{label ?? humanize(status)}</Pill>
);

// ------------------------------------------------------------------------------------------- KPI

export const Delta: React.FC<{ current: number; previous: number; invert?: boolean; days?: number }> = ({ current, previous, invert, days }) => {
  const diff = current - previous;
  if (diff === 0) return <span className="inline-flex items-center gap-0.5 text-[11px] text-adm-muted"><Minus className="h-3 w-3" aria-hidden="true" />no change</span>;
  const up = diff > 0;
  const good = invert ? !up : up;
  const pct = previous > 0 ? `${Math.round((Math.abs(diff) / previous) * 100)}%` : 'new';
  const Icon = up ? ArrowUpRight : ArrowDownRight;
  return (
    <span className={cn('inline-flex items-center gap-0.5 text-[11px] font-medium', good ? 'text-adm-sage' : 'text-adm-red')}
      title={`${fmt(previous)} in the previous ${days ?? ''} days`.replace('  ', ' ')}>
      <Icon className="h-3 w-3" aria-hidden="true" />{up ? '+' : '−'}{fmt(Math.abs(diff))} ({pct})
      <span className="sr-only"> versus previous period</span>
    </span>
  );
};

export const Kpi: React.FC<{
  label: string; value: React.ReactNode; hint?: React.ReactNode; icon?: React.ElementType; to?: string; tone?: Tone;
  delta?: { current: number; previous: number; invert?: boolean; days?: number }; testId?: string;
}> = ({ label, value, hint, icon: Icon, to, tone, delta, testId }) => {
  const body = (
    <>
      <div className="flex items-center justify-between gap-2">
        <p className="text-[11px] font-medium uppercase tracking-[0.08em] text-adm-muted">{label}</p>
        {Icon && <Icon className={cn('h-4 w-4', tone === 'red' ? 'text-adm-red' : tone === 'amber' ? 'text-adm-amber' : 'text-adm-muted')} aria-hidden="true" strokeWidth={1.8} />}
      </div>
      <p className={cn('mt-2 text-2xl font-semibold tabular-nums tracking-tight', tone === 'red' ? 'text-adm-red' : tone === 'amber' ? 'text-adm-amber-text' : 'text-adm-ink')}>{value}</p>
      <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-adm-muted">
        {delta && <Delta {...delta} />}
        {hint && <span>{hint}</span>}
      </div>
    </>
  );
  const cls = 'block rounded-xl border border-adm-line bg-adm-surface p-4 shadow-subtle';
  return to
    ? <Link to={to} className={cn(cls, 'hover:border-adm-sage/40 hover:bg-adm-warm focus-visible:outline-2 focus-visible:outline-adm-sage')} data-testid={testId}>{body}</Link>
    : <div className={cls} data-testid={testId}>{body}</div>;
};

// ------------------------------------------------------------------------------------------- controls

type BtnVariant = 'primary' | 'secondary' | 'ghost' | 'danger';
const BTN: Record<BtnVariant, string> = {
  primary: 'bg-adm-sage text-white hover:bg-adm-sage-dark border border-adm-sage',
  secondary: 'bg-adm-surface text-adm-ink border border-adm-line hover:bg-adm-warm hover:border-sage-300',
  ghost: 'text-adm-ink hover:bg-adm-cream border border-transparent',
  danger: 'bg-adm-red text-white hover:bg-[#991B1B] border border-adm-red',
};

export const Btn = React.forwardRef<HTMLButtonElement, React.ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: BtnVariant; size?: 'sm' | 'md'; icon?: React.ElementType; loading?: boolean;
}>(({ variant = 'secondary', size = 'md', icon: Icon, loading, className, children, disabled, ...props }, ref) => (
  <button ref={ref} type="button" disabled={disabled || loading} {...props}
    className={cn('inline-flex items-center justify-center gap-1.5 rounded-lg font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-adm-sage disabled:cursor-not-allowed disabled:opacity-50',
      size === 'sm' ? 'h-8 px-2.5 text-xs' : 'h-9 px-3.5 text-sm', BTN[variant], className)}>
    {loading ? <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" /> : Icon ? <Icon className="h-3.5 w-3.5" aria-hidden="true" /> : null}
    {children}
  </button>
));
Btn.displayName = 'Btn';

export const inputCls = 'h-9 w-full rounded-lg border border-adm-line bg-adm-surface px-3 text-sm text-adm-ink placeholder:text-sage-400 focus:border-adm-sage focus:outline-none focus:ring-2 focus:ring-adm-sage/20 disabled:bg-adm-warm';

export const Field: React.FC<{ label: string; hint?: string; error?: string; children: (id: string) => React.ReactNode; className?: string }> = ({ label, hint, error, children, className }) => {
  const id = useId();
  return (
    <div className={className}>
      <label htmlFor={id} className="mb-1 block text-xs font-medium text-adm-ink">{label}</label>
      {children(id)}
      {error ? <p className="mt-1 text-xs text-adm-red" role="alert">{error}</p> : hint ? <p className="mt-1 text-xs text-adm-muted">{hint}</p> : null}
    </div>
  );
};

export const Select: React.FC<React.SelectHTMLAttributes<HTMLSelectElement> & { label: string; options: { value: string; label: string }[]; hideLabel?: boolean }> = ({ label, options, hideLabel, className, ...props }) => {
  const id = useId();
  return (
    <div className={className}>
      <label htmlFor={id} className={hideLabel ? 'sr-only' : 'mb-1 block text-xs font-medium text-adm-ink'}>{label}</label>
      <select id={id} {...props} className={cn(inputCls, 'pr-8')}>
        {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
      </select>
    </div>
  );
};

export const Segmented: React.FC<{ label: string; value: string | number; options: { value: string | number; label: string }[]; onChange: (v: string) => void }> = ({ label, value, options, onChange }) => (
  <div role="radiogroup" aria-label={label} className="inline-flex rounded-lg border border-adm-line bg-adm-surface p-0.5">
    {options.map((o) => (
      <button key={o.value} type="button" role="radio" aria-checked={String(o.value) === String(value)} onClick={() => onChange(String(o.value))}
        className={cn('h-8 rounded-md px-3 text-xs font-medium focus-visible:outline-2 focus-visible:outline-adm-sage',
          String(o.value) === String(value) ? 'bg-adm-ink text-white' : 'text-adm-muted hover:text-adm-ink')}>
        {o.label}
      </button>
    ))}
  </div>
);

export const Switch: React.FC<{ checked: boolean; onChange: (v: boolean) => void; label: string; disabled?: boolean; testId?: string }> = ({ checked, onChange, label, disabled, testId }) => (
  <button type="button" role="switch" aria-checked={checked} aria-label={label} disabled={disabled} data-testid={testId} onClick={() => onChange(!checked)}
    className={cn('relative inline-flex h-6 w-11 shrink-0 items-center rounded-full focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-adm-sage disabled:opacity-50',
      checked ? 'bg-adm-sage' : 'bg-sage-200')}>
    <span className={cn('inline-block h-5 w-5 rounded-full bg-white shadow transition-transform', checked ? 'translate-x-5' : 'translate-x-0.5')} />
  </button>
);

export const Tabs: React.FC<{ tabs: { key: string; label: string; count?: number }[]; active: string; onChange: (k: string) => void; label: string }> = ({ tabs, active, onChange, label }) => (
  <div role="tablist" aria-label={label} className="flex gap-1 overflow-x-auto border-b border-adm-line">
    {tabs.map((t) => (
      <button key={t.key} role="tab" type="button" aria-selected={active === t.key} onClick={() => onChange(t.key)}
        className={cn('-mb-px inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-adm-sage',
          active === t.key ? 'border-adm-sage text-adm-ink' : 'border-transparent text-adm-muted hover:text-adm-ink')}>
        {t.label}
        {t.count !== undefined && <span className="rounded bg-adm-cream px-1.5 text-[11px] tabular-nums text-adm-muted">{t.count}</span>}
      </button>
    ))}
  </div>
);

// ------------------------------------------------------------------------------------------- feedback states

export const Notice: React.FC<{ tone: 'success' | 'error' | 'info' | 'warning'; children: React.ReactNode; onClose?: () => void; className?: string }> = ({ tone, children, onClose, className }) => {
  const map = { success: TONES.sage, error: TONES.red, info: TONES.blue, warning: TONES.amber };
  return (
    <div role={tone === 'error' ? 'alert' : 'status'} className={cn('flex items-start justify-between gap-3 rounded-lg border px-3 py-2 text-sm', map[tone], className)}>
      <div className="min-w-0">{children}</div>
      {onClose && <button type="button" onClick={onClose} className="shrink-0 text-xs underline-offset-2 hover:underline" aria-label="Dismiss message">Dismiss</button>}
    </div>
  );
};

export const EmptyState: React.FC<{ title: string; children?: React.ReactNode; icon?: React.ElementType; action?: React.ReactNode }> = ({ title, children, icon: Icon = Inbox, action }) => (
  <div className="flex flex-col items-center justify-center gap-2 px-4 py-10 text-center">
    <span className="flex h-10 w-10 items-center justify-center rounded-full bg-adm-cream"><Icon className="h-5 w-5 text-adm-muted" aria-hidden="true" /></span>
    <p className="text-sm font-medium text-adm-ink">{title}</p>
    {children && <div className="max-w-md text-xs text-adm-muted">{children}</div>}
    {action}
  </div>
);

/** Explains *why* a request failed: signed out, not permitted, unreachable, or a server problem. */
export const ErrorState: React.FC<{ error: Error | null; onRetry?: () => void; title?: string }> = ({ error, onRetry, title }) => {
  const status = error instanceof ApiError ? error.status : -1;
  const [Icon, heading, text] =
    status === 401 ? [Lock, 'Signed out', 'Your session has expired. Sign in again to continue.']
      : status === 403 ? [Lock, 'Not permitted', 'This area is restricted to the FacultyLens administrator.']
        : status === 0 ? [WifiOff, 'Server unreachable', 'The FacultyLens API could not be reached. Check that the backend is running.']
          : status === 404 ? [CircleDashed, 'Not found', 'This record no longer exists.']
            : [AlertTriangle, title ?? 'Could not load this section', error?.message ?? 'Something went wrong.'];
  return (
    <div className="rounded-xl border border-[#FECACA] bg-adm-red-tint/50 px-4 py-6 text-center" role="alert">
      <Icon className="mx-auto h-5 w-5 text-adm-red" aria-hidden="true" />
      <p className="mt-2 text-sm font-semibold text-adm-ink">{heading}</p>
      <p className="mt-1 text-xs text-adm-muted">{text}</p>
      {onRetry && status !== 401 && status !== 403 && <Btn size="sm" className="mt-3" icon={RefreshCw} onClick={onRetry}>Try again</Btn>}
    </div>
  );
};

export const Skeleton: React.FC<{ className?: string }> = ({ className }) => <div className={cn('animate-pulse rounded-md bg-adm-cream', className)} aria-hidden="true" />;

export const LoadingBlock: React.FC<{ rows?: number; label?: string }> = ({ rows = 4, label = 'Loading' }) => (
  <div role="status" aria-label={label} className="space-y-2.5">
    {Array.from({ length: rows }, (_, i) => <Skeleton key={i} className={cn('h-4', i % 3 === 2 ? 'w-2/3' : 'w-full')} />)}
  </div>
);

/** Renders loading / error / empty / data consistently. */
export function Async<T>({ state, children, empty, isEmpty, rows }: {
  state: { data: T | null; error: Error | null; loading: boolean; reload: () => void };
  children: (data: T) => React.ReactNode; empty?: React.ReactNode; isEmpty?: (d: T) => boolean; rows?: number;
}) {
  if (state.error && !state.data) return <ErrorState error={state.error} onRetry={state.reload} />;
  if (!state.data) return <LoadingBlock rows={rows} />;
  if (isEmpty?.(state.data)) return <>{empty}</>;
  return <>{children(state.data)}</>;
}

// ------------------------------------------------------------------------------------------- table + pagination

export interface Column<T> { key: string; header: React.ReactNode; render: (row: T) => React.ReactNode; className?: string; headClassName?: string }

export function DataTable<T>({ columns, rows, rowKey, empty, caption, selection, onRowClick, dense }: {
  columns: Column<T>[]; rows: T[]; rowKey: (r: T) => string | number; empty?: React.ReactNode; caption: string;
  selection?: { selected: Set<number | string>; toggle: (key: number | string) => void; toggleAll: (keys: (number | string)[]) => void; disabled?: (r: T) => boolean };
  onRowClick?: (r: T) => void; dense?: boolean;
}) {
  const keys = rows.filter((r) => !selection?.disabled?.(r)).map(rowKey);
  const allSelected = selection && keys.length > 0 && keys.every((k) => selection.selected.has(k));
  return (
    <div className="-mx-5 overflow-x-auto">
      <table className="min-w-full text-sm">
        <caption className="sr-only">{caption}</caption>
        <thead>
          <tr className="border-b border-adm-line text-left text-[11px] uppercase tracking-[0.06em] text-adm-muted">
            {selection && (
              <th className="w-10 px-5 py-2"><input type="checkbox" aria-label="Select all rows on this page" checked={Boolean(allSelected)} onChange={() => selection.toggleAll(keys)} className="h-4 w-4 accent-[#1E6F5C]" /></th>
            )}
            {columns.map((c) => <th key={c.key} scope="col" className={cn('whitespace-nowrap px-5 py-2 font-medium', c.headClassName)}>{c.header}</th>)}
          </tr>
        </thead>
        <tbody className="divide-y divide-adm-line">
          {rows.length === 0 ? (
            <tr><td colSpan={columns.length + (selection ? 1 : 0)}>{empty ?? <EmptyState title="Nothing to show" />}</td></tr>
          ) : rows.map((r) => {
            const k = rowKey(r);
            return (
              <tr key={k} className={cn('align-top', onRowClick && 'cursor-pointer hover:bg-adm-warm', selection?.selected.has(k) && 'bg-adm-sage-tint/40')}
                onClick={onRowClick ? (e) => { if (!(e.target as HTMLElement).closest('button,a,input,select')) onRowClick(r); } : undefined}>
                {selection && (
                  <td className="px-5 py-2.5">
                    <input type="checkbox" aria-label={`Select row ${k}`} disabled={selection.disabled?.(r)} checked={selection.selected.has(k)} onChange={() => selection.toggle(k)} className="h-4 w-4 accent-[#1E6F5C]" />
                  </td>
                )}
                {columns.map((c) => <td key={c.key} className={cn('px-5', dense ? 'py-2' : 'py-2.5', c.className)}>{c.render(r)}</td>)}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

export const Pagination: React.FC<{ meta: PageMeta | undefined; onPage: (p: number) => void }> = ({ meta, onPage }) => {
  if (!meta || meta.total === 0) return null;
  const from = (meta.current_page - 1) * meta.per_page + 1;
  const to = Math.min(meta.total, meta.current_page * meta.per_page);
  return (
    <nav aria-label="Pagination" className="mt-4 flex items-center justify-between gap-3 text-xs text-adm-muted">
      <span className="tabular-nums">{fmt(from)}–{fmt(to)} of {fmt(meta.total)}</span>
      <div className="flex items-center gap-1">
        <Btn size="sm" variant="ghost" icon={ChevronLeft} disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)} aria-label="Previous page">Prev</Btn>
        <span className="px-2 tabular-nums">Page {meta.current_page} / {meta.last_page}</span>
        <Btn size="sm" variant="ghost" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)} aria-label="Next page">Next<ChevronRight className="h-3.5 w-3.5" aria-hidden="true" /></Btn>
      </div>
    </nav>
  );
};

// ------------------------------------------------------------------------------------------- dialogs

export const Dialog: React.FC<{ open: boolean; title: string; onClose: () => void; children: React.ReactNode; footer?: React.ReactNode; wide?: boolean }> = ({ open, title, onClose, children, footer, wide }) => {
  const ref = useRef<HTMLDivElement>(null);
  const titleId = useId();
  useEffect(() => {
    if (!open) return;
    const previous = document.activeElement as HTMLElement | null;
    const node = ref.current;
    const focusable = () => Array.from(node?.querySelectorAll<HTMLElement>('button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),a[href]') ?? []);
    (focusable()[0] ?? node)?.focus();
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
      if (e.key === 'Tab') {
        const items = focusable();
        if (!items.length) return;
        const first = items[0];
        const last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    };
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('keydown', onKey); previous?.focus(); };
  }, [open, onClose]);

  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-sage-800/60 p-0 sm:items-center sm:p-4" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div ref={ref} role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1}
        className={cn('max-h-[90vh] w-full overflow-y-auto rounded-t-2xl border border-adm-line bg-adm-surface shadow-xl sm:rounded-2xl', wide ? 'sm:max-w-3xl' : 'sm:max-w-md')}>
        <header className="border-b border-adm-line px-5 py-4"><h2 id={titleId} className="text-base font-semibold text-adm-ink">{title}</h2></header>
        <div className="px-5 py-4 text-sm text-adm-ink">{children}</div>
        {footer && <footer className="flex flex-wrap justify-end gap-2 border-t border-adm-line bg-adm-warm px-5 py-3">{footer}</footer>}
      </div>
    </div>
  );
};

/**
 * Confirmation for sensitive actions: optional mandatory reason, optional typed confirmation phrase.
 */
export const ConfirmDialog: React.FC<{
  open: boolean; title: string; body: React.ReactNode; confirmLabel: string; tone?: 'danger' | 'primary';
  reason?: { label: string; required: boolean }; typeToConfirm?: string; busy?: boolean;
  onConfirm: (reason: string) => void; onCancel: () => void;
}> = ({ open, title, body, confirmLabel, tone = 'primary', reason, typeToConfirm, busy, onConfirm, onCancel }) => {
  const [text, setText] = useState('');
  const [typed, setTyped] = useState('');
  useEffect(() => { if (open) { setText(''); setTyped(''); } }, [open]);
  const blocked = (reason?.required && text.trim().length < 3) || (typeToConfirm !== undefined && typed.trim() !== typeToConfirm);
  return (
    <Dialog open={open} title={title} onClose={onCancel}
      footer={<>
        <Btn variant="secondary" onClick={onCancel}>Cancel</Btn>
        <Btn variant={tone === 'danger' ? 'danger' : 'primary'} loading={busy} disabled={blocked} onClick={() => onConfirm(text.trim())} data-testid="confirm-action">{confirmLabel}</Btn>
      </>}>
      <div className="space-y-3">
        <div className="text-sm text-adm-muted">{body}</div>
        {reason && (
          <Field label={reason.label} hint={reason.required ? 'Required. Recorded in the audit log.' : 'Optional. Recorded in the audit log.'}>
            {(id) => <textarea id={id} rows={3} value={text} onChange={(e) => setText(e.target.value)} maxLength={500} className={cn(inputCls, 'h-auto py-2')} />}
          </Field>
        )}
        {typeToConfirm !== undefined && (
          <Field label={`Type "${typeToConfirm}" to confirm`}>
            {(id) => <input id={id} value={typed} onChange={(e) => setTyped(e.target.value)} className={inputCls} autoComplete="off" />}
          </Field>
        )}
      </div>
    </Dialog>
  );
};

export const KeyValue: React.FC<{ items: { label: string; value: React.ReactNode }[]; columns?: 1 | 2 | 3 }> = ({ items, columns = 2 }) => (
  <dl className={cn('grid gap-x-6 gap-y-3 text-sm', columns === 3 ? 'sm:grid-cols-3' : columns === 2 ? 'sm:grid-cols-2' : '')}>
    {items.map((i) => (
      <div key={i.label} className="min-w-0">
        <dt className="text-[11px] uppercase tracking-[0.06em] text-adm-muted">{i.label}</dt>
        <dd className="mt-0.5 break-words text-adm-ink">{i.value}</dd>
      </div>
    ))}
  </dl>
);
