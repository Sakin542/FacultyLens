import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowRight, BookOpen, Inbox, MessageSquare, Send, UserPlus, Users } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { collaborationService } from '@/services/collaborationService';
import { AssignableRole, CollaborationSummary, Invitation, OwnedCourse, ROLE_LABELS, SharedCourse } from '@/types/collaboration';
import { InvitationCard, InviteCollaboratorModal } from '@/components/collaboration/CollaboratorPanels';
import { CollaboratorRoleBadge, CollaborationEmptyState, CollaborationError, CollaborationLoading, getCollaborationErrorMessage } from '@/components/collaboration/CollaborationStates';

/**
 * /collaboration — the faculty member's collaboration workspace:
 * invitations addressed to them, the teams on their own courses, courses shared with them, and invitations they sent.
 * Every action goes through the same authorized endpoints as the per-course page; the API remains authoritative.
 */

const courseLabel = (c: { course_code?: string | null; course_name?: string | null }) => [c.course_code, c.course_name].filter(Boolean).join(' — ') || 'Course';
const term = (c: { semester?: string | null; academic_year?: string | null }) => [c.semester, c.academic_year].filter(Boolean).join(' ');

const Stat: React.FC<{ label: string; value: number; icon: React.ReactNode; testId: string }> = ({ label, value, icon, testId }) => (
  <div className="rounded-xl border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] p-4 flex items-center gap-3">
    <span className="w-9 h-9 rounded-lg bg-sage-100 dark:bg-[#1F1F1F] text-sage-600 dark:text-sage-300 flex items-center justify-center">{icon}</span>
    <div>
      <p className="text-[11px] uppercase tracking-wider text-sage-500">{label}</p>
      <p className="text-xl font-bold text-sage-800 dark:text-white" data-testid={testId}>{value}</p>
    </div>
  </div>
);

const Section: React.FC<{ title: string; description: string; action?: React.ReactNode; children: React.ReactNode; testId: string }> = ({ title, description, action, children, testId }) => (
  <section data-testid={testId} className="space-y-3">
    <div className="flex flex-wrap items-end justify-between gap-2">
      <div>
        <h2 className="text-base font-semibold text-sage-800 dark:text-white">{title}</h2>
        <p className="text-xs text-sage-500">{description}</p>
      </div>
      {action}
    </div>
    {children}
  </section>
);

const OwnedCourseRow: React.FC<{ course: OwnedCourse; onInvite: () => void }> = ({ course: c, onInvite }) => (
  <li data-testid={`owned-course-${c.id}`} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-sage-100 dark:border-[#1F1F1F] last:border-0">
    <div className="min-w-0">
      <Link to={`/courses/${c.id}`} className="text-sm font-medium text-sage-800 dark:text-white hover:underline">{courseLabel(c)}</Link>
      <p className="text-xs text-sage-500">
        {term(c) && <>{term(c)} · </>}
        {c.collaborators_count === 0 ? 'Only you' : `${c.collaborators_count} collaborator${c.collaborators_count === 1 ? '' : 's'}`}
        {c.pending_invitations_count > 0 && <> · {c.pending_invitations_count} invitation{c.pending_invitations_count === 1 ? '' : 's'} pending</>}
        {c.open_discussions_count > 0 && <> · {c.open_discussions_count} open discussion{c.open_discussions_count === 1 ? '' : 's'}</>}
      </p>
    </div>
    <div className="flex items-center gap-2">
      <Button size="sm" variant="outline" leftIcon={<UserPlus className="w-3.5 h-3.5" />} onClick={onInvite} data-testid={`invite-to-${c.id}`}>Invite</Button>
      <Link to={`/courses/${c.id}/collaboration`}><Button size="sm" rightIcon={<ArrowRight className="w-3.5 h-3.5" />} data-testid={`manage-team-${c.id}`}>Manage team</Button></Link>
    </div>
  </li>
);

const SharedCourseRow: React.FC<{ course: SharedCourse }> = ({ course: c }) => (
  <li data-testid={`shared-course-${c.id}`} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-sage-100 dark:border-[#1F1F1F] last:border-0">
    <div className="min-w-0">
      <Link to={`/courses/${c.id}`} className="text-sm font-medium text-sage-800 dark:text-white hover:underline">{courseLabel(c)}</Link>
      <p className="text-xs text-sage-500">
        Owner: {c.owner?.name ?? 'Course owner'}
        {term(c) && <> · {term(c)}</>}
        {(c.open_discussions_count ?? 0) > 0 && <> · {c.open_discussions_count} open discussion{c.open_discussions_count === 1 ? '' : 's'}</>}
      </p>
    </div>
    <div className="flex items-center gap-2">
      <CollaboratorRoleBadge role={c.role} />
      <Link to={`/courses/${c.id}/collaboration`}><Button size="sm" variant="outline" leftIcon={<MessageSquare className="w-3.5 h-3.5" />} data-testid={`open-discussion-${c.id}`}>Discussion</Button></Link>
      <Link to={`/courses/${c.id}`}><Button size="sm" rightIcon={<ArrowRight className="w-3.5 h-3.5" />}>Open course</Button></Link>
    </div>
  </li>
);

const listClass = 'rounded-xl border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616]';

export const CollaborationHub: React.FC = () => {
  const navigate = useNavigate();
  const [summary, setSummary] = useState<CollaborationSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [inviteFor, setInviteFor] = useState<number | 'pick' | null>(null);
  const [inviteError, setInviteError] = useState<string | null>(null);
  const [inviting, setInviting] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setSummary((await collaborationService.getSummary()).data);
    } catch (e) {
      setError(getCollaborationErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => { void load(); }, [load]);

  const owned = summary?.owned_courses ?? [];
  const shared = summary?.shared_courses ?? [];
  const incoming = summary?.pending_invitations ?? [];
  const sent = summary?.sent_invitations ?? [];
  const courseOptions = useMemo(() => owned.map((c) => ({ id: c.id, label: courseLabel(c) })), [owned]);
  const teamSize = owned.reduce((n, c) => n + c.collaborators_count, 0);

  const respond = async (i: Invitation, accept: boolean) => {
    setBusyId(i.id);
    setError(null);
    try {
      if (accept) {
        const res = await collaborationService.acceptInvitationById(i.id);
        navigate(`/courses/${res.data.course_id}/collaboration`);
        return;
      }
      await collaborationService.declineInvitationById(i.id);
      setNotice(`You declined the invitation to ${courseLabel(i.course ?? {})}.`);
      await load();
    } catch (e) {
      setError(getCollaborationErrorMessage(e));
    } finally {
      setBusyId(null);
    }
  };

  const revoke = async (i: Invitation) => {
    if (!i.course) return;
    setBusyId(i.id);
    setError(null);
    try {
      await collaborationService.revokeInvitation(i.course.id, i.id);
      setNotice(`Invitation to ${i.invited_email ?? 'the colleague'} was revoked.`);
      await load();
    } catch (e) {
      setError(getCollaborationErrorMessage(e));
    } finally {
      setBusyId(null);
    }
  };

  const invite = async (data: { email: string; role: AssignableRole; message?: string; courseId?: number }) => {
    const courseId = data.courseId ?? (typeof inviteFor === 'number' ? inviteFor : undefined);
    if (!courseId) return;
    setInviting(true);
    setInviteError(null);
    try {
      const res = await collaborationService.inviteCollaborator(courseId, { email: data.email, role: data.role, message: data.message });
      setInviteFor(null);
      const course = owned.find((c) => c.id === courseId);
      setNotice(res.data.accept_url
        ? `Invitation created. E-mail is not configured here, so share this link with ${data.email}: ${res.data.accept_url}`
        : `${data.email} was invited to ${course ? courseLabel(course) : 'the course'} as ${ROLE_LABELS[data.role]}.`);
      await load();
    } catch (e) {
      setInviteError(getCollaborationErrorMessage(e));
    } finally {
      setInviting(false);
    }
  };

  return (
    <div className="space-y-6" data-testid="collaboration-hub-page">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-sage-800 dark:text-white flex items-center gap-2"><Users className="w-6 h-6" /> Collaboration</h1>
          <p className="text-sm text-sage-500 max-w-2xl">Share your courses with colleagues, respond to invitations, and keep up with the courses others share with you. Collaborators only see what their role allows.</p>
        </div>
        {owned.length > 0 && (
          <Button leftIcon={<UserPlus className="w-4 h-4" />} onClick={() => { setInviteError(null); setInviteFor('pick'); }} data-testid="hub-invite-button">Invite a colleague</Button>
        )}
      </div>

      {error && <CollaborationError message={error} onRetry={load} />}
      {notice && <p className="text-sm text-emerald-700 dark:text-emerald-300 break-all" role="status" data-testid="hub-notice">{notice}</p>}

      {loading && !summary ? <CollaborationLoading /> : summary && (
        <>
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <Stat label="Invitations for you" value={incoming.length} icon={<Inbox className="w-4 h-4" />} testId="stat-incoming" />
            <Stat label="Your collaborators" value={teamSize} icon={<Users className="w-4 h-4" />} testId="stat-team" />
            <Stat label="Shared with you" value={shared.length} icon={<BookOpen className="w-4 h-4" />} testId="stat-shared" />
            <Stat label="Open discussions" value={summary.unresolved_discussions_count} icon={<MessageSquare className="w-4 h-4" />} testId="stat-discussions" />
          </div>

          {incoming.length > 0 && (
            <Section testId="hub-incoming" title="Invitations for you" description="Accepting gives you access to the course with the role shown. Nothing is shared until you accept.">
              <div className="space-y-2">
                {incoming.map((i) => (
                  <InvitationCard key={i.id} invitation={i} busy={busyId === i.id} onAccept={() => void respond(i, true)} onDecline={() => void respond(i, false)} />
                ))}
              </div>
            </Section>
          )}

          <Section testId="hub-owned" title="Your courses" description="Invite colleagues as Editor, Reviewer or Viewer, and manage each course team.">
            {owned.length === 0 ? (
              <CollaborationEmptyState title="You don't own any courses yet" description="Create a course first. You can then invite colleagues to work on it with you." icon={<BookOpen className="w-6 h-6" />} action={<Link to="/courses"><Button size="sm">Go to Courses</Button></Link>} />
            ) : (
              <ul className={listClass}>{owned.map((c) => <OwnedCourseRow key={c.id} course={c} onInvite={() => { setInviteError(null); setInviteFor(c.id); }} />)}</ul>
            )}
          </Section>

          <Section testId="hub-shared" title="Shared with you" description="Courses colleagues have invited you to. Your role decides what you can change.">
            {shared.length === 0 ? (
              <CollaborationEmptyState title="No courses shared with you" description="When a colleague invites you and you accept, the course appears here and in your Courses list." icon={<Users className="w-6 h-6" />} />
            ) : (
              <ul className={listClass}>{shared.map((c) => <SharedCourseRow key={c.id} course={c} />)}</ul>
            )}
          </Section>

          {sent.length > 0 && (
            <Section testId="hub-sent" title="Invitations you sent" description="Waiting for your colleagues to respond. Revoke an invitation if it was sent by mistake.">
              <div className="space-y-2">
                {sent.map((i) => <InvitationCard key={i.id} invitation={i} busy={busyId === i.id} onRevoke={() => void revoke(i)} />)}
              </div>
            </Section>
          )}

          <p className="text-[11px] text-sage-400 flex items-center gap-1.5"><Send className="w-3 h-3" /> Invitations are single-use, expire automatically, and collaboration never turns AI findings into decisions on its own.</p>
        </>
      )}

      {inviteFor !== null && (
        <InviteCollaboratorModal
          onSubmit={invite}
          onClose={() => setInviteFor(null)}
          submitting={inviting}
          error={inviteError}
          courseOptions={inviteFor === 'pick' ? courseOptions : undefined}
          defaultCourseId={typeof inviteFor === 'number' ? inviteFor : undefined}
        />
      )}
    </div>
  );
};
