import React from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '@/context/AuthContext';
import { ShieldCheck } from 'lucide-react';

export const ProtectedRoute: React.FC = () => {
  const { isAuthenticated, loading } = useAuth();

  if (loading) {
    return (
      <div className="min-h-screen flex flex-col items-center justify-center bg-[#F7F7F5] dark:bg-[#121212] p-4">
        <div className="flex flex-col items-center space-y-4">
          <div className="w-12 h-12 rounded-2xl bg-[#111111] dark:bg-white text-white dark:text-[#111111] flex items-center justify-center shadow-card animate-pulse">
            <ShieldCheck className="w-6 h-6" />
          </div>
          <div className="text-center space-y-1">
            <p className="text-sm font-semibold text-[#111111] dark:text-white tracking-tight">
              Verifying Institutional Session
            </p>
            <p className="text-xs text-[#737373] dark:text-[#A3A3A3]">
              Connecting with FacultyLens Sanctum...
            </p>
          </div>
        </div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  return <Outlet />;
};
