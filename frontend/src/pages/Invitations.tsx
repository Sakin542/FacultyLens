import React, { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Mail } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { useAuth } from '@/context/AuthContext';
import { collaborationService } from '@/services/collaborationService';
import { Invitation, ROLE_DESCRIPTIONS, ROLE_LABELS } from '@/types/collaboration';
import { InvitationCard } from '@/components/collaboration/CollaboratorPanels';
import { CollaborationEmptyState, CollaborationError, CollaborationLoading, getCollaborationErrorMessage } from '@/components/collaboration/CollaborationStates';

/** STEP 34: /collaboration/invitations — invitations addressed to the signed-in faculty member. */
export const PendingInvitations: React.FC = () => {
  const navigate = useNavigate();
  const [items, setItems] = useState<Invitation[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true); setError(null);
    try { setItems((await collaborationService.getMyInvitations()).data); } catch (e) { setError(getCollaborationErrorMessage(e)); } finally { setLoading(false); }
  }, []);
  useEffect(() => { void load(); }, [load]);

  const act = async (i: Invitation, accept: boolean) => {
    setBusyId(i.id); setError(null);
    try {
      if (accept) {
        const res = await collaborationService.acceptInvitationById(i.id);
        navigate(`/courses/${res.data.course_id}/collaboration`);
        return;
      }
      await collaborationService.declineInvitationById(i.id);
      setNotice('Invitation declined.');
      await load();
    } catch (e) { setError(getCollaborationErrorMessage(e)); } finally { setBusyId(null); }
  };

  return (
    <div className="space-y-4 max-w-3xl" data-testid="pending-invitations-page">
      <div><h1 className="text-2xl font-bold text-sage-800 dark:text-white">Pending Invitations</h1><p className="text-sm text-sage-500">Courses other faculty have invited you to collaborate on.</p></div>
      {error && <CollaborationError message={error} onRetry={load} />}
      {notice && <p className="text-sm text-emerald-700 dark:text-emerald-300">{notice}</p>}
      {loading ? <CollaborationLoading /> : items.length === 0 ? (
        <CollaborationEmptyState title="No pending invitations" description="When a colleague invites you to a course, it will appear here." icon={<Mail className="w-6 h-6" />} />
      ) : items.map((i) => (
        <InvitationCard key={i.id} invitation={i} busy={busyId === i.id} onAccept={() => void act(i, true)} onDecline={() => void act(i, false)} />
      ))}
    </div>
  );
};

/** STEP 34: /collaboration/invitations/:token — secure link landing; minimal preview, then accept/decline when signed in. */
export const InvitationLanding: React.FC = () => {
  const { token = '' } = useParams<{ token: string }>();
  const { user, loading: isLoading } = useAuth();
  const navigate = useNavigate();
  const [invitation, setInvitation] = useState<Invitation | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);

  useEffect(() => {
    collaborationService.previewInvitation(token).then((r) => setInvitation(r.data)).catch((e) => setError(getCollaborationErrorMessage(e)));
  }, [token]);

  const act = async (accept: boolean) => {
    setBusy(true); setError(null);
    try {
      if (accept) {
        const res = await collaborationService.acceptInvitation(token);
        setDone(res.message ?? 'Invitation accepted.');
        window.setTimeout(() => navigate(`/courses/${res.data.course_id}/collaboration`), 800);
      } else {
        await collaborationService.declineInvitation(token);
        setDone('Invitation declined.');
      }
    } catch (e) { setError(getCollaborationErrorMessage(e)); } finally { setBusy(false); }
  };

  const courseLabel = invitation ? [invitation.course?.course_code, invitation.course?.course_name].filter(Boolean).join(' — ') : '';

  return (
    <div className="min-h-[60vh] flex items-center justify-center p-6" data-testid="invitation-landing">
      <div className="w-full max-w-lg rounded-xl border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] p-6 space-y-4">
        <h1 className="text-xl font-bold text-sage-800 dark:text-white flex items-center gap-2"><Mail className="w-5 h-5" /> Collaboration invitation</h1>
        {error && <CollaborationError message={error} />}
        {!invitation && !error && <CollaborationLoading label="Checking invitation…" />}
        {invitation && (
          <>
            <div className="rounded-lg bg-sage-100 dark:bg-[#1F1F1F] p-4 text-sm space-y-1" data-testid="invitation-preview">
              <p className="text-sage-800 dark:text-white font-medium">{courseLabel}</p>
              <p className="text-sage-600 dark:text-sage-400">Invited by {invitation.invited_by?.name ?? 'a faculty member'} · Role: <strong>{ROLE_LABELS[invitation.role]}</strong></p>
              <p className="text-xs text-sage-500">{ROLE_DESCRIPTIONS[invitation.role]}</p>
              {invitation.expires_at && <p className="text-xs text-sage-500">Expires {new Date(invitation.expires_at).toLocaleString()}</p>}
              {invitation.status !== 'PENDING' && <p className="text-xs text-red-600">This invitation is {invitation.status.toLowerCase()}.</p>}
            </div>
            {done ? <p className="text-sm text-emerald-700 dark:text-emerald-300" data-testid="invitation-done">{done}</p> : invitation.status === 'PENDING' && (
              isLoading ? <CollaborationLoading label="Checking your session…" /> : user ? (
                <div className="flex gap-2">
                  <Button onClick={() => void act(true)} isLoading={busy} data-testid="accept-invitation">Accept</Button>
                  <Button variant="outline" onClick={() => void act(false)} disabled={busy} data-testid="decline-invitation">Decline</Button>
                </div>
              ) : (
                <div className="space-y-2 text-sm">
                  <p className="text-sage-600 dark:text-sage-400">Sign in with the invited faculty account to accept or decline.</p>
                  <Link to={`/login?redirect=${encodeURIComponent(`/collaboration/invitations/${token}`)}`}><Button>Sign in to continue</Button></Link>
                </div>
              )
            )}
          </>
        )}
        <p className="text-[11px] text-sage-400">No course content is shared until you accept. Invitations are single-use and expire automatically.</p>
      </div>
    </div>
  );
};
