import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { API_BASE_URL, getCookie } from './api';
import type { Notification } from '@/types/notification';

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo?: Echo<'reverb'>;
  }
}

if (typeof window !== 'undefined') {
  window.Pusher = Pusher;
}

export type ConnectionStatus = 'connected' | 'connecting' | 'disconnected' | 'unavailable';

let echoInstance: Echo<'reverb'> | null = null;
let currentSubscribedUserId: number | null = null;

/**
 * Creates or retrieves the singleton Laravel Echo instance configured for Reverb.
 */
export function getEcho(): Echo<'reverb'> | null {
  if (typeof window === 'undefined') return null;
  if (echoInstance) return echoInstance;

  const currentHost = window.location ? window.location.hostname : 'localhost';
  const reverbHost = import.meta.env.VITE_REVERB_HOST || currentHost;
  const reverbPort = Number(import.meta.env.VITE_REVERB_PORT || 8085);
  const reverbScheme = import.meta.env.VITE_REVERB_SCHEME || 'http';
  const reverbKey = import.meta.env.VITE_REVERB_APP_KEY || 'facultylens_key';

  interface ChannelAuthData {
    auth: string;
    channel_data?: string;
    shared_secret?: string;
  }

  try {
    echoInstance = new Echo({
      broadcaster: 'reverb',
      key: reverbKey,
      wsHost: reverbHost,
      wsPort: reverbPort,
      wssPort: reverbPort,
      forceTLS: reverbScheme === 'https',
      enabledTransports: ['ws', 'wss'],
      authorizer: (channel: { name: string }) => ({
        authorize: (
          socketId: string,
          callback: (error: Error | null, authData: ChannelAuthData | null) => void,
        ) => {
          fetch(`${API_BASE_URL}/broadcasting/auth`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              Accept: 'application/json',
              'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') || '',
            },
            credentials: 'include',
            body: JSON.stringify({
              socket_id: socketId,
              channel_name: channel.name,
            }),
          })
            .then((res) => {
              if (!res.ok) {
                throw new Error(`Broadcasting auth failed with status ${res.status}`);
              }
              return res.json();
            })
            .then((data: ChannelAuthData) => callback(null, data))
            .catch((err: unknown) => callback(err instanceof Error ? err : new Error(String(err)), null));
        },
      }),
    });

    window.Echo = echoInstance;
    return echoInstance;
  } catch (err) {
    console.warn('[Echo] Failed to initialize Echo client:', err);
    return null;
  }
}

function cleanChannel(echo: Echo<'reverb'>, chName: string): void {
  try {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const connector = (echo as any).connector;
    const pusher = connector?.pusher;
    const isSocketOpen = pusher?.connection?.state === 'connected';

    if (isSocketOpen) {
      try {
        echo.leave(chName);
      } catch {
        // Socket or channel already left
      }
    }

    const ch = connector?.channels?.[chName] || connector?.channels?.[`private-${chName}`];
    if (ch) {
      ch.subscribed = false;
      ch.unbind_all?.();
      if (connector?.channels) {
        delete connector.channels[chName];
        delete connector.channels[`private-${chName}`];
      }
    }
  } catch {
    // Ignore channel cleanup errors
  }
}

/**
 * Subscribes to the authenticated user's private notification channel.
 */
export function subscribeToUserNotifications(
  userId: number,
  onNotification: (notification: Notification) => void,
  onConnectionChange?: (status: ConnectionStatus) => void,
): () => void {
  const echo = getEcho();
  if (!echo || userId <= 0) return () => {};

  // If already subscribed to a different user, leave the old channel
  if (currentSubscribedUserId && currentSubscribedUserId !== userId) {
    cleanChannel(echo, `users.${currentSubscribedUserId}.notifications`);
  }
  currentSubscribedUserId = userId;

  const channelName = `users.${userId}.notifications`;
  const channel = echo.private(channelName);

  const handleEvent = (event: { notification?: Notification } & Partial<Notification>) => {
    const raw = event.notification || event;
    if (!raw || !raw.id) return;
    const notification: Notification = {
      id: String(raw.id),
      type: raw.type || 'SYSTEM_ALERT',
      category: raw.category || 'SYSTEM',
      severity: raw.severity || 'INFO',
      title: raw.title ?? null,
      message: raw.message ?? null,
      data: (raw.data as Record<string, unknown>) ?? {},
      action_url: raw.action_url ?? null,
      entity_type: raw.entity_type ?? null,
      entity_id: typeof raw.entity_id === 'number' ? raw.entity_id : null,
      read_at: raw.read_at ?? null,
      dismissed_at: raw.dismissed_at ?? null,
      expires_at: raw.expires_at ?? null,
      created_at: raw.created_at ?? new Date().toISOString(),
    };
    onNotification(notification);
  };

  channel.listen('.NotificationCreated', handleEvent);
  channel.listen('NotificationCreated', handleEvent);

  // Monitor connection states
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const pusher = (echo.connector as any)?.pusher;
  if (pusher && pusher.connection) {
    const handleConnected = () => onConnectionChange?.('connected');
    const handleConnecting = () => onConnectionChange?.('connecting');
    const handleDisconnected = () => onConnectionChange?.('disconnected');
    const handleUnavailable = () => onConnectionChange?.('unavailable');

    pusher.connection.bind('connected', handleConnected);
    pusher.connection.bind('connecting', handleConnecting);
    pusher.connection.bind('disconnected', handleDisconnected);
    pusher.connection.bind('unavailable', handleUnavailable);

    // Initial status
    if (pusher.connection.state) {
      onConnectionChange?.(pusher.connection.state as ConnectionStatus);
    }

    return () => {
      try {
        const isSocketOpen = pusher?.connection?.state === 'connected';
        if (isSocketOpen) {
          channel.stopListening('.NotificationCreated');
          channel.stopListening('NotificationCreated');
        }
        cleanChannel(echo, channelName);
      } catch {
        // Socket may already be closed/closing
      }

      try {
        pusher?.connection?.unbind('connected', handleConnected);
        pusher?.connection?.unbind('connecting', handleConnecting);
        pusher?.connection?.unbind('disconnected', handleDisconnected);
        pusher?.connection?.unbind('unavailable', handleUnavailable);
      } catch {
        // Ignore unbind errors
      }

      if (currentSubscribedUserId === userId) {
        currentSubscribedUserId = null;
      }
    };
  }

  return () => {
    try {
      cleanChannel(echo, channelName);
    } catch {
      // Ignore
    }
    if (currentSubscribedUserId === userId) {
      currentSubscribedUserId = null;
    }
  };
}

/**
 * Cleanly disconnects and tears down Echo on logout.
 */
export function disconnectRealtime(): void {
  if (!echoInstance) return;
  try {
    const connector = (echoInstance as any).connector;
    const pusher = connector?.pusher;
    if (connector?.channels) {
      Object.keys(connector.channels).forEach((key) => {
        try {
          const ch = connector.channels[key];
          if (ch) {
            ch.subscribed = false;
            ch.unbind_all?.();
          }
        } catch {
          // ignore
        }
      });
      connector.channels = {};
    }
    if (pusher && typeof pusher.disconnect === 'function') {
      pusher.disconnect();
    } else {
      echoInstance.disconnect();
    }
  } catch {
    // Teardown errors are non-critical during logout
  } finally {
    currentSubscribedUserId = null;
    echoInstance = null;
    if (typeof window !== 'undefined') {
      window.Echo = undefined;
    }
  }
}

