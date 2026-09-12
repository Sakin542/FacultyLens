import React from 'react';
import { cn } from '@/utils/cn';

export interface ProgressBarProps {
  value: number; // 0 - 100
  label?: string;
  sublabel?: string;
  showValue?: boolean;
  size?: 'sm' | 'md' | 'lg';
  statusColor?: boolean;
  className?: string;
}

export const ProgressBar: React.FC<ProgressBarProps> = ({
  value,
  label,
  sublabel,
  showValue = true,
  size = 'md',
  statusColor = true,
  className,
}) => {
  const clampedValue = Math.min(Math.max(0, value), 100);

  const getBarColor = (val: number) => {
    if (!statusColor) return 'bg-sage-700';
    if (val >= 80) return 'bg-[#16A34A]';
    if (val >= 60) return 'bg-[#D97706]';
    return 'bg-[#DC2626]';
  };

  const heightClasses = {
    sm: 'h-1.5',
    md: 'h-2.5',
    lg: 'h-4',
  };

  return (
    <div className={cn('w-full space-y-1.5', className)}>
      {(label || showValue) && (
        <div className="flex items-center justify-between text-xs">
          <div className="flex items-center gap-1.5">
            {label && <span className="font-medium text-sage-700">{label}</span>}
            {sublabel && <span className="text-sage-500 text-[11px]">({sublabel})</span>}
          </div>
          {showValue && (
            <span className="font-semibold text-sage-800 tabular-nums">{clampedValue}%</span>
          )}
        </div>
      )}
      <div
        role="progressbar"
        aria-label={label ?? `${clampedValue}%`}
        aria-valuenow={clampedValue}
        aria-valuemin={0}
        aria-valuemax={100}
        className={cn(
          'w-full bg-sage-200 rounded-full overflow-hidden',
          heightClasses[size]
        )}
      >
        <div
          className={cn(
            'h-full rounded-full transition-all duration-500 ease-out',
            getBarColor(clampedValue)
          )}
          style={{ width: `${clampedValue}%` }}
        />
      </div>
    </div>
  );
};

