/**
 * FacultyLens API Service Foundation
 * 
 * Configured for future backend integration (Laravel API / FastAPI).
 * In STEP 02, mock data is served from `@/utils/mockData`.
 */

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api';

export interface ApiResponse<T> {
  data: T;
  message?: string;
  success: boolean;
}

/**
 * Standard fetch wrapper foundation prepared for JWT/Sanctum tokens in STEP 03+
 */
export async function apiClient<T>(
  endpoint: string,
  options: RequestInit = {}
): Promise<T> {
  const token = localStorage.getItem('facultylens_token');

  const defaultHeaders: HeadersInit = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
  };

  const response = await fetch(`${API_BASE_URL}${endpoint}`, {
    ...options,
    headers: {
      ...defaultHeaders,
      ...options.headers,
    },
  });

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({}));
    throw new Error(errorData.message || `API Error: ${response.status} ${response.statusText}`);
  }

  return response.json();
}

/**
 * Service namespaces for future backend endpoints
 */
export const authService = {
  // Stubs for future authentication
  login: async (credentials: Record<string, string>) => {
    console.info('[API Stub] authService.login called with:', credentials.email);
    return { success: true, token: 'mock-jwt-token' };
  },
  register: async (userData: Record<string, string>) => {
    console.info('[API Stub] authService.register called with:', userData.email);
    return { success: true };
  },
  logout: async () => {
    localStorage.removeItem('facultylens_token');
    return { success: true };
  },
};

export const coursesService = {
  getAll: async () => {
    console.info('[API Stub] coursesService.getAll called');
    return [];
  },
};

export const assessmentsService = {
  getAll: async () => {
    console.info('[API Stub] assessmentsService.getAll called');
    return [];
  },
};

export const analysisService = {
  getById: async (id: string) => {
    console.info('[API Stub] analysisService.getById called with:', id);
    return null;
  },
};

