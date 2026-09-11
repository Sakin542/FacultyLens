import React from 'react';
import { ReportScope } from '@/types/report';

export const SCOPE_LABELS: Record<ReportScope, string> = {
  FACULTY: 'My courses (faculty)',
  COURSE: 'Course',
  ASSESSMENT: 'Assessment',
  ASSESSMENT_VERSION: 'Assessment version',
  DEPARTMENT: 'Department',
  INSTITUTION: 'Institution',
};

interface ReportScopeSelectorProps {
  /** Scopes allowed for the selected report type AND the current user (intersection computed by the server). */
  scopes: ReportScope[];
  value: ReportScope | '';
  onChange: (scope: ReportScope) => void;
  disabled?: boolean;
}

export const ReportScopeSelector: React.FC<ReportScopeSelectorProps> = ({ scopes, value, onChange, disabled }) => (
  <div>
    <label htmlFor="report-scope" className="block text-xs font-medium text-sage-700 mb-1.5">Scope</label>
    <select
      id="report-scope"
      value={value}
      disabled={disabled || scopes.length === 0}
      onChange={(e) => onChange(e.target.value as ReportScope)}
      className="w-full rounded-lg border border-sage-200 bg-white px-3 py-2 text-sm text-sage-800 focus:outline-none focus:ring-2 focus:ring-sage-300 disabled:bg-sage-100"
    >
      <option value="">{scopes.length === 0 ? 'Select a report type first' : 'Select a scope…'}</option>
      {scopes.map((s) => (
        <option key={s} value={s}>{SCOPE_LABELS[s]}</option>
      ))}
    </select>
    {(value === 'DEPARTMENT' || value === 'INSTITUTION') && (
      <p className="mt-1.5 text-[11px] text-sage-500">Broad scopes return aggregated data only and are generated in the background.</p>
    )}
  </div>
);
