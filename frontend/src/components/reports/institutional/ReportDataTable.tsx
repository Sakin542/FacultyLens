import React from 'react';
import { ReportCell, ReportTable } from '@/types/report';

const fmt = (v: ReportCell): string => {
  if (v === null || v === undefined || v === '') return 'N/A';
  if (typeof v === 'boolean') return v ? 'Yes' : 'No';
  if (typeof v === 'number') return Number.isInteger(v) ? String(v) : String(Math.round(v * 100) / 100);
  return String(v);
};

interface ReportDataTableProps {
  table: ReportTable;
  /** Cap rendered rows (preview). Server previews already truncate; this is a UI safety cap. */
  maxRows?: number;
  dense?: boolean;
}

export const ReportDataTable: React.FC<ReportDataTableProps> = ({ table, maxRows = 50, dense = true }) => {
  const rows = table.rows.slice(0, maxRows);
  const total = table.total_rows ?? table.rows.length;
  const hidden = total - rows.length;

  return (
    <section className="rounded-xl border border-sage-200 bg-white overflow-hidden" data-testid={`report-table-${table.key}`}>
      <header className="flex items-center justify-between gap-3 px-4 py-2.5 border-b border-sage-200 bg-sage-50">
        <h4 className="text-sm font-semibold text-sage-800">{table.title}</h4>
        <span className="text-[11px] text-sage-500">{total} row{total === 1 ? '' : 's'}</span>
      </header>
      {rows.length === 0 ? (
        <p className="px-4 py-4 text-xs text-sage-500">No data available.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full text-left">
            <thead>
              <tr className="bg-white">
                {table.columns.map((c) => (
                  <th key={c.key} scope="col" className={`${dense ? 'px-3 py-1.5' : 'px-4 py-2'} text-[11px] font-semibold uppercase tracking-wide text-sage-500 whitespace-nowrap border-b border-sage-100`}>{c.label}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row, i) => (
                <tr key={i} className="border-b border-sage-100 last:border-0 hover:bg-sage-50/60">
                  {table.columns.map((c) => {
                    const v = row[c.key];
                    const num = typeof v === 'number';
                    return <td key={c.key} className={`${dense ? 'px-3 py-1.5' : 'px-4 py-2'} text-xs text-sage-800 align-top ${num ? 'tabular-nums text-right' : ''} max-w-[28rem]`}>{fmt(v ?? null)}</td>;
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {(hidden > 0 || table.note) && (
        <footer className="px-4 py-2 border-t border-sage-100 text-[11px] text-sage-500 space-y-0.5">
          {hidden > 0 && <p>{hidden} more row{hidden === 1 ? '' : 's'} in the generated file.</p>}
          {table.note && <p>{table.note}</p>}
        </footer>
      )}
    </section>
  );
};
