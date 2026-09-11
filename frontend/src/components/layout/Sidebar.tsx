import React from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import { cn } from '@/utils/cn';
import { useAuth } from '@/context/AuthContext';
import { Logo } from '@/components/common/Logo';
import {
  LayoutDashboard,
  BookOpen,
  FileCheck2,
  HelpCircle,
  BrainCircuit,
  History,
  MessageSquare,
  MessageSquareText,
  Sparkles,
  Gauge,
  BarChart3,
  Users,
  Settings,
  LogOut,
  X,
  User as UserIcon,
} from 'lucide-react';

export interface SidebarProps {
  mobileOpen?: boolean;
  onCloseMobile?: () => void;
}

export const Sidebar: React.FC<SidebarProps> = ({ mobileOpen, onCloseMobile }) => {
  const navigate = useNavigate();
  const { user, logout } = useAuth();

  const navItems = [
    { name: 'Dashboard', path: '/dashboard', icon: LayoutDashboard },
    { name: 'Courses', path: '/courses', icon: BookOpen },
    { name: 'Assessments', path: '/assessments', icon: FileCheck2 },
    { name: 'Question Bank', path: '/question-bank', icon: HelpCircle },
    { name: 'Analysis', path: '/analysis', icon: BrainCircuit, badge: 'AI' },
    { name: 'Analytics', path: '/analytics', icon: BarChart3 },
    { name: 'Document Chat', path: '/academic-chat', icon: MessageSquareText, badge: 'AI' },
    { name: 'Question Generator', path: '/question-generator', icon: Sparkles, badge: 'AI' },
    { name: 'AI Evaluation', path: '/ai-evaluation', icon: Gauge, badge: 'AI' },
    { name: 'Collaboration', path: '/collaboration/invitations', icon: Users },
    { name: 'History', path: '/history', icon: History },
    { name: 'Feedback', path: '/feedback', icon: MessageSquare },
    { name: 'Settings', path: '/settings', icon: Settings },
  ];

  const handleLogout = async () => {
    await logout();
    if (onCloseMobile) onCloseMobile();
    navigate('/login', { replace: true });
  };

  const displayName = user?.name || user?.fullName || 'Faculty Member';
  const displayDesignation = user?.designation || user?.department || 'Faculty';

  const content = (
    <div className="flex flex-col h-full bg-[#111111] text-white border-r border-[#262626]">
      {/* Brand Header */}
      <div className="flex items-center justify-between h-16 px-5 border-b border-[#262626]">
        <NavLink
          to="/dashboard"
          className="flex items-center gap-2 group"
          onClick={onCloseMobile}
        >
          <Logo size="md" variant="light" />
        </NavLink>

        {mobileOpen && (
          <button
            type="button"
            className="p-1.5 rounded-lg text-[#A3A3A3] hover:text-white hover:bg-[#262626] md:hidden"
            onClick={onCloseMobile}
            aria-label="Close sidebar"
          >
            <X className="w-5 h-5" />
          </button>
        )}
      </div>

      {/* Navigation items */}
      <div className="flex-1 py-6 px-3 space-y-1.5 overflow-y-auto">
        <div className="px-3 pb-2 text-[10px] font-mono uppercase tracking-widest text-[#737373] font-bold">
          Academic Suite
        </div>
        {navItems.map((item) => {
          const Icon = item.icon;
          return (
            <NavLink
              key={item.path}
              to={item.path}
              onClick={onCloseMobile}
              className={({ isActive }) =>
                cn(
                  'flex items-center justify-between px-3.5 py-2.5 rounded-lg text-sm font-semibold tracking-tight transition-all duration-150',
                  isActive
                    ? 'bg-[#262626] text-white shadow-subtle border-l-2 border-white'
                    : 'text-[#A3A3A3] hover:text-white hover:bg-[#1E1E1E]'
                )
              }
            >
              <div className="flex items-center gap-3">
                <Icon className="w-4 h-4 shrink-0" />
                <span>{item.name}</span>
              </div>
              {item.badge && (
                <span className="text-[10px] font-mono px-2 py-0.5 rounded-md bg-white/10 text-white font-semibold border border-white/20">
                  {item.badge}
                </span>
              )}
            </NavLink>
          );
        })}
      </div>

      {/* User Profile & Logout Bottom Section */}
      <div className="p-3.5 border-t border-[#262626] space-y-2 bg-[#0C0C0C]">
        <div className="flex items-center gap-3 px-3 py-2 rounded-lg bg-[#181818] border border-[#262626]">
          <div className="w-8 h-8 rounded-full bg-[#262626] flex items-center justify-center text-white shrink-0 border border-[#3E3E3E]">
            <UserIcon className="w-4 h-4 text-[#A3A3A3]" />
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-xs font-bold text-white truncate tracking-tight">
              {displayName}
            </p>
            <p className="text-[10px] text-[#A3A3A3] truncate font-medium">
              {displayDesignation}
            </p>
          </div>
        </div>

        <button
          type="button"
          onClick={handleLogout}
          className="w-full flex items-center gap-3 px-3.5 py-2 rounded-lg text-xs font-semibold tracking-wide text-[#A3A3A3] hover:text-[#DC2626] hover:bg-[#262626] transition-colors"
        >
          <LogOut className="w-4 h-4 shrink-0" />
          <span>Sign Out</span>
        </button>
      </div>
    </div>
  );

  return (
    <>
      {/* Desktop Persistent Sidebar */}
      <aside className="hidden md:flex md:w-64 md:flex-col md:fixed md:inset-y-0 z-30">
        {content}
      </aside>

      {/* Mobile Drawer Overlay */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 md:hidden flex">
          <div
            className="fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity"
            onClick={onCloseMobile}
            aria-hidden="true"
          />
          <div className="relative flex-1 flex flex-col max-w-xs w-full">
            {content}
          </div>
        </div>
      )}
    </>
  );
};
