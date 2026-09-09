import React from 'react';
import { Badge } from '@/components/common/Badge';
import { PerformanceStatus } from '@/types/performance';

type BadgeVariant = 'Analyzed' | 'Pending' | 'Good' | 'Attention' | 'Critical' | 'default' | 'outline' | 'neutral';

const STATUS: Record<PerformanceStatus, { variant: BadgeVariant; label: string }> = {
  STRONG: { variant: 'Good', label: 'Strong' },
  ON_TARGET: { variant: 'Analyzed', label: 'On Target' },
  MINOR_GAP: { variant: 'neutral', label: 'Minor Gap' },
  MODERATE_GAP: { variant: 'Attention', label: 'Moderate Gap' },
  HIGH_GAP: { variant: 'Critical', label: 'High Gap' },
  INSUFFICIENT_DATA: { variant: 'outline', label: 'Insufficient Data' },
};

export const formatPerformanceStatus = (status: PerformanceStatus | null | undefined): string =>
  status ? STATUS[status]?.label ?? status : '—';

export const formatPct = (value: number | null | undefined, digits = 1): string => {
  if (value === null || value === undefined) return '—';
  return `${Number.isInteger(value) ? value : Number(value.toFixed(digits))}%`;
};

export const formatGap = (gap: number | null | undefined): string => {
  if (gap === null || gap === undefined) return '—';
  if (gap <= 0) return '—';
  return `${Number.isInteger(gap) ? gap : Number(gap.toFixed(1))} pts`;
};

/** Subtle status badge; wording is about performance areas, never about students. */
export const PerformanceGapBadge: React.FC<{ status: PerformanceStatus | null | undefined; className?: string }> = ({ status, className }) => {
  if (!status) return null;
  const meta = STATUS[status] ?? { variant: 'neutral' as BadgeVariant, label: status };
  return (
    <Badge variant={meta.variant} dot className={className} data-testid="performance-status">
      {meta.label}
    </Badge>
  );
};
