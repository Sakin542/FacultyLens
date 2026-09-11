import React, { useEffect, useState } from 'react';
import { Link, Outlet, useLocation } from 'react-router-dom';
import { Menu, Sparkles, ChevronRight } from 'lucide-react';
import { Sidebar, NAV_SECTIONS } from './Sidebar';
import { NotificationBell } from '@/components/collaboration/CollaborationActivity';
import { PageTransition } from '@/components/common/PageTransition';
import { aiService } from '@/services/aiService';

const TITLES: Record<string, { title: string; subtitle: string }> = {
  dashboard: { title: 'Dashboard', subtitle: 'Your courses, assessments and analysis at a glance' },
  courses: { title: 'Courses', subtitle: 'Courses, outcomes and CO/PO mapping' },
  assessments: { title: 'Assessments', subtitle: 'Question papers, blueprints, versions and analysis' },
  'question-bank': { title: 'Question Bank', subtitle: 'Reusable questions across semesters' },
  analysis: { title: 'Assessment Analysis', subtitle: 'Explainable quality, alignment and similarity checks' },
  analytics: { title: 'Academic Analytics', subtitle: 'Trends and signals across your courses' },
  history: { title: 'Analysis History', subtitle: 'Every analysis, attached to the version it examined' },
  'academic-chat': { title: 'Document Chat', subtitle: 'Answers grounded in your own course materials' },
  'question-generator': { title: 'Question Generator', subtitle: 'Drafts stay drafts until you approve them' },
  'ai-evaluation': { title: 'AI Evaluation', subtitle: 'How well the assistant performs on your data' },
  collaboration: { title: 'Collaboration', subtitle: 'Invitations, roles and shared courses' },
  submissions: { title: 'Submissions', subtitle: 'Student answers, rubrics and grading' },
  feedback: { title: 'Feedback', subtitle: 'Tell us what helped and what did not' },
  settings: { title: 'Settings', subtitle: 'Profile, department and security' },
};

type AiState = 'checking' | 'ready' | 'offline';

export const DashboardLayout: React.FC = () => {
  const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false);
  const [ai, setAi] = useState<AiState>('checking');
  const location = useLocation();

  const segment = location.pathname.split('/').filter(Boolean)[0] ?? 'dashboard';
  const { title, subtitle } = TITLES[segment] ?? { title: 'FacultyLens', subtitle: 'AI-powered academic decision support' };
  const sectionTitle = NAV_SECTIONS.find((s) => s.items.some((i) => location.pathname === i.path || location.pathname.startsWith(`${i.path}/`)))?.title;

  useEffect(() => { document.title = `${title} · FacultyLens`; }, [title]);

  useEffect(() => {
    let alive = true;
    const check = () => aiService.checkHealth().then(() => alive && setAi('ready')).catch(() => alive && setAi('offline'));
    void check();
    const t = window.setInterval(check, 120000);
    return () => { alive = false; window.clearInterval(t); };
  }, []);

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
              <nav aria-label="Breadcrumb" className="hidden sm:flex items-center gap-1 text-[11px] text-sage-500">
                <Link to="/dashboard" className="hover:text-sage-800">FacultyLens</Link>
                {sectionTitle && <><ChevronRight className="w-3 h-3" aria-hidden="true" /><span>{sectionTitle}</span></>}
              </nav>
              <h1 className="font-serif text-lg sm:text-xl leading-tight tracking-tight text-sage-800 truncate">{title}</h1>
              <p className="text-[11px] text-sage-500 hidden md:block truncate">{subtitle}</p>
            </div>
          </div>

          <div className="flex items-center gap-2 sm:gap-3 shrink-0">
            <NotificationBell />
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-white border border-sage-200 text-xs text-sage-700" title={ai === 'ready' ? 'AI service reachable' : ai === 'offline' ? 'AI service unreachable — analysis features may be unavailable' : 'Checking AI service'}>
              <Sparkles className="w-3.5 h-3.5 text-sage-600" aria-hidden="true" />
              <span className="hidden sm:inline">AI engine</span>
              <span className={`flex items-center gap-1 font-medium ${ai === 'ready' ? 'text-[#166534]' : ai === 'offline' ? 'text-[#991B1B]' : 'text-sage-500'}`}>
                <span className={`w-1.5 h-1.5 rounded-full ${ai === 'ready' ? 'bg-[#16A34A] landing-pulse-dot' : ai === 'offline' ? 'bg-[#DC2626]' : 'bg-sage-400 animate-pulse'}`} aria-hidden="true" />
                {ai === 'ready' ? 'Ready' : ai === 'offline' ? 'Offline' : 'Checking'}
              </span>
            </span>
          </div>
        </header>

        <main className="app-content flex-1 p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto">
          <PageTransition><Outlet /></PageTransition>
        </main>
      </div>
    </div>
  );
};
