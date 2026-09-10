import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { CollaboratorRoleBadge, PermissionBadge, CollaborationHeader, CollaborationEmptyState, CollaborationError, CollaborationLoading, getCollaborationErrorMessage } from '@/components/collaboration/CollaborationStates';
import { CollaboratorList, CollaboratorCard, InvitationCard, InviteCollaboratorModal, ChangeRoleModal, RemoveCollaboratorModal } from '@/components/collaboration/CollaboratorPanels';
import { CollaborationComments, CommentEditor, MentionSelector } from '@/components/collaboration/CollaborationComments';
import { CollaborationActivity, CollaborationSummaryCard, NotificationBell } from '@/components/collaboration/CollaborationActivity';
import { CourseCollaboration } from '@/pages/CourseCollaboration';
import { PendingInvitations, InvitationLanding } from '@/pages/Invitations';
import { ApiError } from '@/services/api';
import { CollaborationComment, CollaborationOverview, Collaborator, Invitation } from '@/types/collaboration';

vi.mock('@/services/collaborationService', () => ({
  collaborationService: {
    getCollaboration: vi.fn(), getCollaborators: vi.fn(), getMembers: vi.fn(), inviteCollaborator: vi.fn(), revokeInvitation: vi.fn(), changeCollaboratorRole: vi.fn(),
    removeCollaborator: vi.fn(), getMyInvitations: vi.fn(), previewInvitation: vi.fn(), acceptInvitation: vi.fn(), declineInvitation: vi.fn(), acceptInvitationById: vi.fn(),
    declineInvitationById: vi.fn(), getSummary: vi.fn(), getComments: vi.fn(), createComment: vi.fn(), updateComment: vi.fn(), deleteComment: vi.fn(), resolveComment: vi.fn(),
    reopenComment: vi.fn(), getCollaborationActivity: vi.fn(), getNotifications: vi.fn(), markNotificationRead: vi.fn(), markAllNotificationsRead: vi.fn(),
  },
}));
const authState: { user: { id: number; name: string } | null; loading: boolean } = { user: { id: 1, name: 'Dr. A' }, loading: false };
vi.mock('@/context/AuthContext', () => ({ useAuth: () => authState }));

import { collaborationService } from '@/services/collaborationService';
const svc = collaborationService as unknown as Record<string, ReturnType<typeof vi.fn>>;

const editor: Collaborator = { id: 11, user: { id: 2, name: 'Dr. B', email: 'b@u.edu' }, role: 'EDITOR', status: 'ACTIVE', invited_by: { id: 1, name: 'Dr. A' }, invited_at: null, accepted_at: '2026-09-10T10:00:00Z' };
const pendingViewer: Collaborator = { id: 12, user: { id: 4, name: 'Dr. D', email: 'd@u.edu' }, role: 'VIEWER', status: 'PENDING', invited_by: { id: 1, name: 'Dr. A' }, invited_at: null, accepted_at: null };
const invitation: Invitation = { id: 5, course: { id: 1, course_code: 'CSE101', course_name: 'Database Systems' }, invited_email: 'new@u.edu', invited_by: { id: 1, name: 'Dr. A' }, role: 'REVIEWER', status: 'PENDING', message: 'Please review', expires_at: '2026-09-20T00:00:00Z', created_at: null };
const overview = (perms: Partial<CollaborationOverview['permissions']>, role: CollaborationOverview['current_user']['role']): CollaborationOverview => ({
  course: { id: 1, course_code: 'CSE101', course_name: 'Database Systems' }, owner: { id: 1, name: 'Dr. A', email: 'a@u.edu' },
  current_user: { id: 1, role }, permissions: { view: true, comment: true, ...perms }, collaborators: [editor, pendingViewer], pending_invitations: [invitation], roles: ['EDITOR', 'REVIEWER', 'VIEWER'], role_matrix: {},
});
const comment: CollaborationComment = { id: 100, course_id: 1, commentable_type: 'analysis_report', commentable_id: 7, parent_id: null, body: 'Question 4 may be too difficult.', mentions: [], status: 'ACTIVE', author: { id: 2, name: 'Dr. B' }, resolved_by: null, resolved_at: null, edited_at: null, created_at: '2026-09-11T09:00:00Z', replies: [{ id: 101, course_id: 1, commentable_type: 'analysis_report', commentable_id: 7, parent_id: 100, body: 'Agreed.', mentions: [], status: 'ACTIVE', author: { id: 3, name: 'Dr. C' }, resolved_by: null, resolved_at: null, edited_at: null, created_at: null, replies: [], reply_count: 0 }], reply_count: 1 };

describe('STEP 34 collaboration components', () => {
  beforeEach(() => { vi.clearAllMocks(); authState.user = { id: 1, name: 'Dr. A' }; authState.loading = false; });

  it('role/permission badges and states', () => {
    render(<><CollaboratorRoleBadge role="EDITOR" /><PermissionBadge permissions={{ comment: true }} permission="comment" label="Comment" /><PermissionBadge permissions={{}} permission="delete_course" label="Delete" /><CollaborationLoading /><CollaborationEmptyState title="Empty" description="Nothing" /><CollaborationError message="Boom" onRetry={() => undefined} /></>);
    expect(screen.getByTestId('role-badge')).toHaveTextContent('Editor');
    expect(screen.getByTestId('permission-delete_course')).toHaveClass('line-through');
    expect(screen.getByRole('alert')).toHaveTextContent('Boom');
    expect(getCollaborationErrorMessage(new ApiError(403, 'x'))).toBe('x');
    expect(getCollaborationErrorMessage(new ApiError(410, ''))).toMatch(/no longer valid/);
    expect(getCollaborationErrorMessage(new ApiError(429, ''))).toMatch(/Too many/);
    expect(getCollaborationErrorMessage(new ApiError(409, ''))).toMatch(/changed since/);
  });

  it('header shows role and permissions', () => {
    render(<CollaborationHeader courseCode="CSE101" courseName="DB" ownerName="Dr. A" role="REVIEWER" memberCount={2} permissions={{ comment: true }} />);
    expect(screen.getByTestId('collaboration-header')).toHaveTextContent('CSE101 — DB · Owner: Dr. A · 2 collaborators');
    expect(screen.getByTestId('role-badge')).toHaveTextContent('Reviewer');
    expect(screen.getByTestId('permission-manage_collaborators')).toHaveClass('line-through');
  });

  it('collaborator list hides management controls without permission', () => {
    const h = { onInvite: vi.fn(), onChangeRole: vi.fn(), onRemove: vi.fn() };
    const { rerender } = render(<CollaboratorList owner={{ id: 1, name: 'Dr. A' }} collaborators={[editor, pendingViewer]} permissions={{}} {...h} />);
    expect(screen.queryByTestId('invite-button')).toBeNull();
    expect(screen.queryAllByTestId('change-role-button')).toHaveLength(0);
    expect(screen.getByTestId('owner-row')).toHaveTextContent('Dr. A');
    expect(screen.getAllByTestId('membership-status')[1]).toHaveTextContent('Pending');
    rerender(<CollaboratorList owner={{ id: 1, name: 'Dr. A' }} collaborators={[editor]} permissions={{ manage_collaborators: true }} {...h} />);
    fireEvent.click(screen.getByTestId('invite-button'));
    expect(h.onInvite).toHaveBeenCalled();
    fireEvent.click(screen.getByTestId('change-role-button'));
    expect(h.onChangeRole).toHaveBeenCalledWith(editor);
    fireEvent.click(screen.getByTestId('remove-button'));
    expect(h.onRemove).toHaveBeenCalledWith(editor);
    rerender(<CollaboratorList owner={null} collaborators={[]} permissions={{ manage_collaborators: true }} {...h} />);
    expect(screen.getByTestId('collaboration-empty-state')).toHaveTextContent('No collaborators yet');
  });

  it('invite modal validates email and submits role', async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    render(<InviteCollaboratorModal onSubmit={onSubmit} onClose={vi.fn()} error="Already invited" />);
    expect(screen.getByTestId('send-invitation')).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Faculty email'), { target: { value: 'b@university.edu' } });
    fireEvent.change(screen.getByLabelText('Role'), { target: { value: 'EDITOR' } });
    expect(screen.getByRole('alert')).toHaveTextContent('Already invited');
    fireEvent.click(screen.getByTestId('send-invitation'));
    await waitFor(() => expect(onSubmit).toHaveBeenCalledWith({ email: 'b@university.edu', role: 'EDITOR', message: undefined }));
  });

  it('change role, remove and invitation card actions', () => {
    const onRole = vi.fn(); const onRemove = vi.fn(); const onAccept = vi.fn(); const onDecline = vi.fn(); const onRevoke = vi.fn();
    render(<><ChangeRoleModal collaborator={editor} onConfirm={onRole} onClose={vi.fn()} /><RemoveCollaboratorModal collaborator={editor} courseLabel="CSE101" onConfirm={onRemove} onClose={vi.fn()} /><InvitationCard invitation={invitation} onAccept={onAccept} onDecline={onDecline} onRevoke={onRevoke} /><CollaboratorCard collaborator={editor} canManage={false} onChangeRole={vi.fn()} onRemove={vi.fn()} /></>);
    expect(screen.getByTestId('confirm-change-role')).toBeDisabled(); // same role
    fireEvent.change(screen.getByLabelText('New role'), { target: { value: 'REVIEWER' } });
    fireEvent.click(screen.getByTestId('confirm-change-role'));
    expect(onRole).toHaveBeenCalledWith('REVIEWER');
    expect(screen.getByTestId('remove-modal')).toHaveTextContent('lose access');
    fireEvent.click(screen.getByTestId('confirm-remove'));
    expect(onRemove).toHaveBeenCalled();
    expect(screen.getByTestId('invitation-5')).toHaveTextContent('Role: Reviewer');
    fireEvent.click(screen.getByTestId('accept-invitation')); fireEvent.click(screen.getByTestId('decline-invitation')); fireEvent.click(screen.getByTestId('revoke-invitation'));
    expect(onAccept).toHaveBeenCalled(); expect(onDecline).toHaveBeenCalled(); expect(onRevoke).toHaveBeenCalled();
  });

  it('comment editor + mention selector restrict mentions to members and render bodies as text', async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    render(<CommentEditor onSubmit={onSubmit} members={[{ id: 1, name: 'Dr. A', role: 'OWNER' }, { id: 2, name: 'Dr. B', role: 'EDITOR' }]} currentUserId={1} />);
    expect(screen.getByTestId('comment-submit')).toBeDisabled();
    expect(within(screen.getByTestId('mention-selector')).queryByText('@Dr. A')).toBeNull(); // no self-mention
    fireEvent.click(screen.getByText('@Dr. B'));
    fireEvent.change(screen.getByLabelText('Comment'), { target: { value: '<img src=x onerror=alert(1)> thoughts?' } });
    fireEvent.click(screen.getByTestId('comment-submit'));
    await waitFor(() => expect(onSubmit).toHaveBeenCalledWith('<img src=x onerror=alert(1)> thoughts?', [2]));
    render(<MentionSelector members={[]} selected={[]} onChange={vi.fn()} />);
  });

  it('comment thread: replies, resolve, edit-own, XSS-safe rendering', async () => {
    svc.getComments.mockResolvedValue({ status: 'success', data: [{ ...comment, body: '<b>bold</b> text' }], meta: { current_page: 1, last_page: 1, total: 1, can_comment: true } });
    svc.getMembers.mockResolvedValue({ status: 'success', data: [] });
    svc.createComment.mockResolvedValue({ status: 'success', data: comment });
    svc.resolveComment.mockResolvedValue({ status: 'success', data: { ...comment, status: 'RESOLVED' } });
    render(<MemoryRouter><CollaborationComments courseId={1} commentableType="analysis_report" commentableId={7} currentUserId={2} canResolve /></MemoryRouter>);
    await waitFor(() => expect(screen.getByTestId('comment-100')).toBeInTheDocument());
    expect(screen.getAllByTestId('comment-body')[0]).toHaveTextContent('<b>bold</b> text');
    expect(document.querySelector('b')).toBeNull();
    expect(screen.getByTestId('comment-101')).toHaveTextContent('Agreed.');
    expect(screen.getByLabelText('Edit comment')).toBeInTheDocument(); // author (id 2) can edit
    fireEvent.click(screen.getByTestId('reply-button'));
    const editors = screen.getAllByTestId('comment-editor');
    fireEvent.change(within(editors[0]).getByLabelText('Comment'), { target: { value: 'I will review it.' } });
    fireEvent.click(within(editors[0]).getByTestId('comment-submit'));
    await waitFor(() => expect(svc.createComment).toHaveBeenCalledWith(1, expect.objectContaining({ parent_id: 100, body: 'I will review it.' })));
    fireEvent.click(screen.getByTestId('resolve-button'));
    await waitFor(() => expect(svc.resolveComment).toHaveBeenCalledWith(100));
  });

  it('viewer without comment permission sees no editor', async () => {
    svc.getComments.mockResolvedValue({ status: 'success', data: [], meta: { current_page: 1, last_page: 1, total: 0, can_comment: false } });
    svc.getMembers.mockResolvedValue({ status: 'success', data: [] });
    render(<MemoryRouter><CollaborationComments courseId={1} commentableType="course" commentableId={1} currentUserId={4} /></MemoryRouter>);
    await waitFor(() => expect(screen.getByTestId('collaboration-empty-state')).toBeInTheDocument());
    expect(screen.queryByTestId('comment-editor')).toBeNull();
  });

  it('activity timeline paginates; summary card and bell load', async () => {
    svc.getCollaborationActivity.mockResolvedValueOnce({ status: 'success', data: [{ id: 1, action: 'COMMENT_CREATED', entity_type: 'CollaborationComment', entity_id: 1, actor: { id: 2, name: 'Dr. B' }, summary: 'Dr. B commented on question #4.', metadata: {}, created_at: new Date().toISOString() }], meta: { current_page: 1, last_page: 2, total: 2 } })
      .mockResolvedValueOnce({ status: 'success', data: [{ id: 2, action: 'COLLABORATION_ACCEPTED', entity_type: 'CourseCollaborator', entity_id: 1, actor: { id: 2, name: 'Dr. B' }, summary: 'Dr. B joined the course as Editor.', metadata: {}, created_at: '2026-09-01T10:00:00Z' }], meta: { current_page: 2, last_page: 2, total: 2 } });
    svc.getSummary.mockResolvedValue({ status: 'success', data: { shared_courses_count: 1, shared_courses: [{ id: 1, course_code: 'CSE101', course_name: 'DB', role: 'EDITOR' }], pending_invitations_count: 2, pending_invitations: [], unresolved_discussions_count: 3, unread_notifications_count: 1 } });
    svc.getNotifications.mockResolvedValue({ status: 'success', data: [{ id: 'n1', read_at: null, created_at: null, title: 'Invitation', body: 'x' }], meta: { current_page: 1, last_page: 1, total: 1, unread_count: 1 } });
    render(<MemoryRouter><CollaborationActivity courseId={1} /><CollaborationSummaryCard /><NotificationBell /></MemoryRouter>);
    await waitFor(() => expect(screen.getByTestId('activity-1')).toHaveTextContent('Dr. B commented'));
    expect(screen.getByText('Today')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('activity-load-more'));
    await waitFor(() => expect(screen.getByTestId('activity-2')).toBeInTheDocument());
    await waitFor(() => expect(screen.getByTestId('pending-invitations-count')).toHaveTextContent('2'));
    await waitFor(() => expect(screen.getByTestId('unread-count')).toHaveTextContent('1'));
    fireEvent.click(screen.getByLabelText('Notifications'));
    expect(screen.getByText('Invitation')).toBeInTheDocument();
  });
});

const renderAt = (path: string) => render(
  <MemoryRouter initialEntries={[path]}>
    <Routes>
      <Route path="/courses/:courseId/collaboration" element={<CourseCollaboration />} />
      <Route path="/collaboration/invitations" element={<PendingInvitations />} />
      <Route path="/collaboration/invitations/:token" element={<InvitationLanding />} />
      <Route path="/courses/:id" element={<div>course page</div>} />
    </Routes>
  </MemoryRouter>
);

describe('STEP 34 collaboration pages', () => {
  beforeEach(() => {
    vi.clearAllMocks(); authState.user = { id: 1, name: 'Dr. A' }; authState.loading = false;
    svc.getComments.mockResolvedValue({ status: 'success', data: [], meta: { current_page: 1, last_page: 1, total: 0, can_comment: true } });
    svc.getMembers.mockResolvedValue({ status: 'success', data: [] });
    svc.getCollaborationActivity.mockResolvedValue({ status: 'success', data: [], meta: { current_page: 1, last_page: 1, total: 0 } });
  });

  it('owner sees management controls, invites, changes role and removes', async () => {
    svc.getCollaboration.mockResolvedValue({ status: 'success', data: overview({ manage_collaborators: true, approve_recommendation: true }, 'OWNER') });
    svc.inviteCollaborator.mockResolvedValue({ status: 'success', message: 'Invitation sent.', data: { ...invitation, accept_url: 'http://localhost:3000/collaboration/invitations/tok' } });
    svc.changeCollaboratorRole.mockResolvedValue({ status: 'success', data: { user_id: 2, role: 'REVIEWER', status: 'ACTIVE' } });
    svc.removeCollaborator.mockResolvedValue({ status: 'success', data: null });
    renderAt('/courses/1/collaboration');
    await waitFor(() => expect(screen.getByTestId('collaborator-list')).toBeInTheDocument());
    expect(screen.getByTestId('pending-invitations')).toHaveTextContent('new@u.edu');
    fireEvent.click(screen.getByTestId('invite-button'));
    fireEvent.change(screen.getByLabelText('Faculty email'), { target: { value: 'new2@u.edu' } });
    fireEvent.click(screen.getByTestId('send-invitation'));
    await waitFor(() => expect(svc.inviteCollaborator).toHaveBeenCalledWith('1', { email: 'new2@u.edu', role: 'REVIEWER', message: undefined }));
    await waitFor(() => expect(screen.getByTestId('collaboration-notice')).toHaveTextContent('share this link'));
    fireEvent.click(screen.getAllByTestId('change-role-button')[0]);
    fireEvent.change(screen.getByLabelText('New role'), { target: { value: 'REVIEWER' } });
    fireEvent.click(screen.getByTestId('confirm-change-role'));
    await waitFor(() => expect(svc.changeCollaboratorRole).toHaveBeenCalledWith('1', 2, 'REVIEWER'));
    fireEvent.click(screen.getAllByTestId('remove-button')[0]);
    fireEvent.click(screen.getByTestId('confirm-remove'));
    await waitFor(() => expect(svc.removeCollaborator).toHaveBeenCalledWith('1', 2));
  });

  it('reviewer sees read-only roster and no invite button; 403 shows error', async () => {
    svc.getCollaboration.mockResolvedValue({ status: 'success', data: overview({}, 'REVIEWER') });
    renderAt('/courses/1/collaboration');
    await waitFor(() => expect(screen.getByTestId('collaborator-list')).toBeInTheDocument());
    expect(screen.queryByTestId('invite-button')).toBeNull();
    expect(screen.queryByTestId('pending-invitations')).toBeNull();
    expect(screen.getByTestId('collaboration-header')).toHaveTextContent('Reviewer');

    svc.getCollaboration.mockRejectedValue(new ApiError(403, 'You do not have access to this course.'));
    renderAt('/courses/2/collaboration');
    await waitFor(() => expect(screen.getAllByTestId('collaboration-error')[0]).toHaveTextContent('do not have access'));
  });

  it('pending invitations page accepts and declines', async () => {
    svc.getMyInvitations.mockResolvedValue({ status: 'success', data: [invitation] });
    svc.declineInvitationById.mockResolvedValue({ status: 'success', data: { status: 'DECLINED' } });
    svc.acceptInvitationById.mockResolvedValue({ status: 'success', data: { course_id: 1, role: 'REVIEWER', status: 'ACTIVE' } });
    renderAt('/collaboration/invitations');
    await waitFor(() => expect(screen.getByTestId('invitation-5')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('decline-invitation'));
    await waitFor(() => expect(svc.declineInvitationById).toHaveBeenCalledWith(5));
    await waitFor(() => expect(screen.getByText('Invitation declined.')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('accept-invitation'));
    await waitFor(() => expect(svc.acceptInvitationById).toHaveBeenCalledWith(5));
  });

  it('invitation landing previews, requires sign-in, then accepts', async () => {
    svc.previewInvitation.mockResolvedValue({ status: 'success', data: { ...invitation, invited_email: null } });
    svc.acceptInvitation.mockResolvedValue({ status: 'success', message: 'Invitation accepted.', data: { course_id: 1, role: 'REVIEWER', status: 'ACTIVE' } });
    authState.user = null;
    const { unmount } = renderAt('/collaboration/invitations/tok123');
    await waitFor(() => expect(screen.getByTestId('invitation-preview')).toHaveTextContent('CSE101 — Database Systems'));
    expect(screen.getByText('Sign in to continue')).toBeInTheDocument();
    unmount();
    authState.user = { id: 9, name: 'Dr. New' };
    renderAt('/collaboration/invitations/tok123');
    await waitFor(() => expect(screen.getByTestId('accept-invitation')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('accept-invitation'));
    await waitFor(() => expect(svc.acceptInvitation).toHaveBeenCalledWith('tok123'));
    await waitFor(() => expect(screen.getByTestId('invitation-done')).toHaveTextContent('accepted'));

    svc.previewInvitation.mockRejectedValue(new ApiError(410, 'This invitation has expired.'));
    renderAt('/collaboration/invitations/expired');
    await waitFor(() => expect(screen.getAllByTestId('collaboration-error').at(-1)).toHaveTextContent('expired'));
  });
});
