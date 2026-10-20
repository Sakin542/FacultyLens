import React, { lazy, Suspense } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { PublicLayout } from '@/components/layout/PublicLayout';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { ProtectedRoute } from '@/components/common/ProtectedRoute';
import { adminRoutes } from '@/features/admin/routes';
import { ScrollToTop } from '@/components/common/PageTransition';

// Eagerly loaded — small, frequently visited
import { Home } from '@/pages/Home';
import { Login } from '@/pages/Login';
import { Register } from '@/pages/Register';
import { ForgotPassword } from '@/pages/ForgotPassword';
import { ResetPassword } from '@/pages/ResetPassword';
import { NotFound } from '@/pages/NotFound';

// Lazily loaded — large feature bundles only needed on specific routes
// The signed-in workspace pages are lazy too: imported eagerly, they (and everything they use) loaded on every
// first visit, including the public login page.
const Dashboard          = lazy(() => import('@/pages/Dashboard').then(m => ({ default: m.Dashboard })));
const Courses            = lazy(() => import('@/pages/Courses').then(m => ({ default: m.Courses })));
const CourseDetails      = lazy(() => import('@/pages/CourseDetails').then(m => ({ default: m.CourseDetails })));
const CoPoMapping        = lazy(() => import('@/pages/CoPoMapping').then(m => ({ default: m.CoPoMapping })));
const Assessments        = lazy(() => import('@/pages/Assessments').then(m => ({ default: m.Assessments })));
const AssessmentDetails  = lazy(() => import('@/pages/AssessmentDetails').then(m => ({ default: m.AssessmentDetails })));
const Settings           = lazy(() => import('@/pages/Settings').then(m => ({ default: m.Settings })));
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
const CollaborationHub   = lazy(() => import('@/pages/CollaborationHub').then(m => ({ default: m.CollaborationHub })));
const PendingInvitations = lazy(() => import('@/pages/Invitations').then(m => ({ default: m.PendingInvitations })));
const InvitationLanding  = lazy(() => import('@/pages/Invitations').then(m => ({ default: m.InvitationLanding })));
const AcademicAnalytics  = lazy(() => import('@/pages/AcademicAnalytics').then(m => ({ default: m.AcademicAnalytics })));
const AssessmentBlueprintPage = lazy(() => import('@/pages/AssessmentBlueprint').then(m => ({ default: m.AssessmentBlueprintPage })));
const AssessmentVersions = lazy(() => import('@/pages/AssessmentVersions').then(m => ({ default: m.AssessmentVersions })));
const AssessmentVersionDetail = lazy(() => import('@/pages/AssessmentVersionDetail').then(m => ({ default: m.AssessmentVersionDetail })));
const AssessmentVersionCompare = lazy(() => import('@/pages/AssessmentVersionCompare').then(m => ({ default: m.AssessmentVersionCompare })));
const InstitutionalReports = lazy(() => import('@/pages/InstitutionalReports').then(m => ({ default: m.InstitutionalReports })));
const ReportBuilder      = lazy(() => import('@/pages/ReportBuilder').then(m => ({ default: m.ReportBuilder })));
const ReportDetails      = lazy(() => import('@/pages/ReportDetails').then(m => ({ default: m.ReportDetails })));
const Notifications      = lazy(() => import('@/pages/Notifications').then(m => ({ default: m.Notifications })));
const NewsletterLanding  = lazy(() => import('@/pages/NewsletterLanding').then(m => ({ default: m.NewsletterLanding })));
const ExamPapers         = lazy(() => import('@/pages/ExamPapers').then(m => ({ default: m.ExamPapers })));
const ExamPaperEditor    = lazy(() => import('@/pages/ExamPaperEditor').then(m => ({ default: m.ExamPaperEditor })));
const AssessmentIntegrity = lazy(() => import('@/pages/AssessmentIntegrity').then(m => ({ default: m.AssessmentIntegrity })));
const FeedbackBank       = lazy(() => import('@/pages/FeedbackBank').then(m => ({ default: m.FeedbackBank })));
const CourseQualityTrend = lazy(() => import('@/pages/CourseQualityTrend').then(m => ({ default: m.CourseQualityTrend })));
const CourseObe          = lazy(() => import('@/pages/CourseObe').then(m => ({ default: m.CourseObe })));
const CalendarPage       = lazy(() => import('@/pages/Calendar').then(m => ({ default: m.Calendar })));
const WorkloadPage       = lazy(() => import('@/pages/Workload').then(m => ({ default: m.Workload })));
const DepartmentPage     = lazy(() => import('@/pages/Department').then(m => ({ default: m.Department })));
const CourseDesign       = lazy(() => import('@/pages/CourseDesign').then(m => ({ default: m.CourseDesign })));
const AiToolkit          = lazy(() => import('@/pages/AiToolkit').then(m => ({ default: m.AiToolkit })));
const Research           = lazy(() => import('@/pages/Research').then(m => ({ default: m.Research })));
const MobileGrading      = lazy(() => import('@/pages/MobileGrading').then(m => ({ default: m.MobileGrading })));
const AdminLogin         = lazy(() => import('@/pages/admin/AdminLogin').then(m => ({ default: m.AdminLogin })));

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
          <Route path="/reset-password" element={<ResetPassword />} />
          <Route path="/newsletter/confirm/:token" element={<NewsletterLanding mode="confirm" />} />
          <Route path="/newsletter/unsubscribe/:token" element={<NewsletterLanding mode="unsubscribe" />} />
          <Route path="/admin/login" element={<AdminLogin />} />
        </Route>

        {/* Admin control center: separate shell, configured administrator account only */}
        {adminRoutes}

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
            {/* AI Evaluation moved to the admin control center (/admin/ai-evaluation) */}
            <Route path="/ai-evaluation" element={<Navigate to="/dashboard" replace />} />
            <Route path="/analytics" element={<AcademicAnalytics />} />
            {/* STEP 39: Institutional Export & Reporting */}
            <Route path="/reports" element={<InstitutionalReports />} />
            <Route path="/reports/create" element={<ReportBuilder />} />
            <Route path="/reports/:reportId" element={<ReportDetails />} />
            <Route path="/assessments/:assessmentId/blueprint" element={<AssessmentBlueprintPage />} />
            <Route path="/assessments/:assessmentId/exam-papers" element={<ExamPapers />} />
            <Route path="/exam-papers/:paperId" element={<ExamPaperEditor />} />
            <Route path="/assessments/:assessmentId/integrity" element={<AssessmentIntegrity />} />
            <Route path="/feedback-bank" element={<FeedbackBank />} />
            <Route path="/courses/:courseId/quality" element={<CourseQualityTrend />} />
            <Route path="/courses/:courseId/obe" element={<CourseObe />} />
            <Route path="/calendar" element={<CalendarPage />} />
            <Route path="/workload" element={<WorkloadPage />} />
            <Route path="/department" element={<DepartmentPage />} />
            <Route path="/courses/:courseId/design" element={<CourseDesign />} />
            <Route path="/research" element={<Research />} />
            <Route path="/ai-toolkit" element={<AiToolkit />} />
            <Route path="/assessments/:assessmentId/grade" element={<MobileGrading />} />
            <Route path="/assessments/:assessmentId/versions" element={<AssessmentVersions />} />
            <Route path="/assessments/:assessmentId/versions/:versionId" element={<AssessmentVersionDetail />} />
            <Route path="/assessments/:assessmentId/versions/:versionId/compare" element={<AssessmentVersionCompare />} />
            <Route path="/courses/:courseId/collaboration" element={<CourseCollaboration />} />
            <Route path="/collaboration" element={<CollaborationHub />} />
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
            {/* STEP 47: Notification center */}
            <Route path="/notifications" element={<Notifications />} />
            <Route path="/settings" element={<Settings />} />
          </Route>
        </Route>

        {/* 404 Fallback */}
        <Route path="*" element={<NotFound />} />
      </Routes>
    </Suspense>
  );
};

