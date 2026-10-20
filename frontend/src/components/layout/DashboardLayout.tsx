import React, { useEffect, useState } from 'react';
import { Link, Outlet, useLocation } from 'react-router-dom';
import { Megaphone, Menu } from 'lucide-react';
import { usePublicConfig } from '@/hooks/usePublicConfig';
import { Sidebar } from './Sidebar';
import { NotificationBell } from '@/components/notifications/NotificationBell';
import { PageTransition } from '@/components/common/PageTransition';
import { ProfilePicture } from '@/components/profile/ProfilePicture';
import { useAuth } from '@/context/AuthContext';

const TITLES: Record<string, { title: string; subtitle?: string }> = {
  dashboard: { title: 'Dashboard' },
  courses: { title: 'Courses' },
  assessments: { title: 'Assessments' },
  'question-bank': { title: 'Question Bank' },
  analysis: { title: 'Assessment Analysis' },
  analytics: { title: 'Academic Analytics' },
  reports: { title: 'Institutional Reports' },
  history: { title: 'Analysis History' },
  'academic-chat': { title: 'Document Chat' },
  'question-generator': { title: 'Question Generator' },
  collaboration: { title: 'Collaboration' },
  submissions: { title: 'Submissions' },
  feedback: { title: 'Feedback' },
  notifications: { title: 'Notifications' },
  settings: { title: 'Settings' },
  documents: { title: 'Document Details' },
  admin: { title: 'Administration' },
  'exam-papers': { title: 'Exam Paper Builder' },
  'feedback-bank': { title: 'Comment Bank' },
  calendar: { title: 'Academic Calendar' },
  workload: { title: 'Workload' },
  department: { title: 'Department' },
  research: { title: 'Research Papers' },
  'ai-toolkit': { title: 'AI Toolkit' },
};

const NESTED_TITLES: Record<string, { title: string; subtitle?: string }> = {
  chat: { title: 'Document Chat' },
  'question-generator': { title: 'Question Generator' },
  'question-bank': { title: 'Question Bank' },
  'co-po-mapping': { title: 'CO / PO Mapping' },
  collaboration: { title: 'Collaboration' },
  blueprint: { title: 'Assessment Blueprint' },
  versions: { title: 'Assessment Versions' },
  submissions: { title: 'Submissions' },
  report: { title: 'Assessment Report' },
  'exam-papers': { title: 'Exam Paper Builder' },
  integrity: { title: 'Item Analysis & Plagiarism' },
  quality: { title: 'Quality Across Semesters' },
  obe: { title: 'OBE & Course File' },
  design: { title: 'Course Design Assistant' },
  grade: { title: 'Grading' },
};

export const DashboardLayout: React.FC = () => {
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false);
  const location = useLocation();
  const { user } = useAuth();
  const displayName = user?.name || user?.fullName || 'Faculty Member';

  const segments = location.pathname.split('/').filter(Boolean);
  const segment = segments[0] ?? 'dashboard';
  // Nested course tools (e.g. /courses/:id/chat) take the tool's title, not "Courses"
  const nested = segments.length > 2 && segment !== 'admin' ? NESTED_TITLES[segments[segments.length - 1]] : undefined;
  const { features } = usePublicConfig();
  const announcement = features.announcement?.trim();
  const { title, subtitle } = nested ?? TITLES[segment] ?? { title: 'FacultyLens' };

  useEffect(() => { document.title = `${title} · FacultyLens`; }, [title]);

  return (
    <div className="min-h-screen bg-sage-50 text-sage-800 flex">
      <Sidebar mobileOpen={mobileSidebarOpen} onCloseMobile={() => setMobileSidebarOpen(false)} />

      <div className="flex-1 flex flex-col md:pl-64 min-w-0">
        <header className="sticky top-0 z-20 bg-sage-50/90 backdrop-blur-md border-b border-sage-200/70 h-16 flex items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
          <div className="flex items-center gap-3 min-w-0">
            <button type="button" className="p-2 -ml-2 rounded-lg text-sage-800 hover:bg-sage-200/70 md:hidden" onClick={() => setMobileSidebarOpen(true)} aria-label="Open sidebar" aria-expanded={mobileSidebarOpen}>
              <Menu className="w-5 h-5" />
            </button>
            <div className="min-w-0">
              <h1 className="font-serif text-lg sm:text-xl leading-tight tracking-tight text-sage-800 truncate">{title}</h1>
              {subtitle && <p className="text-[11px] text-sage-500 hidden md:block truncate">{subtitle}</p>}
            </div>
          </div>

          <div className="flex items-center gap-2 sm:gap-3 shrink-0">
            <NotificationBell />
            <Link
              to="/settings"
              className="flex items-center gap-2 pl-1 pr-2 py-1 rounded-full hover:bg-sage-200/70 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage-700"
              aria-label={`Account settings for ${displayName}`}
              data-testid="header-account-link"
            >
              <ProfilePicture src={user?.profile_picture_url} name={displayName} size="sm" tone="dark" decorative />
              <span className="hidden sm:block text-xs font-medium text-sage-800 max-w-[10rem] truncate">{displayName}</span>
            </Link>
          </div>
        </header>

        {announcement && (
          <div role="status" className="flex items-center gap-2 border-b border-[#FDE68A] bg-[#FFFBEB] px-4 py-2 text-xs text-[#92400E] sm:px-6 lg:px-8" data-testid="announcement-banner">
            <Megaphone className="w-4 h-4 shrink-0" aria-hidden="true" />
            <span>{announcement}</span>
          </div>
        )}

        <main className="app-content flex-1 p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto">
          <PageTransition><Outlet /></PageTransition>
        </main>
      </div>
    </div>
  );
};
