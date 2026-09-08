import React from 'react';
import { Users } from 'lucide-react';
import { EmptyState } from '@/components/common/EmptyState';

interface SubmissionEmptyStateProps {
  hasFilters?: boolean;
  onAdd?: () => void;
  onImport?: () => void;
  onClearFilters?: () => void;
}

export const SubmissionEmptyState: React.FC<SubmissionEmptyStateProps> = ({ hasFilters = false, onAdd, onImport, onClearFilters }) => {
  if (hasFilters) {
    return (
      <EmptyState
        icon={<Users className="w-6 h-6" />}
        title="No submissions match these filters"
        description="Try adjusting the status, grading or search filters."
        actionLabel={onClearFilters ? 'Clear Filters' : undefined}
        onAction={onClearFilters}
      />
    );
  }
  return (
    <EmptyState
      icon={<Users className="w-6 h-6" />}
      title="No student submissions yet"
      description="Add a submission for a registered student or import text answers from a CSV file to start managing student work for this assessment."
      actionLabel={onAdd ? 'Add Submission' : onImport ? 'Import Answers' : undefined}
      onAction={onAdd ?? onImport}
    />
  );
};
