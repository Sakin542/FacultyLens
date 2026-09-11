import React from 'react';
import { Loader2 } from 'lucide-react';

export const ReportLoading: React.FC<{ message?: string; compact?: boolean }> = ({ message = 'Loading reports…', compact }) => (
  <div role="status" aria-live="polite" className={`flex flex-col items-center justify-center text-center bg-white border border-sage-200 rounded-xl ${compact ? 'p-6 min-h-[160px]' : 'p-10 min-h-[320px]'}`} data-testid="report-loading">
    <Loader2 className="w-7 h-7 text-sage-700 animate-spin mb-3" />
    <p className="text-sm font-medium text-sage-800">{message}</p>
  </div>
);
