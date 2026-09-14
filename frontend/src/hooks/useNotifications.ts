import { useCallback, useEffect, useRef, useState } from 'react';
import { notificationService } from '@/services/notificationService';
import { ApiError } from '@/services/api';
import type { Notification, NotificationFilter, NotificationPagination } from '@/types/notification';

/**
 * STEP 47: notification state for the bell, dropdown and the notification center.
 *
 * - The backend is the source of truth: unread count always comes from /notifications/unread-count (or the
 *   `meta.unread_count` returned by list / mutation calls), never from counting client-side rows.
 * - Broadcasting is not configured, so we poll at the server-suggested interval (default 45 s), pause while the
 *   tab is hidden, and refresh immediately when it becomes visible again.
 * - In-flight de-duplication: a refresh requested while one is running is coalesced.
 * - Every hook instance listens to a window event so a mutation in the dropdown updates the page and vice versa.
 */

export const NOTIFICATIONS_CHANGED_EVENT = 'facultylens:notifications-changed';
export const DEFAULT_POLL_MS = 45_000;
const MIN_POLL_MS = 30_000;

export interface UseNotificationsOptions {
  filter?: NotificationFilter;
  page?: number;
  perPage?: number;
  /** Poll the unread count (and list when `pollList`) while mounted. Default true. */
  poll?: boolean;
  /** Also re-fetch the list on each poll tick (dropdown/page). Default false: only the unread count is polled. */
  pollList?: boolean;
  pollIntervalMs?: number;
  /** Fetch the list on mount. Default true. Set false for the bell, which only needs the unread count. */
  loadList?: boolean;
  /** When false the hook is dormant (no fetch, no polling, no event listener). Default true. */
  enabled?: boolean;
}

export interface UseNotificationsResult {
  notifications: Notification[];
  unreadCount: number;
  meta: NotificationPagination | null;
  loading: boolean;
  error: string | null;
  refresh: () => Promise<void>;
  refreshUnreadCount: () => Promise<void>;
  markAsRead: (id: string) => Promise<void>;
  markAllAsRead: () => Promise<void>;
  dismiss: (id: string) => Promise<void>;
  remove: (id: string) => Promise<void>;
}

const errorMessage = (e: unknown) => (e instanceof ApiError || e instanceof Error) && e.message ? e.message : 'Unable to load notifications.';

const broadcast = (unreadCount?: number) => {
  if (typeof window === 'undefined') return;
  window.dispatchEvent(new CustomEvent(NOTIFICATIONS_CHANGED_EVENT, { detail: { unreadCount } }));
};

export function useNotifications(options: UseNotificationsOptions = {}): UseNotificationsResult {
  const { filter = 'all', page = 1, perPage = 20, poll = true, pollList = false, pollIntervalMs, loadList = true, enabled = true } = options;

  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [meta, setMeta] = useState<NotificationPagination | null>(null);
  const [loading, setLoading] = useState<boolean>(loadList);
  const [error, setError] = useState<string | null>(null);
  const [serverInterval, setServerInterval] = useState<number | null>(null);

  const alive = useRef(true);
  const listInFlight = useRef<{ key: string; promise: Promise<void> } | null>(null);
  const listSeq = useRef(0);
  const countInFlight = useRef<Promise<void> | null>(null);
  // Skip the self-emitted change event (we already applied the result locally).
  const suppressNextEvent = useRef(false);

  const refresh = useCallback((): Promise<void> => {
    const key = `${filter}|${page}|${perPage}`;
    // Coalesce only identical requests; a changed filter/page must always fetch, and stale responses are dropped.
    if (listInFlight.current?.key === key) return listInFlight.current.promise;
    const seq = ++listSeq.current;
    const run = (async () => {
      setLoading(true);
      try {
        const res = await notificationService.getNotifications({ page, perPage, filter });
        if (!alive.current || seq !== listSeq.current) return;
        setNotifications(res.data ?? []);
        setMeta(res.meta ?? null);
        if (typeof res.meta?.unread_count === 'number') setUnreadCount(res.meta.unread_count);
        if (typeof res.meta?.poll_interval_seconds === 'number') setServerInterval(res.meta.poll_interval_seconds * 1000);
        setError(null);
      } catch (e) {
        if (alive.current && seq === listSeq.current) setError(errorMessage(e));
      } finally {
        if (alive.current && seq === listSeq.current) setLoading(false);
        if (listInFlight.current?.key === key) listInFlight.current = null;
      }
    })();
    listInFlight.current = { key, promise: run };
    return run;
  }, [filter, page, perPage]);

  const refreshUnreadCount = useCallback((): Promise<void> => {
    if (countInFlight.current) return countInFlight.current;
    const run = (async () => {
      try {
        const res = await notificationService.getUnreadCount();
        if (!alive.current) return;
        setUnreadCount(res.data?.unread_count ?? 0);
        if (typeof res.data?.poll_interval_seconds === 'number') setServerInterval(res.data.poll_interval_seconds * 1000);
        if (!loadList) setError(null);
      } catch (e) {
        // A failed poll must not clear a previously good count; surface the error only when there is no list to show it.
        if (alive.current && !loadList) setError(errorMessage(e));
      } finally {
        countInFlight.current = null;
      }
    })();
    countInFlight.current = run;
    return run;
  }, [loadList]);

  const applyMeta = useCallback((metaUnread?: number) => {
    if (typeof metaUnread === 'number') setUnreadCount(metaUnread);
    suppressNextEvent.current = true;
    broadcast(metaUnread);
  }, []);

  const markAsRead = useCallback(async (id: string) => {
    const now = new Date().toISOString();
    setNotifications((list) => list.map((n) => (n.id === id && !n.read_at ? { ...n, read_at: now } : n)));
    try {
      const res = await notificationService.markAsRead(id);
      applyMeta(res.meta?.unread_count);
    } catch (e) {
      setError(errorMessage(e));
      void refresh();
      throw e;
    }
  }, [applyMeta, refresh]);

  const markAllAsRead = useCallback(async () => {
    const now = new Date().toISOString();
    setNotifications((list) => list.map((n) => (n.read_at ? n : { ...n, read_at: now })));
    try {
      const res = await notificationService.markAllAsRead();
      applyMeta(res.meta?.unread_count ?? 0);
    } catch (e) {
      setError(errorMessage(e));
      void refresh();
      throw e;
    }
  }, [applyMeta, refresh]);

  const dismiss = useCallback(async (id: string) => {
    setNotifications((list) => list.filter((n) => n.id !== id));
    try {
      const res = await notificationService.dismissNotification(id);
      applyMeta(res.meta?.unread_count);
    } catch (e) {
      setError(errorMessage(e));
      void refresh();
      throw e;
    }
  }, [applyMeta, refresh]);

  const remove = useCallback(async (id: string) => {
    setNotifications((list) => list.filter((n) => n.id !== id));
    try {
      const res = await notificationService.deleteNotification(id);
      applyMeta(res.meta?.unread_count);
    } catch (e) {
      setError(errorMessage(e));
      void refresh();
      throw e;
    }
  }, [applyMeta, refresh]);

  // Initial load
  useEffect(() => {
    alive.current = true;
    if (!enabled) return () => { alive.current = false; };
    if (loadList) void refresh(); else void refreshUnreadCount();
    return () => { alive.current = false; };
  }, [refresh, refreshUnreadCount, loadList, enabled]);

  // Cross-instance sync
  useEffect(() => {
    if (!enabled || typeof window === 'undefined') return;
    const onChange = (e: Event) => {
      if (suppressNextEvent.current) { suppressNextEvent.current = false; return; }
      const detail = (e as CustomEvent<{ unreadCount?: number }>).detail;
      const hasCount = typeof detail?.unreadCount === 'number';
      if (hasCount) setUnreadCount(detail.unreadCount as number);
      // The count in the event came from the server response; only lists need a re-fetch.
      if (loadList) void refresh(); else if (!hasCount) void refreshUnreadCount();
    };
    window.addEventListener(NOTIFICATIONS_CHANGED_EVENT, onChange);
    return () => window.removeEventListener(NOTIFICATIONS_CHANGED_EVENT, onChange);
  }, [enabled, loadList, refresh, refreshUnreadCount]);

  // Polling (paused while hidden; immediate refresh on return)
  useEffect(() => {
    if (!enabled || !poll || typeof window === 'undefined') return;
    const interval = Math.max(MIN_POLL_MS, pollIntervalMs ?? serverInterval ?? DEFAULT_POLL_MS);
    const tick = () => {
      if (typeof document !== 'undefined' && document.hidden) return;
      void refreshUnreadCount();
      if (pollList) void refresh();
    };
    const timer = window.setInterval(tick, interval);
    const onVisibility = () => { if (typeof document !== 'undefined' && !document.hidden) tick(); };
    document.addEventListener('visibilitychange', onVisibility);
    return () => {
      window.clearInterval(timer);
      document.removeEventListener('visibilitychange', onVisibility);
    };
  }, [enabled, poll, pollList, pollIntervalMs, serverInterval, refresh, refreshUnreadCount]);

  return { notifications, unreadCount, meta, loading, error, refresh, refreshUnreadCount, markAsRead, markAllAsRead, dismiss, remove };
}
