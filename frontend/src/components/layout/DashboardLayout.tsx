import React, { useEffect, useState } from 'react';
import { Link, Outlet, useLocation } from 'react-router-dom';
import { Menu } from 'lucide-react';
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
  'ai-evaluation': { title: 'AI Evaluation' },
  collaboration: { title: 'Collaboration' },
  submissions: { title: 'Submissions' },
  feedback: { title: 'Feedback' },
  notifications: { title: 'Notifications' },
  settings: { title: 'Settings' },
};

export const DashboardLayout: React.FC = () => {
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false);
  const location = useLocation();
  const { user } = useAuth();
  const displayName = user?.name || user?.fullName || 'Faculty Member';

  const segment = location.pathname.split('/').filter(Boolean)[0] ?? 'dashboard';
  const { title, subtitle } = TITLES[segment] ?? { title: 'FacultyLens' };

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

        <main className="app-content flex-1 p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto">
          <PageTransition><Outlet /></PageTransition>
        </main>
      </div>
    </div>
  );
};
