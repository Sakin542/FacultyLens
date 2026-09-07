import React from 'react';
import { Card } from '@/components/common/Card';
import { Loader2 } from 'lucide-react';

interface AnalysisHistoryLoadingProps {
  message?: string;
  className?: string;
}

export const AnalysisHistoryLoading: React.FC<AnalysisHistoryLoadingProps> = ({
  message = 'Loading analysis history records...',
  className = '',
}) => {
  return (
    <Card className={`p-12 text-center bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-sm space-y-3 ${className}`}>
      <Loader2 className="w-8 h-8 animate-spin text-[#111111] dark:text-white mx-auto" />
      <p className="text-xs text-[#737373]">{message}</p>
    </Card>
  );
};

