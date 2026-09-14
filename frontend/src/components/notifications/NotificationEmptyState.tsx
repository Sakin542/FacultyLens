import React from 'react';
import { BellOff } from 'lucide-react';
import { cn } from '@/utils/cn';

export interface NotificationEmptyStateProps {
  title?: string;
  description?: string;
  compact?: boolean;
  className?: string;
}

/** STEP 47: shown when the (filtered) list is empty. Never renders placeholder/fake notifications. */
export const NotificationEmptyState: React.FC<NotificationEmptyStateProps> = ({
  title = "You're all caught up.",
  description = 'Important FacultyLens activity will appear here.',
  compact = false,
  className,
}) => (
  <div data-testid="notification-empty" className={cn('flex flex-col items-center text-center', compact ? 'px-4 py-8' : 'px-6 py-14', className)}>
    <span className={cn('rounded-full bg-[#F7F4EE] border border-[#E7E2D8] text-[#6B6B63] flex items-center justify-center mb-3', compact ? 'w-9 h-9' : 'w-12 h-12')}>
      <BellOff className={compact ? 'w-4 h-4' : 'w-5 h-5'} aria-hidden="true" strokeWidth={1.8} />
    </span>
    <p className={cn('font-semibold text-[#171717]', compact ? 'text-sm' : 'text-base')}>{title}</p>
    <p className={cn('text-[#6B6B63] mt-1 max-w-xs', compact ? 'text-xs' : 'text-sm')}>{description}</p>
  </div>
);
