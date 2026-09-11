import React from 'react';
import { cn } from '@/utils/cn';
import { Card } from '@/components/common/Card';

export interface StatCardProps {
  label: string;
  value: string | number;
  description?: string;
  icon?: React.ReactNode;
  trend?: {
    value: string;
    isPositive?: boolean;
  };
  className?: string;
}

export const StatCard: React.FC<StatCardProps> = ({
  label,
  value,
  description,
  icon,
  trend,
  className,
}) => {
  return (
    <Card className={cn('relative overflow-hidden transition-all duration-150 hover:shadow-card', className)}>
      <div className="flex items-start justify-between">
        <div className="space-y-1">
          <p className="text-xs font-medium uppercase tracking-wider text-sage-500">{label}</p>
          <div className="text-3xl font-bold tracking-tight text-sage-800">{value}</div>
          {description && (
            <p className="text-xs text-sage-500 pt-0.5">{description}</p>
          )}
          {trend && (
            <div className="flex items-center gap-1.5 pt-1">
              <span
                className={cn(
                  'text-xs font-semibold px-1.5 py-0.5 rounded',
                  trend.isPositive
                    ? 'bg-[#F0FDF4] text-[#166534]'
                    : 'bg-[#FEF2F2] text-[#991B1B]'
                )}
              >
                {trend.value}
              </span>
              <span className="text-xs text-sage-500">vs previous cycle</span>
            </div>
          )}
        </div>
        {icon && (
          <div className="w-10 h-10 rounded-lg bg-sage-100 border border-sage-200 flex items-center justify-center text-sage-800">
            {icon}
          </div>
        )}
      </div>
    </Card>
  );
};

