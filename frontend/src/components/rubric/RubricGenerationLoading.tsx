import React from 'react';
import { Loader2, Sparkles } from 'lucide-react';

/**
 * Shown while the AI service drafts a rubric. No fake progress percentage is displayed.
 */
export const RubricGenerationLoading: React.FC = () => (
  <div
    className="p-8 text-center space-y-4 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] rounded-2xl"
    role="status"
    aria-live="polite"
  >
    <div className="w-12 h-12 rounded-xl bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center mx-auto">
      <Loader2 className="w-6 h-6 animate-spin text-[#111111] dark:text-white" />
    </div>
    <div className="space-y-1">
      <h3 className="text-sm font-bold text-[#111111] dark:text-white flex items-center justify-center gap-2">
        <Sparkles className="w-4 h-4 text-amber-500" />
        Generating rubric...
      </h3>
      <p className="text-xs text-[#737373] max-w-sm mx-auto leading-relaxed">
        FacultyLens is analyzing the question and preparing a draft marking rubric.
      </p>
    </div>
  </div>
);
