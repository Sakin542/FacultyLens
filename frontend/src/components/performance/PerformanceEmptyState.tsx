import React from 'react';
import { BarChart3, Play } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { PerformanceMeta } from '@/types/performance';

interface PerformanceEmptyStateProps {
  meta?: PerformanceMeta | null;
  onAnalyze: () => void;
  isAnalyzing?: boolean;
}

/** No snapshot yet. Explains what will be analyzed and how many finalized answers exist. */
export const PerformanceEmptyState: React.FC<PerformanceEmptyStateProps> = ({ meta, onAnalyze, isAnalyzing = false }) => {
  const count = meta?.finalized_answer_count ?? 0;
  return (
    <div className="p-6 rounded-xl border border-dashed border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] text-center space-y-3" data-testid="performance-empty">
      <div className="w-10 h-10 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] flex items-center justify-center mx-auto">
        <BarChart3 className="w-5 h-5 text-[#737373]" />
      </div>
      <div className="space-y-1">
        <h4 className="text-sm font-bold text-[#111111] dark:text-white">No performance analysis yet</h4>
        <p className="text-xs text-[#737373] max-w-md mx-auto">
          {count > 0
            ? `${count} finalized answer${count === 1 ? '' : 's'} are available. Generate the analysis to see question, topic and learning-outcome performance against the ${meta?.expected_performance_percent ?? 70}% benchmark.`
            : 'Finalize faculty grades for student answers first. Only finalized marks are used; AI-suggested marks are never counted.'}
        </p>
      </div>
      <Button variant="primary" size="sm" leftIcon={<Play className="w-3.5 h-3.5" />} onClick={onAnalyze} isLoading={isAnalyzing} data-testid="analyze-performance">
        Analyze Student Performance
      </Button>
    </div>
  );
};
