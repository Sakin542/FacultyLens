import React from 'react';
import { useNavigate } from 'react-router-dom';
import { FileBarChart2 } from 'lucide-react';
import { EmptyState } from '@/components/common/EmptyState';

interface ReportEmptyStateProps {
  title?: string;
  description?: string;
  actionLabel?: string;
  actionTo?: string;
}

export const ReportEmptyState: React.FC<ReportEmptyStateProps> = ({
  title = 'No reports yet',
  description = 'Create your first institutional report: choose a report type, scope and filters, preview the data, then export as PDF, CSV or XLSX.',
  actionLabel = 'Create report',
  actionTo = '/reports/create',
}) => {
  const navigate = useNavigate();
  return (
    <div data-testid="report-empty-state">
      <EmptyState icon={<FileBarChart2 className="w-6 h-6" />} title={title} description={description} actionLabel={actionLabel} onAction={() => navigate(actionTo)} />
    </div>
  );
};
