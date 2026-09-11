import React, { lazy, Suspense } from 'react';
import { Routes, Route } from 'react-router-dom';
import { PublicLayout } from '@/components/layout/PublicLayout';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { ProtectedRoute } from '@/components/common/ProtectedRoute';
import { ScrollToTop } from '@/components/common/PageTransition';

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
const AcademicChat       = lazy(() => import('@/pages/AcademicChat').then(m => ({ default: m.AcademicChat })));
const QuestionGenerator  = lazy(() => import('@/pages/QuestionGenerator').then(m => ({ default: m.QuestionGenerator })));
const CourseCollaboration = lazy(() => import('@/pages/CourseCollaboration').then(m => ({ default: m.CourseCollaboration })));
const PendingInvitations = lazy(() => import('@/pages/Invitations').then(m => ({ default: m.PendingInvitations })));
const InvitationLanding  = lazy(() => import('@/pages/Invitations').then(m => ({ default: m.InvitationLanding })));
const AiEvaluation       = lazy(() => import('@/pages/AiEvaluation').then(m => ({ default: m.AiEvaluation })));
const AcademicAnalytics  = lazy(() => import('@/pages/AcademicAnalytics').then(m => ({ default: m.AcademicAnalytics })));
const AssessmentBlueprintPage = lazy(() => import('@/pages/AssessmentBlueprint').then(m => ({ default: m.AssessmentBlueprintPage })));
const AssessmentVersions = lazy(() => import('@/pages/AssessmentVersions').then(m => ({ default: m.AssessmentVersions })));
const AssessmentVersionDetail = lazy(() => import('@/pages/AssessmentVersionDetail').then(m => ({ default: m.AssessmentVersionDetail })));
const AssessmentVersionCompare = lazy(() => import('@/pages/AssessmentVersionCompare').then(m => ({ default: m.AssessmentVersionCompare })));

/**
 * Lightweight fallback shown while a lazy page chunk loads.
 */
const PageLoader: React.FC = () => (
  <div className="flex items-center justify-center min-h-[60vh] page-enter" role="status" aria-label="Loading">
    <div className="h-8 w-8 rounded-full border-[3px] border-sage-300 border-t-sage-700 animate-spin" />
  </div>
);

export const AppRoutes: React.FC = () => {
  return (
    <Suspense fallback={<PageLoader />}>
      <ScrollToTop />
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
        {/* STEP 34: secure invitation link (minimal preview; accept/decline require sign-in) */}
        <Route path="/collaboration/invitations/:token" element={<InvitationLanding />} />

        {/* Protected Dashboard Routes (Only Authenticated / Registered Faculty) */}
        <Route element={<ProtectedRoute />}>
          <Route element={<DashboardLayout />}>
            <Route path="/dashboard" element={<Dashboard />} />
            <Route path="/courses" element={<Courses />} />
            <Route path="/courses/:id" element={<CourseDetails />} />
            <Route path="/courses/:courseId/co-po-mapping" element={<CoPoMapping />} />
            <Route path="/courses/:courseId/chat" element={<AcademicChat />} />
            <Route path="/academic-chat" element={<AcademicChat />} />
            <Route path="/courses/:courseId/question-generator" element={<QuestionGenerator />} />
            <Route path="/question-generator" element={<QuestionGenerator />} />
            <Route path="/ai-evaluation" element={<AiEvaluation />} />
            <Route path="/analytics" element={<AcademicAnalytics />} />
            <Route path="/assessments/:assessmentId/blueprint" element={<AssessmentBlueprintPage />} />
            <Route path="/assessments/:assessmentId/versions" element={<AssessmentVersions />} />
            <Route path="/assessments/:assessmentId/versions/:versionId" element={<AssessmentVersionDetail />} />
            <Route path="/assessments/:assessmentId/versions/:versionId/compare" element={<AssessmentVersionCompare />} />
            <Route path="/courses/:courseId/collaboration" element={<CourseCollaboration />} />
            <Route path="/collaboration/invitations" element={<PendingInvitations />} />
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

