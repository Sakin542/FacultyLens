import React from 'react';
import { cn } from '@/utils/cn';

/** STEP 47: loading placeholder rows (announced once via role=status). */
export const NotificationSkeleton: React.FC<{ rows?: number; compact?: boolean; className?: string }> = ({ rows = 4, compact = false, className }) => (
  <div role="status" aria-live="polite" aria-label="Loading notifications" data-testid="notification-skeleton" className={cn('divide-y divide-[#E7E2D8]', className)}>
    <span className="sr-only">Loading notifications…</span>
    {Array.from({ length: rows }).map((_, i) => (
      <div key={i} className={cn('flex gap-3 animate-pulse', compact ? 'px-3 py-2.5' : 'px-4 py-4')} aria-hidden="true">
        <div className="w-8 h-8 rounded-full bg-[#F7F4EE] border border-[#E7E2D8] shrink-0" />
        <div className="flex-1 space-y-2 py-0.5">
          <div className="h-3 rounded bg-[#EFEBE3] w-2/3" />
          <div className="h-3 rounded bg-[#F7F4EE] w-full" />
          {!compact && <div className="h-2.5 rounded bg-[#F7F4EE] w-1/4" />}
        </div>
      </div>
    ))}
  </div>
);
