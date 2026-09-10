import React, { useState } from 'react';
import { Check, Mail, Trash2, UserPlus, X } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { AssignableRole, Collaborator, Invitation, Permissions, ROLE_DESCRIPTIONS, ROLE_LABELS } from '@/types/collaboration';
import { CollaboratorRoleBadge, CollaborationEmptyState } from './CollaborationStates';

/** STEP 34: roster, invitations and management modals. Buttons are hidden by permissions; the API still enforces. */

const Modal: React.FC<{ title: string; onClose: () => void; children: React.ReactNode; testId: string }> = ({ title, onClose, children, testId }) => (
  <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-label={title} data-testid={testId}>
    <div className="w-full max-w-md rounded-xl bg-white dark:bg-[#161616] border border-[#E5E5E5] dark:border-[#2A2A2A] p-5 space-y-4">
      <div className="flex items-center justify-between"><h3 className="text-base font-semibold text-[#111111] dark:text-white">{title}</h3><button type="button" aria-label="Close" onClick={onClose} className="text-[#737373]">✕</button></div>
      {children}
    </div>
  </div>
);

const selectClass = 'w-full rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-3 py-2 text-sm text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#111111] dark:focus:ring-white';

export const RoleSelect: React.FC<{ value: AssignableRole; onChange: (r: AssignableRole) => void; label?: string }> = ({ value, onChange, label = 'Role' }) => (
  <label className="block text-xs font-medium text-[#525252] dark:text-[#A3A3A3]">
    {label}
    <select aria-label={label} className={`${selectClass} mt-1`} value={value} onChange={(e) => onChange(e.target.value as AssignableRole)}>
      {(['EDITOR', 'REVIEWER', 'VIEWER'] as AssignableRole[]).map((r) => <option key={r} value={r}>{ROLE_LABELS[r]}</option>)}
    </select>
    <span className="mt-1 block text-[11px] text-[#A3A3A3]">{ROLE_DESCRIPTIONS[value]}</span>
  </label>
);

export const CollaboratorCard: React.FC<{ collaborator: Collaborator; canManage: boolean; onChangeRole: (c: Collaborator) => void; onRemove: (c: Collaborator) => void }> = ({ collaborator: c, canManage, onChangeRole, onRemove }) => (
  <li data-testid={`collaborator-${c.user?.id ?? c.id}`} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-[#F0F0F0] dark:border-[#1F1F1F] last:border-0">
    <div className="flex items-center gap-3 min-w-0">
      <div className="w-9 h-9 rounded-full bg-[#F7F7F5] dark:bg-[#1F1F1F] border border-[#E5E5E5] dark:border-[#2A2A2A] flex items-center justify-center text-sm font-semibold text-[#111111] dark:text-white">{(c.user?.name ?? '?').slice(0, 1).toUpperCase()}</div>
      <div className="min-w-0">
        <p className="text-sm font-medium text-[#111111] dark:text-white truncate">{c.user?.name ?? 'Unknown user'}</p>
        <p className="text-xs text-[#737373] truncate">{c.user?.email ?? c.user?.department ?? ''}{c.invited_by ? ` · invited by ${c.invited_by.name}` : ''}</p>
      </div>
    </div>
    <div className="flex items-center gap-2">
      <CollaboratorRoleBadge role={c.role} />
      <Badge variant={c.status === 'ACTIVE' ? 'Good' : 'Pending'} data-testid="membership-status">{c.status === 'ACTIVE' ? 'Active' : 'Pending'}</Badge>
      {canManage && (
        <>
          <Button size="sm" variant="outline" onClick={() => onChangeRole(c)} data-testid="change-role-button" disabled={c.status !== 'ACTIVE'}>Change role</Button>
          <Button size="sm" variant="ghost" className="text-red-600" leftIcon={<Trash2 className="w-3.5 h-3.5" />} onClick={() => onRemove(c)} data-testid="remove-button">Remove</Button>
        </>
      )}
    </div>
  </li>
);

export const CollaboratorList: React.FC<{ owner: { id: number; name: string; email?: string | null } | null; collaborators: Collaborator[]; permissions: Permissions; onInvite: () => void; onChangeRole: (c: Collaborator) => void; onRemove: (c: Collaborator) => void }> = ({ owner, collaborators, permissions, onInvite, onChangeRole, onRemove }) => {
  const canManage = !!permissions.manage_collaborators;
  return (
    <section data-testid="collaborator-list" className="rounded-xl border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616]">
      <div className="flex items-center justify-between px-4 py-3 border-b border-[#E5E5E5] dark:border-[#2A2A2A]">
        <h2 className="text-sm font-semibold text-[#111111] dark:text-white">Collaborators</h2>
        {canManage && <Button size="sm" leftIcon={<UserPlus className="w-3.5 h-3.5" />} onClick={onInvite} data-testid="invite-button">Invite Faculty</Button>}
      </div>
      <ul>
        {owner && (
          <li data-testid="owner-row" className="flex items-center justify-between gap-3 px-4 py-3 border-b border-[#F0F0F0] dark:border-[#1F1F1F]">
            <div className="flex items-center gap-3"><div className="w-9 h-9 rounded-full bg-[#111111] dark:bg-white text-white dark:text-[#111111] flex items-center justify-center text-sm font-semibold">{owner.name.slice(0, 1).toUpperCase()}</div>
              <div><p className="text-sm font-medium text-[#111111] dark:text-white">{owner.name}</p><p className="text-xs text-[#737373]">{owner.email ?? 'Course owner'}</p></div></div>
            <CollaboratorRoleBadge role="OWNER" />
          </li>
        )}
        {collaborators.length === 0 ? (
          <li className="p-4"><CollaborationEmptyState title="No collaborators yet" description="Invite faculty members to review and improve this course together." action={canManage ? <Button size="sm" variant="outline" onClick={onInvite}>Invite Faculty</Button> : undefined} /></li>
        ) : collaborators.map((c) => <CollaboratorCard key={c.id} collaborator={c} canManage={canManage} onChangeRole={onChangeRole} onRemove={onRemove} />)}
      </ul>
    </section>
  );
};

export const InvitationCard: React.FC<{ invitation: Invitation; onAccept?: () => void; onDecline?: () => void; onRevoke?: () => void; busy?: boolean }> = ({ invitation: i, onAccept, onDecline, onRevoke, busy }) => (
  <div data-testid={`invitation-${i.id}`} className="rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] p-4 flex flex-wrap items-center justify-between gap-3">
    <div className="min-w-0">
      <p className="text-sm font-medium text-[#111111] dark:text-white flex items-center gap-2"><Mail className="w-4 h-4 text-[#737373]" /> {[i.course?.course_code, i.course?.course_name].filter(Boolean).join(' — ') || 'Course'}</p>
      <p className="text-xs text-[#737373]">{i.invited_email ? `To ${i.invited_email} · ` : ''}Invited by {i.invited_by?.name ?? 'a faculty member'} · Role: {ROLE_LABELS[i.role]}{i.expires_at ? ` · Expires ${new Date(i.expires_at).toLocaleDateString()}` : ''}</p>
      {i.message && <p className="mt-1 text-xs italic text-[#525252] dark:text-[#A3A3A3]">“{i.message}”</p>}
    </div>
    <div className="flex items-center gap-2">
      <Badge variant={i.status === 'PENDING' ? 'Pending' : i.status === 'ACCEPTED' ? 'Good' : 'neutral'}>{i.status}</Badge>
      {onAccept && <Button size="sm" leftIcon={<Check className="w-3.5 h-3.5" />} onClick={onAccept} isLoading={busy} data-testid="accept-invitation">Accept</Button>}
      {onDecline && <Button size="sm" variant="outline" leftIcon={<X className="w-3.5 h-3.5" />} onClick={onDecline} disabled={busy} data-testid="decline-invitation">Decline</Button>}
      {onRevoke && <Button size="sm" variant="ghost" className="text-red-600" onClick={onRevoke} disabled={busy} data-testid="revoke-invitation">Revoke</Button>}
    </div>
  </div>
);

export const InviteCollaboratorModal: React.FC<{ onSubmit: (data: { email: string; role: AssignableRole; message?: string }) => Promise<void> | void; onClose: () => void; submitting?: boolean; error?: string | null }> = ({ onSubmit, onClose, submitting, error }) => {
  const [email, setEmail] = useState('');
  const [role, setRole] = useState<AssignableRole>('REVIEWER');
  const [message, setMessage] = useState('');
  const valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());
  return (
    <Modal title="Invite faculty" onClose={onClose} testId="invite-modal">
      <form noValidate className="space-y-3" onSubmit={(e) => { e.preventDefault(); if (valid) void onSubmit({ email: email.trim(), role, message: message.trim() || undefined }); }}>
        <label className="block text-xs font-medium text-[#525252] dark:text-[#A3A3A3]">Faculty email<Input aria-label="Faculty email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="colleague@university.edu" className="mt-1" /></label>
        <RoleSelect value={role} onChange={setRole} />
        <label className="block text-xs font-medium text-[#525252] dark:text-[#A3A3A3]">Message (optional)
          <textarea aria-label="Invitation message" value={message} onChange={(e) => setMessage(e.target.value)} rows={2} maxLength={1000} className={`${selectClass} mt-1`} />
        </label>
        {error && <p role="alert" className="text-xs text-red-600">{error}</p>}
        <p className="text-[11px] text-[#A3A3A3]">The invitation expires automatically, is single-use, and does not expose course content until accepted.</p>
        <div className="flex justify-end gap-2"><Button type="button" variant="ghost" size="sm" onClick={onClose}>Cancel</Button><Button type="submit" size="sm" disabled={!valid} isLoading={submitting} data-testid="send-invitation">Send invitation</Button></div>
      </form>
    </Modal>
  );
};

export const ChangeRoleModal: React.FC<{ collaborator: Collaborator; onConfirm: (role: AssignableRole) => Promise<void> | void; onClose: () => void; submitting?: boolean }> = ({ collaborator: c, onConfirm, onClose, submitting }) => {
  const [role, setRole] = useState<AssignableRole>((c.role === 'OWNER' ? 'EDITOR' : c.role) as AssignableRole);
  return (
    <Modal title={`Change ${c.user?.name ?? 'collaborator'}'s role`} onClose={onClose} testId="change-role-modal">
      <p className="text-sm text-[#525252] dark:text-[#A3A3A3]">Current role: <strong>{ROLE_LABELS[c.role]}</strong></p>
      <RoleSelect value={role} onChange={setRole} label="New role" />
      <div className="flex justify-end gap-2"><Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" disabled={role === c.role} isLoading={submitting} onClick={() => void onConfirm(role)} data-testid="confirm-change-role">Change role</Button></div>
    </Modal>
  );
};

export const RemoveCollaboratorModal: React.FC<{ collaborator: Collaborator; courseLabel: string; onConfirm: () => Promise<void> | void; onClose: () => void; submitting?: boolean }> = ({ collaborator: c, courseLabel, onConfirm, onClose, submitting }) => (
  <Modal title="Remove collaborator" onClose={onClose} testId="remove-modal">
    <p className="text-sm text-[#525252] dark:text-[#A3A3A3]">Remove <strong>{c.user?.name ?? 'this collaborator'}</strong> from {courseLabel}? They will lose access to this course and its collaborative resources. Their past comments and review history remain attributable.</p>
    <div className="flex justify-end gap-2"><Button variant="ghost" size="sm" onClick={onClose}>Cancel</Button><Button size="sm" variant="danger" isLoading={submitting} onClick={() => void onConfirm()} data-testid="confirm-remove">Remove</Button></div>
  </Modal>
);
