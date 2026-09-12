/**
 * FacultyLens Reusable API Client with Laravel Sanctum Support
 */

/**
 * Dynamically resolve the API base URL to ensure hostname matches current browser context
 * (prevents cookie isolation between localhost and 127.0.0.1)
 */
function resolveEndpoints() {
  const envBase = import.meta.env.VITE_API_BASE_URL;
  const currentHost = typeof window !== 'undefined' && window.location ? window.location.hostname : 'localhost';

  if (envBase) {
    try {
      const parsed = new URL(envBase, typeof window !== 'undefined' ? window.location.origin : 'http://localhost:3000');
      // If current browser is on localhost, but env is 127.0.0.1, or vice versa:
      if ((currentHost === 'localhost' && parsed.hostname === '127.0.0.1') ||
          (currentHost === '127.0.0.1' && parsed.hostname === 'localhost')) {
        parsed.hostname = currentHost;
      }
      const apiBase = parsed.toString().replace(/\/$/, '');
      const backendRoot = apiBase.replace(/\/api\/?$/, '');
      return { apiBaseUrl: apiBase, backendUrl: backendRoot };
    } catch {
      const apiBase = envBase.replace(/\/$/, '');
      return { apiBaseUrl: apiBase, backendUrl: apiBase.replace(/\/api\/?$/, '') };
    }
  }

  // Default to relative if no env (works with Vite proxy) or matching host on 8080
  return {
    apiBaseUrl: `http://${currentHost}:8080/api`,
    backendUrl: `http://${currentHost}:8080`,
  };
}

export const { apiBaseUrl: API_BASE_URL, backendUrl: BACKEND_URL } = resolveEndpoints();

export interface ApiResponse<T = unknown> {
  status?: 'success' | 'error';
  data?: T;
  user?: T;
  message?: string;
  errors?: Record<string, string[]>;
}

export class ApiError extends Error {
  status: number;
  data: Record<string, unknown>;
  errors?: Record<string, string[]>;

  constructor(status: number, message: string, data: Record<string, unknown> = {}, errors?: Record<string, string[]>) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.data = data;
    this.errors = errors;
  }
}

/**
 * Human-readable fallback for HTTP failures where the backend did not supply a message
 * (gateway error pages, timeouts, throttling). Never exposes technical details.
 */
export function friendlyStatusMessage(status: number): string {
  switch (status) {
    case 400: return 'The request could not be processed. Please review your input and try again.';
    case 403: return 'You do not have permission to perform this action.';
    case 404: return 'The requested record could not be found. It may have been removed.';
    case 409: return 'This action conflicts with the current state of the record. Refresh and try again.';
    case 413: return 'The uploaded file is too large.';
    case 422: return 'Some of the submitted values are invalid.';
    case 429: return 'Too many requests. Please wait a moment and try again.';
    case 502:
    case 503: return 'A required service is temporarily unavailable. Please try again shortly.';
    case 504: return 'The server took too long to respond. Please try again.';
    default:
      return status >= 500
        ? 'Something went wrong on our side. Please try again later.'
        : `Request failed with status ${status}`;
  }
}

/**
 * Get cookie value by name from document.cookie
 */
export function getCookie(name: string): string | null {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.match(new RegExp(`(^|;\\s*)(${name})=([^;]*)`));
  return match ? decodeURIComponent(match[3]) : null;
}

/**
 * Fetch CSRF cookie from Laravel Sanctum
 */
export async function getCsrfCookie(): Promise<void> {
  try {
    const endpoints = resolveEndpoints();
    await fetch(`${endpoints.backendUrl}/sanctum/csrf-cookie`, {
      method: 'GET',
      credentials: 'include',
      headers: {
        'Accept': 'application/json',
      },
    });
  } catch (error) {
    console.warn('[Sanctum] CSRF initialization warning:', error);
  }
}

/**
 * Fired when an authenticated request is rejected with 401 (server-side session expired or revoked).
 * AuthContext listens and clears the client session so ProtectedRoute redirects to /login.
 */
export const SESSION_EXPIRED_EVENT = 'facultylens:session-expired';

const AUTH_ENDPOINTS = ['/auth/login', '/auth/register', '/auth/user', '/auth/logout'];

function isAuthEndpoint(url: string): boolean {
  return AUTH_ENDPOINTS.some((p) => url.includes(p));
}

/**
 * Unified API Client for Sanctum-authenticated requests
 */
export async function apiClient<T>(
  endpoint: string,
  options: RequestInit = {},
  retriedAfterCsrfMismatch = false
): Promise<T> {
  const endpoints = resolveEndpoints();
  const url = endpoint.startsWith('http') ? endpoint : `${endpoints.apiBaseUrl}${endpoint.startsWith('/') ? endpoint : `/${endpoint}`}`;
  const method = (options.method || 'GET').toUpperCase();
  const mutating = ['POST', 'PUT', 'DELETE', 'PATCH'].includes(method);

  // For stateful mutating requests, initialize CSRF cookie if not present
  if (mutating) {
    let xsrfToken = getCookie('XSRF-TOKEN');
    if (!xsrfToken) {
      await getCsrfCookie();
      xsrfToken = getCookie('XSRF-TOKEN');
    }
  }

  const xsrfToken = getCookie('XSRF-TOKEN');

  const defaultHeaders: Record<string, string> = {
    'Accept': 'application/json',
    ...(options.body && !(options.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {}),
    ...(xsrfToken ? { 'X-XSRF-TOKEN': xsrfToken } : {}),
  };

  let response: Response;
  try {
    response = await fetch(url, {
      ...options,
      credentials: 'include', // Always include Sanctum session cookies
      headers: {
        ...defaultHeaders,
        ...(options.headers as Record<string, string>),
      },
    });
  } catch (networkError) {
    // fetch() only rejects on network-level failures (server down, DNS, CORS, offline)
    throw new ApiError(0, 'Unable to reach the FacultyLens server. Check your connection and try again.', {
      cause: networkError instanceof Error ? networkError.message : String(networkError),
    });
  }

  // A stale XSRF cookie (session rotated in another tab, server restart) yields 419 before the
  // request is processed, so refreshing the token and replaying once is safe — even for POST.
  if (response.status === 419 && mutating && !retriedAfterCsrfMismatch) {
    await getCsrfCookie();
    return apiClient<T>(endpoint, options, true);
  }

  if (!response.ok) {
    let errorData: Record<string, unknown> = {};
    try {
      errorData = await response.json();
    } catch {
      // response is not JSON (e.g. a gateway error page)
    }

    let message = (errorData.message as string) || friendlyStatusMessage(response.status);

    if (response.status === 401) {
      if (isAuthEndpoint(url)) {
        message = (errorData.message as string) || 'Invalid email or password';
      } else {
        message = 'Your session has expired. Please sign in again.';
        if (typeof window !== 'undefined') {
          window.dispatchEvent(new CustomEvent(SESSION_EXPIRED_EVENT));
        }
      }
    } else if (response.status === 419) {
      message = 'Your session has expired. Please sign in again.';
    } else if (response.status === 422 && errorData.errors) {
      const firstError = Object.values(errorData.errors as Record<string, string[]>)[0]?.[0];
      if (firstError) {
        message = firstError;
      }
    } else if (response.status >= 500 && typeof errorData.message === 'string' && /exception|stack|trace|sqlstate|\.php/i.test(errorData.message)) {
      // Never surface internal details even if a misconfigured server returns them
      message = friendlyStatusMessage(response.status);
    }

    throw new ApiError(
      response.status,
      message,
      errorData,
      errorData.errors as Record<string, string[]> | undefined
    );
  }

  if (response.status === 204) {
    return {} as T;
  }

  return response.json();
}
