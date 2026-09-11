import React, { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft } from 'lucide-react';
import { useAuth } from '@/context/AuthContext';
import { collaborationService } from '@/services/collaborationService';
import { AssignableRole, CollaborationOverview, Collaborator, Invitation } from '@/types/collaboration';
import { CollaborationError, CollaborationHeader, CollaborationLoading, getCollaborationErrorMessage } from '@/components/collaboration/CollaborationStates';
import { ChangeRoleModal, CollaboratorList, InvitationCard, InviteCollaboratorModal, RemoveCollaboratorModal } from '@/components/collaboration/CollaboratorPanels';
import { CollaborationActivity } from '@/components/collaboration/CollaborationActivity';
import { CollaborationComments } from '@/components/collaboration/CollaborationComments';

/**
 * STEP 34: /courses/:courseId/collaboration — roster, invitations, course-level discussion and activity.
 * Management controls render only when the server says manage_collaborators; the API enforces regardless.
 */
export const CourseCollaboration: React.FC = () => {
  const { courseId } = useParams<{ courseId: string }>();
  const { user } = useAuth();
  const [overview, setOverview] = useState<CollaborationOverview | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [inviteOpen, setInviteOpen] = useState(false);
  const [inviteError, setInviteError] = useState<string | null>(null);
  const [roleTarget, setRoleTarget] = useState<Collaborator | null>(null);
  const [removeTarget, setRemoveTarget] = useState<Collaborator | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    if (!courseId) return;
    setLoading(true); setError(null);
    try { setOverview((await collaborationService.getCollaboration(courseId)).data); } catch (e) { setError(getCollaborationErrorMessage(e)); } finally { setLoading(false); }
  }, [courseId]);
  useEffect(() => { void load(); }, [load]);

  const run = async (fn: () => Promise<string | undefined>) => { setBusy(true); setError(null); try { const msg = await fn(); if (msg) setNotice(msg); await load(); } catch (e) { setError(getCollaborationErrorMessage(e)); } finally { setBusy(false); } };

  const invite = async (data: { email: string; role: AssignableRole; message?: string }) => {
    if (!courseId) return;
    setBusy(true); setInviteError(null);
    try {
      const res = await collaborationService.inviteCollaborator(courseId, data);
      setInviteOpen(false);
      setNotice(res.data.accept_url ? `Invitation created. Mail is not configured in this environment — share this link: ${res.data.accept_url}` : (res.message ?? 'Invitation sent.'));
      await load();
    } catch (e) { setInviteError(getCollaborationErrorMessage(e)); } finally { setBusy(false); }
  };

  const courseLabel = overview ? [overview.course.course_code, overview.course.course_name].filter(Boolean).join(' — ') : '';
  const perms = overview?.permissions ?? {};

  return (
    <div className="space-y-4" data-testid="course-collaboration-page">
      <Link to={`/courses/${courseId}`} className="inline-flex items-center gap-1 text-xs text-sage-500 hover:text-sage-800 dark:hover:text-white"><ArrowLeft className="w-3.5 h-3.5" /> Back to course</Link>
      {loading && !overview ? <CollaborationLoading /> : error && !overview ? <CollaborationError message={error} onRetry={load} /> : overview && (
        <>
          <CollaborationHeader courseCode={overview.course.course_code} courseName={overview.course.course_name} ownerName={overview.owner?.name} role={overview.current_user.role} memberCount={overview.collaborators.filter((c) => c.status === 'ACTIVE').length} permissions={perms} />
          {error && <CollaborationError message={error} />}
          {notice && <p className="text-sm text-emerald-700 dark:text-emerald-300 break-all" data-testid="collaboration-notice">{notice}</p>}
          <div className="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-4">
            <div className="space-y-4">
              <CollaboratorList owner={overview.owner} collaborators={overview.collaborators} permissions={perms} onInvite={() => { setInviteError(null); setInviteOpen(true); }} onChangeRole={setRoleTarget} onRemove={setRemoveTarget} />
              {perms.manage_collaborators && overview.pending_invitations.length > 0 && (
                <section data-testid="pending-invitations" className="space-y-2">
                  <h2 className="text-sm font-semibold text-sage-800 dark:text-white">Pending invitations</h2>
                  {overview.pending_invitations.map((i: Invitation) => <InvitationCard key={i.id} invitation={i} busy={busy} onRevoke={() => run(async () => { await collaborationService.revokeInvitation(courseId!, i.id); return 'Invitation revoked.'; })} />)}
                </section>
              )}
              <CollaborationComments courseId={courseId!} commentableType="course" commentableId={courseId!} currentUserId={user ? Number(user.id) : undefined} canComment={!!perms.comment} canResolve={!!perms.approve_recommendation} title="Course discussion" />
            </div>
            <CollaborationActivity courseId={courseId!} />
          </div>
        </>
      )}

      {inviteOpen && <InviteCollaboratorModal onSubmit={invite} onClose={() => setInviteOpen(false)} submitting={busy} error={inviteError} />}
      {roleTarget && <ChangeRoleModal collaborator={roleTarget} submitting={busy} onClose={() => setRoleTarget(null)} onConfirm={(role) => run(async () => { await collaborationService.changeCollaboratorRole(courseId!, roleTarget.user!.id, role); setRoleTarget(null); return `${roleTarget.user?.name} is now ${role.toLowerCase()}.`; })} />}
      {removeTarget && <RemoveCollaboratorModal collaborator={removeTarget} courseLabel={courseLabel} submitting={busy} onClose={() => setRemoveTarget(null)} onConfirm={() => run(async () => { await collaborationService.removeCollaborator(courseId!, removeTarget.user!.id); setRemoveTarget(null); return `${removeTarget.user?.name} was removed.`; })} />}
    </div>
  );
};
