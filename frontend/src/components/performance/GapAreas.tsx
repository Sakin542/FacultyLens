import React from 'react';
import { AlertTriangle } from 'lucide-react';
import { PerformanceArea } from '@/types/performance';
import { PerformanceGapBadge, formatGap, formatPct } from './PerformanceGapBadge';

const TYPE_LABEL: Record<PerformanceArea['type'], string> = { learning_outcome: 'Learning outcome', topic: 'Topic', question: 'Question' };

/** Potential learning gaps (never "confirmed" or "failures"). */
export const GapAreas: React.FC<{ areas: PerformanceArea[]; minResponses: number }> = ({ areas, minResponses }) => (
  <div className="space-y-2" data-testid="gap-areas">
    <h4 className="text-[10px] uppercase tracking-wider font-semibold text-[#737373] flex items-center gap-1.5">
      <AlertTriangle className="w-3 h-3 text-amber-600" /> Potential Learning Gaps
    </h4>
    {areas.length === 0 ? (
      <p className="text-xs text-[#737373] italic">No potential gap areas met the minimum of {minResponses} finalized responses.</p>
    ) : (
      <ol className="space-y-1.5">
        {areas.map((a, i) => (
          <li key={`${a.type}-${a.label}`} className="p-2.5 rounded-lg border border-amber-200/70 dark:border-amber-900/60 bg-amber-50/40 dark:bg-amber-950/20 flex items-start justify-between gap-2" data-testid="gap-area">
            <div className="min-w-0 text-xs">
              <span className="font-mono text-[#737373] mr-1.5">{i + 1}.</span>
              <span className="font-semibold text-[#111111] dark:text-white">{a.label}</span>
              <span className="text-[10px] text-[#737373] ml-2">{TYPE_LABEL[a.type]}</span>
              {a.description && <p className="text-[11px] text-[#737373] truncate">{a.description}</p>}
            </div>
            <div className="flex items-center gap-2 shrink-0 text-xs">
              <span className="font-mono text-[#111111] dark:text-white">{formatPct(a.average_percentage)}</span>
              <span className="text-[10px] text-[#737373]">gap {formatGap(a.performance_gap)}</span>
              <PerformanceGapBadge status={a.performance_status} />
            </div>
          </li>
        ))}
      </ol>
    )}
    <p className="text-[11px] text-[#737373]">Potential learning gap identified — review may be useful. Data shows performance differences, not causes.</p>
  </div>
);
