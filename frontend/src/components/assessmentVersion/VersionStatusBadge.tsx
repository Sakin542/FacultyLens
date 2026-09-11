import React from 'react';
import { Badge } from '@/components/common/Badge';
import { VersionStatus } from '@/types/assessmentVersion';
import { humanize, statusVariant } from './versionUtils';

/** STEP 38: status pill used across list, timeline, header and detail. */
export const VersionStatusBadge: React.FC<{ status: VersionStatus | string; label?: string; size?: 'sm' | 'md'; dot?: boolean; className?: string }> = ({ status, label, size = 'sm', dot = true, className }) => (
  <Badge data-testid="version-status-badge" variant={statusVariant(status)} size={size} dot={dot} className={className}>{label ?? humanize(status)}</Badge>
);
