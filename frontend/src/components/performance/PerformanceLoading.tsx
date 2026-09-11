import React from 'react';
import { BarChart3, Loader2 } from 'lucide-react';

export const PerformanceLoading: React.FC<{ queued?: boolean }> = ({ queued = false }) => (
  <div className="p-6 rounded-xl bg-sage-100 dark:bg-[#2C2C2E] border border-sage-200 dark:border-[#3A3A3C] flex items-center gap-4" role="status" aria-live="polite" data-testid="performance-loading">
    <div className="w-10 h-10 rounded-lg bg-white dark:bg-[#1C1C1E] border border-sage-200 dark:border-[#3A3A3C] flex items-center justify-center shrink-0">
      <Loader2 className="w-5 h-5 animate-spin text-sage-800 dark:text-white" />
    </div>
    <div>
      <h4 className="text-sm font-bold text-sage-800 dark:text-white flex items-center gap-2">
        <BarChart3 className="w-3.5 h-3.5 text-sage-500" /> Student Performance
      </h4>
      <p className="text-xs text-sage-500">{queued ? 'Analysis is queued and will complete shortly…' : 'Aggregating finalized grades…'}</p>
    </div>
  </div>
);
