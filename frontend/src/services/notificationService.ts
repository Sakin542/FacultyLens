import { apiClient } from './api';
import type {
  NotificationFilter,
  NotificationListResponse,
  NotificationMutationResponse,
  NotificationPreferencesResponse,
  NotificationPreferenceUpdate,
  UnreadCountResponse,
} from '@/types/notification';

/**
 * STEP 47: notification center API. All requests go through the shared Sanctum `apiClient`; the backend scopes
 * every call to the authenticated user, so nothing here needs (or receives) a user id.
 */
export interface GetNotificationsParams {
  page?: number;
  perPage?: number;
  filter?: NotificationFilter;
}

export const notificationService = {
  getNotifications: ({ page = 1, perPage = 20, filter = 'all' }: GetNotificationsParams = {}): Promise<NotificationListResponse> => {
    const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });
    if (filter && filter !== 'all') params.set('filter', filter);
    return apiClient<NotificationListResponse>(`/notifications?${params.toString()}`, { method: 'GET' });
  },

  getUnreadCount: (): Promise<UnreadCountResponse> => apiClient<UnreadCountResponse>('/notifications/unread-count', { method: 'GET' }),

  markAsRead: (id: string): Promise<NotificationMutationResponse> =>
    apiClient<NotificationMutationResponse>(`/notifications/${encodeURIComponent(id)}/read`, { method: 'POST' }),

  markAllAsRead: (): Promise<NotificationMutationResponse> => apiClient<NotificationMutationResponse>('/notifications/read-all', { method: 'POST' }),

  dismissNotification: (id: string): Promise<NotificationMutationResponse> =>
    apiClient<NotificationMutationResponse>(`/notifications/${encodeURIComponent(id)}/dismiss`, { method: 'POST' }),

  deleteNotification: (id: string): Promise<NotificationMutationResponse> =>
    apiClient<NotificationMutationResponse>(`/notifications/${encodeURIComponent(id)}`, { method: 'DELETE' }),

  getPreferences: (): Promise<NotificationPreferencesResponse> => apiClient<NotificationPreferencesResponse>('/notification-preferences', { method: 'GET' }),

  updatePreferences: (preferences: NotificationPreferenceUpdate[]): Promise<{ status: string; data: { preferences: NotificationPreferencesResponse['data']['preferences'] } }> =>
    apiClient('/notification-preferences', { method: 'PUT', body: JSON.stringify({ preferences }) }),
};
