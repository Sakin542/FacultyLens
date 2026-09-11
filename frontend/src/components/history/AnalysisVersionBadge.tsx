import React from 'react';
import { Badge } from '@/components/common/Badge';
import { Clock, History, CheckCircle2 } from 'lucide-react';

interface AnalysisVersionBadgeProps {
  version: number;
  isCurrent: boolean;
  analyzedAt?: string;
  size?: 'sm' | 'md' | 'lg';
  showTimestamp?: boolean;
}

export const AnalysisVersionBadge: React.FC<AnalysisVersionBadgeProps> = ({
  version,
  isCurrent,
  analyzedAt,
  size = 'md',
  showTimestamp = false,
}) => {
  const formattedDate = analyzedAt
    ? new Date(analyzedAt).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })
    : null;

  return (
    <div className="inline-flex items-center gap-2">
      {/* Version Tag */}
      <span
        className={`inline-flex items-center gap-1 font-mono font-bold rounded-md bg-sage-100 dark:bg-[#2C2C2E] border border-sage-200 dark:border-[#3A3A3C] text-sage-800 dark:text-white ${
          size === 'sm'
            ? 'text-[11px] px-1.5 py-0.5'
            : size === 'lg'
            ? 'text-sm px-2.5 py-1'
            : 'text-xs px-2 py-0.5'
        }`}
      >
        <History className={size === 'sm' ? 'w-3 h-3 text-sage-500' : 'w-3.5 h-3.5 text-sage-500'} />
        v{version}
      </span>

      {/* Current vs Historical State */}
      {isCurrent ? (
        <Badge
          variant="Good"
          className="inline-flex items-center gap-1 text-[11px] font-semibold"
        >
          <CheckCircle2 className="w-3 h-3 text-emerald-500" />
          Current
        </Badge>
      ) : (
        <Badge
          variant="neutral"
          className="inline-flex items-center gap-1 text-[11px] font-medium text-sage-500 dark:text-[#A1A1AA]"
        >
          Historical
        </Badge>
      )}

      {/* Optional Timestamp */}
      {showTimestamp && formattedDate && (
        <span className="inline-flex items-center gap-1 text-xs text-sage-500 font-normal">
          <Clock className="w-3 h-3" />
          {formattedDate}
        </span>
      )}
    </div>
  );
};

