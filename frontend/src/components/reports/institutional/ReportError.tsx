import React from 'react';
import { AlertTriangle, RefreshCw } from 'lucide-react';
import { Button } from '@/components/common/Button';

interface ReportErrorProps {
  message?: string;
  onRetry?: () => void;
  compact?: boolean;
}

/** Safe, user-facing errors only (the API never returns SQL, paths or stack traces). */
export const ReportError: React.FC<ReportErrorProps> = ({ message = 'Report generation failed. Please try again.', onRetry, compact }) => (
  <div role="alert" className={`rounded-xl border border-red-200 bg-red-50 ${compact ? 'px-4 py-3 flex items-center gap-3' : 'p-8 flex flex-col items-center text-center'}`} data-testid="report-error">
    <AlertTriangle className={`text-red-700 shrink-0 ${compact ? 'w-5 h-5' : 'w-8 h-8 mb-2'}`} />
    <div className={compact ? 'flex-1 min-w-0' : ''}>
      <p className="text-sm font-semibold text-red-800">{message}</p>
    </div>
    {onRetry && (
      <Button type="button" variant="outline" size="sm" onClick={onRetry} leftIcon={<RefreshCw className="w-4 h-4" />} className={compact ? '' : 'mt-3'}>
        Retry
      </Button>
    )}
  </div>
);
