import React, { useState } from 'react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { AIGradingCriterionResult } from '@/types/grading';
import { formatMarks } from './SuggestedMarksCard';

interface CriterionGradingBreakdownProps {
  criteria: AIGradingCriterionResult[];
}

const LEVEL_STYLES: Record<string, string> = {
  STRONG: 'bg-emerald-500',
  PARTIAL: 'bg-amber-500',
  LIMITED: 'bg-orange-500',
  NOT_ADDRESSED: 'bg-red-500',
};

const CriterionRow: React.FC<{ c: AIGradingCriterionResult }> = ({ c }) => {
  const [open, setOpen] = useState(false);
  const ratio = c.maximum_marks > 0 ? Math.min(1, c.suggested_marks / c.maximum_marks) : 0;
  const bar = LEVEL_STYLES[c.coverage_level ?? ''] ?? 'bg-sage-700 dark:bg-white';

  return (
    <li className="rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E]" data-testid="criterion-row">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="w-full flex items-center gap-3 p-3 text-left"
        aria-expanded={open}
      >
        <div className="flex-1 min-w-0 space-y-1.5">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs font-semibold text-sage-800 dark:text-white truncate">{c.criterion}</span>
            <span className="text-xs font-mono font-bold text-sage-800 dark:text-white shrink-0" data-testid="criterion-marks">
              {formatMarks(c.suggested_marks)} / {formatMarks(c.maximum_marks)}
            </span>
          </div>
          <div className="h-1.5 rounded-full bg-sage-200 dark:bg-[#3A3A3C] overflow-hidden" aria-hidden="true">
            <div className={`h-full rounded-full ${bar}`} style={{ width: `${Math.round(ratio * 100)}%` }} />
          </div>
        </div>
        {open ? <ChevronUp className="w-4 h-4 text-sage-500 shrink-0" /> : <ChevronDown className="w-4 h-4 text-sage-500 shrink-0" />}
      </button>

      {open && (
        <div className="px-3 pb-3 space-y-2 text-xs border-t border-sage-200 dark:border-[#2C2C2E] pt-2" data-testid="criterion-details">
          <p className="text-sage-700 dark:text-sage-200 leading-relaxed">{c.evaluation}</p>
          {c.evidence.length > 0 && (
            <div>
              <span className="block text-[10px] uppercase tracking-wider text-sage-500 mb-1">Evidence from the answer</span>
              <ul className="space-y-1">
                {c.evidence.map((e, i) => (
                  <li key={i} className="pl-2 border-l-2 border-emerald-300 dark:border-emerald-700 text-sage-700 dark:text-sage-200 italic">“{e}”</li>
                ))}
              </ul>
            </div>
          )}
          {c.missing_elements.length > 0 && (
            <div>
              <span className="block text-[10px] uppercase tracking-wider text-sage-500 mb-1">Missing</span>
              <ul className="list-disc pl-4 space-y-0.5 text-sage-700 dark:text-sage-200">
                {c.missing_elements.map((m, i) => <li key={i}>{m}</li>)}
              </ul>
            </div>
          )}
          {c.evidence.length === 0 && c.missing_elements.length === 0 && (
            <p className="text-sage-500 italic">No additional detail was provided for this criterion.</p>
          )}
        </div>
      )}
    </li>
  );
};

/**
 * Per-criterion suggested marks with evidence and missing elements (expand to see detail).
 */
export const CriterionGradingBreakdown: React.FC<CriterionGradingBreakdownProps> = ({ criteria }) => {
  if (criteria.length === 0) {
    return <p className="text-xs text-sage-500 italic">No criterion-level results were returned.</p>;
  }
  return (
    <div className="space-y-2" data-testid="criterion-breakdown">
      <span className="block text-[10px] uppercase tracking-wider font-semibold text-sage-500">Criterion Breakdown</span>
      <ul className="space-y-2">
        {criteria.map((c) => <CriterionRow key={c.id ?? `${c.rubric_criterion_id}-${c.criterion}`} c={c} />)}
      </ul>
    </div>
  );
};
