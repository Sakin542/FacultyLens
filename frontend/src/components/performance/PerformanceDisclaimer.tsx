import React from 'react';
import { Info } from 'lucide-react';

export const PERFORMANCE_LIMITATIONS =
  'Performance analysis is based on available finalized grading data. Aggregate results may be affected by sample size, missing responses, assessment design, grading variation, and question characteristics. Identified gaps are signals for faculty review rather than definitive conclusions about student learning.';

export const PerformanceDisclaimer: React.FC<{ text?: string; className?: string }> = ({ text = PERFORMANCE_LIMITATIONS, className = '' }) => (
  <p className={`text-[11px] text-sage-500 italic flex items-start gap-1.5 ${className}`} data-testid="performance-disclaimer">
    <Info className="w-3 h-3 shrink-0 mt-0.5" /> {text}
  </p>
);
