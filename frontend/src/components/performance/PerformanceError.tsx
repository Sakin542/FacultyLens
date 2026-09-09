import React from 'react';
import { AlertCircle, RotateCcw } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { ApiError } from '@/services/api';

export function getPerformanceErrorMessage(error: unknown): string {
  const status = error instanceof ApiError ? error.status : null;
  const raw = error instanceof Error ? error.message : null;
  switch (status) {
    case 401: return 'Your session has expired. Please sign in again.';
    case 403: return 'You are not authorized to view performance data for this assessment.';
    case 404: return 'The assessment could not be found.';
    case 409: return raw || 'A performance analysis for the current grades already exists.';
    case 422: return raw || 'The performance analysis could not be generated.';
    case 429: return 'Too many requests. Please wait a moment and try again.';
    case 500: case 502: case 503: case 504: return 'The performance analysis is temporarily unavailable. Please try again later.';
    default: return raw || 'The performance analysis could not be loaded.';
  }
}

export const PerformanceError: React.FC<{ error?: unknown; message?: string | null; onRetry?: () => void; isRetrying?: boolean }> = ({ error, message, onRetry, isRetrying = false }) => (
  <div className="p-3.5 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 rounded-xl flex items-start gap-3 text-red-700 dark:text-red-300" role="alert" data-testid="performance-error">
    <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
    <div className="flex-1 space-y-2">
      <p className="text-xs leading-relaxed">{message || getPerformanceErrorMessage(error)}</p>
      <p className="text-[11px] text-red-600/80 dark:text-red-400/80">Grades are unaffected.</p>
      {onRetry && (
        <Button variant="outline" size="sm" leftIcon={<RotateCcw className={`w-3.5 h-3.5 ${isRetrying ? 'animate-spin' : ''}`} />} onClick={onRetry} disabled={isRetrying}>
          {isRetrying ? 'Retrying…' : 'Try Again'}
        </Button>
      )}
    </div>
  </div>
);
