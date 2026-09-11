import React from 'react';
import { Archive, CheckCircle2, GitCompare, Lock, Pencil, Plus, RotateCcw, Send } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { AssessmentVersionSummary, VersionPermissions } from '@/types/assessmentVersion';

/**
 * STEP 38: state-aware actions. Drafts can be edited/submitted/approved/finalized; anything locked
 * offers "Create new version" / "Restore as new version" as the primary path (never in-place edits).
 */
export const VersionActions: React.FC<{
  version: AssessmentVersionSummary;
  permissions: VersionPermissions;
  busy?: boolean;
  editing?: boolean;
  onEdit: () => void;
  onSubmitReview: () => void;
  onApprove: () => void;
  onFinalize: () => void;
  onArchive: () => void;
  onRestore: () => void;
  onNewVersion: () => void;
  onCompare: () => void;
}> = ({ version: v, permissions: p, busy, editing, onEdit, onSubmitReview, onApprove, onFinalize, onArchive, onRestore, onNewVersion, onCompare }) => {
  const locked = !v.is_editable;
  const canFinalize = ['DRAFT', 'IN_REVIEW', 'APPROVED'].includes(v.status) && !v.has_submissions;
  return (
    <div data-testid="version-actions" className="flex flex-wrap items-center gap-2" role="group" aria-label="Version actions">
      {p.edit && !locked && <Button size="sm" variant={editing ? 'secondary' : 'outline'} onClick={onEdit} disabled={busy} leftIcon={<Pencil className="w-3.5 h-3.5" />}>{editing ? 'Cancel editing' : 'Edit draft'}</Button>}
      {p.edit && v.status === 'DRAFT' && !v.has_submissions && <Button size="sm" variant="outline" onClick={onSubmitReview} disabled={busy} leftIcon={<Send className="w-3.5 h-3.5" />}>Submit for review</Button>}
      {p.approve && ['DRAFT', 'IN_REVIEW'].includes(v.status) && !v.has_submissions && <Button size="sm" variant="outline" onClick={onApprove} disabled={busy} leftIcon={<CheckCircle2 className="w-3.5 h-3.5" />}>Approve</Button>}
      {p.finalize && canFinalize && <Button size="sm" onClick={onFinalize} disabled={busy} leftIcon={<Lock className="w-3.5 h-3.5" />} title="Validate and lock this version as an immutable historical record">Finalize</Button>}
      {p.edit && locked && v.status !== 'ARCHIVED' && <Button size="sm" onClick={onNewVersion} disabled={busy} leftIcon={<Plus className="w-3.5 h-3.5" />}>Create new version</Button>}
      {p.restore && (locked || v.status === 'ARCHIVED') && <Button size="sm" variant="outline" onClick={onRestore} disabled={busy} leftIcon={<RotateCcw className="w-3.5 h-3.5" />}>Restore as new version</Button>}
      <Button size="sm" variant="outline" onClick={onCompare} disabled={busy} leftIcon={<GitCompare className="w-3.5 h-3.5" />}>Compare</Button>
      {p.archive && v.status !== 'ARCHIVED' && <Button size="sm" variant="ghost" onClick={onArchive} disabled={busy} leftIcon={<Archive className="w-3.5 h-3.5" />} className="text-sage-500">Archive</Button>}
      {locked && v.status !== 'ARCHIVED' && <span className="text-xs text-sage-500 inline-flex items-center gap-1"><Lock className="w-3 h-3" aria-hidden="true" />{v.has_submissions && v.status !== 'FINALIZED' ? 'Locked: student submissions reference this version.' : `${v.status === 'FINALIZED' ? 'Finalized' : 'Approved'} versions are immutable.`}</span>}
    </div>
  );
};
