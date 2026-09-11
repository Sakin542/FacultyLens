import React, { useRef } from 'react';
import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '@/context/AuthContext';
import { LensLogo } from '@/components/layout/Navbar';

export const SessionSplash: React.FC = () => {
  // The index.html boot splash is still in the DOM during the first render; continue it without replaying entrances.
  const seamless = useRef(typeof document !== 'undefined' && !!document.getElementById('boot'));

  return (
  <div
    className={`min-h-screen flex flex-col items-center justify-center bg-[#FAF9F6] p-6 relative overflow-hidden ${seamless.current ? 'splash-seamless' : ''}`}
    role="status"
    aria-live="polite"
    aria-label="Verifying institutional session"
  >
    <div
      className="pointer-events-none absolute inset-0 opacity-60"
      style={{ backgroundImage: 'radial-gradient(#E8ECE3 1px, transparent 1px)', backgroundSize: '22px 22px' }}
      aria-hidden="true"
    />
    <div className="pointer-events-none absolute -top-32 left-1/2 -translate-x-1/2 h-96 w-96 rounded-full bg-sage-200/50 blur-3xl" aria-hidden="true" />

    <div className="relative flex flex-col items-center text-center">
      <div className="splash-logo group">
        <span className="splash-halo" aria-hidden="true" />
        <LensLogo size={72} />
      </div>

      <p className="splash-step splash-step-1 mt-5 font-serif text-3xl tracking-tight text-sage-800">FacultyLens</p>

      <div className="splash-step splash-step-2 mt-6 space-y-1.5">
        <p className="text-sm font-semibold text-sage-800 tracking-tight">Verifying Institutional Session</p>
        <p className="text-xs text-sage-500">
          Connecting with FacultyLens Sanctum
          <span className="splash-dots" aria-hidden="true"><span>.</span><span>.</span><span>.</span></span>
        </p>
      </div>

      <div className="splash-step splash-step-3 mt-6 h-1 w-40 overflow-hidden rounded-full bg-sage-200" aria-hidden="true">
        <div className="splash-bar h-full w-1/3 rounded-full bg-gradient-to-r from-sage-400 via-sage-600 to-sage-400" />
      </div>
    </div>
  </div>
  );
};

export const ProtectedRoute: React.FC = () => {
  const { isAuthenticated, loading } = useAuth();

  if (loading) {
    return <SessionSplash />;
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  return <Outlet />;
};
