import React from 'react';
import { Info } from 'lucide-react';

export const ALIGNMENT_DISCLAIMER =
  'AI-generated rubric alignment is an assistive analysis. It may miss context, nuance, or valid alternative answers. Faculty review remains necessary.';

export const ALIGNMENT_NOT_CORRECTNESS =
  'Alignment indicates evidence of rubric coverage, not correctness. Faculty review is recommended to verify correctness.';

/** Concise, non-intrusive limitation notice. */
export const AlignmentDisclaimer: React.FC<{ className?: string }> = ({ className = '' }) => (
  <p className={`text-[11px] text-[#737373] italic flex items-start gap-1.5 ${className}`} data-testid="alignment-disclaimer">
    <Info className="w-3 h-3 shrink-0 mt-0.5" /> {ALIGNMENT_DISCLAIMER}
  </p>
);
