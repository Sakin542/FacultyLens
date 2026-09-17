import { describe, it, expect, vi, beforeEach } from 'vitest';
import { authService } from '@/services/authService';
import * as apiModule from '@/services/api';

describe('authService session and logout management', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    authService.resetSessionProbe();
  });

  it('getSession invokes /auth/session and deduplicates concurrent calls', async () => {
    const mockApiClient = vi.spyOn(apiModule, 'apiClient').mockResolvedValue({
      status: 'success',
      authenticated: false,
      user: null,
    });

    const promise1 = authService.getSession();
    const promise2 = authService.getSession();

    expect(promise1).toBe(promise2); // Same promise in-flight

    const res = await promise1;
    expect(res.authenticated).toBe(false);
    expect(mockApiClient).toHaveBeenCalledTimes(1);
    expect(mockApiClient).toHaveBeenCalledWith('/auth/session', { method: 'GET' });
  });

  it('resetSessionProbe clears pending probe so a subsequent getSession fetches freshly', async () => {
    const mockApiClient = vi.spyOn(apiModule, 'apiClient').mockResolvedValue({
      status: 'success',
      authenticated: true,
      user: { id: 1, name: 'Faculty' },
    });

    const res1 = await authService.getSession();
    expect(res1.authenticated).toBe(true);

    authService.resetSessionProbe();

    mockApiClient.mockResolvedValueOnce({
      status: 'success',
      authenticated: false,
      user: null,
    });

    const res2 = await authService.getSession();
    expect(res2.authenticated).toBe(false);
    expect(mockApiClient).toHaveBeenCalledTimes(2);
  });

  it('logout issues POST /auth/logout and resets session probe', async () => {
    const mockApiClient = vi.spyOn(apiModule, 'apiClient').mockResolvedValue({
      status: 'success',
      message: 'Logout successful',
    });

    const res = await authService.logout();
    expect(res.status).toBe('success');
    expect(mockApiClient).toHaveBeenCalledWith('/auth/logout', { method: 'POST' });
  });
});

