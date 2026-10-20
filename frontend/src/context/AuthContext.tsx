import React, { createContext, useContext, useEffect, useState, useCallback } from 'react';
import { User } from '@/types';
import { authService, AuthResponse, LoginPayload, RegisterPayload } from '@/services/authService';
import { ApiError, SESSION_EXPIRED_EVENT } from '@/services/api';
import { disconnectRealtime } from '@/services/echo';

/** Password (or Google) accepted, but a 6-digit authenticator code is still needed to finish signing in. */
export interface TwoFactorPending {
  twoFactorRequired: true;
  challengeToken: string;
}

export const isTwoFactorPending = (value: unknown): value is TwoFactorPending =>
  typeof value === 'object' && value !== null && (value as TwoFactorPending).twoFactorRequired === true;

interface AuthContextType {
  user: User | null;
  isAuthenticated: boolean;
  loading: boolean;
  login: (credentials: LoginPayload) => Promise<User | TwoFactorPending>;
  /** Admin panel sign-in (administrator account only). */
  adminLogin: (credentials: LoginPayload) => Promise<User | TwoFactorPending>;
  googleLogin: (credential: string) => Promise<User | TwoFactorPending>;
  completeTwoFactor: (challengeToken: string, code: { code?: string; recovery_code?: string }) => Promise<User>;
  register: (data: RegisterPayload) => Promise<User>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<User | null>;
  /** Replace the in-memory user after a profile/avatar change (no refetch, no reload). */
  updateUser: (user: User) => void;
}

export const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState<boolean>(true);

  const normalizeUser = (rawUser: User): User => {
    return {
      ...rawUser,
      fullName: rawUser.name || rawUser.fullName || '',
    };
  };

  const refreshUser = useCallback(async (): Promise<User | null> => {
    try {
      const response = await authService.getCurrentUser();
      if (response.user) {
        const normalized = normalizeUser(response.user);
        setUser(normalized);
        return normalized;
      }
      setUser(null);
      return null;
    } catch {
      setUser(null);
      return null;
    }
  }, []);

  // Initialize session from Laravel Sanctum on mount / refresh (F5).
  // Only a real answer ("not signed in" or 401) signs the user out. A rate-limit reply, a server error or a network
  // blip (e.g. the backend restarting) is retried briefly, so a refresh does not throw a signed-in user to /login.
  // The page shows as soon as the session is known; there is no minimum splash time (it made every refresh ≥1.5 s).
  useEffect(() => {
    let isMounted = true;
    const retryDelaysMs = import.meta.env.MODE === 'test' ? [] : [400, 1000, 2000, 3000];
    const transient = (err: unknown) => !(err instanceof ApiError) || err.status === 429 || err.status >= 500;

    const initializeAuth = async () => {
      try {
        for (let attempt = 0; ; attempt++) {
          try {
            const session = await authService.getSession();
            if (isMounted) {
              setUser(session.authenticated && session.user ? normalizeUser(session.user) : null);
            }
            return;
          } catch (err) {
            if (!transient(err) || attempt >= retryDelaysMs.length) throw err;
            await new Promise((resolve) => setTimeout(resolve, retryDelaysMs[attempt]));
            if (!isMounted) return;
          }
        }
      } catch {
        if (isMounted) {
          setUser(null);
        }
      } finally {
        if (isMounted) {
          setLoading(false);
        }
      }
    };

    initializeAuth();

    return () => {
      isMounted = false;
    };
  }, []);

  // A 401 on any authenticated request means the server session is gone: drop the client session
  // so ProtectedRoute redirects to /login instead of leaving a dead "logged-in" shell (BUG-007).
  useEffect(() => {
    const onExpired = () => setUser(null);
    window.addEventListener(SESSION_EXPIRED_EVENT, onExpired);
    return () => window.removeEventListener(SESSION_EXPIRED_EVENT, onExpired);
  }, []);

  const finishSignIn = (response: AuthResponse): User | TwoFactorPending => {
    if (response.status === 'two_factor_required' && response.challenge_token) {
      return { twoFactorRequired: true, challengeToken: response.challenge_token };
    }
    if (!response.user) {
      throw new Error(response.message || 'Authentication failed');
    }
    const normalized = normalizeUser(response.user);
    setUser(normalized);
    return normalized;
  };

  const login = async (credentials: LoginPayload): Promise<User | TwoFactorPending> =>
    finishSignIn(await authService.login(credentials));

  const adminLogin = async (credentials: LoginPayload): Promise<User | TwoFactorPending> =>
    finishSignIn(await authService.adminLogin(credentials));

  const googleLogin = async (credential: string): Promise<User | TwoFactorPending> =>
    finishSignIn(await authService.googleLogin(credential));

  const completeTwoFactor = async (challengeToken: string, code: { code?: string; recovery_code?: string }): Promise<User> => {
    const result = finishSignIn(await authService.twoFactorChallenge({ challenge_token: challengeToken, ...code }));
    if (isTwoFactorPending(result)) {
      throw new Error('Verification did not complete. Please sign in again.');
    }
    return result;
  };

  const register = async (data: RegisterPayload): Promise<User> => {
    const response = await authService.register(data);
    if (!response.user) {
      throw new Error(response.message || 'Registration failed');
    }
    const normalized = normalizeUser(response.user);
    setUser(normalized);
    return normalized;
  };

  const logout = async (): Promise<void> => {
    disconnectRealtime();
    try {
      await Promise.race([
        authService.logout(),
        new Promise((resolve) => setTimeout(resolve, 5000)),
      ]);
    } catch (err) {
      console.warn('Logout API error:', err);
    } finally {
      authService.resetSessionProbe();
      setUser(null);
    }
  };

  const updateUser = useCallback((next: User) => {
    setUser((prev) => normalizeUser({ ...(prev ?? {}), ...next } as User));
  }, []);

  return (
    <AuthContext.Provider
      value={{
        user,
        isAuthenticated: Boolean(user),
        loading,
        login,
        adminLogin,
        googleLogin,
        completeTwoFactor,
        register,
        logout,
        refreshUser,
        updateUser,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = (): AuthContextType => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};

