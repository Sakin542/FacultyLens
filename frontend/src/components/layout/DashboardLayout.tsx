import React, { useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { Sidebar } from './Sidebar';
import { Menu, Sparkles } from 'lucide-react';
import { Badge } from '@/components/common/Badge';

export const DashboardLayout: React.FC = () => {
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false);
  const location = useLocation();

  const getPageTitle = (pathname: string): { title: string; subtitle: string } => {
    switch (pathname) {
      case '/dashboard':
        return { title: 'Dashboard', subtitle: 'Academic performance & analysis overview' };
      case '/courses':
        return { title: 'Courses', subtitle: 'Manage your assigned semester courses and outcomes' };
      case '/assessments':
        return { title: 'Assessments', subtitle: 'Exams, quizzes, and project evaluation papers' };
      case '/analysis':
        return { title: 'Assessment Analysis', subtitle: 'AI-assisted deep quality & coverage audit' };
      case '/history':
        return { title: 'Analysis History', subtitle: 'Past assessment reviews and improvement logs' };
      case '/settings':
        return { title: 'Settings', subtitle: 'Profile, department metadata, and security' };
      default:
        return { title: 'FacultyLens', subtitle: 'AI Decision Support System' };
    }
  };

  const { title, subtitle } = getPageTitle(location.pathname);

  return (
    <div className="min-h-screen bg-[#F7F7F5] flex">
      {/* Sidebar Navigation */}
      <Sidebar
        mobileOpen={mobileSidebarOpen}
        onCloseMobile={() => setMobileSidebarOpen(false)}
      />

      {/* Main Content Area */}
      <div className="flex-1 flex flex-col md:pl-64 min-w-0">
        {/* Top Header Bar */}
        <header className="sticky top-0 z-20 bg-[#F7F7F5]/90 backdrop-blur-md border-b border-[#E5E5E5] h-16 flex items-center justify-between px-4 sm:px-6 lg:px-8">
          <div className="flex items-center gap-3 min-w-0">
            <button
              type="button"
              className="p-2 -ml-2 rounded-lg text-[#111111] hover:bg-[#E5E5E5] md:hidden focus:outline-none"
              onClick={() => setMobileSidebarOpen(true)}
              aria-label="Open sidebar"
            >
              <Menu className="w-5 h-5" />
            </button>
            <div className="truncate">
              <h1 className="text-base sm:text-lg font-bold text-[#111111] tracking-tight truncate">
                {title}
              </h1>
              <p className="text-xs text-[#737373] hidden sm:block truncate">
                {subtitle}
              </p>
            </div>
          </div>

          <div className="flex items-center gap-3 shrink-0">
            <Badge variant="outline" className="font-mono text-[11px] hidden sm:inline-flex bg-white">
              Spring 2026
            </Badge>
            <div className="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white border border-[#E5E5E5] text-xs font-medium text-[#262626] shadow-subtle">
              <Sparkles className="w-3.5 h-3.5 text-[#111111]" />
              <span className="hidden sm:inline">AI Engine:</span>
              <span className="text-[#166534] font-semibold flex items-center gap-1">
                <span className="w-1.5 h-1.5 rounded-full bg-[#16A34A] animate-pulse"></span>
                Ready
              </span>
            </div>
          </div>
        </header>

        {/* Dynamic Page Content */}
        <main className="flex-1 p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto">
          <Outlet />
        </main>
      </div>
    </div>
  );
};

