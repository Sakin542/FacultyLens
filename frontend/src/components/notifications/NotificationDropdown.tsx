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
      className={cn('absolute right-0 mt-2 w-[min(92vw,380px)] rounded-xl border border-[#E7E2D8] bg-white shadow-lg z-30 overflow-hidden text-sm')}
      onBlur={(e) => { if (!panelRef.current?.contains(e.relatedTarget as Node) && e.relatedTarget !== anchorRef.current) onClose(); }}
    >
      <div className="flex items-center justify-between gap-2 px-3 py-2.5 border-b border-[#E7E2D8] bg-[#F7F4EE]">
        <h2 className="text-sm font-semibold text-[#171717]">
          Notifications
          {unreadCount > 0 && <span className="ml-1.5 text-xs font-normal text-[#6B6B63]">({unreadCount} unread)</span>}
        </h2>
        <div className="flex items-center gap-1">
          {unreadCount > 0 && (
            <button type="button" onClick={() => { void markAllAsRead().catch(() => undefined); }} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-[#1E6F5C] hover:bg-white focus-visible:outline-2 focus-visible:outline-[#1E6F5C]" data-testid="dropdown-mark-all-read">
              <CheckCheck className="w-3.5 h-3.5" aria-hidden="true" /> Mark all read
            </button>
          )}
          <Link to="/notifications?view=preferences" onClick={onClose} className="p-1.5 rounded-md text-[#6B6B63] hover:text-[#171717] hover:bg-white focus-visible:outline-2 focus-visible:outline-[#1E6F5C]" aria-label="Notification preferences">
            <Settings2 className="w-4 h-4" aria-hidden="true" />
          </Link>
        </div>
      </div>

      <div className="max-h-[min(60vh,420px)] overflow-y-auto">
        <NotificationList notifications={notifications} loading={loading} error={error} onRetry={() => { void refresh(); }} onOpen={onOpen} onDismiss={(id) => { void dismiss(id).catch(() => undefined); }} compact aria-label="Recent notifications" />
      </div>

      <div className="border-t border-[#E7E2D8] bg-white px-3 py-2 text-center">
        <Link to="/notifications" onClick={onClose} className="text-xs font-medium text-[#1E6F5C] hover:underline focus-visible:outline-2 focus-visible:outline-[#1E6F5C] rounded" data-testid="dropdown-view-all">
          View all notifications
        </Link>
      </div>
    </div>
  );
};
