import React from 'react';
import { AlertTriangle, CheckCircle2, Info } from 'lucide-react';
import { MappingFinding, MappingSummary } from '@/types/coPo';
import { MappingStatusBadge } from './MappingStatusBadge';

/** One finding with its STEP 14-style recommendation; wording is a review signal, never a verdict. */
export const MappingFindingCard: React.FC<{ finding: MappingFinding }> = ({ finding }) => (
  <li className="p-3 rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] space-y-1.5" data-testid="mapping-finding">
    <div className="flex items-start justify-between gap-2">
      <div className="min-w-0">
        <p className="text-xs font-semibold text-[#111111] dark:text-white">{finding.title}</p>
        <p className="text-[11px] text-[#737373]">{finding.description}</p>
      </div>
      <div className="flex items-center gap-1.5 shrink-0">
        <MappingStatusBadge kind="severity" status={finding.severity} />
      </div>
    </div>
    {finding.recommendation && (
      <p className="text-xs text-[#262626] dark:text-[#E5E5E5] pl-2 border-l-2 border-[#E5E5E5] dark:border-[#3A3A3C]" data-testid="finding-recommendation">
        {finding.recommendation}
      </p>
    )}
    <p className="text-[10px] text-[#737373] font-mono">{finding.type.replace(/_/g, ' ').toLowerCase()}{finding.category ? ` · ${finding.category.replace(/_/g, ' ')}` : ''}{finding.priority ? ` · priority ${finding.priority}` : ''}</p>
  </li>
);

export const MappingFindings: React.FC<{ findings: MappingFinding[] }> = ({ findings }) => (
  <div className="space-y-2" data-testid="mapping-findings">
    <h4 className="text-[10px] uppercase tracking-wider font-semibold text-[#737373]">Validation Findings ({findings.length})</h4>
    {findings.length === 0 ? (
      <p className="text-xs text-[#737373] italic">No mapping review signals were generated.</p>
    ) : (
      <ul className="space-y-2">{findings.map((f) => <MappingFindingCard key={f.id} finding={f} />)}</ul>
    )}
  </div>
);

/** Checklist summary derived from the analysis; ✓ / ⚠ from actual data. */
export const MappingValidationSummary: React.FC<{ summary: MappingSummary }> = ({ summary }) => {
  const tiles: Array<[string, React.ReactNode]> = [
    ['COs', summary.co_count],
    ['POs', summary.po_count],
    ['Active Mappings', `${summary.active_mapping_count} / ${summary.possible_mapping_count}`],
    ['Questions Mapped', `${summary.questions_mapped} / ${summary.question_count}`],
    ['COs With Evidence', summary.cos_with_evidence !== undefined ? `${summary.cos_with_evidence} / ${summary.co_count}` : '—'],
    ['POs With Evidence', summary.pos_with_evidence !== undefined ? `${summary.pos_with_evidence} / ${summary.po_count}` : '—'],
  ];
  const bars: Array<[string, number | undefined]> = [
    ['CO Coverage', summary.co_coverage_percent],
    ['PO Evidence', summary.po_evidence_percent],
    ['Question Mapping', summary.question_mapping_percent],
  ];
  return (
    <div className="space-y-3" data-testid="mapping-validation-summary">
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        {tiles.map(([label, value]) => (
          <div key={label} className="p-3 rounded-xl bg-[#F7F7F5] dark:bg-[#2C2C2E] text-center" data-testid="mapping-tile">
            <span className="block text-[10px] uppercase font-semibold text-[#737373]">{label}</span>
            <span className="block text-lg font-bold font-mono text-[#111111] dark:text-white mt-1">{value}</span>
          </div>
        ))}
      </div>
      {bars.some(([, v]) => v !== undefined) && (
        <div className="space-y-1.5" data-testid="mapping-bars">
          {bars.filter(([, v]) => v !== undefined).map(([label, v]) => (
            <div key={label} className="flex items-center gap-3 text-xs">
              <span className="w-32 text-[#737373]">{label}</span>
              <div className="flex-1 h-2 rounded-full bg-[#E5E5E5] dark:bg-[#3A3A3C] overflow-hidden"><div className="h-full bg-[#111111] dark:bg-white rounded-full" style={{ width: `${Math.min(v as number, 100)}%` }} /></div>
              <span className="w-12 text-right font-mono text-[#111111] dark:text-white">{v}%</span>
            </div>
          ))}
        </div>
      )}
      {summary.validation_checks && (
        <ul className="space-y-1 text-xs" data-testid="validation-checks">
          {summary.validation_checks.map((c) => (
            <li key={c.label} className="flex items-center gap-2 text-[#262626] dark:text-[#E5E5E5]">
              {c.ok ? <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600 shrink-0" /> : <AlertTriangle className="w-3.5 h-3.5 text-amber-600 shrink-0" />} {c.label}
            </li>
          ))}
        </ul>
      )}
      <p className="text-[11px] text-[#737373] flex items-center gap-1"><Info className="w-3 h-3" /> Density = non-zero cells ÷ (COs × POs). Coverage = marks attributed to a CO ÷ total assessment marks.</p>
    </div>
  );
};
