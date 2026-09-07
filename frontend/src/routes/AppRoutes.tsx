import React from 'react';
import { Routes, Route } from 'react-router-dom';
import { PublicLayout } from '@/components/layout/PublicLayout';
import { DashboardLayout } from '@/components/layout/DashboardLayout';

import { Home } from '@/pages/Home';
import { Login } from '@/pages/Login';
import { Register } from '@/pages/Register';
import { ForgotPassword } from '@/pages/ForgotPassword';
import { Dashboard } from '@/pages/Dashboard';
import { Courses } from '@/pages/Courses';
import { Assessments } from '@/pages/Assessments';
import { Analysis } from '@/pages/Analysis';
import { History } from '@/pages/History';
import { Settings } from '@/pages/Settings';
import { NotFound } from '@/pages/NotFound';

export const AppRoutes: React.FC = () => {
  return (
    <Routes>
      {/* Public Pages */}
      <Route element={<PublicLayout />}>
        <Route path="/" element={<Home />} />
        <Route path="/login" element={<Login />} />
        <Route path="/register" element={<Register />} />
        <Route path="/forgot-password" element={<ForgotPassword />} />
      </Route>

      {/* Dashboard Protected Application Pages */}
      <Route element={<DashboardLayout />}>
        <Route path="/dashboard" element={<Dashboard />} />
        <Route path="/courses" element={<Courses />} />
        <Route path="/assessments" element={<Assessments />} />
        <Route path="/analysis" element={<Analysis />} />
        <Route path="/history" element={<History />} />
        <Route path="/settings" element={<Settings />} />
      </Route>

      {/* 404 Fallback */}
      <Route path="*" element={<NotFound />} />
    </Routes>
  );
};

