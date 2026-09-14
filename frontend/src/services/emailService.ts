import { apiClient } from './api';

/**
 * Frontend surface of the FacultyLens e-mail system. Deliberately small: the browser never sees SMTP settings
 * (host, port, username, password) — only coarse readiness flags and the caller's own delivery log.
 * Preferences live in notificationService (getPreferences / updatePreferences).
 */
export type EmailDeliveryStatus = 'PENDING' | 'PROCESSING' | 'SENT' | 'FAILED' | 'CANCELLED';

export interface EmailDelivery {
  id: number;
  type: string;
  category: string | null;
  template: string;
  /** Masked (e.g. "fa***@university.edu"). */
  recipient: string;
  subject: string;
  status: EmailDeliveryStatus;
  provider: string;
  attempts: number;
  notification_id: string | null;
  queued_at: string | null;
  sent_at: string | null;
  failed_at: string | null;
  error_code: string | null;
  created_at: string | null;
}

export interface EmailStatus {
  enabled: boolean;
  configured: boolean;
  /** True when messages are captured locally (log / Mailpit) instead of leaving the system. */
  capture_mode: boolean;
  queue_connection: string;
  queue: string;
  horizon: boolean;
  test_endpoint_enabled: boolean;
  preview_enabled: boolean;
}

export const emailService = {
  getStatus: (): Promise<EmailStatus> => apiClient<EmailStatus>('/email/status', { method: 'GET' }),

  /** Development/admin only. Faculty may only target their own account address; omit `recipient` to use it. */
  sendTestEmail: (recipient?: string): Promise<EmailDelivery> =>
    apiClient<EmailDelivery>('/email/test', { method: 'POST', body: JSON.stringify(recipient ? { recipient } : {}) }),

  getDeliveries: (params: { status?: EmailDeliveryStatus; type?: string; perPage?: number } = {}): Promise<EmailDelivery[]> => {
    const query = new URLSearchParams();
    if (params.status) query.set('status', params.status);
    if (params.type) query.set('type', params.type);
    if (params.perPage) query.set('per_page', String(params.perPage));
    const qs = query.toString();
    return apiClient<EmailDelivery[]>(`/email/deliveries${qs ? `?${qs}` : ''}`, { method: 'GET' });
  },

  getDelivery: (id: number): Promise<EmailDelivery> => apiClient<EmailDelivery>(`/email/deliveries/${id}`, { method: 'GET' }),
};
