import React from 'react';
import { ShieldAlert } from 'lucide-react';

interface GradingDisclaimerProps {
  variant?: 'inline' | 'block';
  className?: string;
}

export const GRADING_DISCLAIMER = 'AI-generated grading assistance. Faculty review is required before finalizing marks.';
export const GRADING_DISCLAIMER_LONG =
  'AI grading assistance is a decision-support feature. Faculty review and final judgment are required.';

/**
 * Academic-integrity notice shown before generation, on the AI result, and before finalizing.
 */
export const GradingDisclaimer: React.FC<GradingDisclaimerProps> = ({ variant = 'inline', className = '' }) => {
  if (variant === 'block') {
    return (
      <div
        className={`p-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 flex items-start gap-2 text-amber-800 dark:text-amber-300 ${className}`}
        role="note"
        data-testid="grading-disclaimer"
      >
        <ShieldAlert className="w-4 h-4 shrink-0 mt-0.5" />
        <p className="text-xs leading-relaxed">{GRADING_DISCLAIMER_LONG}</p>
      </div>
    );
  }
  return (
    <p className={`text-[11px] text-[#737373] italic flex items-center gap-1.5 ${className}`} data-testid="grading-disclaimer">
      <ShieldAlert className="w-3 h-3 shrink-0" /> {GRADING_DISCLAIMER}
    </p>
  );
};
