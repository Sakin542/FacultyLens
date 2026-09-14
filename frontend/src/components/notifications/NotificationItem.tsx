import React from 'react';
import { Check, Trash2, X } from 'lucide-react';
import { cn } from '@/utils/cn';
import type { Notification } from '@/types/notification';
import { CategoryIcon, SEVERITY_META, actionLabel, categoryLabel, formatRelativeTime, isUnread, resolveActionPath } from './notificationUi';

export interface NotificationItemProps {
  notification: Notification;
  /** Called after the item was marked read (if unread); the parent navigates to `path` when present. */
  onOpen: (notification: Notification, path: string | null) => void;
  onMarkRead?: (id: string) => void;
  onDismiss?: (id: string) => void;
  onDelete?: (id: string) => void;
  compact?: boolean;
  now?: Date;
}

/**
 * STEP 47: one notification row. Unread state is shown with a dot + bold title + sr-only text (not colour alone);
 * severity with icon + label. The row is a real <button>; per-row actions are separate labelled buttons.
 */
export const NotificationItem: React.FC<NotificationItemProps> = ({ notification: n, onOpen, onMarkRead, onDismiss, onDelete, compact = false, now }) => {
  const unread = isUnread(n);
  const path = resolveActionPath(n.action_url);
  const sev = SEVERITY_META[n.severity] ?? SEVERITY_META.INFO;
  const SevIcon = sev.icon;
  const time = formatRelativeTime(n.created_at, now);
  const title = n.title ?? String(n.type).replace(/_/g, ' ').toLowerCase();

  return (
    <li
      data-testid={`notification-${n.id}`}
      data-unread={unread ? 'true' : 'false'}
      className={cn('group relative flex gap-3 border-b border-[#E7E2D8] last:border-b-0 transition-colors', unread ? 'bg-[#F7F4EE]' : 'bg-white', 'hover:bg-[#FBF9F5]', compact ? 'px-3 py-2.5' : 'px-4 py-4')}
    >
      <span className={cn('shrink-0 rounded-full border flex items-center justify-center', sev.className, compact ? 'w-8 h-8' : 'w-9 h-9')} title={`${categoryLabel(n.category)} · ${sev.label}`}>
        <CategoryIcon category={n.category} className={compact ? 'w-4 h-4' : 'w-[18px] h-[18px]'} />
      </span>

      <div className="flex-1 min-w-0">
        <button
          type="button"
          onClick={() => onOpen(n, path)}
          className="block w-full text-left rounded-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1E6F5C]"
          aria-label={`${unread ? 'Unread: ' : ''}${title}${path ? `. ${actionLabel(n)}` : ''}`}
        >
          <span className="flex items-start gap-2">
            {unread && <span className="mt-1.5 w-2 h-2 rounded-full bg-[#1E6F5C] shrink-0" aria-hidden="true" />}
            <span className={cn('block text-[#171717] leading-snug', compact ? 'text-sm' : 'text-[15px]', unread ? 'font-semibold' : 'font-medium')}>{title}</span>
          </span>
          {n.message && <span className={cn('block text-[#6B6B63] mt-0.5', compact ? 'text-xs line-clamp-2' : 'text-sm')}>{n.message}</span>}
          <span className={cn('flex flex-wrap items-center gap-x-2 gap-y-1 mt-1.5 text-[11px] text-[#6B6B63]', compact && 'mt-1')}>
            {time && <time dateTime={n.created_at ?? undefined}>{time}</time>}
            <span aria-hidden="true">·</span>
            <span className="inline-flex items-center rounded-md border border-[#E7E2D8] bg-white px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-[#4C6B62]">{categoryLabel(n.category)}</span>
            <span className={cn('inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[10px]', sev.className)}>
              <SevIcon className="w-3 h-3" aria-hidden="true" />
              {sev.label}
            </span>
            {path && !compact && <span className="text-[#1E6F5C] font-medium">{actionLabel(n)} →</span>}
          </span>
        </button>
      </div>

      {(onMarkRead || onDismiss || onDelete) && (
        <div className={cn('flex items-start gap-0.5 shrink-0', compact ? 'opacity-70 group-hover:opacity-100 focus-within:opacity-100' : '')}>
          {onMarkRead && unread && (
            <button type="button" onClick={() => onMarkRead(n.id)} aria-label={`Mark "${title}" as read`} title="Mark as read"
              className="p-1.5 rounded-md text-[#6B6B63] hover:text-[#171717] hover:bg-white border border-transparent hover:border-[#E7E2D8] focus-visible:outline-2 focus-visible:outline-[#1E6F5C]">
              <Check className="w-4 h-4" aria-hidden="true" />
            </button>
          )}
          {onDismiss && (
            <button type="button" onClick={() => onDismiss(n.id)} aria-label={`Dismiss "${title}"`} title="Dismiss"
              className="p-1.5 rounded-md text-[#6B6B63] hover:text-[#171717] hover:bg-white border border-transparent hover:border-[#E7E2D8] focus-visible:outline-2 focus-visible:outline-[#1E6F5C]">
              <X className="w-4 h-4" aria-hidden="true" />
            </button>
          )}
          {onDelete && (
            <button type="button" onClick={() => onDelete(n.id)} aria-label={`Delete "${title}"`} title="Delete"
              className="p-1.5 rounded-md text-[#6B6B63] hover:text-[#991B1B] hover:bg-white border border-transparent hover:border-[#E7E2D8] focus-visible:outline-2 focus-visible:outline-[#1E6F5C]">
              <Trash2 className="w-4 h-4" aria-hidden="true" />
            </button>
          )}
        </div>
      )}
    </li>
  );
};
