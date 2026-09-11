import React from 'react';
import { AlertTriangle } from 'lucide-react';
import { cn } from '@/utils/cn';

export const VersionError: React.FC<{ message: string; onRetry?: () => void; className?: string }> = ({ message, onRetry, className }) => (
  <div data-testid="version-error" role="alert" className={cn('flex items-start gap-2 rounded-lg border border-red-200 dark:border-red-900/50 bg-red-50 dark:bg-red-950/30 px-3 py-2 text-sm text-red-700 dark:text-red-300', className)}>
    <AlertTriangle className="w-4 h-4 mt-0.5 flex-shrink-0" aria-hidden="true" />
    <div className="flex-1"><p>{message}</p>{onRetry && <button type="button" onClick={onRetry} className="mt-1 text-xs font-medium underline underline-offset-2">Retry</button>}</div>
  </div>
);
