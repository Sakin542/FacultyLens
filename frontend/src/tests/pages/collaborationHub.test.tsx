import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { CollaborationHub } from '@/pages/CollaborationHub';
import { CollaborationSummary, Invitation } from '@/types/collaboration';

vi.mock('@/services/collaborationService', () => ({
  collaborationService: {
    getSummary: vi.fn(), acceptInvitationById: vi.fn(), declineInvitationById: vi.fn(), revokeInvitation: vi.fn(), inviteCollaborator: vi.fn(),
  },
}));

import { collaborationService } from '@/services/collaborationService';
const svc = collaborationService as unknown as Record<string, ReturnType<typeof vi.fn>>;

const incoming: Invitation = { id: 7, course: { id: 30, course_code: 'CSE310', course_name: 'Compilers' }, invited_email: 'me@u.edu', invited_by: { id: 9, name: 'Dr. Rahman' }, role: 'REVIEWER', status: 'PENDING', message: null, expires_at: '2026-10-20T00:00:00Z', created_at: null };
const sent: Invitation = { id: 8, course: { id: 10, course_code: 'CSE401', course_name: 'Artificial Intelligence' }, invited_email: 'colleague@u.edu', invited_by: { id: 1, name: 'Dr. Me' }, role: 'EDITOR', status: 'PENDING', message: null, expires_at: '2026-10-20T00:00:00Z', created_at: null };

const summary = (over: Partial<CollaborationSummary> = {}): CollaborationSummary => ({
  shared_courses_count: 1,
  shared_courses: [{ id: 20, course_code: 'CSE320', course_name: 'Networks', role: 'EDITOR', owner: { id: 5, name: 'Dr. Karim' }, open_discussions_count: 2 }],
  owned_courses: [
    { id: 10, course_code: 'CSE401', course_name: 'Artificial Intelligence', collaborators_count: 2, pending_invitations_count: 1, open_discussions_count: 0 },
    { id: 11, course_code: 'CSE402', course_name: 'Machine Learning', collaborators_count: 0, pending_invitations_count: 0, open_discussions_count: 0 },
  ],
  sent_invitations: [sent],
  pending_invitations_count: 1,
  pending_invitations: [incoming],
  unresolved_discussions_count: 2,
  unread_notifications_count: 0,
  ...over,
});

const renderHub = () => render(
  <MemoryRouter initialEntries={['/collaboration']}>
    <Routes>
      <Route path="/collaboration" element={<CollaborationHub />} />
      <Route path="/courses/:courseId/collaboration" element={<p>course collaboration page</p>} />
    </Routes>
  </MemoryRouter>,
);

describe('Collaboration hub (/collaboration)', () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it('shows incoming invitations, owned teams, shared courses and sent invitations', async () => {
    svc.getSummary.mockResolvedValue({ status: 'success', data: summary() });
    renderHub();

    expect(await screen.findByTestId('collaboration-hub-page')).toBeInTheDocument();
    await waitFor(() => expect(screen.getByTestId('stat-team')).toHaveTextContent('2'));
    expect(screen.getByTestId('stat-incoming')).toHaveTextContent('1');
    expect(screen.getByTestId('stat-discussions')).toHaveTextContent('2');

    const ownedRow = screen.getByTestId('owned-course-10');
    expect(ownedRow).toHaveTextContent('2 collaborators');
    expect(ownedRow).toHaveTextContent('1 invitation pending');
    expect(screen.getByTestId('owned-course-11')).toHaveTextContent('Only you');

    const sharedRow = screen.getByTestId('shared-course-20');
    expect(sharedRow).toHaveTextContent('Owner: Dr. Karim');
    expect(sharedRow).toHaveTextContent('2 open discussions');
    expect(within(sharedRow).getByTestId('role-badge')).toHaveTextContent('Editor');

    expect(within(screen.getByTestId('hub-sent')).getByText(/colleague@u.edu/)).toBeInTheDocument();
  });

  it('accepting an invitation opens that course collaboration page', async () => {
    svc.getSummary.mockResolvedValue({ status: 'success', data: summary() });
    svc.acceptInvitationById.mockResolvedValue({ status: 'success', data: { course_id: 30, role: 'REVIEWER', status: 'ACTIVE' } });
    renderHub();

    fireEvent.click(await within(await screen.findByTestId('hub-incoming')).findByTestId('accept-invitation'));
    expect(await screen.findByText('course collaboration page')).toBeInTheDocument();
    expect(svc.acceptInvitationById).toHaveBeenCalledWith(7);
  });

  it('invites a colleague to a chosen course from the hub', async () => {
    svc.getSummary.mockResolvedValue({ status: 'success', data: summary() });
    svc.inviteCollaborator.mockResolvedValue({ status: 'success', message: 'Invitation sent.', data: { ...sent, id: 9 } });
    renderHub();

    fireEvent.click(await screen.findByTestId('hub-invite-button'));
    const modal = screen.getByTestId('invite-modal');
    fireEvent.change(within(modal).getByTestId('invite-course'), { target: { value: '11' } });
    fireEvent.change(within(modal).getByLabelText('Faculty email'), { target: { value: 'new@u.edu' } });
    fireEvent.change(within(modal).getByLabelText('Role'), { target: { value: 'EDITOR' } });
    fireEvent.click(within(modal).getByTestId('send-invitation'));

    await waitFor(() => expect(svc.inviteCollaborator).toHaveBeenCalledWith(11, { email: 'new@u.edu', role: 'EDITOR', message: undefined }));
    expect(await screen.findByTestId('hub-notice')).toHaveTextContent('new@u.edu was invited to CSE402 — Machine Learning as Editor.');
    expect(svc.getSummary).toHaveBeenCalledTimes(2);
  });

  it('revokes a sent invitation', async () => {
    svc.getSummary.mockResolvedValue({ status: 'success', data: summary() });
    svc.revokeInvitation.mockResolvedValue({ status: 'success', data: null });
    renderHub();

    fireEvent.click(await within(await screen.findByTestId('hub-sent')).findByTestId('revoke-invitation'));
    await waitFor(() => expect(svc.revokeInvitation).toHaveBeenCalledWith(10, 8));
    expect(await screen.findByTestId('hub-notice')).toHaveTextContent('Invitation to colleague@u.edu was revoked.');
  });

  it('shows helpful empty states for a new faculty member', async () => {
    svc.getSummary.mockResolvedValue({ status: 'success', data: summary({ owned_courses: [], shared_courses: [], shared_courses_count: 0, sent_invitations: [], pending_invitations: [], pending_invitations_count: 0, unresolved_discussions_count: 0 }) });
    renderHub();

    expect(await screen.findByText("You don't own any courses yet")).toBeInTheDocument();
    expect(screen.getByText('No courses shared with you')).toBeInTheDocument();
    expect(screen.queryByTestId('hub-invite-button')).not.toBeInTheDocument();
    expect(screen.queryByTestId('hub-incoming')).not.toBeInTheDocument();
  });
});
