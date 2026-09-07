import { apiClient, getCsrfCookie } from './api';
import { User } from '@/types';

export interface RegisterPayload {
  name: string;
  email: string;
  department: string;
  designation: string;
  password: string;
  password_confirmation: string;
}

export interface LoginPayload {
  email: string;
  password: string;
  remember?: boolean;
}

export interface AuthResponse {
  status: 'success' | 'error';
  message?: string;
  user?: User;
}

export const authService = {
  /**
   * Request Sanctum CSRF cookie initialization
   */
  initCsrf: async (): Promise<void> => {
    await getCsrfCookie();
  },

  /**
   * Register a new faculty account
   */
  register: async (data: RegisterPayload): Promise<AuthResponse> => {
    await getCsrfCookie();
    return apiClient<AuthResponse>('/auth/register', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  },

  /**
   * Sign in faculty member
   */
  login: async (credentials: LoginPayload): Promise<AuthResponse> => {
    await getCsrfCookie();
    return apiClient<AuthResponse>('/auth/login', {
      method: 'POST',
      body: JSON.stringify(credentials),
    });
  },

  /**
   * Fetch currently authenticated faculty user
   */
  getCurrentUser: async (): Promise<AuthResponse> => {
    return apiClient<AuthResponse>('/auth/user', {
      method: 'GET',
    });
  },

  /**
   * Invalidate session and log faculty member out
   */
  logout: async (): Promise<AuthResponse> => {
    return apiClient<AuthResponse>('/auth/logout', {
      method: 'POST',
    });
  },
};
