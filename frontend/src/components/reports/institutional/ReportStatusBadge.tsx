import React from 'react';
import { Badge, BadgeVariant } from '@/components/common/Badge';
import { ReportStatus } from '@/types/report';

const MAP: Record<ReportStatus, { label: string; variant: BadgeVariant }> = {
  PENDING: { label: 'Pending', variant: 'Pending' },
  PROCESSING: { label: 'Processing', variant: 'Pending' },
  COMPLETED: { label: 'Completed', variant: 'Good' },
  FAILED: { label: 'Failed', variant: 'Critical' },
  CANCELLED: { label: 'Cancelled', variant: 'neutral' },
};

export const ReportStatusBadge: React.FC<{ status: ReportStatus; expired?: boolean; className?: string }> = ({ status, expired, className }) => {
  if (status === 'COMPLETED' && expired) {
    return <Badge variant="Attention" size="sm" className={className} data-testid="report-status">Expired</Badge>;
  }
  const m = MAP[status] ?? { label: status, variant: 'neutral' as BadgeVariant };
  return <Badge variant={m.variant} size="sm" dot={status === 'PROCESSING' || status === 'PENDING'} className={className} data-testid="report-status">{m.label}</Badge>;
};
