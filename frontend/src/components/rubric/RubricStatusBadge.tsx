import React from 'react';
import { Badge, BadgeVariant } from '@/components/common/Badge';
import { RubricStatus } from '@/types/rubric';

const STATUS_CONFIG: Record<RubricStatus, { variant: BadgeVariant; label: string }> = {
  DRAFT: { variant: 'Pending', label: 'Draft' },
  APPROVED: { variant: 'Good', label: 'Approved' },
  ARCHIVED: { variant: 'neutral', label: 'Archived' },
};

interface RubricStatusBadgeProps {
  status: RubricStatus;
  className?: string;
}

export const RubricStatusBadge: React.FC<RubricStatusBadgeProps> = ({ status, className }) => {
  const config = STATUS_CONFIG[status] ?? STATUS_CONFIG.DRAFT;
  return (
    <Badge variant={config.variant} dot className={className} data-testid="rubric-status-badge">
      {config.label}
    </Badge>
  );
};
