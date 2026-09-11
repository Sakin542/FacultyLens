import React from 'react';
import { cn } from '@/utils/cn';
import { Lock, Users } from 'lucide-react';
import { ReportType, ReportTypeKey } from '@/types/report';

interface ReportTypeSelectorProps {
  types: ReportType[];
  value: ReportTypeKey | '';
  onChange: (type: ReportTypeKey) => void;
  disabled?: boolean;
}

/** Only types the server has authorized for the current user are ever passed in. */
export const ReportTypeSelector: React.FC<ReportTypeSelectorProps> = ({ types, value, onChange, disabled }) => (
  <div>
    <label htmlFor="report-type" className="block text-xs font-medium text-sage-700 mb-1.5">Report Type</label>
    <select
      id="report-type"
      value={value}
      disabled={disabled}
      onChange={(e) => onChange(e.target.value as ReportTypeKey)}
      className="w-full rounded-lg border border-sage-200 bg-white px-3 py-2 text-sm text-sage-800 focus:outline-none focus:ring-2 focus:ring-sage-300 disabled:bg-sage-100"
    >
      <option value="">Select a report type…</option>
      {types.map((t) => (
        <option key={t.key} value={t.key}>{t.label}</option>
      ))}
    </select>
    {value && (() => {
      const t = types.find((x) => x.key === value);
      if (!t) return null;
      return (
        <div className={cn('mt-2 rounded-lg border px-3 py-2 text-xs', t.student_data ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-sage-200 bg-sage-50 text-sage-600')}>
          <p>{t.description}</p>
          <p className="mt-1 inline-flex items-center gap-1 text-[11px]">
            {t.student_data ? <Lock className="w-3 h-3" /> : <Users className="w-3 h-3" />}
            {t.student_data ? 'Uses finalized student grades (aggregated only; requires student-data access on the course).' : `Available scopes: ${t.scopes.map((s) => s.replace('_', ' ').toLowerCase()).join(', ')}.`}
          </p>
        </div>
      );
    })()}
  </div>
);
