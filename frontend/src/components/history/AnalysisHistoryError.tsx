import React from 'react';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { AlertCircle, RotateCcw } from 'lucide-react';

interface AnalysisHistoryErrorProps {
  message?: string;
  onRetry?: () => void;
}

export const AnalysisHistoryError: React.FC<AnalysisHistoryErrorProps> = ({
  message = 'Failed to load analysis history data.',
  onRetry,
}) => {
  return (
    <Card className="p-8 text-center bg-red-50/50 dark:bg-red-950/20 border border-red-200 dark:border-red-900/40 rounded-xl space-y-4 max-w-md mx-auto">
      <div className="w-12 h-12 rounded-xl bg-red-100 dark:bg-red-900/50 text-red-600 dark:text-red-400 flex items-center justify-center mx-auto">
        <AlertCircle className="w-6 h-6" />
      </div>

      <div className="space-y-1">
        <h4 className="text-sm font-bold text-red-900 dark:text-red-300">
          Unable to Load Analysis History
        </h4>
        <p className="text-xs text-red-700 dark:text-red-400">
          {message}
        </p>
      </div>

      {onRetry && (
        <Button
          variant="secondary"
          size="sm"
          onClick={onRetry}
          className="text-xs"
        >
          <RotateCcw className="w-3.5 h-3.5 mr-1.5" />
          Try Again
        </Button>
      )}
    </Card>
  );
};

