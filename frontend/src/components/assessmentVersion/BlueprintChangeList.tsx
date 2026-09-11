import React from 'react';
import { Card } from '@/components/common/Card';
import { BlueprintComparisonBlock, BlueprintDimensionDiff } from '@/types/assessmentVersion';
import { fmtMarks, fmtSigned } from './versionUtils';

const Table: React.FC<{ dim: BlueprintDimensionDiff; fromLabel: string; toLabel: string; unit?: string; testId: string }> = ({ dim, fromLabel, toLabel, unit = '%', testId }) => (
  dim.rows.length === 0 ? null : (
    <table className="w-full text-xs" data-testid={testId}>
      <caption className="text-left text-[11px] uppercase tracking-wide text-[#737373] mb-1">{dim.label}</caption>
      <thead><tr className="text-[#737373]"><th className="text-left font-medium py-0.5">Key</th><th className="text-right font-medium">{fromLabel}</th><th className="text-right font-medium">{toLabel}</th><th className="text-right font-medium">Change</th></tr></thead>
      <tbody>
        {dim.rows.map((r) => (
          <tr key={r.key} className={`border-t border-[#F0F0F0] dark:border-[#2A2A2A] ${r.changed ? 'font-medium' : ''}`}>
            <td className="py-0.5 text-[#262626] dark:text-[#D4D4D4]">{r.label}</td>
            <td className="text-right tabular-nums">{r.from === null ? '—' : `${fmtMarks(r.from)}${unit}`}</td>
            <td className="text-right tabular-nums">{r.to === null ? '—' : `${fmtMarks(r.to)}${unit}`}</td>
            <td className={`text-right tabular-nums ${r.changed ? (r.difference > 0 ? 'text-[#166534]' : 'text-[#991B1B]') : 'text-[#737373]'}`}>{r.changed ? fmtSigned(r.difference, unit === '%' ? ' pp' : '') : '—'}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
);

/** STEP 38: planned (blueprint snapshot) and actual (question profile) distribution changes between two versions. */
export const BlueprintChangeList: React.FC<{ blueprint: BlueprintComparisonBlock; fromLabel: string; toLabel: string }> = ({ blueprint: b, fromLabel, toLabel }) => {
  const dims = ['difficulty', 'cognitive', 'learning_outcomes', 'program_outcomes', 'topics', 'question_types'];
  return (
    <Card data-testid="blueprint-change-list" className="p-4 space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-[#111111] dark:text-white">Blueprint &amp; distribution changes</h3>
        <span className="text-xs text-[#737373]">{b.changed ? 'Changes detected' : 'No distribution changes'} · threshold {b.threshold_pp} pp</span>
      </div>
      <section className="space-y-2">
        <h4 className="text-xs font-semibold text-[#262626] dark:text-[#D4D4D4]">Actual question profile</h4>
        {b.profile.structure && <Table dim={b.profile.structure} fromLabel={fromLabel} toLabel={toLabel} unit="" testId="profile-structure" />}
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
          {dims.map((k) => b.profile[k] && <Table key={k} dim={b.profile[k]} fromLabel={fromLabel} toLabel={toLabel} testId={`profile-${k}`} />)}
        </div>
      </section>
      <section className="space-y-2">
        <h4 className="text-xs font-semibold text-[#262626] dark:text-[#D4D4D4]">Planned blueprint targets</h4>
        {!b.planned.configured ? <p className="text-xs text-[#737373]">Neither version has a blueprint snapshot.</p> : (
          <>
            <p className="text-xs text-[#737373]">{fromLabel}: {b.planned.from ? `blueprint v${b.planned.from.blueprint_version}` : 'no blueprint'} · {toLabel}: {b.planned.to ? `blueprint v${b.planned.to.blueprint_version}` : 'no blueprint'}</p>
            {b.planned.structure && <Table dim={{ label: 'Planned structure', rows: b.planned.structure.rows }} fromLabel={fromLabel} toLabel={toLabel} unit="" testId="planned-structure" />}
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
              {dims.map((k) => b.planned.dimensions[k] && <Table key={k} dim={b.planned.dimensions[k]} fromLabel={fromLabel} toLabel={toLabel} testId={`planned-${k}`} />)}
            </div>
          </>
        )}
      </section>
    </Card>
  );
};
