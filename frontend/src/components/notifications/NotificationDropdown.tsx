import React, { useEffect, useRef } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { CheckCheck, Settings2 } from 'lucide-react';
import { cn } from '@/utils/cn';
import type { Notification } from '@/types/notification';
import { useNotifications } from '@/hooks/useNotifications';
import { NotificationList } from './NotificationList';

export interface NotificationDropdownProps {
  open: boolean;
  onClose: () => void;
  /** Element that toggles the panel (used to return focus and ignore outside-clicks on it). */
  anchorRef: React.RefObject<HTMLElement | null>;
  id?: string;
  limit?: number;
}

/**
 * STEP 47: recent notifications panel under the bell. Loads only while open (10 most recent), marks an item read
 * on click and navigates to its in-app action path. Escape / outside click / Tab-out close it.
 */
export const NotificationDropdown: React.FC<NotificationDropdownProps> = ({ open, onClose, anchorRef, id = 'notification-dropdown', limit = 10 }) => {
  const navigate = useNavigate();
  const panelRef = useRef<HTMLDivElement>(null);
  const { notifications, unreadCount, loading, error, refresh, markAsRead, markAllAsRead, dismiss } = useNotifications({ perPage: limit, poll: false, enabled: open });

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') { e.stopPropagation(); onClose(); anchorRef.current?.focus(); } };
    const onPointer = (e: MouseEvent) => {
      const target = e.target as Node;
      if (panelRef.current?.contains(target) || anchorRef.current?.contains(target)) return;
      onClose();
    };
    document.addEventListener('keydown', onKey);
    document.addEventListener('mousedown', onPointer);
    return () => { document.removeEventListener('keydown', onKey); document.removeEventListener('mousedown', onPointer); };
  }, [open, onClose, anchorRef]);

  useEffect(() => {
    if (open) {
      const first = panelRef.current?.querySelector<HTMLElement>('button, a');
      first?.focus();
    }
  }, [open, loading]);

  if (!open) return null;

  const onOpen = async (n: Notification, path: string | null) => {
    if (!n.read_at) {
      try { await markAsRead(n.id); } catch { /* surfaced via hook error */ }
    }
    onClose();
    if (path) navigate(path);
  };

  return (
    <div
      ref={panelRef}
      id={id}
      role="dialog"
      aria-label="Notifications"
      data-testid="notification-dropdown"
      // < sm: fixed full-width sheet under the 4rem header (an absolute panel anchored to the bell would overflow the
      // left edge on narrow phones); ≥ sm: classic anchored dropdown.
      className={cn(
        'fixed inset-x-3 top-[calc(4rem+0.5rem)] w-auto rounded-xl border border-sage-200 bg-white shadow-lg z-30 overflow-hidden text-sm',
        'sm:absolute sm:inset-x-auto sm:top-auto sm:right-0 sm:mt-2 sm:w-[380px]',
      )}
      onBlur={(e) => { if (!panelRef.current?.contains(e.relatedTarget as Node) && e.relatedTarget !== anchorRef.current) onClose(); }}
    >
      <div className="flex items-center justify-between gap-2 px-3 py-2.5 border-b border-sage-200 bg-sage-100">
        <h2 className="text-sm font-semibold text-sage-800 min-w-0 truncate">
          Notifications
          {unreadCount > 0 && <span className="ml-1.5 text-xs font-normal text-sage-500">({unreadCount} unread)</span>}
        </h2>
        <div className="flex items-center gap-1 shrink-0">
          {unreadCount > 0 && (
            <button type="button" onClick={() => { void markAllAsRead().catch(() => undefined); }} className="inline-flex items-center gap-1 rounded-md px-2 py-1.5 text-xs font-medium text-sage-700 hover:bg-white focus-visible:outline-2 focus-visible:outline-sage-700" aria-label="Mark all read" data-testid="dropdown-mark-all-read">
              <CheckCheck className="w-4 h-4 sm:w-3.5 sm:h-3.5" aria-hidden="true" /><span className="hidden sm:inline">Mark all read</span>
            </button>
          )}
          <Link to="/notifications?view=preferences" onClick={onClose} className="p-2 sm:p-1.5 rounded-md text-sage-500 hover:text-sage-800 hover:bg-white focus-visible:outline-2 focus-visible:outline-sage-700" aria-label="Notification preferences">
            <Settings2 className="w-4 h-4" aria-hidden="true" />
          </Link>
        </div>
      </div>

      <div className="max-h-[calc(100dvh-4rem-0.5rem-7.5rem)] sm:max-h-[min(60vh,420px)] overflow-y-auto overscroll-contain">
        <NotificationList notifications={notifications} loading={loading} error={error} onRetry={() => { void refresh(); }} onOpen={onOpen} onDismiss={(id) => { void dismiss(id).catch(() => undefined); }} compact aria-label="Recent notifications" />
      </div>

      <div className="border-t border-sage-200 bg-white px-3 py-2 text-center">
        <Link to="/notifications" onClick={onClose} className="text-xs font-medium text-sage-700 hover:underline focus-visible:outline-2 focus-visible:outline-sage-700 rounded" data-testid="dropdown-view-all">
          View all notifications
        </Link>
      </div>
    </div>
  );
};
