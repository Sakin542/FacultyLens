import React, { createContext, useContext, useEffect, useState, useCallback, useRef, useMemo } from 'react';
import { useAuth } from './AuthContext';
import { notificationService } from '@/services/notificationService';
import { subscribeToUserNotifications, disconnectRealtime, ConnectionStatus } from '@/services/echo';
import { ApiError } from '@/services/api';
import type { Notification, NotificationPagination } from '@/types/notification';

export const NOTIFICATIONS_CHANGED_EVENT = 'facultylens:notifications-changed';

export interface NotificationContextType {
  notifications: Notification[];
  unreadCount: number;
  loading: boolean;
  error: string | null;
  connected: ConnectionStatus;
  toasts: Notification[];
  meta: NotificationPagination | null;
  addNotification: (notification: Notification) => void;
  markAsRead: (id: string) => Promise<void>;
  markAllAsRead: () => Promise<void>;
  dismiss: (id: string) => Promise<void>;
  remove: (id: string) => Promise<void>;
  refresh: (params?: { page?: number; perPage?: number; filter?: string }) => Promise<void>;
  refreshUnreadCount: () => Promise<void>;
  dismissToast: (id: string) => void;
}

export const NotificationContext = createContext<NotificationContextType | undefined>(undefined);

const errorMessage = (e: unknown) =>
  (e instanceof ApiError || e instanceof Error) && e.message ? e.message : 'Unable to load notifications.';

export const NotificationProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user, isAuthenticated } = useAuth();
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [unreadCount, setUnreadCount] = useState<number>(0);
  const [loading, setLoading] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [connected, setConnected] = useState<ConnectionStatus>('disconnected');
  const [toasts, setToasts] = useState<Notification[]>([]);
  const [meta, setMeta] = useState<NotificationPagination | null>(null);

  const channelRef = useRef<BroadcastChannel | null>(null);
  const isMounted = useRef<boolean>(true);
  const unreadInFlight = useRef<Promise<void> | null>(null);

  // Broadcast multi-tab update
  const postTabMessage = useCallback((msg: { type: string; payload?: unknown }) => {
    try {
      channelRef.current?.postMessage(msg);
    } catch {
      // BroadcastChannel not available or failed
    }
  }, []);

  const refreshUnreadCount = useCallback(async (): Promise<void> => {
    if (!isAuthenticated) return;
    if (unreadInFlight.current) return unreadInFlight.current;

    const promise = (async () => {
      try {
        const res = await notificationService.getUnreadCount();
        if (!isMounted.current) return;
        if (typeof res.data?.unread_count === 'number') {
          setUnreadCount(res.data.unread_count);
        }
        setError(null);
      } catch (e) {
        if (isMounted.current) {
          setError(errorMessage(e));
        }
      } finally {
        unreadInFlight.current = null;
      }
    })();

    unreadInFlight.current = promise;
    return promise;
  }, [isAuthenticated]);

  const refresh = useCallback(
    async (params: { page?: number; perPage?: number; filter?: string } = {}): Promise<void> => {
      if (!isAuthenticated) return;
      setLoading(true);
      try {
        const res = await notificationService.getNotifications({
          page: params.page ?? 1,
          perPage: params.perPage ?? 20,
          filter: (params.filter as any) ?? 'all',
        });
        if (!isMounted.current) return;
        setNotifications(res.data ?? []);
        setMeta(res.meta ?? null);
        if (typeof res.meta?.unread_count === 'number') {
          setUnreadCount(res.meta.unread_count);
        }
        setError(null);
      } catch (e) {
        if (isMounted.current) {
          setError(errorMessage(e));
        }
      } finally {
        if (isMounted.current) {
          setLoading(false);
        }
      }
    },
    [isAuthenticated],
  );

  const addNotification = useCallback(
    (n: Notification) => {
      setNotifications((prev) => {
        // Strict deduplication by ID
        if (prev.some((item) => item.id === n.id)) {
          return prev;
        }

        // Increment unread count if notification is unread
        if (!n.read_at) {
          setUnreadCount((c) => c + 1);
          // Add toast for unread notifications
          setToasts((t) => [n, ...t.slice(0, 4)]);
        }

        return [n, ...prev];
      });

      // Dispatch window event and multi-tab message
      if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent(NOTIFICATIONS_CHANGED_EVENT, { detail: { notification: n } }));
      }
      postTabMessage({ type: 'ADD_NOTIFICATION', payload: n });
    },
    [postTabMessage],
  );

  const markAsRead = useCallback(
    async (id: string) => {
      const now = new Date().toISOString();

      setUnreadCount((c) => Math.max(0, c - 1));
      setNotifications((prev) =>
        prev.map((item) => (item.id === id && !item.read_at ? { ...item, read_at: now } : item)),
      );

      setToasts((prev) => prev.filter((t) => t.id !== id));
      postTabMessage({ type: 'MARK_READ', payload: { id, read_at: now } });

      try {
        const res = await notificationService.markAsRead(id);
        if (typeof res.meta?.unread_count === 'number') {
          setUnreadCount(res.meta.unread_count);
        }
      } catch (e) {
        setError(errorMessage(e));
        void refresh();
        throw e;
      }
    },
    [postTabMessage, refresh],
  );

  const markAllAsRead = useCallback(async () => {
    const now = new Date().toISOString();
    setNotifications((prev) => prev.map((item) => (item.read_at ? item : { ...item, read_at: now })));
    setUnreadCount(0);
    setToasts([]);

    postTabMessage({ type: 'MARK_ALL_READ', payload: { read_at: now } });

    try {
      const res = await notificationService.markAllAsRead();
      if (typeof res.meta?.unread_count === 'number') {
        setUnreadCount(res.meta.unread_count);
      }
    } catch (e) {
      setError(errorMessage(e));
      void refresh();
      throw e;
    }
  }, [postTabMessage, refresh]);

  const dismiss = useCallback(
    async (id: string) => {
      setNotifications((prev) => {
        const target = prev.find((item) => item.id === id);
        if (target && !target.read_at) {
          setUnreadCount((c) => Math.max(0, c - 1));
        }
        return prev.filter((item) => item.id !== id);
      });
      setToasts((prev) => prev.filter((t) => t.id !== id));

      postTabMessage({ type: 'DISMISS', payload: { id } });

      try {
        const res = await notificationService.dismissNotification(id);
        if (typeof res.meta?.unread_count === 'number') {
          setUnreadCount(res.meta.unread_count);
        }
      } catch (e) {
        setError(errorMessage(e));
        void refresh();
        throw e;
      }
    },
    [postTabMessage, refresh],
  );

  const remove = useCallback(
    async (id: string) => {
      setNotifications((prev) => {
        const target = prev.find((item) => item.id === id);
        if (target && !target.read_at) {
          setUnreadCount((c) => Math.max(0, c - 1));
        }
        return prev.filter((item) => item.id !== id);
      });
      setToasts((prev) => prev.filter((t) => t.id !== id));

      postTabMessage({ type: 'REMOVE', payload: { id } });

      try {
        const res = await notificationService.deleteNotification(id);
        if (typeof res.meta?.unread_count === 'number') {
          setUnreadCount(res.meta.unread_count);
        }
      } catch (e) {
        setError(errorMessage(e));
        void refresh();
        throw e;
      }
    },
    [postTabMessage, refresh],
  );

  const dismissToast = useCallback((id: string) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  // Multi-tab BroadcastChannel listener
  useEffect(() => {
    if (typeof window === 'undefined' || typeof BroadcastChannel === 'undefined') return;
    const channel = new BroadcastChannel('facultylens:notifications');
    channelRef.current = channel;

    channel.onmessage = (event) => {
      const { type, payload } = event.data || {};
      if (type === 'ADD_NOTIFICATION' && payload?.id) {
        const n = payload as Notification;
        setNotifications((prev) => {
          if (prev.some((item) => item.id === n.id)) return prev;
          if (!n.read_at) setUnreadCount((c) => c + 1);
          return [n, ...prev];
        });
      } else if (type === 'MARK_READ' && payload?.id) {
        const { id, read_at } = payload;
        setNotifications((prev) =>
          prev.map((item) => {
            if (item.id === id && !item.read_at) {
              setUnreadCount((c) => Math.max(0, c - 1));
              return { ...item, read_at };
            }
            return item;
          }),
        );
        setToasts((prev) => prev.filter((t) => t.id !== id));
      } else if (type === 'MARK_ALL_READ') {
        const { read_at } = payload || { read_at: new Date().toISOString() };
        setNotifications((prev) => prev.map((item) => ({ ...item, read_at: item.read_at || read_at })));
        setUnreadCount(0);
        setToasts([]);
      } else if (type === 'DISMISS' || type === 'REMOVE') {
        const { id } = payload || {};
        if (id) {
          setNotifications((prev) => {
            const target = prev.find((item) => item.id === id);
            if (target && !target.read_at) setUnreadCount((c) => Math.max(0, c - 1));
            return prev.filter((item) => item.id !== id);
          });
          setToasts((prev) => prev.filter((t) => t.id !== id));
        }
      }
    };

    return () => {
      channel.close();
      channelRef.current = null;
    };
  }, []);

  // WebSocket Subscription Lifecycle
  useEffect(() => {
    isMounted.current = true;
    if (!isAuthenticated || !user?.id) {
      disconnectRealtime();
      setConnected('disconnected');
      setNotifications([]);
      setUnreadCount(0);
      setToasts([]);
      return () => {
        isMounted.current = false;
      };
    }

    // Fetch initial unread count on login/mount
    void refreshUnreadCount();

    // Subscribe to authenticated user's private channel
    const unsubscribe = subscribeToUserNotifications(
      Number(user.id),
      (notification) => {
        if (!isMounted.current) return;
        addNotification(notification);
      },
      (status) => {
        if (!isMounted.current) return;
        setConnected(status);
        // On reconnection after disconnect, synchronize with backend
        if (status === 'connected') {
          void refreshUnreadCount();
        }
      },
    );

    return () => {
      isMounted.current = false;
      unsubscribe();
    };
  }, [isAuthenticated, user?.id, addNotification, refreshUnreadCount]);

  const contextValue = useMemo<NotificationContextType>(
    () => ({
      notifications,
      unreadCount,
      loading,
      error,
      connected,
      toasts,
      meta,
      addNotification,
      markAsRead,
      markAllAsRead,
      dismiss,
      remove,
      refresh,
      refreshUnreadCount,
      dismissToast,
    }),
    [
      notifications,
      unreadCount,
      loading,
      error,
      connected,
      toasts,
      meta,
      addNotification,
      markAsRead,
      markAllAsRead,
      dismiss,
      remove,
      refresh,
      refreshUnreadCount,
      dismissToast,
    ],
  );

  return (
    <NotificationContext.Provider value={contextValue}>
      {children}
    </NotificationContext.Provider>
  );
};

export const useNotificationContext = (): NotificationContextType => {
  const context = useContext(NotificationContext);
  if (!context) {
    throw new Error('useNotificationContext must be used within a NotificationProvider');
  }
  return context;
};

