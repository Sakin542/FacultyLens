import React from 'react';
import { Badge } from '@/components/common/Badge';
import { AIGradingStatus } from '@/types/grading';

type BadgeVariant = 'Analyzed' | 'Pending' | 'Good' | 'Attention' | 'Critical' | 'default' | 'outline' | 'neutral';

const STATUS: Record<AIGradingStatus, { variant: BadgeVariant; label: string }> = {
  PENDING: { variant: 'Pending', label: 'Queued' },
  PROCESSING: { variant: 'Attention', label: 'Processing' },
  COMPLETED: { variant: 'outline', label: 'AI Suggestion Ready' },
  FAILED: { variant: 'Critical', label: 'Failed' },
  REVIEWED: { variant: 'Good', label: 'Faculty Reviewed' },
  FINALIZED: { variant: 'default', label: 'Faculty Final Marks' },
};

export const formatAIGradingStatus = (status: AIGradingStatus): string => STATUS[status]?.label ?? status;

/** Status of an AI grading run (distinct from the submission's grading_status). */
export const GradingStatusBadge: React.FC<{ status: AIGradingStatus; className?: string }> = ({ status, className }) => {
  const meta = STATUS[status] ?? { variant: 'neutral' as BadgeVariant, label: status };
  return (
    <Badge variant={meta.variant} dot className={className} data-testid="ai-grading-status">
      {meta.label}
    </Badge>
  );
};
