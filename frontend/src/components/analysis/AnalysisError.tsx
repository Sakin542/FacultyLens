import React from 'react';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { AlertCircle, RotateCcw } from 'lucide-react';

interface AnalysisErrorProps {
  error: string | null;
  status?: number | null;
  onRetry: () => void;
  isRetrying?: boolean;
}

export const AnalysisError: React.FC<AnalysisErrorProps> = ({
  error,
  status,
  onRetry,
  isRetrying = false,
}) => {
  const getFriendlyMessage = (code?: number | null, raw?: string | null) => {
    if (code === 403) {
      return 'You are not authorized to view this analysis report.';
    }
    if (code === 404) {
      return 'The requested assessment could not be found.';
    }
    if (code === 504) {
      return 'The AI analysis request took too long and timed out. Please try again.';
    }
    if (code === 502) {
      return 'The AI analysis engine is temporarily unavailable. Please try again in a few moments.';
    }
    return raw || 'AI analysis could not be completed. Please try again.';
  };

  return (
    <Card className="p-8 text-center bg-white dark:bg-[#1C1C1E] border border-red-200 dark:border-red-900 rounded-2xl shadow-sm space-y-4 max-w-lg mx-auto">
      <div className="w-12 h-12 rounded-xl bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 flex items-center justify-center text-red-600 dark:text-red-400 mx-auto">
        <AlertCircle className="w-6 h-6" />
      </div>

      <div className="space-y-1.5">
        <h3 className="text-base font-bold text-sage-800 dark:text-white">
          Analysis Unavailable
        </h3>
        <p className="text-xs text-sage-500 leading-relaxed max-w-sm mx-auto">
          {getFriendlyMessage(status, error)}
        </p>
      </div>

      <Button
        variant="primary"
        size="sm"
        leftIcon={<RotateCcw className={`w-3.5 h-3.5 ${isRetrying ? 'animate-spin' : ''}`} />}
        onClick={onRetry}
        disabled={isRetrying}
        className="mx-auto"
      >
        {isRetrying ? 'Retrying...' : 'Try Again'}
      </Button>
    </Card>
  );
};

