import React from 'react';
import { ReportCell, ReportSummaryItem } from '@/types/report';

const fmt = (v: ReportCell): string => {
  if (v === null || v === undefined || v === '') return 'N/A';
  if (typeof v === 'boolean') return v ? 'Yes' : 'No';
  return String(v);
};

/** KPI-style grid of the report's headline figures (server-computed; never derived client-side). */
export const ReportSummary: React.FC<{ items: ReportSummaryItem[]; title?: string }> = ({ items, title = 'Summary' }) => {
  if (!items.length) return null;
  return (
    <section data-testid="report-summary">
      <h3 className="text-sm font-semibold text-sage-800 mb-2">{title}</h3>
      <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-2.5">
        {items.map((it, i) => (
          <div key={`${it.label}-${i}`} className="rounded-lg border border-sage-200 bg-white px-3 py-2.5">
            <p className="text-[11px] text-sage-500 truncate" title={it.label}>{it.label}</p>
            <p className="text-base font-semibold text-sage-800 tabular-nums truncate" title={fmt(it.value)}>{fmt(it.value)}</p>
          </div>
        ))}
      </div>
    </section>
  );
};
