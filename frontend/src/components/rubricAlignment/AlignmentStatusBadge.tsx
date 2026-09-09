import React from 'react';
import { Badge } from '@/components/common/Badge';
import { AlignmentStatus } from '@/types/rubricAlignment';

type BadgeVariant = 'Analyzed' | 'Pending' | 'Good' | 'Attention' | 'Critical' | 'default' | 'outline' | 'neutral';

const STATUS: Record<AlignmentStatus, { variant: BadgeVariant; label: string; symbol: string }> = {
  STRONG: { variant: 'Good', label: 'Strong', symbol: '✓' },
  PARTIAL: { variant: 'Attention', label: 'Partial', symbol: '◐' },
  WEAK: { variant: 'neutral', label: 'Weak', symbol: '◔' },
  NOT_ALIGNED: { variant: 'outline', label: 'Not Aligned', symbol: '✕' },
};

export const formatAlignmentStatus = (status: AlignmentStatus | null | undefined): string =>
  status ? STATUS[status]?.label ?? status : '—';

export const alignmentSymbol = (status: AlignmentStatus): string => STATUS[status]?.symbol ?? '';

/** Subtle semantic badge for STRONG / PARTIAL / WEAK / NOT_ALIGNED (alignment, not correctness). */
export const AlignmentStatusBadge: React.FC<{ status: AlignmentStatus; className?: string; showSymbol?: boolean }> = ({ status, className, showSymbol = false }) => {
  const meta = STATUS[status] ?? { variant: 'neutral' as BadgeVariant, label: status, symbol: '' };
  return (
    <Badge variant={meta.variant} dot={!showSymbol} className={className} data-testid="alignment-status">
      {showSymbol ? `${meta.symbol} ${meta.label}` : meta.label}
    </Badge>
  );
};
