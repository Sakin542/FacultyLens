import React from 'react';
import { AlertCircle, RotateCcw } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { ApiError } from '@/services/api';

interface RubricErrorProps {
  error: unknown;
  onRetry?: () => void;
  isRetrying?: boolean;
}

/**
 * Maps API failures to faculty-friendly messages without exposing internals.
 */
export function getRubricErrorMessage(error: unknown): string {
  const status = error instanceof ApiError ? error.status : null;
  const raw = error instanceof Error ? error.message : null;

  switch (status) {
    case 401:
      return 'Your session has expired. Please sign in again.';
    case 403:
      return 'You are not authorized to manage rubrics for this question.';
    case 404:
      return 'The question or rubric could not be found.';
    case 408:
    case 504:
      return 'Rubric generation took too long. Please try again.';
    case 422:
      return raw || 'The rubric could not be saved. Please check the criteria and marks.';
    case 429:
      return 'Too many rubric requests. Please wait a moment and try again.';
    case 502:
      return raw || 'FacultyLens could not produce a valid rubric for this question. Please try again or create the rubric manually.';
    case 503:
      return 'Rubric generation is temporarily unavailable. Please try again later.';
    case 500:
      return 'Something went wrong while processing the rubric. Please try again.';
    default:
      return raw || 'Rubric operation failed. Please try again.';
  }
}

export const RubricError: React.FC<RubricErrorProps> = ({ error, onRetry, isRetrying = false }) => (
  <div
    className="p-4 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 rounded-xl flex items-start gap-3 text-red-700 dark:text-red-300"
    role="alert"
  >
    <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
    <div className="flex-1 space-y-2">
      <p className="text-xs leading-relaxed">{getRubricErrorMessage(error)}</p>
      {onRetry && (
        <Button
          variant="outline"
          size="sm"
          leftIcon={<RotateCcw className={`w-3.5 h-3.5 ${isRetrying ? 'animate-spin' : ''}`} />}
          onClick={onRetry}
          disabled={isRetrying}
        >
          {isRetrying ? 'Retrying...' : 'Try Again'}
        </Button>
      )}
    </div>
  </div>
);
