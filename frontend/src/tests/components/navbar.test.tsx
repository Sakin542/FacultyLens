import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { Navbar } from '@/components/layout/Navbar';
import { AuthContext } from '@/context/AuthContext';
import { User } from '@/types';

const ctx = (user: User | null, loading = false) => ({
  user,
  isAuthenticated: Boolean(user),
  loading,
  login: vi.fn(),
  logout: vi.fn(),
  register: vi.fn(),
  refreshUser: vi.fn(),
  updateUser: vi.fn(),
});

const faculty: User = { id: 3, name: 'Dr. Ada Lovelace', email: 'ada@university.edu', department: 'CSE', designation: 'Professor', profile_picture_url: '/api/users/3/profile-picture?v=1' };

const renderNav = (value: ReturnType<typeof ctx>) =>
  render(<AuthContext.Provider value={value}><MemoryRouter><Navbar /></MemoryRouter></AuthContext.Provider>);

describe('Public Navbar auth controls', () => {
  it('shows Sign In and Register for visitors', () => {
    renderNav(ctx(null));
    expect(screen.getAllByRole('button', { name: 'Sign In' }).length).toBeGreaterThan(0);
    expect(screen.getAllByRole('button', { name: 'Register' }).length).toBeGreaterThan(0);
    expect(screen.queryByTestId('navbar-profile-link')).toBeNull();
  });

  it('shows only the profile link when signed in', () => {
    renderNav(ctx(faculty));
    expect(screen.queryByRole('button', { name: 'Sign In' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Register' })).toBeNull();
    const link = screen.getByTestId('navbar-profile-link');
    expect(link).toHaveAttribute('href', '/dashboard');
    expect(link).toHaveTextContent('Dr. Ada Lovelace');
    expect(link.querySelector('img')?.getAttribute('src')).toContain('/api/users/3/profile-picture?v=1');
  });

  it('renders neither control while the session is still loading', () => {
    renderNav(ctx(null, true));
    expect(screen.queryByRole('button', { name: 'Sign In' })).toBeNull();
    expect(screen.queryByTestId('navbar-profile-link')).toBeNull();
  });
});
