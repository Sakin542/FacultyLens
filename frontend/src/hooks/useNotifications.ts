import { useCallback, useContext, useEffect, useRef, useState } from 'react';
import { notificationService } from '@/services/notificationService';
import { ApiError } from '@/services/api';
import { NotificationContext } from '@/context/NotificationContext';
import type { Notification, NotificationFilter, NotificationPagination } from '@/types/notification';
import type { ConnectionStatus } from '@/services/echo';

/**
 * STEP 48: Real-time notification hook with centralized state synchronization.
 *
 * - When used within a NotificationProvider, shares reactive state (unreadCount, notifications, connected)
 *   across the Bell, Badge, Dropdown, Toast, and Notifications page.
 * - Live updates occur automatically via Laravel Echo (Reverb) over private user channels without page reload.
 * - When used outside NotificationProvider (e.g. isolated component unit tests), gracefully falls back to local
 *   state, polling, and window event synchronization.
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
  connected?: ConnectionStatus;
  refresh: () => Promise<void>;
  refreshUnreadCount: () => Promise<void>;
  markAsRead: (id: string) => Promise<void>;
  markAllAsRead: () => Promise<void>;
  dismiss: (id: string) => Promise<void>;
  remove: (id: string) => Promise<void>;
  addNotification?: (n: Notification) => void;
}

const errorMessage = (e: unknown) =>
  (e instanceof ApiError || e instanceof Error) && e.message ? e.message : 'Unable to load notifications.';

const broadcast = (unreadCount?: number) => {
  if (typeof window === 'undefined') return;
  window.dispatchEvent(new CustomEvent(NOTIFICATIONS_CHANGED_EVENT, { detail: { unreadCount } }));
};

export function useNotifications(options: UseNotificationsOptions = {}): UseNotificationsResult {
  const context = useContext(NotificationContext);

  const {
    filter = 'all',
    page = 1,
    perPage = 20,
    poll = true,
    pollList = false,
    pollIntervalMs,
    loadList = true,
    enabled = true,
  } = options;

  // Local fallback state (used if outside NotificationProvider, or for custom filtered/paginated lists)
  const [localNotifications, setLocalNotifications] = useState<Notification[]>([]);
  const [localUnreadCount, setLocalUnreadCount] = useState<number>(0);
  const [localMeta, setLocalMeta] = useState<NotificationPagination | null>(null);
  const [localLoading, setLocalLoading] = useState<boolean>(loadList);
  const [localError, setLocalError] = useState<string | null>(null);
  const [serverInterval, setServerInterval] = useState<number | null>(null);

  const alive = useRef(true);
  const listInFlight = useRef<{ key: string; promise: Promise<void> } | null>(null);
  const listSeq = useRef(0);
  const countInFlight = useRef<Promise<void> | null>(null);
  const suppressNextEvent = useRef(false);

  // Determine whether this call can directly use context notifications (default inbox page 1)
  const isDefaultInbox = filter === 'all' && page === 1;

  // Stable context callback references
  const contextRefresh = context?.refresh;
  const contextRefreshUnread = context?.refreshUnreadCount;
  const contextMarkAsRead = context?.markAsRead;
  const contextMarkAllAsRead = context?.markAllAsRead;
  const contextDismiss = context?.dismiss;
  const contextRemove = context?.remove;
  const contextAddNotification = context?.addNotification;

  const refresh = useCallback((): Promise<void> => {
    if (contextRefreshUnread && isDefaultInbox && !loadList) {
      return contextRefreshUnread();
    }
    if (contextRefresh && isDefaultInbox) {
      return contextRefresh({ page, perPage, filter });
    }

    const key = `${filter}|${page}|${perPage}`;
    if (listInFlight.current?.key === key) return listInFlight.current.promise;
    const seq = ++listSeq.current;
    const run = (async () => {
      setLocalLoading(true);
      try {
        const res = await notificationService.getNotifications({ page, perPage, filter });
        if (!alive.current || seq !== listSeq.current) return;
        setLocalNotifications(res.data ?? []);
        setLocalMeta(res.meta ?? null);
        if (typeof res.meta?.unread_count === 'number') setLocalUnreadCount(res.meta.unread_count);
        if (typeof res.meta?.poll_interval_seconds === 'number') setServerInterval(res.meta.poll_interval_seconds * 1000);
        setLocalError(null);
      } catch (e) {
        if (alive.current && seq === listSeq.current) setLocalError(errorMessage(e));
      } finally {
        if (alive.current && seq === listSeq.current) setLocalLoading(false);
        if (listInFlight.current?.key === key) listInFlight.current = null;
      }
    })();
    listInFlight.current = { key, promise: run };
    return run;
  }, [contextRefresh, contextRefreshUnread, isDefaultInbox, loadList, filter, page, perPage]);

  const refreshUnreadCount = useCallback((): Promise<void> => {
    if (contextRefreshUnread) {
      return contextRefreshUnread();
    }
    if (countInFlight.current) return countInFlight.current;
    const run = (async () => {
      try {
        const res = await notificationService.getUnreadCount();
        if (!alive.current) return;
        setLocalUnreadCount(res.data?.unread_count ?? 0);
        if (typeof res.data?.poll_interval_seconds === 'number') setServerInterval(res.data.poll_interval_seconds * 1000);
        if (!loadList) setLocalError(null);
      } catch (e) {
        if (alive.current && !loadList) setLocalError(errorMessage(e));
      } finally {
        countInFlight.current = null;
      }
    })();
    countInFlight.current = run;
    return run;
  }, [contextRefreshUnread, loadList]);

  const applyMeta = useCallback((metaUnread?: number) => {
    if (typeof metaUnread === 'number') setLocalUnreadCount(metaUnread);
    suppressNextEvent.current = true;
    broadcast(metaUnread);
  }, []);

  const markAsRead = useCallback(
    async (id: string) => {
      if (contextMarkAsRead) {
        await contextMarkAsRead(id);
        if (!isDefaultInbox) {
          setLocalNotifications((list) =>
            list.map((n) => (n.id === id && !n.read_at ? { ...n, read_at: new Date().toISOString() } : n)),
          );
        }
        return;
      }
      const now = new Date().toISOString();
      setLocalNotifications((list) => list.map((n) => (n.id === id && !n.read_at ? { ...n, read_at: now } : n)));
      try {
        const res = await notificationService.markAsRead(id);
        applyMeta(res.meta?.unread_count);
      } catch (e) {
        setLocalError(errorMessage(e));
        void refresh();
        throw e;
      }
    },
    [contextMarkAsRead, isDefaultInbox, applyMeta, refresh],
  );

  const markAllAsRead = useCallback(async () => {
    if (contextMarkAllAsRead) {
      await contextMarkAllAsRead();
      if (!isDefaultInbox) {
        const now = new Date().toISOString();
        setLocalNotifications((list) => list.map((n) => (n.read_at ? n : { ...n, read_at: now })));
      }
      return;
    }
    const now = new Date().toISOString();
    setLocalNotifications((list) => list.map((n) => (n.read_at ? n : { ...n, read_at: now })));
    try {
      const res = await notificationService.markAllAsRead();
      applyMeta(res.meta?.unread_count ?? 0);
    } catch (e) {
      setLocalError(errorMessage(e));
      void refresh();
      throw e;
    }
  }, [contextMarkAllAsRead, isDefaultInbox, applyMeta, refresh]);

  const dismiss = useCallback(
    async (id: string) => {
      if (contextDismiss) {
        await contextDismiss(id);
        if (!isDefaultInbox) {
          setLocalNotifications((list) => list.filter((n) => n.id !== id));
        }
        return;
      }
      setLocalNotifications((list) => list.filter((n) => n.id !== id));
      try {
        const res = await notificationService.dismissNotification(id);
        applyMeta(res.meta?.unread_count);
      } catch (e) {
        setLocalError(errorMessage(e));
        void refresh();
        throw e;
      }
    },
    [contextDismiss, isDefaultInbox, applyMeta, refresh],
  );

  const remove = useCallback(
    async (id: string) => {
      if (contextRemove) {
        await contextRemove(id);
        if (!isDefaultInbox) {
          setLocalNotifications((list) => list.filter((n) => n.id !== id));
        }
        return;
      }
      setLocalNotifications((list) => list.filter((n) => n.id !== id));
      try {
        const res = await notificationService.deleteNotification(id);
        applyMeta(res.meta?.unread_count);
      } catch (e) {
        setLocalError(errorMessage(e));
        void refresh();
        throw e;
      }
    },
    [contextRemove, isDefaultInbox, applyMeta, refresh],
  );

  const addNotification = useCallback(
    (n: Notification) => {
      if (contextAddNotification) {
        contextAddNotification(n);
        return;
      }
      setLocalNotifications((prev) => {
        if (prev.some((item) => item.id === n.id)) return prev;
        if (!n.read_at) setLocalUnreadCount((c) => c + 1);
        return [n, ...prev];
      });
      broadcast();
    },
    [contextAddNotification],
  );

  // Initial load
  useEffect(() => {
    alive.current = true;
    if (!enabled) return () => { alive.current = false; };
    if (Boolean(context) && !loadList) {
      // In centralized context mode, NotificationProvider already owns and fetches unreadCount on mount
      return () => { alive.current = false; };
    }
    if (loadList) {
      void refresh();
    } else {
      void refreshUnreadCount();
    }
    return () => { alive.current = false; };
  }, [enabled, Boolean(context), loadList, refresh, refreshUnreadCount]);

  // Cross-instance window sync (for standalone mode)
  useEffect(() => {
    if (!enabled || typeof window === 'undefined' || context) return;
    const onChange = (e: Event) => {
      if (suppressNextEvent.current) {
        suppressNextEvent.current = false;
        return;
      }
      const detail = (e as CustomEvent<{ unreadCount?: number }>).detail;
      const hasCount = typeof detail?.unreadCount === 'number';
      if (hasCount) setLocalUnreadCount(detail.unreadCount as number);
      if (loadList) void refresh();
      else if (!hasCount) void refreshUnreadCount();
    };
    window.addEventListener(NOTIFICATIONS_CHANGED_EVENT, onChange);
    return () => window.removeEventListener(NOTIFICATIONS_CHANGED_EVENT, onChange);
  }, [enabled, context, loadList, refresh, refreshUnreadCount]);

  // Polling fallback when realtime is disconnected or when standalone
  useEffect(() => {
    if (!enabled || !poll || typeof window === 'undefined') return;
    // When connected via Reverb, polling is paused to reduce server load
    if (context?.connected === 'connected') return;

    const interval = Math.max(MIN_POLL_MS, pollIntervalMs ?? serverInterval ?? DEFAULT_POLL_MS);
    const tick = () => {
      if (typeof document !== 'undefined' && document.hidden) return;
      void refreshUnreadCount();
      if (pollList) void refresh();
    };
    const timer = window.setInterval(tick, interval);
    const onVisibility = () => {
      if (typeof document !== 'undefined' && !document.hidden) tick();
    };
    document.addEventListener('visibilitychange', onVisibility);
    return () => {
      window.clearInterval(timer);
      document.removeEventListener('visibilitychange', onVisibility);
    };
  }, [enabled, poll, pollList, pollIntervalMs, serverInterval, context?.connected, refresh, refreshUnreadCount]);

  if (context && isDefaultInbox) {
    return {
      notifications: context.notifications,
      unreadCount: context.unreadCount,
      meta: context.meta,
      loading: context.loading,
      error: context.error,
      connected: context.connected,
      refresh,
      refreshUnreadCount,
      markAsRead,
      markAllAsRead,
      dismiss,
      remove,
      addNotification,
    };
  }

  return {
    notifications: localNotifications,
    unreadCount: context ? context.unreadCount : localUnreadCount,
    meta: localMeta,
    loading: localLoading,
    error: localError,
    connected: context?.connected ?? 'disconnected',
    refresh,
    refreshUnreadCount,
    markAsRead,
    markAllAsRead,
    dismiss,
    remove,
    addNotification,
  };
}
