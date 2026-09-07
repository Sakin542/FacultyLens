import React from 'react';
import { cn } from '@/utils/cn';
import { FolderOpen } from 'lucide-react';
import { Button } from './Button';

export interface EmptyStateProps {
  icon?: React.ReactNode;
  title: string;
  description: string;
  actionLabel?: string;
  onAction?: () => void;
  className?: string;
}

export const EmptyState: React.FC<EmptyStateProps> = ({
  icon,
  title,
  description,
  actionLabel,
  onAction,
  className,
}) => {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center p-8 md:p-12 text-center rounded-xl border border-dashed border-[#E5E5E5] bg-white',
        className
      )}
    >
      <div className="w-12 h-12 rounded-full bg-[#F7F7F5] border border-[#E5E5E5] flex items-center justify-center text-[#737373] mb-4">
        {icon || <FolderOpen className="w-6 h-6" />}
      </div>
      <h4 className="text-base font-semibold text-[#111111] mb-1">{title}</h4>
      <p className="text-sm text-[#737373] max-w-sm mb-6">{description}</p>
      {actionLabel && onAction && (
        <Button variant="outline" size="sm" onClick={onAction}>
          {actionLabel}
        </Button>
      )}
    </div>
  );
};

