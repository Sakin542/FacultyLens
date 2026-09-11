import React from 'react';
import { Search, X } from 'lucide-react';
import { Button } from '@/components/common/Button';
import {
  GRADING_STATUSES,
  GradingStatus,
  SUBMISSION_STATUSES,
  SubmissionFilterParams,
  SubmissionStatus,
} from '@/types/submission';
import { formatGradingStatus, formatSubmissionStatus } from './SubmissionStatusBadge';

interface SubmissionFiltersProps {
  filters: SubmissionFilterParams;
  onChange: (patch: Partial<SubmissionFilterParams>) => void;
  onClear: () => void;
  disabled?: boolean;
}

const selectClass =
  'rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] px-3 py-2 text-xs text-sage-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-sage-600 dark:focus:ring-white';

export const SubmissionFilters: React.FC<SubmissionFiltersProps> = ({ filters, onChange, onClear, disabled = false }) => {
  const hasFilters = Boolean(filters.status || filters.grading_status || filters.search || filters.submitted_from || filters.submitted_to);

  return (
    <div className="flex flex-col lg:flex-row lg:items-center gap-2" data-testid="submission-filters">
      <div className="relative flex-1 min-w-[200px]">
        <Search className="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-sage-500" />
        <input
          type="search"
          value={filters.search ?? ''}
          onChange={(e) => onChange({ search: e.target.value, page: 1 })}
          placeholder="Search student ID, name or submission ID"
          aria-label="Search submissions"
          disabled={disabled}
          className={`${selectClass} w-full pl-9`}
        />
      </div>

      <select
        aria-label="Filter by status"
        value={filters.status ?? ''}
        onChange={(e) => onChange({ status: e.target.value as SubmissionStatus | '', page: 1 })}
        disabled={disabled}
        className={selectClass}
      >
        <option value="">All statuses</option>
        {SUBMISSION_STATUSES.map((s) => (
          <option key={s} value={s}>{formatSubmissionStatus(s)}</option>
        ))}
      </select>

      <select
        aria-label="Filter by grading status"
        value={filters.grading_status ?? ''}
        onChange={(e) => onChange({ grading_status: e.target.value as GradingStatus | '', page: 1 })}
        disabled={disabled}
        className={selectClass}
      >
        <option value="">All grading states</option>
        {GRADING_STATUSES.map((s) => (
          <option key={s} value={s}>{formatGradingStatus(s)}</option>
        ))}
      </select>

      <input
        type="date"
        aria-label="Submitted from"
        value={filters.submitted_from ?? ''}
        onChange={(e) => onChange({ submitted_from: e.target.value, page: 1 })}
        disabled={disabled}
        className={selectClass}
      />
      <input
        type="date"
        aria-label="Submitted to"
        value={filters.submitted_to ?? ''}
        onChange={(e) => onChange({ submitted_to: e.target.value, page: 1 })}
        disabled={disabled}
        className={selectClass}
      />

      {hasFilters && (
        <Button variant="ghost" size="sm" leftIcon={<X className="w-3.5 h-3.5" />} onClick={onClear} disabled={disabled}>
          Clear
        </Button>
      )}
    </div>
  );
};
