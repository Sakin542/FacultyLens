import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { apiClient, ApiError, friendlyStatusMessage } from '@/services/api';

/**
 * STEP 41 — every failure the backend or the network can produce must reach the UI as a safe,
 * human-readable ApiError (no raw stack traces, no "Failed to fetch", no bare status codes for 5xx).
 */
const jsonResponse = (status: number, body: unknown | null, ok = false) => ({
  ok,
  status,
  json: async () => {
    if (body === null) throw new SyntaxError('Unexpected token < in JSON');
    return body;
  },
});

describe('apiClient error mapping', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=test-token';
  });
  afterEach(() => {
    vi.restoreAllMocks();
  });

  const expectError = async (status: number, body: unknown | null) => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(jsonResponse(status, body) as unknown as Response);
    try {
      await apiClient('/anything');
    } catch (e) {
      return e as ApiError;
    }
    throw new Error('apiClient must reject on a non-2xx response');
  };

  it('keeps the backend message when one is provided', async () => {
    const e = await expectError(403, { message: 'Unauthorized access to assessment.' });
    expect(e).toBeInstanceOf(ApiError);
    expect(e.status).toBe(403);
    expect(e.message).toBe('Unauthorized access to assessment.');
  });

  it('uses the first validation error for 422 responses', async () => {
    const e = await expectError(422, { message: 'The given data was invalid.', errors: { total_marks: ['Total marks must be at least 0.'], title: ['Required'] } });
    expect(e.message).toBe('Total marks must be at least 0.');
    expect(e.errors?.title).toEqual(['Required']);
  });

  it('maps 401 and 419 to sign-in guidance', async () => {
    expect((await expectError(401, {})).message).toBe('Invalid email or password');
    expect((await expectError(419, { message: 'CSRF token mismatch.' })).message).toBe('Your session has expired. Please sign in again.');
  });

  it('produces friendly text for AI-service / gateway outages without a JSON body', async () => {
    expect((await expectError(502, null)).message).toBe('A required service is temporarily unavailable. Please try again shortly.');
    expect((await expectError(503, null)).message).toBe('A required service is temporarily unavailable. Please try again shortly.');
    expect((await expectError(504, null)).message).toBe('The server took too long to respond. Please try again.');
    expect((await expectError(500, null)).message).toBe('Something went wrong on our side. Please try again later.');
  });

  it('never surfaces internal exception details from a 5xx body', async () => {
    const e = await expectError(500, { message: 'SQLSTATE[23000]: Integrity constraint violation in /var/www/app/Services/X.php:42', exception: 'PDOException' });
    expect(e.message).not.toMatch(/SQLSTATE|\.php|PDOException/);
    expect(e.message).toBe(friendlyStatusMessage(500));
  });

  it('explains throttling, missing records and conflicts when the backend omits a message', async () => {
    expect((await expectError(429, null)).message).toBe('Too many requests. Please wait a moment and try again.');
    expect((await expectError(404, null)).message).toBe('The requested record could not be found. It may have been removed.');
    expect((await expectError(409, null)).message).toBe('This action conflicts with the current state of the record. Refresh and try again.');
    expect((await expectError(403, null)).message).toBe('You do not have permission to perform this action.');
  });

  it('wraps network failures (server unreachable) in an ApiError with status 0', async () => {
    vi.spyOn(globalThis, 'fetch').mockRejectedValue(new TypeError('Failed to fetch'));
    await expect(apiClient('/courses')).rejects.toMatchObject({
      name: 'ApiError',
      status: 0,
      message: 'Unable to reach the FacultyLens server. Check your connection and try again.',
    });
  });

  it('returns an empty object for 204 responses', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue({ ok: true, status: 204, json: async () => { throw new Error('no body'); } } as unknown as Response);
    await expect(apiClient('/reports/1', { method: 'DELETE' })).resolves.toEqual({});
  });
});
