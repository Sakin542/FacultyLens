import React from 'react';
import { PerformanceStatus } from '@/types/performance';

const BAR: Record<PerformanceStatus, string> = {
  STRONG: 'bg-emerald-500',
  ON_TARGET: 'bg-[#111111] dark:bg-white',
  MINOR_GAP: 'bg-[#A3A3A3]',
  MODERATE_GAP: 'bg-amber-500',
  HIGH_GAP: 'bg-red-400',
  INSUFFICIENT_DATA: 'bg-[#E5E5E5] dark:bg-[#3A3A3C]',
};

interface PerformanceBarProps {
  value: number | null;
  status: PerformanceStatus;
  expected: number;
}

/** Horizontal bar with a benchmark marker; subtle colouring by status. */
export const PerformanceBar: React.FC<PerformanceBarProps> = ({ value, status, expected }) => (
  <div className="relative w-full h-2.5 rounded-full bg-[#E5E5E5] dark:bg-[#3A3A3C] overflow-hidden" aria-hidden="true" data-testid="performance-bar">
    <div className="absolute top-0 bottom-0 w-0.5 bg-[#737373] z-10" style={{ left: `${Math.min(Math.max(expected, 0), 100)}%` }} />
    {value !== null && <div className={`h-full rounded-full ${BAR[status]}`} style={{ width: `${Math.min(Math.max(value, 0), 100)}%` }} />}
  </div>
);
