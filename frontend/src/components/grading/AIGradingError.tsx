import React from 'react';
import { AlertCircle, RotateCcw } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { ApiError } from '@/services/api';

interface AIGradingErrorProps {
  error?: unknown;
  message?: string | null;
  onRetry?: () => void;
  isRetrying?: boolean;
}

/**
 * Maps API failures to faculty-friendly messages without exposing internals or answer content.
 */
export function getGradingErrorMessage(error: unknown): string {
  const status = error instanceof ApiError ? error.status : null;
  const raw = error instanceof Error ? error.message : null;

  switch (status) {
    case 401:
      return 'Your session has expired. Please sign in again.';
    case 403:
      return 'You are not authorized to grade this answer.';
    case 404:
      return 'The answer or AI grading result could not be found.';
    case 408:
    case 504:
      return 'AI grading assistance took too long. Please try again.';
    case 409:
      return raw || 'An AI grading suggestion already exists for this answer. Use regenerate to request a new evaluation.';
    case 422:
      return raw || 'AI grading assistance could not be requested for this answer.';
    case 429:
      return 'Too many AI grading requests. Please wait a moment and try again.';
    case 502:
      return raw || 'FacultyLens could not validate the AI grading result. No grade was saved.';
    case 503:
      return 'AI grading assistance is temporarily unavailable. Please try again later.';
    case 500:
      return 'Something went wrong while processing AI grading assistance. Please try again.';
    default:
      return raw || 'AI grading assistance failed. Please try again.';
  }
}

export const AIGradingError: React.FC<AIGradingErrorProps> = ({ error, message, onRetry, isRetrying = false }) => (
  <div
    className="p-3.5 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 rounded-xl flex items-start gap-3 text-red-700 dark:text-red-300"
    role="alert"
    data-testid="ai-grading-error"
  >
    <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
    <div className="flex-1 space-y-2">
      <p className="text-xs leading-relaxed">{message || getGradingErrorMessage(error)}</p>
      <p className="text-[11px] text-red-600/80 dark:text-red-400/80">No grade was saved. Faculty can still enter marks manually.</p>
      {onRetry && (
        <Button
          variant="outline"
          size="sm"
          leftIcon={<RotateCcw className={`w-3.5 h-3.5 ${isRetrying ? 'animate-spin' : ''}`} />}
          onClick={onRetry}
          disabled={isRetrying}
        >
          {isRetrying ? 'Retrying…' : 'Try Again'}
        </Button>
      )}
    </div>
  </div>
);
