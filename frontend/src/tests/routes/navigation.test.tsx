import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { AppRoutes } from '@/routes/AppRoutes';
import { AuthContext } from '@/context/AuthContext';
import { User } from '@/types';

describe('Routing and Navigation Security', () => {
  const createMockAuthContext = (overrides = {}) => ({
    user: null as User | null,
    isAuthenticated: false,
    loading: false,
    login: vi.fn(),
    logout: vi.fn(),
    register: vi.fn(),
    refreshUser: vi.fn(),
    ...overrides,
  });

  it('redirects unauthenticated users attempting to access /dashboard to /login', () => {
    const mockContext = createMockAuthContext({ isAuthenticated: false, loading: false });

    render(
      <AuthContext.Provider value={mockContext}>
        <MemoryRouter initialEntries={['/dashboard']}>
          <AppRoutes />
        </MemoryRouter>
      </AuthContext.Provider>
    );

    expect(screen.getByRole('heading', { name: /sign in/i })).toBeInTheDocument();
  });

  it('renders login page for public /login route', () => {
    const mockContext = createMockAuthContext({ isAuthenticated: false, loading: false });

    render(
      <AuthContext.Provider value={mockContext}>
        <MemoryRouter initialEntries={['/login']}>
          <AppRoutes />
        </MemoryRouter>
      </AuthContext.Provider>
    );

    expect(screen.getByRole('heading', { name: /sign in/i })).toBeInTheDocument();
    expect(screen.getByPlaceholderText(/faculty@example\.com/i)).toBeInTheDocument();
  });

  it('renders register page for public /register route', () => {
    const mockContext = createMockAuthContext({ isAuthenticated: false, loading: false });

    render(
      <AuthContext.Provider value={mockContext}>
        <MemoryRouter initialEntries={['/register']}>
          <AppRoutes />
        </MemoryRouter>
      </AuthContext.Provider>
    );

    expect(screen.getByRole('heading', { name: /create faculty account/i })).toBeInTheDocument();
  });

  it('renders 404 page for nonexistent routes', () => {
    const mockContext = createMockAuthContext({ isAuthenticated: false, loading: false });

    render(
      <AuthContext.Provider value={mockContext}>
        <MemoryRouter initialEntries={['/completely-unknown-route']}>
          <AppRoutes />
        </MemoryRouter>
      </AuthContext.Provider>
    );

    expect(screen.getByText(/404/i)).toBeInTheDocument();
  });
});
