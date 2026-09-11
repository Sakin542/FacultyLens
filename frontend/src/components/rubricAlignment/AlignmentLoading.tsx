import React from 'react';
import { Loader2, Target } from 'lucide-react';
import { AlignmentAnalysisStatus } from '@/types/rubricAlignment';

/** Shown while the alignment job is queued/processing. No fake progress percentage. */
export const AlignmentLoading: React.FC<{ status?: AlignmentAnalysisStatus }> = ({ status = 'PROCESSING' }) => (
  <div
    className="p-5 rounded-xl bg-sage-100 dark:bg-[#2C2C2E] border border-sage-200 dark:border-[#3A3A3C] flex items-center gap-4"
    role="status"
    aria-live="polite"
    data-testid="alignment-loading"
  >
    <div className="w-10 h-10 rounded-lg bg-white dark:bg-[#1C1C1E] border border-sage-200 dark:border-[#3A3A3C] flex items-center justify-center shrink-0">
      <Loader2 className="w-5 h-5 animate-spin text-sage-800 dark:text-white" />
    </div>
    <div className="space-y-0.5">
      <h4 className="text-sm font-bold text-sage-800 dark:text-white flex items-center gap-2">
        <Target className="w-3.5 h-3.5 text-sage-500" /> Answer ↔ Rubric Alignment
      </h4>
      <p className="text-xs text-sage-500">Comparing the answer with each approved rubric criterion…</p>
      <p className="text-[11px] text-sage-500">
        Status: <span className="font-medium text-sage-700 dark:text-sage-200">{status === 'PENDING' ? 'Queued' : 'Processing'}</span>
      </p>
    </div>
  </div>
);
