import React from 'react';
import { Badge, BadgeVariant } from '@/components/common/Badge';
import { AnswerStatus, GradingStatus, SubmissionStatus } from '@/types/submission';

const SUBMISSION: Record<SubmissionStatus, { variant: BadgeVariant; label: string }> = {
  DRAFT: { variant: 'neutral', label: 'Draft' },
  SUBMITTED: { variant: 'Pending', label: 'Submitted' },
  UNDER_REVIEW: { variant: 'Attention', label: 'Under Review' },
  GRADED: { variant: 'Good', label: 'Graded' },
  RETURNED: { variant: 'default', label: 'Returned' },
};

const GRADING: Record<GradingStatus, { variant: BadgeVariant; label: string }> = {
  NOT_STARTED: { variant: 'neutral', label: 'Not Started' },
  IN_PROGRESS: { variant: 'Attention', label: 'In Progress' },
  AI_ASSISTED: { variant: 'outline', label: 'AI Assisted' },
  FACULTY_REVIEWED: { variant: 'Good', label: 'Faculty Reviewed' },
  FINALIZED: { variant: 'default', label: 'Finalized' },
};

const ANSWER: Record<AnswerStatus, { variant: BadgeVariant; label: string }> = {
  NOT_REVIEWED: { variant: 'neutral', label: 'Not Reviewed' },
  UNDER_REVIEW: { variant: 'Attention', label: 'Under Review' },
  REVIEWED: { variant: 'Good', label: 'Reviewed' },
};

export const formatSubmissionStatus = (s: SubmissionStatus) => SUBMISSION[s]?.label ?? s;
export const formatGradingStatus = (s: GradingStatus) => GRADING[s]?.label ?? s;
export const formatAnswerStatus = (s: AnswerStatus) => ANSWER[s]?.label ?? s;

export const SubmissionStatusBadge: React.FC<{ status: SubmissionStatus; className?: string }> = ({ status, className }) => {
  const c = SUBMISSION[status] ?? { variant: 'neutral' as BadgeVariant, label: status };
  return <Badge variant={c.variant} dot className={className} data-testid="submission-status-badge">{c.label}</Badge>;
};

export const GradingStatusBadge: React.FC<{ status: GradingStatus; className?: string }> = ({ status, className }) => {
  const c = GRADING[status] ?? { variant: 'neutral' as BadgeVariant, label: status };
  return <Badge variant={c.variant} className={className} data-testid="grading-status-badge">{c.label}</Badge>;
};

export const AnswerStatusBadge: React.FC<{ status: AnswerStatus; className?: string }> = ({ status, className }) => {
  const c = ANSWER[status] ?? { variant: 'neutral' as BadgeVariant, label: status };
  return <Badge variant={c.variant} className={className} data-testid="answer-status-badge">{c.label}</Badge>;
};
