import React from 'react';
import { Loader2, Sparkles } from 'lucide-react';
import { AIGradingStatus } from '@/types/grading';
import { formatAIGradingStatus } from './GradingStatusBadge';

interface AIGradingLoadingProps {
  status?: AIGradingStatus;
}

/**
 * Shown while the AI grading job is queued/processing. No fake progress percentage.
 */
export const AIGradingLoading: React.FC<AIGradingLoadingProps> = ({ status = 'PROCESSING' }) => (
  <div
    className="p-5 rounded-xl bg-sage-100 dark:bg-[#2C2C2E] border border-sage-200 dark:border-[#3A3A3C] flex items-center gap-4"
    role="status"
    aria-live="polite"
    data-testid="ai-grading-loading"
  >
    <div className="w-10 h-10 rounded-lg bg-white dark:bg-[#1C1C1E] border border-sage-200 dark:border-[#3A3A3C] flex items-center justify-center shrink-0">
      <Loader2 className="w-5 h-5 animate-spin text-sage-800 dark:text-white" />
    </div>
    <div className="space-y-0.5">
      <h4 className="text-sm font-bold text-sage-800 dark:text-white flex items-center gap-2">
        <Sparkles className="w-3.5 h-3.5 text-amber-500" /> AI Grading Assistance
      </h4>
      <p className="text-xs text-sage-500">Analyzing the answer against the approved rubric…</p>
      <p className="text-[11px] text-sage-500">
        Status: <span className="font-medium text-sage-700 dark:text-sage-200">{formatAIGradingStatus(status)}</span>
      </p>
    </div>
  </div>
);
