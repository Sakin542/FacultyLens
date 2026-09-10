import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Activity, Bell, Users } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { collaborationService } from '@/services/collaborationService';
import { ActivityItem, AppNotification, CollaborationSummary } from '@/types/collaboration';
import { CollaborationEmptyState, CollaborationError, CollaborationLoading, CollaboratorRoleBadge, getCollaborationErrorMessage } from './CollaborationStates';
import { cn } from '@/utils/cn';

/** STEP 34: activity timeline, dashboard widget, notification bell. */

function dayLabel(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso); const today = new Date();
  const sameDay = d.toDateString() === today.toDateString();
  const yesterday = new Date(today); yesterday.setDate(today.getDate() - 1);
  return sameDay ? 'Today' : d.toDateString() === yesterday.toDateString() ? 'Yesterday' : d.toLocaleDateString();
}

export const CollaborationActivity: React.FC<{ courseId: number | string; perPage?: number }> = ({ courseId, perPage = 20 }) => {
  const [items, setItems] = useState<ActivityItem[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = async (p: number, append = false) => {
    setLoading(true); setError(null);
    try {
      const res = await collaborationService.getCollaborationActivity(courseId, p, perPage);
      setItems((prev) => (append ? [...prev, ...res.data] : res.data));
      setPage(res.meta?.current_page ?? p); setLastPage(res.meta?.last_page ?? 1);
    } catch (e) { setError(getCollaborationErrorMessage(e)); } finally { setLoading(false); }
  };
  useEffect(() => { void load(1); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [courseId]);

  const grouped = items.reduce<Record<string, ActivityItem[]>>((acc, it) => { const k = dayLabel(it.created_at); (acc[k] ||= []).push(it); return acc; }, {});

  return (
    <section data-testid="collaboration-activity" className="rounded-xl border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] p-4 space-y-3">
      <h3 className="text-sm font-semibold text-[#111111] dark:text-white flex items-center gap-2"><Activity className="w-4 h-4" /> Activity</h3>
      {error && <CollaborationError message={error} onRetry={() => load(1)} />}
      {loading && items.length === 0 ? <CollaborationLoading label="Loading activity…" /> : items.length === 0 ? (
        <CollaborationEmptyState title="No collaboration activity yet" description="Invitations, comments, reviews and approvals will appear here." icon={<Activity className="w-6 h-6" />} className="py-6" />
      ) : (
        <div className="space-y-4">
          {Object.entries(grouped).map(([day, list]) => (
            <div key={day}>
              <p className="text-[11px] uppercase tracking-wide text-[#A3A3A3] mb-1">{day}</p>
              <ul className="space-y-1.5">
                {list.map((it) => (
                  <li key={it.id} data-testid={`activity-${it.id}`} className="text-sm text-[#111111] dark:text-white flex items-start gap-2">
                    <span className="mt-1.5 w-1.5 h-1.5 rounded-full bg-[#111111] dark:bg-white flex-shrink-0" />
                    <span className="flex-1">{it.summary}<span className="ml-2 text-[11px] text-[#A3A3A3]">{it.created_at ? new Date(it.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''}</span></span>
                  </li>
                ))}
              </ul>
            </div>
          ))}
          {page < lastPage && <Button size="sm" variant="outline" onClick={() => load(page + 1, true)} isLoading={loading} data-testid="activity-load-more">Load more</Button>}
        </div>
      )}
    </section>
  );
};

export const CollaborationSummaryCard: React.FC<{ className?: string }> = ({ className }) => {
  const [summary, setSummary] = useState<CollaborationSummary | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => { collaborationService.getSummary().then((r) => setSummary(r.data)).catch((e) => setError(getCollaborationErrorMessage(e))); }, []);
  return (
    <section data-testid="collaboration-summary" className={cn('rounded-xl border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] p-5 space-y-3', className)}>
      <div className="flex items-center justify-between"><h3 className="text-sm font-semibold text-[#111111] dark:text-white flex items-center gap-2"><Users className="w-4 h-4" /> Collaboration</h3><Link to="/collaboration/invitations" className="text-xs underline text-[#737373]">Invitations</Link></div>
      {error && <CollaborationError message={error} />}
      {!summary && !error ? <CollaborationLoading /> : summary && (
        <>
          <dl className="grid grid-cols-3 gap-2 text-center">
            <div className="rounded-lg bg-[#F7F7F5] dark:bg-[#1F1F1F] p-2"><dt className="text-[11px] text-[#737373]">Pending invitations</dt><dd className="text-lg font-bold text-[#111111] dark:text-white" data-testid="pending-invitations-count">{summary.pending_invitations_count}</dd></div>
            <div className="rounded-lg bg-[#F7F7F5] dark:bg-[#1F1F1F] p-2"><dt className="text-[11px] text-[#737373]">Courses shared with you</dt><dd className="text-lg font-bold text-[#111111] dark:text-white">{summary.shared_courses_count}</dd></div>
            <div className="rounded-lg bg-[#F7F7F5] dark:bg-[#1F1F1F] p-2"><dt className="text-[11px] text-[#737373]">Unresolved discussions</dt><dd className="text-lg font-bold text-[#111111] dark:text-white">{summary.unresolved_discussions_count}</dd></div>
          </dl>
          {summary.shared_courses.length > 0 && (
            <ul className="text-sm divide-y divide-[#F0F0F0] dark:divide-[#1F1F1F]">
              {summary.shared_courses.slice(0, 5).map((c) => (
                <li key={c.id} className="flex items-center justify-between py-1.5"><Link to={`/courses/${c.id}`} className="text-[#111111] dark:text-white hover:underline">{c.course_code} — {c.course_name}</Link><CollaboratorRoleBadge role={c.role} /></li>
              ))}
            </ul>
          )}
        </>
      )}
    </section>
  );
};

export const NotificationBell: React.FC = () => {
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<AppNotification[]>([]);
  const [unread, setUnread] = useState(0);
  const load = () => collaborationService.getNotifications().then((r) => { setItems(r.data); setUnread(r.meta?.unread_count ?? r.data.filter((n) => !n.read_at).length); }).catch(() => undefined);
  useEffect(() => { void load(); const t = window.setInterval(load, 60000); return () => window.clearInterval(t); }, []);
  return (
    <div className="relative" data-testid="notification-bell">
      <button type="button" aria-label="Notifications" onClick={() => setOpen((o) => !o)} className="relative p-2 rounded-full bg-white border border-[#E5E5E5] text-[#111111] hover:bg-[#F7F7F5]">
        <Bell className="w-4 h-4" />
        {unread > 0 && <span data-testid="unread-count" className="absolute -top-1 -right-1 min-w-[18px] h-[18px] rounded-full bg-[#111111] text-white text-[10px] font-bold flex items-center justify-center px-1">{unread > 9 ? '9+' : unread}</span>}
      </button>
      {open && (
        <div className="absolute right-0 mt-2 w-80 max-h-96 overflow-y-auto rounded-xl border border-[#E5E5E5] bg-white shadow-lg z-30 text-sm" role="menu">
          <div className="flex items-center justify-between px-3 py-2 border-b border-[#E5E5E5]"><span className="font-semibold text-[#111111]">Notifications</span>{unread > 0 && <button type="button" className="text-xs underline text-[#737373]" onClick={() => collaborationService.markAllNotificationsRead().then(load)}>Mark all read</button>}</div>
          {items.length === 0 ? <p className="px-3 py-6 text-xs text-[#737373] text-center">You're all caught up.</p> : items.slice(0, 20).map((n) => (
            <button key={n.id} type="button" onClick={() => { if (!n.read_at) void collaborationService.markNotificationRead(n.id).then(load); if (n.url) window.location.assign(n.url); }}
              className={cn('w-full text-left px-3 py-2 border-b border-[#F0F0F0] hover:bg-[#FAFAFA]', !n.read_at && 'bg-[#F7F7F5]')}>
              <span className="block font-medium text-[#111111] truncate">{n.title ?? n.event}</span>
              <span className="block text-xs text-[#737373] line-clamp-2">{n.body}</span>
              {!n.read_at && <Badge variant="Pending" className="mt-1">New</Badge>}
            </button>
          ))}
        </div>
      )}
    </div>
  );
};
