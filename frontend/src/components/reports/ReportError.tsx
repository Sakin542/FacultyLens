import React from 'react';
import { useNavigate } from 'react-router-dom';
import { AlertTriangle, RefreshCw, ArrowLeft } from 'lucide-react';
import { Button } from '@/components/common/Button';

interface ReportErrorProps {
  message?: string;
  onRetry?: () => void;
  backUrl?: string;
}

export const ReportError: React.FC<ReportErrorProps> = ({
  message = 'An error occurred while loading the academic assessment report.',
  onRetry,
  backUrl,
}) => {
  const navigate = useNavigate();

  return (
    <div className="min-h-[450px] flex flex-col items-center justify-center p-8 bg-white border border-sage-200 rounded-xl shadow-subtle text-center">
      <div className="w-14 h-14 rounded-2xl bg-red-50 border border-red-200 flex items-center justify-center mb-4 text-red-600">
        <AlertTriangle className="w-7 h-7" />
      </div>

      <h3 className="text-lg font-bold text-sage-800">
        Unable to Load Report
      </h3>

      <p className="text-xs text-sage-500 max-w-md mt-1.5 mb-6 leading-relaxed">
        {message}
      </p>

      <div className="flex items-center gap-3">
        {backUrl ? (
          <Button
            variant="outline"
            onClick={() => navigate(backUrl)}
            leftIcon={<ArrowLeft className="w-4 h-4" />}
          >
            Back
          </Button>
        ) : (
          <Button
            variant="outline"
            onClick={() => navigate(-1)}
            leftIcon={<ArrowLeft className="w-4 h-4" />}
          >
            Go Back
          </Button>
        )}

        {onRetry && (
          <Button
            variant="primary"
            onClick={onRetry}
            leftIcon={<RefreshCw className="w-4 h-4" />}
          >
            Retry Loading
          </Button>
        )}
      </div>
    </div>
  );
};

