import React from 'react';
import { AnalysisHistoryItem } from '@/types/analysisHistory';
import { Button } from '@/components/common/Button';
import { GitCompare, X, ArrowRight } from 'lucide-react';

interface ComparisonSelectorProps {
  selectedItems: AnalysisHistoryItem[];
  onRemoveItem: (id: number) => void;
  onClear: () => void;
  onCompare: () => void;
}

export const ComparisonSelector: React.FC<ComparisonSelectorProps> = ({
  selectedItems,
  onRemoveItem,
  onClear,
  onCompare,
}) => {
  if (selectedItems.length === 0) {
    return null;
  }

  const isReady = selectedItems.length === 2;

  return (
    <div className="fixed bottom-6 left-1/2 -translate-x-1/2 z-40 max-w-2xl w-[92%] sm:w-full bg-[#111111] text-white p-4 rounded-2xl shadow-2xl border border-[#333333] flex flex-col sm:flex-row items-center justify-between gap-3 animate-in fade-in slide-in-from-bottom-4 duration-200">
      <div className="flex items-center gap-3 w-full sm:w-auto">
        <div className="w-9 h-9 rounded-xl bg-[#222222] border border-[#333333] flex items-center justify-center text-blue-400 shrink-0">
          <GitCompare className="w-4 h-4" />
        </div>

        <div className="space-y-0.5">
          <h4 className="text-xs font-bold text-white flex items-center gap-2">
            Compare Analyses ({selectedItems.length}/2 selected)
          </h4>
          <div className="flex flex-wrap items-center gap-2 text-xs">
            {selectedItems.map((item, idx) => (
              <span
                key={item.id}
                className="inline-flex items-center gap-1.5 bg-[#262626] border border-[#3A3A3A] px-2 py-0.5 rounded-md text-xs font-medium text-white"
              >
                <span className="text-[11px] text-[#A1A1AA]">
                  #{idx + 1}:
                </span>
                <span className="max-w-[140px] truncate font-semibold">
                  {item.assessment?.title || `Analysis #${item.id}`}
                </span>
                <span className="font-mono text-[10px] text-blue-400">
                  (v{item.analysis_version})
                </span>
                <button
                  type="button"
                  onClick={() => onRemoveItem(item.id)}
                  className="hover:text-red-400 transition-colors p-0.5"
                  title="Remove"
                >
                  <X className="w-3 h-3" />
                </button>
              </span>
            ))}
            {!isReady && (
              <span className="text-xs text-[#8E8E93] italic">
                Select 1 more analysis to compare
              </span>
            )}
          </div>
        </div>
      </div>

      <div className="flex items-center gap-2 w-full sm:w-auto justify-end">
        <Button
          variant="ghost"
          size="sm"
          onClick={onClear}
          className="text-xs text-[#A1A1AA] hover:text-white"
        >
          Clear
        </Button>

        <Button
          variant="primary"
          size="sm"
          disabled={!isReady}
          onClick={onCompare}
          className="bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs px-4"
        >
          Compare Side-by-Side
          <ArrowRight className="w-3.5 h-3.5 ml-1.5" />
        </Button>
      </div>
    </div>
  );
};

