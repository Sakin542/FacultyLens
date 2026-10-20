import React from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '@/context/AuthContext';
import { SessionSplash } from './ProtectedRoute';

/** Admin panel guard (UX only — every /api/admin endpoint is enforced server-side by the `admin` middleware). */
export const AdminRoute: React.FC = () => {
  const { isAuthenticated, loading, user } = useAuth();

  if (loading) return <SessionSplash />;
  if (!isAuthenticated || !user?.is_admin) return <Navigate to="/admin/login" replace />;

  return <Outlet />;
};
