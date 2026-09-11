import React, { useEffect, useState } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import { cn } from '@/utils/cn';
import { useAuth } from '@/context/AuthContext';
import { BrandMark } from './Navbar';
import { ConfirmSignOutDialog } from '@/components/common/ConfirmSignOutDialog';
import {
  LayoutDashboard, BookOpen, FileCheck2, HelpCircle, BrainCircuit, History, MessageSquare, MessageSquareText, Sparkles, Gauge, BarChart3, Users, Settings, LogOut, X, User as UserIcon, ExternalLink, Leaf,
} from 'lucide-react';

export interface SidebarProps {
  mobileOpen?: boolean;
  onCloseMobile?: () => void;
}

interface NavItem { name: string; path: string; icon: React.ElementType; badge?: string; end?: boolean }

export const NAV_SECTIONS: { title: string; items: NavItem[] }[] = [
  { title: 'Overview', items: [
    { name: 'Dashboard', path: '/dashboard', icon: LayoutDashboard, end: true },
    { name: 'Analytics', path: '/analytics', icon: BarChart3 },
  ] },
  { title: 'Teaching', items: [
    { name: 'Courses', path: '/courses', icon: BookOpen },
    { name: 'Assessments', path: '/assessments', icon: FileCheck2 },
    { name: 'Question Bank', path: '/question-bank', icon: HelpCircle },
    { name: 'History', path: '/history', icon: History },
  ] },
  { title: 'AI tools', items: [
    { name: 'Analysis', path: '/analysis', icon: BrainCircuit, badge: 'AI' },
    { name: 'Question Generator', path: '/question-generator', icon: Sparkles, badge: 'AI' },
    { name: 'Document Chat', path: '/academic-chat', icon: MessageSquareText, badge: 'AI' },
    { name: 'AI Evaluation', path: '/ai-evaluation', icon: Gauge, badge: 'AI' },
  ] },
  { title: 'Workspace', items: [
    { name: 'Collaboration', path: '/collaboration/invitations', icon: Users },
    { name: 'Feedback', path: '/feedback', icon: MessageSquare },
    { name: 'Settings', path: '/settings', icon: Settings },
  ] },
];

/** Sage-gradient app sidebar: fixed on md+, slide-in drawer (ESC / backdrop to close, scroll-locked) on mobile. */
export const Sidebar: React.FC<SidebarProps> = ({ mobileOpen, onCloseMobile }) => {
  const navigate = useNavigate();
  const { user, logout } = useAuth();
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [signingOut, setSigningOut] = useState(false);

  useEffect(() => {
    if (!mobileOpen) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onCloseMobile?.(); };
    window.addEventListener('keydown', onKey);
    return () => { document.body.style.overflow = prev; window.removeEventListener('keydown', onKey); };
  }, [mobileOpen, onCloseMobile]);

  const handleLogout = async () => {
    setSigningOut(true);
    try {
      await logout();
    } finally {
      setSigningOut(false);
      setConfirmOpen(false);
    }
    onCloseMobile?.();
    navigate('/login', { replace: true });
  };

  const displayName = user?.name || user?.fullName || 'Faculty Member';
  const displayDesignation = user?.designation || user?.department || 'Faculty';
  const initials = displayName.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || 'F';

  const content = (
    <div className="relative flex flex-col h-full text-white overflow-hidden bg-gradient-to-b from-sage-500 via-sage-600 to-sage-700">
      <Leaf className="landing-float-slow absolute -right-16 -bottom-10 w-64 h-64 text-white/[0.07] pointer-events-none" aria-hidden="true" />
      <div className="absolute inset-x-0 top-0 h-40 bg-white/5 blur-2xl pointer-events-none" aria-hidden="true" />

      <div className="relative flex items-center justify-between h-16 px-5 border-b border-white/10">
        <NavLink to="/dashboard" onClick={onCloseMobile} aria-label="FacultyLens dashboard"><BrandMark light /></NavLink>
        {mobileOpen && (
          <button type="button" className="p-1.5 rounded-lg text-white/70 hover:text-white hover:bg-white/10 md:hidden" onClick={onCloseMobile} aria-label="Close sidebar"><X className="w-5 h-5" /></button>
        )}
      </div>

      <nav className="relative flex-1 py-4 px-3 overflow-y-auto space-y-5" aria-label="Main">
        {NAV_SECTIONS.map((section) => (
          <div key={section.title}>
            <p className="px-3 pb-1.5 text-[10px] uppercase tracking-[0.18em] text-sage-200/80">{section.title}</p>
            <ul className="space-y-0.5">
              {section.items.map((item) => (
                <li key={item.path}>
                  <NavLink to={item.path} end={item.end} onClick={onCloseMobile}
                    className={({ isActive }) => cn(
                      'group relative flex items-center justify-between gap-3 px-3 py-2 rounded-lg text-sm transition-all',
                      isActive ? 'bg-white text-sage-800 shadow-card' : 'text-white/80 hover:text-white hover:bg-white/10 hover:translate-x-0.5',
                    )}>
                    {({ isActive }) => (
                      <>
                        <span className="flex items-center gap-3 min-w-0">
                          <span className={cn('w-7 h-7 rounded-md flex items-center justify-center shrink-0 transition-colors', isActive ? 'bg-sage-100 text-sage-700' : 'bg-white/10 text-white group-hover:bg-white/15')}><item.icon className="w-4 h-4" strokeWidth={1.8} /></span>
                          <span className="truncate">{item.name}</span>
                        </span>
                        {item.badge && <span className={cn('text-[9px] tracking-wide px-1.5 py-0.5 rounded-md border', isActive ? 'border-sage-300 text-sage-600' : 'border-white/25 text-white/80')}>{item.badge}</span>}
                      </>
                    )}
                  </NavLink>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </nav>

      <div className="relative p-3 border-t border-white/10 space-y-1.5">
        <NavLink to="/settings" onClick={onCloseMobile} className="flex items-center gap-3 px-3 py-2 rounded-lg bg-white/10 hover:bg-white/15 transition-colors" aria-label="Open profile settings">
          <span className="w-8 h-8 rounded-full bg-white text-sage-700 flex items-center justify-center text-[11px] font-semibold shrink-0" aria-hidden="true">{initials || <UserIcon className="w-4 h-4" />}</span>
          <span className="min-w-0 flex-1">
            <span className="block text-xs font-semibold text-white truncate">{displayName}</span>
            <span className="block text-[10px] text-white/65 truncate">{displayDesignation}</span>
          </span>
        </NavLink>
        <div className="flex items-center gap-1.5">
          <NavLink to="/" onClick={onCloseMobile} className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-xs text-white/75 hover:text-white hover:bg-white/10 transition-colors"><ExternalLink className="w-3.5 h-3.5" />Site</NavLink>
          <button type="button" onClick={() => setConfirmOpen(true)} className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-xs text-white/75 hover:text-white hover:bg-white/10 transition-colors"><LogOut className="w-3.5 h-3.5" />Sign out</button>
        </div>
      </div>
    </div>
  );

  return (
    <>
      <ConfirmSignOutDialog open={confirmOpen} busy={signingOut} email={user?.email} onConfirm={handleLogout} onCancel={() => setConfirmOpen(false)} />
      <aside className="hidden md:flex md:w-64 md:flex-col md:fixed md:inset-y-0 z-30">{content}</aside>

      {mobileOpen && (
        <div className="fixed inset-0 z-50 md:hidden flex" role="dialog" aria-modal="true" aria-label="Navigation">
          <div className="fixed inset-0 bg-sage-800/60 backdrop-blur-[2px] page-enter" onClick={onCloseMobile} aria-hidden="true" />
          <div className="relative flex-1 flex flex-col max-w-xs w-full shadow-elevated sidebar-drawer">{content}</div>
        </div>
      )}
    </>
  );
};
