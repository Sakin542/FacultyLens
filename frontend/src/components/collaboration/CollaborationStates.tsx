import React from 'react';
import { AlertTriangle, Crown, Eye, Loader2, PenLine, Search, Users } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { ApiError } from '@/services/api';
import { CollaborationRole, PermissionKey, Permissions, ROLE_LABELS } from '@/types/collaboration';
import { cn } from '@/utils/cn';

/** STEP 34: small shared collaboration primitives. */

export const CollaboratorRoleBadge: React.FC<{ role: CollaborationRole | null | undefined; className?: string }> = ({ role, className }) => {
  if (!role) return null;
  const variant = role === 'OWNER' ? 'Good' : role === 'EDITOR' ? 'Analyzed' : role === 'REVIEWER' ? 'Attention' : 'neutral';
  const Icon = role === 'OWNER' ? Crown : role === 'EDITOR' ? PenLine : role === 'REVIEWER' ? Search : Eye;
  return (
    <Badge variant={variant} className={cn('inline-flex items-center gap-1', className)} data-testid="role-badge">
      <Icon className="w-3 h-3" /> {ROLE_LABELS[role]}
    </Badge>
  );
};

export const PermissionBadge: React.FC<{ permissions: Permissions | undefined; permission: PermissionKey; label: string }> = ({ permissions, permission, label }) => (
  <span data-testid={`permission-${permission}`} className={cn('inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px]', permissions?.[permission] ? 'border-emerald-200 text-emerald-700 bg-emerald-50 dark:bg-emerald-950/30 dark:text-emerald-300' : 'border-[#E5E5E5] dark:border-[#2A2A2A] text-[#A3A3A3] line-through')}>
    {label}
  </span>
);

export const CollaborationLoading: React.FC<{ label?: string }> = ({ label = 'Loading collaboration…' }) => (
  <div data-testid="collaboration-loading" role="status" className="flex items-center gap-2 text-sm text-[#737373] px-4 py-6">
    <Loader2 className="w-4 h-4 animate-spin" /> {label}
  </div>
);

export const CollaborationEmptyState: React.FC<{ title: string; description: string; icon?: React.ReactNode; action?: React.ReactNode; className?: string }> = ({ title, description, icon, action, className }) => (
  <div data-testid="collaboration-empty-state" className={cn('flex flex-col items-center justify-center text-center py-10 px-6 rounded-xl border border-dashed border-[#E5E5E5] dark:border-[#2A2A2A]', className)}>
    <div className="w-12 h-12 rounded-full bg-[#F7F7F5] dark:bg-[#1F1F1F] border border-[#E5E5E5] dark:border-[#2A2A2A] flex items-center justify-center text-[#737373] mb-3">{icon ?? <Users className="w-6 h-6" />}</div>
    <h4 className="text-base font-semibold text-[#111111] dark:text-white mb-1">{title}</h4>
    <p className="text-sm text-[#737373] max-w-md mb-4">{description}</p>
    {action}
  </div>
);

export function getCollaborationErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.status === 401) return 'Your session has expired. Please sign in again.';
    if (err.status === 403) return err.message || 'You do not have permission to perform this action.';
    if (err.status === 404) return err.message || 'Not found.';
    if (err.status === 409) return err.message || 'This item changed since you loaded it. Reload and try again.';
    if (err.status === 410) return err.message || 'This invitation is no longer valid.';
    if (err.status === 422) return err.message || 'Please check the form and try again.';
    if (err.status === 429) return 'Too many requests. Please wait a moment and try again.';
    return err.message || 'Something went wrong.';
  }
  return err instanceof Error ? err.message : 'Something went wrong.';
}

export const CollaborationError: React.FC<{ message: string; onRetry?: () => void; className?: string }> = ({ message, onRetry, className }) => (
  <div data-testid="collaboration-error" role="alert" className={cn('flex items-start gap-2 rounded-lg border border-red-200 dark:border-red-900/50 bg-red-50 dark:bg-red-950/30 px-3 py-2 text-sm text-red-700 dark:text-red-300', className)}>
    <AlertTriangle className="w-4 h-4 mt-0.5 flex-shrink-0" />
    <div className="flex-1"><p>{message}</p>{onRetry && <button type="button" onClick={onRetry} className="mt-1 text-xs underline underline-offset-2">Try again</button>}</div>
  </div>
);

export const CollaborationHeader: React.FC<{ courseCode?: string | null; courseName?: string | null; ownerName?: string | null; role: CollaborationRole | null; memberCount: number; permissions?: Permissions }> = ({ courseCode, courseName, ownerName, role, memberCount, permissions }) => (
  <header data-testid="collaboration-header" className="rounded-xl border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] p-5 space-y-3">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 className="text-xl font-bold text-[#111111] dark:text-white flex items-center gap-2"><Users className="w-5 h-5" /> Course Collaboration</h1>
        <p className="text-sm text-[#737373]">{[courseCode, courseName].filter(Boolean).join(' — ')}{ownerName ? ` · Owner: ${ownerName}` : ''} · {memberCount} collaborator{memberCount === 1 ? '' : 's'}</p>
      </div>
      <div className="flex items-center gap-2 text-xs text-[#737373]">Your role: <CollaboratorRoleBadge role={role} /></div>
    </div>
    <div className="flex flex-wrap gap-1.5">
      <PermissionBadge permissions={permissions} permission="edit_assessment" label="Edit assessments" />
      <PermissionBadge permissions={permissions} permission="approve_generated_question" label="Approve drafts" />
      <PermissionBadge permissions={permissions} permission="comment" label="Comment" />
      <PermissionBadge permissions={permissions} permission="view_student_data" label="Student data" />
      <PermissionBadge permissions={permissions} permission="manage_collaborators" label="Manage collaborators" />
    </div>
    <p className="text-[11px] text-[#A3A3A3]">Collaboration provides controlled faculty review and discussion. AI findings remain assistive and never become decisions automatically.</p>
  </header>
);
