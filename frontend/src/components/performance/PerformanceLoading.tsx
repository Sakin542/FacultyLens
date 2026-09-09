import React from 'react';
import { BarChart3, Loader2 } from 'lucide-react';

export const PerformanceLoading: React.FC<{ queued?: boolean }> = ({ queued = false }) => (
  <div className="p-6 rounded-xl bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center gap-4" role="status" aria-live="polite" data-testid="performance-loading">
    <div className="w-10 h-10 rounded-lg bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center shrink-0">
      <Loader2 className="w-5 h-5 animate-spin text-[#111111] dark:text-white" />
    </div>
    <div>
      <h4 className="text-sm font-bold text-[#111111] dark:text-white flex items-center gap-2">
        <BarChart3 className="w-3.5 h-3.5 text-[#737373]" /> Student Performance
      </h4>
      <p className="text-xs text-[#737373]">{queued ? 'Analysis is queued and will complete shortly…' : 'Aggregating finalized grades…'}</p>
    </div>
  </div>
);
