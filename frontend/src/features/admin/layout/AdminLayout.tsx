import React, { Suspense, useCallback, useEffect, useRef, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { Bell, ChevronRight, Leaf, LogOut, Menu, Search, X } from 'lucide-react';
import { cn } from '@/utils/cn';
import { useAuth } from '@/context/AuthContext';
import { notificationService } from '@/services/notificationService';
import type { Notification } from '@/types/notification';
import { BrandMark } from '@/components/layout/Navbar';
import { ConfirmSignOutDialog } from '@/components/common/ConfirmSignOutDialog';
import { ProfilePicture } from '@/components/profile/ProfilePicture';
import { PageTransition } from '@/components/common/PageTransition';
import { ADMIN_NAV, navItemFor } from '../nav';
import { CommandPalette, rememberDestination } from './CommandPalette';
import { timeAgo } from '../components/ui';

/** Breadcrumb trail from the URL: Admin › Group › Page › detail. */
const useBreadcrumbs = () => {
  const { pathname } = useLocation();
  const item = navItemFor(pathname);
  const group = ADMIN_NAV.find((g) => g.items.some((i) => i.key === item?.key));
  const crumbs: { label: string; to?: string }[] = [{ label: 'Admin', to: '/admin' }];
  if (item && item.path !== '/admin') {
    if (group) crumbs.push({ label: group.label });
    crumbs.push({ label: item.label, to: item.path });
    const rest = pathname.slice(item.path.length).split('/').filter(Boolean);
    if (rest.length) crumbs.push({ label: /^\d+$/.test(rest[0]) ? `#${rest[0]}` : rest[0] });
  } else {
    crumbs.push({ label: 'Dashboard' });
  }
  return { crumbs, item };
};

/** Admin notifications (alerts are delivered through the existing notification system). Opens admin pages only. */
const NotificationBell: React.FC = () => {
  const [open, setOpen] = useState(false);
  const [count, setCount] = useState<number | null>(null);
  const [items, setItems] = useState<Notification[] | null>(null);
  const ref = useRef<HTMLDivElement>(null);
  const navigate = useNavigate();

  const refresh = useCallback(() => {
    notificationService.getUnreadCount().then((r) => setCount(r.data.unread_count)).catch(() => setCount(null));
  }, []);

  useEffect(() => {
    refresh();
    const t = setInterval(refresh, 60_000);
    return () => clearInterval(t);
  }, [refresh]);

  useEffect(() => {
    if (!open) return;
    notificationService.getNotifications({ perPage: 6 }).then((r) => setItems(r.data)).catch(() => setItems([]));
    const close = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false); };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, [open]);

  const openItem = async (n: Notification) => {
    if (!n.read_at) await notificationService.markAsRead(n.id).catch(() => undefined);
    setOpen(false);
    refresh();
    navigate(n.action_url?.startsWith('/admin') ? n.action_url : '/admin/alerts');
  };

  return (
    <div className="relative" ref={ref}>
      <button type="button" onClick={() => setOpen((o) => !o)} aria-expanded={open} aria-label={`Administrative notifications${count ? `, ${count} unread` : ''}`}
        className="relative flex h-9 w-9 items-center justify-center rounded-lg text-sage-700 transition-colors hover:bg-sage-200/70 focus-visible:outline-2 focus-visible:outline-sage-700" data-testid="admin-bell">
        <Bell className="h-[18px] w-[18px]" aria-hidden="true" />
        {count ? <span className="absolute -right-0.5 -top-0.5 min-w-[18px] rounded-full bg-accent px-1 text-center text-[10px] font-semibold leading-[18px] text-white">{count > 99 ? '99+' : count}</span> : null}
      </button>
      {open && (
        <div className="absolute right-0 z-40 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-sage-200 bg-white shadow-elevated">
          <div className="flex items-center justify-between border-b border-sage-200 px-4 py-2.5">
            <p className="text-sm font-semibold text-sage-800">Notifications</p>
            <button type="button" className="text-xs text-sage-700 hover:underline" onClick={() => { void notificationService.markAllAsRead().then(refresh); setItems((xs) => xs?.map((x) => ({ ...x, read_at: x.read_at ?? new Date().toISOString() })) ?? null); }}>Mark all read</button>
          </div>
          <ul className="max-h-80 divide-y divide-sage-100 overflow-y-auto">
            {items === null && <li className="px-4 py-6 text-center text-xs text-sage-500">Loading…</li>}
            {items?.length === 0 && <li className="px-4 py-6 text-center text-xs text-sage-500">No notifications.</li>}
            {items?.map((n) => (
              <li key={n.id}>
                <button type="button" onClick={() => { void openItem(n); }} className="flex w-full gap-2 px-4 py-2.5 text-left hover:bg-sage-50">
                  <span className={cn('mt-1.5 h-2 w-2 shrink-0 rounded-full', n.read_at ? 'bg-transparent' : 'bg-sage-600')} aria-hidden="true" />
                  <span className="min-w-0">
                    <span className="block truncate text-sm text-sage-800">{n.title ?? 'Notification'}</span>
                    <span className="block line-clamp-2 text-xs text-sage-500">{n.message}</span>
                    <span className="block text-[11px] text-sage-500">{timeAgo(n.created_at)}{n.read_at ? '' : ' · unread'}</span>
                  </span>
                </button>
              </li>
            ))}
          </ul>
          <Link to="/admin/alerts" onClick={() => setOpen(false)} className="block border-t border-sage-200 bg-sage-50 px-4 py-2 text-center text-xs font-medium text-sage-700 hover:underline">Open alerts</Link>
        </div>
      )}
    </div>
  );
};

/** Admin sidebar: the same sage-gradient design as the faculty sidebar, listing only admin sections. */
const Sidebar: React.FC<{ onNavigate?: () => void; onClose?: () => void }> = ({ onNavigate, onClose }) => {
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const { user, logout } = useAuth();
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [signingOut, setSigningOut] = useState(false);
  const activeKey = navItemFor(pathname)?.key;
  const displayName = user?.name || user?.fullName || 'Administrator';

  const signOut = async () => {
    setSigningOut(true);
    try {
      await logout();
      navigate('/admin/login', { replace: true });
    } finally {
      setSigningOut(false);
      setConfirmOpen(false);
    }
  };

  return (
    <div className="relative flex h-full flex-col overflow-hidden bg-gradient-to-b from-sage-500 via-sage-600 to-sage-700 text-white">
      <Leaf className="landing-float-slow pointer-events-none absolute -bottom-10 -right-16 h-64 w-64 text-white/[0.07]" aria-hidden="true" />
      <div className="pointer-events-none absolute inset-x-0 top-0 h-40 bg-white/5 blur-2xl" aria-hidden="true" />

      <div className="relative flex h-16 items-center justify-between border-b border-white/10 px-5">
        <Link to="/admin" onClick={onNavigate} aria-label="Admin dashboard"><BrandMark light subtitle="Administration" /></Link>
        {onClose && (
          <button type="button" className="rounded-lg p-1.5 text-white/70 hover:bg-white/10 hover:text-white" onClick={onClose} aria-label="Close navigation"><X className="h-5 w-5" /></button>
        )}
      </div>

      <nav aria-label="Admin sections" className="scrollbar-subtle relative flex-1 space-y-5 overflow-y-auto px-3 py-4">
        {ADMIN_NAV.map((group) => (
          <div key={group.key}>
            <p className="px-3 pb-1.5 text-[10px] uppercase tracking-[0.18em] text-sage-200/80">{group.label}</p>
            <ul className="space-y-0.5">
              {group.items.map((item) => {
                const active = activeKey === item.key;
                return (
                  <li key={item.key}>
                    <NavLink to={item.path} end={item.path === '/admin'} aria-current={active ? 'page' : undefined}
                      onClick={() => { rememberDestination({ group: 'Pages', title: item.label, subtitle: item.description, url: item.path }); onNavigate?.(); }}
                      className={cn('group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-all',
                        active ? 'bg-white text-sage-800 shadow-card' : 'text-white/80 hover:translate-x-0.5 hover:bg-white/10 hover:text-white')}>
                      <span className={cn('flex h-7 w-7 shrink-0 items-center justify-center rounded-md transition-colors', active ? 'bg-sage-100 text-sage-700' : 'bg-white/10 text-white group-hover:bg-white/15')}>
                        <item.icon className="h-4 w-4" strokeWidth={1.8} aria-hidden="true" />
                      </span>
                      <span className="truncate">{item.label}</span>
                    </NavLink>
                  </li>
                );
              })}
            </ul>
          </div>
        ))}
      </nav>

      <div className="relative space-y-1.5 border-t border-white/10 p-3">
        <Link to="/admin/account" onClick={onNavigate} className="flex items-center gap-3 rounded-lg bg-white/10 px-3 py-2 transition-colors hover:bg-white/15" aria-label="Open my account settings">
          <ProfilePicture src={user?.profile_picture_url} name={displayName} size="sm" tone="inverse" decorative className="ring-2 ring-white/30" />
          <span className="min-w-0 flex-1">
            <span className="block truncate text-xs font-semibold text-white">{displayName}</span>
            <span className="block truncate text-[10px] text-white/65">Administrator</span>
          </span>
        </Link>
        <button type="button" onClick={() => setConfirmOpen(true)} className="inline-flex w-full items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-xs text-white/75 transition-colors hover:bg-white/10 hover:text-white">
          <LogOut className="h-3.5 w-3.5" aria-hidden="true" />Sign out
        </button>
      </div>
      <ConfirmSignOutDialog open={confirmOpen} busy={signingOut} email={user?.email} onConfirm={() => { void signOut(); }} onCancel={() => setConfirmOpen(false)} />
    </div>
  );
};

/**
 * The admin shell. Same visual design as the faculty DashboardLayout (sage sidebar, sage-50 page, serif title),
 * but the sidebar only lists administration sections — no faculty teaching or AI tools.
 */
export const AdminLayout: React.FC = () => {
  const [mobileOpen, setMobileOpen] = useState(false);
  const [paletteOpen, setPaletteOpen] = useState(false);
  const { crumbs, item } = useBreadcrumbs();
  const { pathname } = useLocation();
  const { user } = useAuth();
  const displayName = user?.name || user?.fullName || 'Administrator';
  const title = item?.label ?? 'Dashboard';

  useEffect(() => { setMobileOpen(false); }, [pathname]);
  useEffect(() => { document.title = `${title} · FacultyLens Admin`; }, [title]);

  useEffect(() => {
    if (!mobileOpen) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setMobileOpen(false); };
    window.addEventListener('keydown', onKey);
    return () => { document.body.style.overflow = prev; window.removeEventListener('keydown', onKey); };
  }, [mobileOpen]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setPaletteOpen(true); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  return (
    <div className="flex min-h-screen bg-sage-50 text-sage-800" data-testid="admin-layout">
      <a href="#admin-main" className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[70] focus:rounded-lg focus:bg-sage-800 focus:px-3 focus:py-2 focus:text-sm focus:text-white">Skip to content</a>

      <aside className="z-30 hidden md:fixed md:inset-y-0 md:flex md:w-64 md:flex-col"><Sidebar /></aside>
      {mobileOpen && (
        <div className="fixed inset-0 z-50 flex md:hidden" role="dialog" aria-modal="true" aria-label="Admin navigation">
          <div className="page-enter fixed inset-0 bg-sage-800/60 backdrop-blur-[2px]" onClick={() => setMobileOpen(false)} aria-hidden="true" />
          <div className="sidebar-drawer relative flex w-full max-w-xs flex-1 flex-col shadow-elevated"><Sidebar onNavigate={() => setMobileOpen(false)} onClose={() => setMobileOpen(false)} /></div>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col md:pl-64">
        <header className="sticky top-0 z-20 flex h-16 items-center justify-between gap-3 border-b border-sage-200/70 bg-sage-50/90 px-4 backdrop-blur-md sm:px-6 lg:px-8">
          <div className="flex min-w-0 items-center gap-3">
            <button type="button" className="-ml-2 rounded-lg p-2 text-sage-800 hover:bg-sage-200/70 md:hidden" onClick={() => setMobileOpen(true)} aria-label="Open navigation" aria-expanded={mobileOpen}>
              <Menu className="h-5 w-5" />
            </button>
            <div className="min-w-0">
              <p className="truncate font-serif text-lg leading-tight tracking-tight text-sage-800 sm:text-xl" aria-hidden="true">{title}</p>
              <nav aria-label="Breadcrumb" className="hidden md:block">
                <ol className="flex min-w-0 items-center gap-1 text-[11px] text-sage-500">
                  {crumbs.map((c, i) => (
                    <li key={`${c.label}-${i}`} className="flex min-w-0 items-center gap-1">
                      {i > 0 && <ChevronRight className="h-3 w-3 shrink-0 text-sage-300" aria-hidden="true" />}
                      {c.to && i < crumbs.length - 1
                        ? <Link to={c.to} className="truncate hover:text-sage-800">{c.label}</Link>
                        : <span className="truncate" aria-current={i === crumbs.length - 1 ? 'page' : undefined}>{c.label}</span>}
                    </li>
                  ))}
                </ol>
              </nav>
            </div>
          </div>

          <div className="flex shrink-0 items-center gap-2 sm:gap-3">
            <button type="button" onClick={() => setPaletteOpen(true)} data-testid="admin-search"
              className="flex h-9 items-center gap-2 rounded-lg border border-sage-200 bg-white px-2.5 text-sm text-sage-500 transition-colors hover:border-sage-300 hover:text-sage-800 focus-visible:outline-2 focus-visible:outline-sage-700 lg:w-56">
              <Search className="h-4 w-4" aria-hidden="true" />
              <span className="hidden flex-1 text-left lg:block">Search admin…</span>
              <kbd className="hidden rounded border border-sage-200 px-1 text-[10px] lg:block">Ctrl K</kbd>
              <span className="sr-only lg:hidden">Search</span>
            </button>
            <NotificationBell />
            <Link to="/admin/account" className="flex items-center gap-2 rounded-full py-1 pl-1 pr-2 transition-colors hover:bg-sage-200/70 focus-visible:outline-2 focus-visible:outline-sage-700" aria-label={`Account settings for ${displayName}`}>
              <ProfilePicture src={user?.profile_picture_url} name={displayName} size="sm" tone="dark" decorative />
              <span className="hidden max-w-[10rem] truncate text-xs font-medium text-sage-800 sm:block">{displayName}</span>
            </Link>
          </div>
        </header>

        <main id="admin-main" className="app-content mx-auto w-full max-w-7xl flex-1 p-4 sm:p-6 lg:p-8">
          <Suspense fallback={<div role="status" aria-label="Loading" className="py-20 text-center text-sm text-sage-500">Loading…</div>}>
            <PageTransition><Outlet /></PageTransition>
          </Suspense>
        </main>
      </div>

      <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
    </div>
  );
};
