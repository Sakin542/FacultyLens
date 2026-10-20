import React, { useState } from 'react';
import { cn } from '@/utils/cn';
import type { DailyPoint } from '../types';
import { fmt } from './ui';

/**
 * Chart colors, validated with the dataviz palette checker (lightness band, chroma floor, CVD separation ΔE ≥ 15).
 * The failed hue sits below 3:1 against the surface, so every chart carries a legend, a hover tooltip and a table.
 */
export const CHART = { ok: '#2E8259', failed: '#D9963A', single: '#2E8259', track: '#EFE9DE' };

const dayLabel = (date: string) => new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });

/**
 * Daily bars. With `split`, each bar stacks succeeded (bottom) and failed (top) with a 2px surface gap.
 * One axis, recessive baseline, per-column hover target larger than the mark.
 */
export const DailyBars: React.FC<{
  data: DailyPoint[]; label: string; split?: boolean; height?: number; okLabel?: string; failedLabel?: string; unit?: string; testId?: string;
}> = ({ data, label, split, height = 140, okLabel = 'Succeeded', failedLabel = 'Failed', unit = '', testId }) => {
  const [hover, setHover] = useState<number | null>(null);
  const max = Math.max(1, ...data.map((d) => d.total));
  const n = Math.max(1, data.length);
  const total = data.reduce((s, d) => s + d.total, 0);
  const failedTotal = data.reduce((s, d) => s + (d.failed ?? 0), 0);

  return (
    <figure className="relative" data-testid={testId}>
      <figcaption className="sr-only">{label}</figcaption>
      {split && (
        <div className="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-adm-muted" aria-hidden="true">
          <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm" style={{ background: CHART.ok }} />{okLabel} <span className="tabular-nums text-adm-ink">{fmt(total - failedTotal)}</span></span>
          <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm" style={{ background: CHART.failed }} />{failedLabel} <span className="tabular-nums text-adm-ink">{fmt(failedTotal)}</span></span>
        </div>
      )}
      <div className="relative" style={{ height }} onMouseLeave={() => setHover(null)}>
        <div className="absolute inset-x-0 bottom-0 h-px bg-adm-line" aria-hidden="true" />
        {total === 0 && <p className="absolute inset-0 flex items-center justify-center text-xs text-adm-muted">No activity in this period</p>}
        <div className="absolute inset-0 grid" style={{ gridTemplateColumns: `repeat(${n}, minmax(0, 1fr))` }}>
          {data.map((d, i) => {
            const h = (d.total / max) * 94;
            const failedH = split && d.total ? ((d.failed ?? 0) / d.total) * h : 0;
            const dim = hover !== null && hover !== i;
            return (
              <div key={d.date} className="relative" onMouseEnter={() => setHover(i)} onFocus={() => setHover(i)} onBlur={() => setHover(null)} tabIndex={0}
                aria-label={`${dayLabel(d.date)}: ${d.total}${unit}${split ? `, ${d.failed ?? 0} ${failedLabel.toLowerCase()}` : ''}`}>
                {d.total > 0 && (
                  <div className="pointer-events-none absolute bottom-0 left-1/2 flex -translate-x-1/2 flex-col" style={{ width: 'calc(100% - 2px)', maxWidth: 28, height: `${Math.max(2, h)}%`, opacity: dim ? 0.45 : 1 }}>
                    {failedH > 0 && <span className="w-full rounded-t-[4px]" style={{ height: `${(failedH / h) * 100}%`, minHeight: 2, background: CHART.failed, marginBottom: 2 }} />}
                    <span className={cn('w-full flex-1', failedH > 0 ? '' : 'rounded-t-[4px]')} style={{ background: split ? CHART.ok : CHART.single }} />
                  </div>
                )}
              </div>
            );
          })}
        </div>
        {hover !== null && data[hover] && (
          <div role="tooltip" className="pointer-events-none absolute -top-1 z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-lg border border-adm-line bg-adm-surface px-2.5 py-1.5 text-xs shadow-md"
            style={{ left: `${Math.min(88, Math.max(12, ((hover + 0.5) / n) * 100))}%` }}>
            <p className="font-semibold text-adm-ink">{dayLabel(data[hover].date)}</p>
            <p className="tabular-nums text-adm-muted">{fmt(data[hover].total)}{unit} total</p>
            {split && <p className="tabular-nums text-adm-muted">{fmt(data[hover].ok ?? 0)} {okLabel.toLowerCase()} · {fmt(data[hover].failed ?? 0)} {failedLabel.toLowerCase()}</p>}
          </div>
        )}
      </div>
      <div className="mt-1.5 flex justify-between text-[10px] tabular-nums text-adm-muted">
        <span>{data[0] ? dayLabel(data[0].date) : ''}</span>
        <span>peak {fmt(max)}{unit} / day</span>
        <span>{data[n - 1] ? dayLabel(data[n - 1].date) : ''}</span>
      </div>
      <table className="sr-only">
        <caption>{label}</caption>
        <thead><tr><th>Date</th><th>Total</th>{split && <><th>{okLabel}</th><th>{failedLabel}</th></>}</tr></thead>
        <tbody>{data.map((d) => <tr key={d.date}><td>{d.date}</td><td>{d.total}</td>{split && <><td>{d.ok ?? 0}</td><td>{d.failed ?? 0}</td></>}</tr>)}</tbody>
      </table>
    </figure>
  );
};

/** Hourly availability strip: each cell is the share of healthy checks in that hour; empty hours are shown as gaps. */
export const AvailabilityStrip: React.FC<{ series: { hour: string; checks: number; healthy: number }[]; label: string }> = ({ series, label }) => {
  const [hover, setHover] = useState<number | null>(null);
  const checked = series.filter((s) => s.checks > 0);
  const pct = checked.length ? Math.round((checked.reduce((a, s) => a + s.healthy, 0) / checked.reduce((a, s) => a + s.checks, 0)) * 1000) / 10 : null;
  return (
    <div className="relative" onMouseLeave={() => setHover(null)}>
      <div className="flex h-6 gap-[2px]" role="img" aria-label={`${label}: ${pct === null ? 'no recorded checks' : `${pct}% of checks healthy`}`}>
        {series.map((s, i) => {
          const ratio = s.checks ? s.healthy / s.checks : null;
          const color = ratio === null ? 'transparent' : ratio === 1 ? CHART.ok : ratio === 0 ? '#B91C1C' : CHART.failed;
          return (
            <span key={s.hour} onMouseEnter={() => setHover(i)} className="flex-1 rounded-[2px]"
              style={{ background: color, border: ratio === null ? '1px dashed #E2E6E1' : undefined }} />
          );
        })}
      </div>
      {hover !== null && series[hover] && (
        <div role="tooltip" className="pointer-events-none absolute -top-1 z-10 -translate-y-full whitespace-nowrap rounded-lg border border-adm-line bg-adm-surface px-2 py-1 text-xs shadow-md"
          style={{ left: `${Math.min(80, (hover / series.length) * 100)}%` }}>
          {new Date(series[hover].hour).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}:{' '}
          {series[hover].checks ? `${series[hover].healthy}/${series[hover].checks} healthy` : 'not checked'}
        </div>
      )}
      <p className="mt-1 text-[11px] text-adm-muted">{pct === null ? 'No recorded checks in this window' : `${pct}% healthy across ${fmt(checked.reduce((a, s) => a + s.checks, 0))} checks`}</p>
    </div>
  );
};

/** Horizontal share bar for a part-to-whole split with an explicit legend (labels carry identity, not color). */
export const ShareBar: React.FC<{ parts: { label: string; value: number; color: string }[]; label: string }> = ({ parts, label }) => {
  const total = parts.reduce((s, p) => s + p.value, 0);
  return (
    <div>
      <div className="flex h-2.5 gap-[2px] overflow-hidden rounded-full bg-adm-cream" role="img" aria-label={`${label}: ${parts.map((p) => `${p.label} ${p.value}`).join(', ')}`}>
        {total > 0 && parts.filter((p) => p.value > 0).map((p) => <span key={p.label} style={{ width: `${(p.value / total) * 100}%`, background: p.color }} />)}
      </div>
      <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-adm-muted">
        {parts.map((p) => (
          <li key={p.label} className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm" style={{ background: p.color }} aria-hidden="true" />{p.label} <span className="tabular-nums text-adm-ink">{fmt(p.value)}</span></li>
        ))}
      </ul>
    </div>
  );
};
