import React from 'react';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { History, ArrowRight } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

interface AnalysisHistoryEmptyStateProps {
  hasFilters?: boolean;
  onClearFilters?: () => void;
}

export const AnalysisHistoryEmptyState: React.FC<AnalysisHistoryEmptyStateProps> = ({
  hasFilters = false,
  onClearFilters,
}) => {
  const navigate = useNavigate();

  return (
    <Card className="p-12 text-center bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-sm space-y-4 max-w-lg mx-auto">
      <div className="w-14 h-14 rounded-2xl bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center mx-auto text-[#737373]">
        <History className="w-7 h-7" />
      </div>

      <div className="space-y-1">
        <h3 className="text-base font-bold text-[#111111] dark:text-white">
          {hasFilters ? 'No Matching Analysis Records Found' : 'No Analysis History Yet'}
        </h3>
        <p className="text-xs text-[#737373] max-w-sm mx-auto">
          {hasFilters
            ? 'No previous analysis records match your active filters or search terms. Try clearing filters to see all historical runs.'
            : 'Every time you run an AI analysis on an assessment, a permanent snapshot is archived here with quality metrics and version history.'}
        </p>
      </div>

      <div className="pt-2 flex items-center justify-center gap-3">
        {hasFilters && onClearFilters ? (
          <Button variant="secondary" size="sm" onClick={onClearFilters}>
            Clear Active Filters
          </Button>
        ) : (
          <Button
            variant="primary"
            size="sm"
            onClick={() => navigate('/assessments')}
            className="bg-[#111111] dark:bg-white text-white dark:text-[#111111]"
          >
            Go to Assessments
            <ArrowRight className="w-4 h-4 ml-1.5" />
          </Button>
        )}
      </div>
    </Card>
  );
};

