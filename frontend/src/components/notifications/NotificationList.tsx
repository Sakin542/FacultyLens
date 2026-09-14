import React from 'react';
import { AlertCircle, RefreshCw } from 'lucide-react';
import { cn } from '@/utils/cn';
import type { Notification } from '@/types/notification';
import { NotificationItem } from './NotificationItem';
import { NotificationSkeleton } from './NotificationSkeleton';
import { NotificationEmptyState } from './NotificationEmptyState';

export interface NotificationListProps {
  notifications: Notification[];
  loading: boolean;
  error: string | null;
  onRetry: () => void;
  onOpen: (notification: Notification, path: string | null) => void;
  onMarkRead?: (id: string) => void;
  onDismiss?: (id: string) => void;
  onDelete?: (id: string) => void;
  compact?: boolean;
  emptyTitle?: string;
  emptyDescription?: string;
  className?: string;
  'aria-label'?: string;
}

/** STEP 47: list with loading / error (retry) / empty states. Data only ever comes from the API. */
export const NotificationList: React.FC<NotificationListProps> = ({
  notifications, loading, error, onRetry, onOpen, onMarkRead, onDismiss, onDelete, compact = false, emptyTitle, emptyDescription, className, 'aria-label': ariaLabel = 'Notifications',
}) => {
  if (loading && notifications.length === 0) {
    return <NotificationSkeleton compact={compact} rows={compact ? 3 : 5} className={className} />;
  }
  if (error && notifications.length === 0) {
    return (
      <div role="alert" data-testid="notification-error" className={cn('flex flex-col items-center text-center text-sage-800', compact ? 'px-4 py-8' : 'px-6 py-14', className)}>
        <AlertCircle className="w-6 h-6 text-[#991B1B] mb-2" aria-hidden="true" />
        <p className={cn('font-medium', compact ? 'text-sm' : 'text-base')}>Unable to load notifications.</p>
        {!compact && <p className="text-sm text-sage-500 mt-1">{error}</p>}
        <button type="button" onClick={onRetry} className="mt-3 inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-sage-200 bg-white px-3 py-1.5 text-sm font-medium hover:bg-sage-100 focus-visible:outline-2 focus-visible:outline-sage-700">
          <RefreshCw className="w-3.5 h-3.5" aria-hidden="true" /> Retry
        </button>
      </div>
    );
  }
  if (notifications.length === 0) {
    return <NotificationEmptyState compact={compact} title={emptyTitle} description={emptyDescription} className={className} />;
  }
  return (
    <ul aria-label={ariaLabel} aria-busy={loading || undefined} data-testid="notification-list" className={cn('list-none m-0 p-0', className)}>
      {notifications.map((n) => (
        <NotificationItem key={n.id} notification={n} onOpen={onOpen} onMarkRead={onMarkRead} onDismiss={onDismiss} onDelete={onDelete} compact={compact} />
      ))}
    </ul>
  );
};
