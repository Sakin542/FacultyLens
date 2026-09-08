import React from 'react';
import { History } from 'lucide-react';
import { Rubric } from '@/types/rubric';
import { RubricStatusBadge } from './RubricStatusBadge';
import { formatMarks } from './rubricMath';

interface RubricVersionHistoryProps {
  rubrics: Rubric[];
  selectedId: number | null;
  onSelect: (rubric: Rubric) => void;
}

/**
 * Lists every rubric version for a question so faculty can compare and choose.
 * Historical (archived) versions remain readable.
 */
export const RubricVersionHistory: React.FC<RubricVersionHistoryProps> = ({ rubrics, selectedId, onSelect }) => {
  if (rubrics.length <= 1) return null;

  return (
    <div className="space-y-2" data-testid="rubric-version-history">
      <div className="flex items-center gap-2 text-[11px] font-semibold text-[#737373] uppercase tracking-wider">
        <History className="w-3.5 h-3.5" />
        Version history ({rubrics.length})
      </div>
      <ul className="flex flex-wrap gap-2">
        {rubrics.map((r) => {
          const active = r.id === selectedId;
          return (
            <li key={r.id}>
              <button
                type="button"
                onClick={() => onSelect(r)}
                aria-pressed={active}
                className={`flex items-center gap-2 px-3 py-1.5 rounded-lg border text-xs transition-colors ${
                  active
                    ? 'border-[#111111] dark:border-white bg-[#F7F7F5] dark:bg-[#2C2C2E]'
                    : 'border-[#E5E5E5] dark:border-[#3A3A3C] hover:border-[#CCCCCC]'
                }`}
              >
                <span className="font-mono font-bold text-[#111111] dark:text-white">v{r.version}</span>
                <RubricStatusBadge status={r.status} />
                <span className="text-[#737373] font-mono">{formatMarks(r.criteria_total)} marks</span>
                {r.generated_at && (
                  <span className="text-[#737373]">{new Date(r.generated_at).toLocaleDateString()}</span>
                )}
              </button>
            </li>
          );
        })}
      </ul>
    </div>
  );
};
