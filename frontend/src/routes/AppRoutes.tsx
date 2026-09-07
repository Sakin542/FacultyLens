import React from 'react';
import { Routes, Route } from 'react-router-dom';
import { PublicLayout } from '@/components/layout/PublicLayout';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { ProtectedRoute } from '@/components/common/ProtectedRoute';

import { Home } from '@/pages/Home';
import { Login } from '@/pages/Login';
import { Register } from '@/pages/Register';
import { ForgotPassword } from '@/pages/ForgotPassword';
import { Dashboard } from '@/pages/Dashboard';
import { Courses } from '@/pages/Courses';
import { CourseDetails } from '@/pages/CourseDetails';
import { Assessments } from '@/pages/Assessments';
import { AssessmentDetails } from '@/pages/AssessmentDetails';
import { QuestionBank } from '@/pages/QuestionBank';
import { Analysis } from '@/pages/Analysis';
import { DocumentDetails } from '@/pages/DocumentDetails';
import { History, AssessmentHistory } from '@/pages/AssessmentHistory';
import { Settings } from '@/pages/Settings';
import { AssessmentReport } from '@/pages/AssessmentReport';
import { SharedReport } from '@/pages/SharedReport';
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

      {/* Public Shared Report Access (STEP 18) */}
      <Route path="/shared/reports/:token" element={<SharedReport />} />

      {/* Protected Dashboard Routes (Only Authenticated / Registered Faculty) */}
      <Route element={<ProtectedRoute />}>
        <Route element={<DashboardLayout />}>
          <Route path="/dashboard" element={<Dashboard />} />
          <Route path="/courses" element={<Courses />} />
          <Route path="/courses/:id" element={<CourseDetails />} />
          <Route path="/courses/:courseId/question-bank" element={<QuestionBank />} />
          <Route path="/question-bank" element={<QuestionBank />} />
          <Route path="/assessments" element={<Assessments />} />
          <Route path="/assessments/:id" element={<AssessmentDetails />} />
          <Route path="/assessments/:id/analysis" element={<Analysis />} />
          <Route path="/assessments/:assessmentId/report" element={<AssessmentReport />} />
          <Route path="/assessments/:id/report" element={<AssessmentReport />} />
          <Route path="/documents/:id" element={<DocumentDetails />} />
          <Route path="/assessment-history" element={<AssessmentHistory />} />
          <Route path="/history" element={<History />} />
          <Route path="/analysis" element={<Analysis />} />
          <Route path="/settings" element={<Settings />} />
        </Route>
      </Route>

      {/* 404 Fallback */}
      <Route path="*" element={<NotFound />} />
    </Routes>
  );
};

