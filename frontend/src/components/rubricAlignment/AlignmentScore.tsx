import React from 'react';
import { AlignmentStatus } from '@/types/rubricAlignment';
import { formatAlignmentStatus } from './AlignmentStatusBadge';

interface AlignmentScoreProps {
  score: number | null;
  status: AlignmentStatus | null;
  unweightedScore?: number | null;
  size?: 'md' | 'lg';
}

export const formatPercent = (value: number | null | undefined): string => {
  if (value === null || value === undefined) return '—';
  return `${Number.isInteger(value) ? value : Number(value.toFixed(1))}%`;
};

const RING: Record<AlignmentStatus, string> = {
  STRONG: 'border-emerald-300 dark:border-emerald-700',
  PARTIAL: 'border-amber-300 dark:border-amber-700',
  WEAK: 'border-sage-300 dark:border-[#4A4A4C]',
  NOT_ALIGNED: 'border-red-200 dark:border-red-900',
};

/** Overall mark-weighted alignment percentage with its status. Not a grade. */
export const AlignmentScore: React.FC<AlignmentScoreProps> = ({ score, status, unweightedScore, size = 'lg' }) => (
  <div className="flex items-center gap-4" data-testid="alignment-score">
    <div className={`rounded-full border-4 ${status ? RING[status] : 'border-sage-200'} flex items-center justify-center ${size === 'lg' ? 'w-20 h-20' : 'w-14 h-14'} bg-white dark:bg-[#1C1C1E]`}>
      <span className={`font-mono font-bold text-sage-800 dark:text-white ${size === 'lg' ? 'text-xl' : 'text-sm'}`} data-testid="alignment-percent">
        {formatPercent(score)}
      </span>
    </div>
    <div className="space-y-0.5">
      <span className="block text-[10px] uppercase tracking-wider font-semibold text-sage-500">Overall Alignment</span>
      <span className="block text-sm font-bold text-sage-800 dark:text-white" data-testid="alignment-overall-status">{formatAlignmentStatus(status)}</span>
      <span className="block text-[10px] text-sage-500">
        Mark-weighted{unweightedScore !== null && unweightedScore !== undefined ? ` · unweighted ${formatPercent(unweightedScore)}` : ''}
      </span>
    </div>
  </div>
);
