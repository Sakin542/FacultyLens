import { apiClient } from './api';

export interface NewsletterResponse {
  status: 'success' | 'error';
  message: string;
}

/** Public "Faculty dispatch" newsletter endpoints (double opt-in; confirm/unsubscribe tokens arrive by e-mail). */
export const newsletterService = {
  subscribe: (email: string): Promise<NewsletterResponse> =>
    apiClient<NewsletterResponse>('/newsletter/subscribe', { method: 'POST', body: JSON.stringify({ email: email.trim() }) }),

  confirm: (token: string): Promise<NewsletterResponse> =>
    apiClient<NewsletterResponse>(`/newsletter/confirm/${encodeURIComponent(token)}`, { method: 'POST' }),

  unsubscribe: (token: string): Promise<NewsletterResponse> =>
    apiClient<NewsletterResponse>(`/newsletter/unsubscribe/${encodeURIComponent(token)}`, { method: 'POST' }),
};
