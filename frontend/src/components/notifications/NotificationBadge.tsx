import React from 'react';
import { cn } from '@/utils/cn';

export interface NotificationBadgeProps {
  /** Real backend count; the display caps at `max` but the accessible label always states the true number. */
  count: number;
  max?: number;
  className?: string;
}

/** STEP 47: unread counter for the bell. Renders nothing at zero. */
export const NotificationBadge: React.FC<NotificationBadgeProps> = ({ count, max = 99, className }) => {
  if (!count || count <= 0) return null;
  const display = count > max ? `${max}+` : String(count);
  return (
    <span
      data-testid="unread-count"
      data-count={count}
      className={cn(
        'inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-sage-700 text-white text-[10px] font-semibold leading-none tabular-nums',
        className,
      )}
    >
      <span aria-hidden="true">{display}</span>
      <span className="sr-only">{count} unread {count === 1 ? 'notification' : 'notifications'}</span>
    </span>
  );
};
