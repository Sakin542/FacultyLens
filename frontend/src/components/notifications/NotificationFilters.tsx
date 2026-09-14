import React from 'react';
import { cn } from '@/utils/cn';
import type { NotificationFilter } from '@/types/notification';
import { NOTIFICATION_CATEGORIES, CATEGORY_LABELS } from '@/types/notification';

export interface NotificationFiltersProps {
  value: NotificationFilter;
  onChange: (filter: NotificationFilter) => void;
  unreadCount?: number;
  className?: string;
}

const FILTERS: { value: NotificationFilter; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'unread', label: 'Unread' },
  ...NOTIFICATION_CATEGORIES.map((c) => ({ value: c as NotificationFilter, label: CATEGORY_LABELS[c] })),
];

/** STEP 47: server-side filter tabs (roving arrow-key navigation, aria-selected). */
export const NotificationFilters: React.FC<NotificationFiltersProps> = ({ value, onChange, unreadCount, className }) => {
  const refs = React.useRef<Array<HTMLButtonElement | null>>([]);

  const onKeyDown = (e: React.KeyboardEvent, index: number) => {
    const last = FILTERS.length - 1;
    let next: number | null = null;
    if (e.key === 'ArrowRight') next = index === last ? 0 : index + 1;
    if (e.key === 'ArrowLeft') next = index === 0 ? last : index - 1;
    if (e.key === 'Home') next = 0;
    if (e.key === 'End') next = last;
    if (next === null) return;
    e.preventDefault();
    refs.current[next]?.focus();
    onChange(FILTERS[next].value);
  };

  return (
    <div role="tablist" aria-label="Filter notifications" className={cn('flex flex-wrap gap-1.5', className)}>
      {FILTERS.map((f, i) => {
        const selected = f.value === value;
        return (
          <button
            key={f.value}
            ref={(el) => { refs.current[i] = el; }}
            type="button"
            role="tab"
            aria-selected={selected}
            tabIndex={selected ? 0 : -1}
            data-testid={`notification-filter-${f.value}`}
            onClick={() => onChange(f.value)}
            onKeyDown={(e) => onKeyDown(e, i)}
            className={cn(
              'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1E6F5C]',
              selected ? 'bg-[#171717] text-white border-[#171717]' : 'bg-white text-[#171717] border-[#E7E2D8] hover:bg-[#F7F4EE]',
            )}
          >
            {f.label}
            {f.value === 'unread' && typeof unreadCount === 'number' && unreadCount > 0 && (
              <span className={cn('rounded-full px-1.5 text-[10px] tabular-nums', selected ? 'bg-white/20' : 'bg-[#F7F4EE] border border-[#E7E2D8]')} aria-label={`${unreadCount} unread`}>{unreadCount > 99 ? '99+' : unreadCount}</span>
            )}
          </button>
        );
      })}
    </div>
  );
};
