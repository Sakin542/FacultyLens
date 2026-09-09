import React from 'react';
import { CheckCircle2 } from 'lucide-react';
import { PerformanceArea } from '@/types/performance';
import { formatPct } from './PerformanceGapBadge';

const TYPE_LABEL: Record<PerformanceArea['type'], string> = { learning_outcome: 'Learning outcome', topic: 'Topic', question: 'Question' };

/** Balanced view: where observed performance is high. */
export const StrongAreas: React.FC<{ areas: PerformanceArea[] }> = ({ areas }) => (
  <div className="space-y-2" data-testid="strong-areas">
    <h4 className="text-[10px] uppercase tracking-wider font-semibold text-[#737373] flex items-center gap-1.5">
      <CheckCircle2 className="w-3 h-3 text-emerald-600" /> Strong Performance Areas
    </h4>
    {areas.length === 0 ? (
      <p className="text-xs text-[#737373] italic">No areas reached the strong-performance threshold yet.</p>
    ) : (
      <ul className="space-y-1.5">
        {areas.map((a) => (
          <li key={`${a.type}-${a.label}`} className="p-2.5 rounded-lg border border-emerald-200/70 dark:border-emerald-900/60 bg-emerald-50/40 dark:bg-emerald-950/20 flex items-center justify-between gap-2 text-xs" data-testid="strong-area">
            <div className="min-w-0">
              <span className="font-semibold text-[#111111] dark:text-white">{a.label}</span>
              <span className="text-[10px] text-[#737373] ml-2">{TYPE_LABEL[a.type]}</span>
            </div>
            <span className="font-mono text-[#111111] dark:text-white shrink-0">{formatPct(a.average_percentage)}</span>
          </li>
        ))}
      </ul>
    )}
  </div>
);
