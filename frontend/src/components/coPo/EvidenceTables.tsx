import React from 'react';
import { CoCoverage, PoEvidence } from '@/types/coPo';
import { MappingStatusBadge } from './MappingStatusBadge';

const pct = (v: number | null | undefined): string => (v === null || v === undefined ? 'N/A' : `${Number.isInteger(v) ? v : Number(v.toFixed(1))}%`);
const gap = (v: number | null | undefined): string => (v === null || v === undefined || v <= 0 ? '—' : `${Number.isInteger(v) ? v : Number(v.toFixed(1))} pts`);

/** CO coverage table (marks attributed / total). */
export const CoCoverageTable: React.FC<{ rows: CoCoverage[] }> = ({ rows }) => (
  <div className="overflow-x-auto rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C]" data-testid="co-coverage-table">
    <table className="w-full text-xs">
      <thead className="bg-[#F7F7F5] dark:bg-[#2C2C2E] text-[10px] uppercase tracking-wider text-[#737373]">
        <tr><th className="text-left px-3 py-2">CO</th><th className="text-right px-3 py-2">Questions</th><th className="text-right px-3 py-2">Marks</th><th className="text-right px-3 py-2">Coverage</th><th className="text-right px-3 py-2">PO links</th><th className="text-left px-3 py-2">Coverage status</th></tr>
      </thead>
      <tbody className="divide-y divide-[#E5E5E5] dark:divide-[#2C2C2E]">
        {rows.map((co) => (
          <tr key={co.learning_outcome_id} data-testid="co-coverage-row">
            <td className="px-3 py-2"><span className="font-mono font-semibold text-[#111111] dark:text-white">{co.display_code}</span> <span className="text-[#737373]">{co.description}</span></td>
            <td className="px-3 py-2 text-right font-mono">{co.question_count}</td>
            <td className="px-3 py-2 text-right font-mono">{co.mapped_marks}</td>
            <td className="px-3 py-2 text-right font-mono text-[#111111] dark:text-white">{pct(co.coverage_percent)}</td>
            <td className="px-3 py-2 text-right font-mono">{co.po_mapping_count}</td>
            <td className="px-3 py-2 text-[#737373]">{co.coverage_status.replace(/_/g, ' ').toLowerCase()}</td>
          </tr>
        ))}
      </tbody>
    </table>
  </div>
);

/** CO coverage + STEP 30 performance matrix — the core STEP 31 view. */
export const CoPerformanceMatrix: React.FC<{ rows: CoCoverage[] }> = ({ rows }) => (
  <div className="space-y-2" data-testid="co-performance-matrix">
    <h4 className="text-[10px] uppercase tracking-wider font-semibold text-[#737373]">CO Coverage &amp; Performance</h4>
    <div className="overflow-x-auto rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C]">
      <table className="w-full text-xs">
        <thead className="bg-[#F7F7F5] dark:bg-[#2C2C2E] text-[10px] uppercase tracking-wider text-[#737373]">
          <tr><th className="text-left px-3 py-2">CO</th><th className="text-right px-3 py-2">Coverage</th><th className="text-right px-3 py-2">Performance</th><th className="text-right px-3 py-2">Gap</th><th className="text-right px-3 py-2">Responses</th><th className="text-left px-3 py-2">Status</th></tr>
        </thead>
        <tbody className="divide-y divide-[#E5E5E5] dark:divide-[#2C2C2E]">
          {rows.map((co) => (
            <tr key={co.learning_outcome_id} data-testid="co-performance-row">
              <td className="px-3 py-2 font-mono font-semibold text-[#111111] dark:text-white" title={co.description}>{co.display_code}</td>
              <td className="px-3 py-2 text-right font-mono">{pct(co.coverage_percent)}</td>
              <td className="px-3 py-2 text-right font-mono text-[#111111] dark:text-white">{pct(co.performance_percent)}</td>
              <td className="px-3 py-2 text-right font-mono text-[#737373]">{gap(co.performance_gap)}</td>
              <td className="px-3 py-2 text-right font-mono text-[#737373]">{co.response_count || '—'}</td>
              <td className="px-3 py-2"><MappingStatusBadge kind="co" status={co.status} /></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
    <p className="text-[11px] text-[#737373]">Performance uses finalized faculty marks only (STEP 30). A gap is a review signal, not evidence that an outcome was not learned.</p>
  </div>
);

/** PO evidence: CO mapping evidence + assessment evidence + student performance. */
export const PoEvidenceMatrix: React.FC<{ rows: PoEvidence[] }> = ({ rows }) => (
  <div className="space-y-2" data-testid="po-evidence-matrix">
    <h4 className="text-[10px] uppercase tracking-wider font-semibold text-[#737373]">PO Evidence</h4>
    {rows.length === 0 ? (
      <p className="text-xs text-[#737373] italic">No program outcomes configured for this course's program.</p>
    ) : (
      <div className="overflow-x-auto rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C]">
        <table className="w-full text-xs">
          <thead className="bg-[#F7F7F5] dark:bg-[#2C2C2E] text-[10px] uppercase tracking-wider text-[#737373]">
            <tr><th className="text-left px-3 py-2">PO</th><th className="text-left px-3 py-2">Mapped COs</th><th className="text-left px-3 py-2">CO evidence</th><th className="text-right px-3 py-2">Assessment evidence</th><th className="text-right px-3 py-2">Contribution</th><th className="text-right px-3 py-2">Student evidence</th><th className="text-left px-3 py-2">Status</th></tr>
          </thead>
          <tbody className="divide-y divide-[#E5E5E5] dark:divide-[#2C2C2E]">
            {rows.map((po) => (
              <tr key={po.program_outcome_id} data-testid="po-evidence-row">
                <td className="px-3 py-2"><span className="font-mono font-semibold text-[#111111] dark:text-white">{po.code}</span> <span className="text-[#737373]">{po.title}</span></td>
                <td className="px-3 py-2 text-[#737373]">{po.mapped_cos.length ? po.mapped_cos.map((c) => `${c.code} (${c.level})`).join(', ') : '—'}</td>
                <td className="px-3 py-2 font-mono">{po.co_evidence}</td>
                <td className="px-3 py-2 text-right font-mono">{pct(po.assessment_evidence_percent)}</td>
                <td className="px-3 py-2 text-right font-mono text-[#737373]">{pct(po.contribution_percent)}</td>
                <td className="px-3 py-2 text-right font-mono text-[#111111] dark:text-white">{pct(po.student_performance_percent)}</td>
                <td className="px-3 py-2"><MappingStatusBadge kind="po" status={po.status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    )}
    <p className="text-[11px] text-[#737373]">Contribution = Σ(CO coverage × level weight 1/3, 2/3, 1). "Evidence available" means evidence exists for review — not that a PO is achieved.</p>
  </div>
);

/** Compact PO coverage table (mapping-only view without performance). */
export const PoCoverageTable: React.FC<{ rows: PoEvidence[] }> = ({ rows }) => (
  <div className="overflow-x-auto rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C]" data-testid="po-coverage-table">
    <table className="w-full text-xs">
      <thead className="bg-[#F7F7F5] dark:bg-[#2C2C2E] text-[10px] uppercase tracking-wider text-[#737373]">
        <tr><th className="text-left px-3 py-2">PO</th><th className="text-right px-3 py-2">Mapped COs</th><th className="text-right px-3 py-2">Assessment evidence</th><th className="text-left px-3 py-2">Evidence status</th></tr>
      </thead>
      <tbody className="divide-y divide-[#E5E5E5] dark:divide-[#2C2C2E]">
        {rows.map((po) => (
          <tr key={po.program_outcome_id}>
            <td className="px-3 py-2 font-mono font-semibold text-[#111111] dark:text-white">{po.code}</td>
            <td className="px-3 py-2 text-right font-mono">{po.mapped_co_count}</td>
            <td className="px-3 py-2 text-right font-mono">{pct(po.assessment_evidence_percent)}</td>
            <td className="px-3 py-2 text-[#737373]">{po.evidence_status.replace(/_/g, ' ').toLowerCase()}</td>
          </tr>
        ))}
      </tbody>
    </table>
  </div>
);
