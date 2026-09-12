import React, { createContext, useContext, useEffect, useState, useCallback } from 'react';
import { User } from '@/types';
import { authService, LoginPayload, RegisterPayload } from '@/services/authService';
import { SESSION_EXPIRED_EVENT } from '@/services/api';

interface AuthContextType {
  user: User | null;
  isAuthenticated: boolean;
  loading: boolean;
  login: (credentials: LoginPayload) => Promise<User>;
  register: (data: RegisterPayload) => Promise<User>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<User | null>;
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

  // Initialize session from Laravel Sanctum on mount / refresh (F5)
  useEffect(() => {
    let isMounted = true;
    // Keep the boot splash on screen long enough for its reveal sequence (skipped in tests)
    const minSplashMs = import.meta.env.MODE === 'test' ? 0 : 1500;
    const startedAt = Date.now();

    const initializeAuth = async () => {
      try {
        const response = await authService.getCurrentUser();
        if (isMounted && response.user) {
          setUser(normalizeUser(response.user));
        }
      } catch {
        if (isMounted) {
          setUser(null);
        }
      } finally {
        const remaining = Math.max(0, minSplashMs - (Date.now() - startedAt));
        if (remaining > 0) {
          await new Promise((resolve) => setTimeout(resolve, remaining));
        }
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

  const login = async (credentials: LoginPayload): Promise<User> => {
    const response = await authService.login(credentials);
    if (!response.user) {
      throw new Error(response.message || 'Authentication failed');
    }
    const normalized = normalizeUser(response.user);
    setUser(normalized);
    return normalized;
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
    try {
      await authService.logout();
    } catch (err) {
      console.warn('Logout API error:', err);
    } finally {
      setUser(null);
    }
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        isAuthenticated: Boolean(user),
        loading,
        login,
        register,
        logout,
        refreshUser,
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

