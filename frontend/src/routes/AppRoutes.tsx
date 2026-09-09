import React, { lazy, Suspense } from 'react';
import { Routes, Route } from 'react-router-dom';
import { PublicLayout } from '@/components/layout/PublicLayout';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { ProtectedRoute } from '@/components/common/ProtectedRoute';

// Eagerly loaded — small, frequently visited
import { Home } from '@/pages/Home';
import { Login } from '@/pages/Login';
import { Register } from '@/pages/Register';
import { ForgotPassword } from '@/pages/ForgotPassword';
import { Dashboard } from '@/pages/Dashboard';
import { Courses } from '@/pages/Courses';
import { CourseDetails } from '@/pages/CourseDetails';
import { CoPoMapping } from '@/pages/CoPoMapping';
import { Assessments } from '@/pages/Assessments';
import { AssessmentDetails } from '@/pages/AssessmentDetails';
import { Settings } from '@/pages/Settings';
import { NotFound } from '@/pages/NotFound';

// Lazily loaded — large feature bundles only needed on specific routes
const QuestionBank       = lazy(() => import('@/pages/QuestionBank').then(m => ({ default: m.QuestionBank })));
const Analysis           = lazy(() => import('@/pages/Analysis').then(m => ({ default: m.Analysis })));
const DocumentDetails    = lazy(() => import('@/pages/DocumentDetails').then(m => ({ default: m.DocumentDetails })));
const AssessmentHistory  = lazy(() => import('@/pages/AssessmentHistory').then(m => ({ default: m.AssessmentHistory })));
const AnalysisHistory    = lazy(() => import('@/pages/AnalysisHistory').then(m => ({ default: m.AnalysisHistory })));
const AnalysisComparison = lazy(() => import('@/pages/AnalysisComparison').then(m => ({ default: m.AnalysisComparison })));
const AnalysisDetails    = lazy(() => import('@/pages/AnalysisDetails').then(m => ({ default: m.AnalysisDetails })));
const Feedback           = lazy(() => import('@/pages/Feedback').then(m => ({ default: m.Feedback })));
const AssessmentReport   = lazy(() => import('@/pages/AssessmentReport').then(m => ({ default: m.AssessmentReport })));
const SharedReport       = lazy(() => import('@/pages/SharedReport').then(m => ({ default: m.SharedReport })));
const StudentSubmissions = lazy(() => import('@/pages/StudentSubmissions').then(m => ({ default: m.StudentSubmissions })));
const SubmissionDetails  = lazy(() => import('@/pages/SubmissionDetails').then(m => ({ default: m.SubmissionDetails })));

/**
 * Lightweight fallback shown while a lazy page chunk loads.
 */
const PageLoader: React.FC = () => (
  <div className="flex items-center justify-center min-h-[60vh]">
    <div className="h-8 w-8 rounded-full border-4 border-blue-600 border-t-transparent animate-spin" />
  </div>
);

export const AppRoutes: React.FC = () => {
  return (
    <Suspense fallback={<PageLoader />}>
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
            <Route path="/courses/:courseId/co-po-mapping" element={<CoPoMapping />} />
            <Route path="/courses/:courseId/question-bank" element={<QuestionBank />} />
            <Route path="/question-bank" element={<QuestionBank />} />
            <Route path="/assessments" element={<Assessments />} />
            <Route path="/assessments/:id" element={<AssessmentDetails />} />
            <Route path="/assessments/:id/analysis" element={<Analysis />} />
            <Route path="/assessments/:id/submissions" element={<StudentSubmissions />} />
            <Route path="/submissions/:id" element={<SubmissionDetails />} />
            <Route path="/assessments/:assessmentId/report" element={<AssessmentReport />} />
            <Route path="/assessments/:id/report" element={<AssessmentReport />} />
            <Route path="/documents/:id" element={<DocumentDetails />} />
            <Route path="/assessment-history" element={<AssessmentHistory />} />
            <Route path="/history" element={<AnalysisHistory />} />
            <Route path="/analysis/history" element={<AnalysisHistory />} />
            <Route path="/analysis/compare" element={<AnalysisComparison />} />
            <Route path="/analysis/:analysisId" element={<AnalysisDetails />} />
            <Route path="/analysis" element={<Analysis />} />
            <Route path="/feedback" element={<Feedback />} />
            <Route path="/settings" element={<Settings />} />
          </Route>
        </Route>

        {/* 404 Fallback */}
        <Route path="*" element={<NotFound />} />
      </Routes>
    </Suspense>
  );
};

