import React, { useCallback, useRef, useState } from 'react';
import { Bell } from 'lucide-react';
import { cn } from '@/utils/cn';
import { useNotifications } from '@/hooks/useNotifications';
import { NotificationBadge } from './NotificationBadge';
import { NotificationDropdown } from './NotificationDropdown';

export interface NotificationBellProps {
  className?: string;
}

/**
 * STEP 47: navbar bell. The count is the backend's unread count (polled, paused while the tab is hidden);
 * the dropdown fetches the recent list only when opened.
 */
export const NotificationBell: React.FC<NotificationBellProps> = ({ className }) => {
  const [open, setOpen] = useState(false);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const { unreadCount } = useNotifications({ loadList: false, poll: true });
  const close = useCallback(() => setOpen(false), []);

  const label = unreadCount > 0 ? `Notifications, ${unreadCount} unread` : 'Notifications';

  return (
    <div className={cn('relative', className)} data-testid="notification-bell">
      <button
        ref={buttonRef}
        type="button"
        aria-label={label}
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-controls="notification-dropdown"
        onClick={() => setOpen((o) => !o)}
        className="relative inline-flex items-center justify-center h-10 w-10 sm:h-9 sm:w-9 rounded-full bg-white border border-sage-200 text-sage-800 hover:bg-sage-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage-700"
      >
        <Bell className="w-[18px] h-[18px] sm:w-4 sm:h-4" aria-hidden="true" strokeWidth={1.8} />
        <NotificationBadge count={unreadCount} className="absolute -top-1 -right-1" />
      </button>
      <NotificationDropdown open={open} onClose={close} anchorRef={buttonRef} />
    </div>
  );
};
