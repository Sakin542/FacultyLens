import React from 'react';
import { Badge } from '@/components/common/Badge';
import { CoStatus, FindingSeverity, PoStatus, QuestionCoStatus } from '@/types/coPo';

type BadgeVariant = 'Analyzed' | 'Pending' | 'Good' | 'Attention' | 'Critical' | 'default' | 'outline' | 'neutral';

const CO: Record<CoStatus, { variant: BadgeVariant; label: string }> = {
  STRONG: { variant: 'Good', label: 'Strong' },
  ON_TARGET: { variant: 'Analyzed', label: 'On Target' },
  REVIEW: { variant: 'Attention', label: 'Review' },
  LOW_COVERAGE: { variant: 'Attention', label: 'Low Coverage' },
  NOT_ASSESSED: { variant: 'Critical', label: 'Not Assessed' },
  NO_PERFORMANCE_DATA: { variant: 'outline', label: 'No Performance Data' },
};
const PO: Record<PoStatus, { variant: BadgeVariant; label: string }> = {
  EVIDENCE_AVAILABLE: { variant: 'Good', label: 'Evidence Available' },
  REVIEW: { variant: 'Attention', label: 'Review' },
  LIMITED_EVIDENCE: { variant: 'neutral', label: 'Limited Evidence' },
  NOT_MAPPED: { variant: 'outline', label: 'Not Mapped' },
};
const SEV: Record<FindingSeverity, { variant: BadgeVariant; label: string }> = {
  HIGH: { variant: 'Critical', label: 'High' },
  MEDIUM: { variant: 'Attention', label: 'Medium' },
  LOW: { variant: 'neutral', label: 'Low' },
  INFO: { variant: 'outline', label: 'Info' },
};
const QSTATUS: Record<QuestionCoStatus, { variant: BadgeVariant; label: string }> = {
  PENDING: { variant: 'Pending', label: 'Pending Faculty Review' },
  CONFIRMED: { variant: 'Good', label: 'Faculty Confirmed' },
  REJECTED: { variant: 'neutral', label: 'Rejected' },
};

export const formatCoStatus = (s: CoStatus): string => CO[s]?.label ?? s;
export const formatPoStatus = (s: PoStatus): string => PO[s]?.label ?? s;

export const MappingStatusBadge: React.FC<{ kind: 'co' | 'po' | 'severity' | 'question'; status: string; className?: string }> = ({ kind, status, className }) => {
  const table = kind === 'co' ? CO : kind === 'po' ? PO : kind === 'severity' ? SEV : QSTATUS;
  const meta = (table as Record<string, { variant: BadgeVariant; label: string }>)[status] ?? { variant: 'neutral' as BadgeVariant, label: status.replace(/_/g, ' ') };
  return <Badge variant={meta.variant} dot={kind !== 'severity'} className={className} data-testid={`mapping-status-${kind}`}>{meta.label}</Badge>;
};
