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
    className="p-5 rounded-xl bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center gap-4"
    role="status"
    aria-live="polite"
    data-testid="ai-grading-loading"
  >
    <div className="w-10 h-10 rounded-lg bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center shrink-0">
      <Loader2 className="w-5 h-5 animate-spin text-[#111111] dark:text-white" />
    </div>
    <div className="space-y-0.5">
      <h4 className="text-sm font-bold text-[#111111] dark:text-white flex items-center gap-2">
        <Sparkles className="w-3.5 h-3.5 text-amber-500" /> AI Grading Assistance
      </h4>
      <p className="text-xs text-[#737373]">Analyzing the answer against the approved rubric…</p>
      <p className="text-[11px] text-[#737373]">
        Status: <span className="font-medium text-[#262626] dark:text-[#E5E5E5]">{formatAIGradingStatus(status)}</span>
      </p>
    </div>
  </div>
);
