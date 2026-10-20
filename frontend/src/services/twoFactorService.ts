import { apiClient } from './api';

export interface TwoFactorStatus {
  enabled: boolean;
  pending: boolean;
  confirmed_at: string | null;
  recovery_codes_remaining: number;
}

interface Envelope<T> { status: string; message?: string; data: T }

export const twoFactorService = {
  status: () => apiClient<Envelope<TwoFactorStatus>>('/auth/two-factor').then((r) => r.data),

  /** Returns the new secret (for manual entry) and the otpauth:// URL to render as a QR code. */
  enable: (password: string) =>
    apiClient<Envelope<{ secret: string; otpauth_url: string }>>('/auth/two-factor/enable', { method: 'POST', body: JSON.stringify({ password }) }).then((r) => r.data),

  confirm: (code: string) =>
    apiClient<Envelope<{ recovery_codes: string[] }>>('/auth/two-factor/confirm', { method: 'POST', body: JSON.stringify({ code }) }).then((r) => r.data),

  regenerateRecoveryCodes: (password: string) =>
    apiClient<Envelope<{ recovery_codes: string[] }>>('/auth/two-factor/recovery-codes', { method: 'POST', body: JSON.stringify({ password }) }).then((r) => r.data),

  disable: (password: string) =>
    apiClient<{ message: string }>('/auth/two-factor', { method: 'DELETE', body: JSON.stringify({ password }) }),
};
