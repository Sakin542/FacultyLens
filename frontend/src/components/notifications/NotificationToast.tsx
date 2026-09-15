import React, { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { X, ExternalLink } from 'lucide-react';
import { cn } from '@/utils/cn';
import type { Notification } from '@/types/notification';
import { CategoryIcon, SEVERITY_META, categoryLabel, resolveActionPath } from './notificationUi';

export interface NotificationToastProps {
  notification: Notification;
  onDismiss: (id: string) => void;
  onOpen?: (notification: Notification, path: string | null) => void;
  autoDismissMs?: number;
}

/**
 * STEP 48: Non-intrusive toast for real-time notification arrivals.
 * Matches FacultyLens white/cream design system with sage borders and accessible labels.
 */
export const NotificationToast: React.FC<NotificationToastProps> = ({
  notification: n,
  onDismiss,
  onOpen,
  autoDismissMs = 6000,
}) => {
  const navigate = useNavigate();
  const path = resolveActionPath(n.action_url);
  const sev = SEVERITY_META[n.severity] ?? SEVERITY_META.INFO;
  const SevIcon = sev.icon;
  const title = n.title ?? 'New Notification';

  useEffect(() => {
    if (autoDismissMs <= 0) return;
    const timer = setTimeout(() => {
      onDismiss(n.id);
    }, autoDismissMs);
    return () => clearTimeout(timer);
  }, [n.id, autoDismissMs, onDismiss]);

  const handleClick = () => {
    if (onOpen) {
      onOpen(n, path);
    } else {
      onDismiss(n.id);
      if (path) navigate(path);
    }
  };

  return (
    <div
      role="status"
      aria-live="polite"
      data-testid={`notification-toast-${n.id}`}
      className={cn(
        'group pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border border-sage-200 bg-white p-3.5 shadow-lg transition-all duration-300 animate-in fade-in slide-in-from-top-2 sm:slide-in-from-bottom-2',
        'hover:border-sage-300',
      )}
    >
      <span
        className={cn('shrink-0 rounded-full border flex items-center justify-center w-8 h-8', sev.className)}
        title={`${categoryLabel(n.category)} · ${sev.label}`}
      >
        <CategoryIcon category={n.category} className="w-4 h-4" />
      </span>

      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-1.5">
          <span className="text-xs font-semibold text-sage-800 truncate">{title}</span>
          <span className={cn('inline-flex items-center gap-0.5 rounded px-1 text-[9px] font-medium border', sev.className)}>
            <SevIcon className="w-2.5 h-2.5" aria-hidden="true" />
            {sev.label}
          </span>
        </div>
        {n.message && <p className="text-xs text-sage-600 mt-0.5 line-clamp-2">{n.message}</p>}
        {path && (
          <button
            type="button"
            onClick={handleClick}
            className="inline-flex items-center gap-1 mt-1.5 text-xs font-medium text-sage-700 hover:text-sage-900 hover:underline focus-visible:outline-2 focus-visible:outline-sage-700 rounded"
          >
            <span>View</span>
            <ExternalLink className="w-3 h-3" aria-hidden="true" />
          </button>
        )}
      </div>

      <button
        type="button"
        onClick={() => onDismiss(n.id)}
        aria-label={`Dismiss notification "${title}"`}
        className="shrink-0 p-1 text-sage-400 hover:text-sage-700 rounded-md hover:bg-sage-100 focus-visible:outline-2 focus-visible:outline-sage-700"
      >
        <X className="w-4 h-4" aria-hidden="true" />
      </button>
    </div>
  );
};

export interface NotificationToastContainerProps {
  toasts: Notification[];
  onDismiss: (id: string) => void;
  onOpen?: (notification: Notification, path: string | null) => void;
}

export const NotificationToastContainer: React.FC<NotificationToastContainerProps> = ({
  toasts,
  onDismiss,
  onOpen,
}) => {
  if (toasts.length === 0) return null;

  return (
    <div
      aria-label="Real-time notifications"
      className="fixed bottom-4 right-4 z-50 flex flex-col gap-2 pointer-events-none max-w-sm w-full px-3 sm:px-0"
    >
      {toasts.map((toast) => (
        <NotificationToast key={toast.id} notification={toast} onDismiss={onDismiss} onOpen={onOpen} />
      ))}
    </div>
  );
};

