import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Bell, CheckCheck, ChevronLeft, ChevronRight, RefreshCw, Settings2 } from 'lucide-react';
import { cn } from '@/utils/cn';
import { Button } from '@/components/common/Button';
import { useNotifications } from '@/hooks/useNotifications';
import { NotificationFilters } from '@/components/notifications/NotificationFilters';
import { NotificationList } from '@/components/notifications/NotificationList';
import { NotificationPreferences } from '@/components/notifications/NotificationPreferences';
import type { Notification, NotificationFilter } from '@/types/notification';
import { NOTIFICATION_CATEGORIES } from '@/types/notification';

const PER_PAGE = 20;

const isFilter = (v: string | null): v is NotificationFilter => v === 'all' || v === 'unread' || (NOTIFICATION_CATEGORIES as string[]).includes(v ?? '');

/**
 * STEP 47: /notifications — the notification center (inbox + preferences). Filters and pagination live in the URL
 * and are applied server-side; every action is confirmed by the backend response.
 */
export const Notifications: React.FC = () => {
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const view = params.get('view') === 'preferences' ? 'preferences' : 'inbox';
  const filter: NotificationFilter = isFilter(params.get('filter')) ? (params.get('filter') as NotificationFilter) : 'all';
  const page = Math.max(1, Number(params.get('page') || 1));

  const { notifications, unreadCount, meta, loading, error, refresh, markAsRead, markAllAsRead, dismiss, remove } = useNotifications({ filter, page, perPage: PER_PAGE, poll: true, pollList: true, enabled: view === 'inbox' });
  const [notice, setNotice] = useState<string | null>(null);

  const update = useCallback((patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    for (const [k, v] of Object.entries(patch)) { if (v === null || v === '' || (k === 'page' && v === '1') || (k === 'filter' && v === 'all')) next.delete(k); else next.set(k, v); }
    setParams(next, { replace: true });
  }, [params, setParams]);

  useEffect(() => {
    if (meta && page > meta.last_page && meta.last_page >= 1) update({ page: String(meta.last_page) });
  }, [meta, page, update]);

  const onOpen = async (n: Notification, path: string | null) => {
    if (!n.read_at) { try { await markAsRead(n.id); } catch { return; } }
    if (path) navigate(path);
  };

  const safe = (fn: () => Promise<void>, ok: string) => async () => {
    try { await fn(); setNotice(ok); window.setTimeout(() => setNotice(null), 3000); } catch { /* error is shown by the list */ }
  };

  const pagination = useMemo(() => {
    if (!meta || meta.last_page <= 1) return null;
    return (
      <nav aria-label="Notification pages" className="flex items-center justify-between gap-3 px-4 py-3 border-t border-sage-200 text-xs text-sage-500">
        <span>Page {meta.current_page} of {meta.last_page} · {meta.total} total</span>
        <span className="flex items-center gap-1">
          <button type="button" disabled={meta.current_page <= 1} onClick={() => update({ page: String(meta.current_page - 1) })} aria-label="Previous page" className="inline-flex items-center gap-1 rounded-md border border-sage-200 bg-white px-2 py-1 hover:bg-sage-100 disabled:opacity-40 focus-visible:outline-2 focus-visible:outline-sage-700"><ChevronLeft className="w-3.5 h-3.5" aria-hidden="true" /> Prev</button>
          <button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => update({ page: String(meta.current_page + 1) })} aria-label="Next page" className="inline-flex items-center gap-1 rounded-md border border-sage-200 bg-white px-2 py-1 hover:bg-sage-100 disabled:opacity-40 focus-visible:outline-2 focus-visible:outline-sage-700">Next <ChevronRight className="w-3.5 h-3.5" aria-hidden="true" /></button>
        </span>
      </nav>
    );
  }, [meta, update]);

  return (
    <div className="space-y-5 page-enter" data-testid="notifications-page">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex items-start gap-3">
          <span className="w-10 h-10 rounded-xl bg-white border border-sage-200 flex items-center justify-center text-sage-800"><Bell className="w-5 h-5" aria-hidden="true" strokeWidth={1.8} /></span>
          <div>
            <h2 className="text-xl font-semibold text-sage-800 tracking-tight">Notification center</h2>
            {(view !== 'inbox' || unreadCount > 0) && (
              <p className="text-sm text-sage-500">
                {view === 'inbox' ? `${unreadCount} unread ${unreadCount === 1 ? 'notification' : 'notifications'}.` : 'Choose what FacultyLens tells you about.'}
              </p>
            )}
          </div>
        </div>
        <div role="tablist" aria-label="Notification center sections" className="inline-flex rounded-lg border border-sage-200 bg-white p-0.5">
          {(['inbox', 'preferences'] as const).map((v) => (
            <button key={v} type="button" role="tab" aria-selected={view === v} data-testid={`view-${v}`} onClick={() => update({ view: v === 'inbox' ? null : v })}
              className={cn('inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium focus-visible:outline-2 focus-visible:outline-sage-700', view === v ? 'bg-sage-700 text-white' : 'text-sage-800 hover:bg-sage-100')}>
              {v === 'inbox' ? <Bell className="w-3.5 h-3.5" aria-hidden="true" /> : <Settings2 className="w-3.5 h-3.5" aria-hidden="true" />}
              {v === 'inbox' ? 'Inbox' : 'Preferences'}
            </button>
          ))}
        </div>
      </div>

      {view === 'preferences' ? (
        <NotificationPreferences />
      ) : (
        <section className="rounded-xl border border-sage-200 bg-white overflow-hidden" aria-label="Notification inbox">
          <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-sage-200 bg-sage-100">
            <NotificationFilters value={filter} unreadCount={unreadCount} onChange={(f) => update({ filter: f, page: null })} />
            <div className="flex items-center gap-1.5">
              <Button type="button" variant="ghost" size="sm" onClick={() => { void refresh(); }} leftIcon={<RefreshCw className={cn('w-3.5 h-3.5', loading && 'animate-spin')} />} aria-label="Refresh notifications">Refresh</Button>
              <Button type="button" variant="outline" size="sm" disabled={unreadCount === 0} onClick={safe(markAllAsRead, 'All notifications marked as read.')} leftIcon={<CheckCheck className="w-3.5 h-3.5" />} data-testid="mark-all-read">Mark all as read</Button>
            </div>
          </div>

          {notice && <p role="status" className="px-4 py-2 text-xs text-[#166534] bg-[#F0FDF4] border-b border-[#BBF7D0]">{notice}</p>}

          <NotificationList
            notifications={notifications}
            loading={loading}
            error={error}
            onRetry={() => { void refresh(); }}
            onOpen={onOpen}
            onMarkRead={(id) => { void safe(() => markAsRead(id), 'Marked as read.')(); }}
            onDismiss={(id) => { void safe(() => dismiss(id), 'Notification dismissed.')(); }}
            onDelete={(id) => { void safe(() => remove(id), 'Notification deleted.')(); }}
            emptyTitle={filter === 'unread' ? "You're all caught up." : filter === 'all' ? "You're all caught up." : 'No notifications in this category.'}
            emptyDescription={filter === 'all' || filter === 'unread' ? 'Important FacultyLens activity will appear here.' : 'Switch to All to see everything.'}
          />
          {pagination}
        </section>
      )}
    </div>
  );
};
