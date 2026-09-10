import { apiClient } from './api';
import {
  ActivityItem, AppNotification, AssignableRole, CollaborationComment, CollaborationOverview, CollaborationSummary, Collaborator,
  CommentableType, Invitation, MentionableUser, PageMeta, UserRef,
} from '@/types/collaboration';

interface Envelope<T> { status: string; message?: string; data: T; meta?: PageMeta; }

/**
 * STEP 34: Faculty collaboration API. Every call is re-authorized server-side.
 */
export const collaborationService = {
  getCollaboration: (courseId: number | string): Promise<Envelope<CollaborationOverview>> =>
    apiClient(`/courses/${courseId}/collaboration`, { method: 'GET' }),

  getCollaborators: (courseId: number | string): Promise<Envelope<{ owner: UserRef | null; collaborators: Collaborator[]; pending_invitations: Invitation[] }>> =>
    apiClient(`/courses/${courseId}/collaborators`, { method: 'GET' }),

  getMembers: (courseId: number | string): Promise<Envelope<MentionableUser[]>> =>
    apiClient(`/courses/${courseId}/collaboration/members`, { method: 'GET' }),

  inviteCollaborator: (courseId: number | string, data: { email: string; role: AssignableRole; message?: string }): Promise<Envelope<Invitation>> =>
    apiClient(`/courses/${courseId}/collaborators/invite`, { method: 'POST', body: JSON.stringify(data) }),

  revokeInvitation: (courseId: number | string, invitationId: number | string): Promise<Envelope<null>> =>
    apiClient(`/courses/${courseId}/collaboration/invitations/${invitationId}`, { method: 'DELETE' }),

  changeCollaboratorRole: (courseId: number | string, userId: number | string, role: AssignableRole): Promise<Envelope<{ user_id: number; role: AssignableRole; status: string }>> =>
    apiClient(`/courses/${courseId}/collaborators/${userId}/role`, { method: 'PATCH', body: JSON.stringify({ role }) }),

  removeCollaborator: (courseId: number | string, userId: number | string): Promise<Envelope<null>> =>
    apiClient(`/courses/${courseId}/collaborators/${userId}`, { method: 'DELETE' }),

  getMyInvitations: (): Promise<Envelope<Invitation[]>> => apiClient('/collaboration/invitations', { method: 'GET' }),

  previewInvitation: (token: string): Promise<Envelope<Invitation>> =>
    apiClient(`/collaboration/invitations/${encodeURIComponent(token)}`, { method: 'GET' }),

  acceptInvitation: (token: string): Promise<Envelope<{ course_id: number; role: AssignableRole; status: string }>> =>
    apiClient(`/collaboration/invitations/${encodeURIComponent(token)}/accept`, { method: 'POST' }),

  declineInvitation: (token: string): Promise<Envelope<{ status: string }>> =>
    apiClient(`/collaboration/invitations/${encodeURIComponent(token)}/decline`, { method: 'POST' }),

  acceptInvitationById: (id: number | string): Promise<Envelope<{ course_id: number; role: AssignableRole; status: string }>> =>
    apiClient(`/collaboration/my-invitations/${id}/accept`, { method: 'POST' }),

  declineInvitationById: (id: number | string): Promise<Envelope<{ status: string }>> =>
    apiClient(`/collaboration/my-invitations/${id}/decline`, { method: 'POST' }),

  getSummary: (): Promise<Envelope<CollaborationSummary>> => apiClient('/collaboration/summary', { method: 'GET' }),

  getComments: (courseId: number | string, params?: { commentable_type?: CommentableType; commentable_id?: number | string; status?: 'ACTIVE' | 'RESOLVED'; page?: number; per_page?: number }): Promise<Envelope<CollaborationComment[]>> => {
    const q = new URLSearchParams();
    Object.entries(params ?? {}).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') q.append(k, String(v)); });
    const qs = q.toString();
    return apiClient(`/courses/${courseId}/comments${qs ? `?${qs}` : ''}`, { method: 'GET' });
  },

  createComment: (courseId: number | string, data: { commentable_type: CommentableType; commentable_id: number | string; body: string; parent_id?: number | null; mentions?: number[] }): Promise<Envelope<CollaborationComment>> =>
    apiClient(`/courses/${courseId}/comments`, { method: 'POST', body: JSON.stringify(data) }),

  updateComment: (commentId: number | string, body: string): Promise<Envelope<CollaborationComment>> =>
    apiClient(`/comments/${commentId}`, { method: 'PUT', body: JSON.stringify({ body }) }),

  deleteComment: (commentId: number | string): Promise<Envelope<null>> => apiClient(`/comments/${commentId}`, { method: 'DELETE' }),

  resolveComment: (commentId: number | string): Promise<Envelope<CollaborationComment>> => apiClient(`/comments/${commentId}/resolve`, { method: 'POST' }),

  reopenComment: (commentId: number | string): Promise<Envelope<CollaborationComment>> => apiClient(`/comments/${commentId}/reopen`, { method: 'POST' }),

  getCollaborationActivity: (courseId: number | string, page = 1, perPage = 20): Promise<Envelope<ActivityItem[]>> =>
    apiClient(`/courses/${courseId}/collaboration/activity?page=${page}&per_page=${perPage}`, { method: 'GET' }),

  getNotifications: (unreadOnly = false): Promise<Envelope<AppNotification[]>> =>
    apiClient(`/notifications${unreadOnly ? '?unread=1' : ''}`, { method: 'GET' }),

  markNotificationRead: (id: string): Promise<Envelope<null>> => apiClient(`/notifications/${id}/read`, { method: 'POST' }),

  markAllNotificationsRead: (): Promise<Envelope<null>> => apiClient('/notifications/read-all', { method: 'POST' }),
};
