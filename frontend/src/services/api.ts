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
 * Unified API Client for Sanctum-authenticated requests
 */
export async function apiClient<T>(
  endpoint: string,
  options: RequestInit = {}
): Promise<T> {
  const endpoints = resolveEndpoints();
  const url = endpoint.startsWith('http') ? endpoint : `${endpoints.apiBaseUrl}${endpoint.startsWith('/') ? endpoint : `/${endpoint}`}`;
  const method = (options.method || 'GET').toUpperCase();

  // For stateful mutating requests, initialize CSRF cookie if not present
  if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
    let xsrfToken = getCookie('XSRF-TOKEN');
    if (!xsrfToken) {
      await getCsrfCookie();
      xsrfToken = getCookie('XSRF-TOKEN');
    }
  }

  const xsrfToken = getCookie('XSRF-TOKEN');

  const defaultHeaders: Record<string, string> = {
    'Accept': 'application/json',
    ...(options.body ? { 'Content-Type': 'application/json' } : {}),
    ...(xsrfToken ? { 'X-XSRF-TOKEN': xsrfToken } : {}),
  };

  const response = await fetch(url, {
    ...options,
    credentials: 'include', // Always include Sanctum session cookies
    headers: {
      ...defaultHeaders,
      ...(options.headers as Record<string, string>),
    },
  });

  if (!response.ok) {
    let errorData: Record<string, unknown> = {};
    try {
      errorData = await response.json();
    } catch {
      // response is not JSON
    }

    let message = (errorData.message as string) || `Request failed with status ${response.status}`;

    if (response.status === 401) {
      message = (errorData.message as string) || 'Invalid email or password';
    } else if (response.status === 419) {
      message = 'Your session has expired. Please sign in again.';
    } else if (response.status === 422 && errorData.errors) {
      const firstError = Object.values(errorData.errors as Record<string, string[]>)[0]?.[0];
      if (firstError) {
        message = firstError;
      }
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
