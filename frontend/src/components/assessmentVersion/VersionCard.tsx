import React from 'react';
import { Link } from 'react-router-dom';
import { GitBranch, Lock, Users } from 'lucide-react';
import { Card } from '@/components/common/Card';
import { AssessmentVersionSummary } from '@/types/assessmentVersion';
import { VersionStatusBadge } from './VersionStatusBadge';
import { fmtDate, fmtMarks, humanize } from './versionUtils';

/** STEP 38: one row in the version history. */
export const VersionCard: React.FC<{
  version: AssessmentVersionSummary;
  isCurrent?: boolean;
  selected?: boolean;
  onToggleSelect?: (id: number) => void;
}> = ({ version: v, isCurrent, selected, onToggleSelect }) => (
  <Card data-testid={`version-card-${v.id}`} className="p-4 flex flex-col md:flex-row md:items-center gap-3">
    {onToggleSelect && (
      <label className="flex items-center gap-2 text-xs text-sage-500">
        <input type="checkbox" aria-label={`Select ${v.version_label} for comparison`} checked={!!selected} onChange={() => onToggleSelect(v.id)} className="rounded border-sage-200" />
        <span className="md:hidden">Compare</span>
      </label>
    )}
    <div className="flex-1 min-w-0 space-y-1">
      <div className="flex flex-wrap items-center gap-2">
        <Link to={`/assessments/${v.assessment_id}/versions/${v.id}`} className="font-semibold text-sage-800 dark:text-white hover:underline">{v.version_label}</Link>
        <VersionStatusBadge status={v.status} />
        <span className="text-[10px] uppercase tracking-wide text-sage-500">{v.version_type}</span>
        {isCurrent && <span className="text-[10px] uppercase tracking-wide text-[#166534] font-semibold">Current</span>}
        {v.has_submissions && <span className="inline-flex items-center gap-1 text-[10px] text-sage-500" title="Student submissions reference this version"><Users className="w-3 h-3" aria-hidden="true" />submissions</span>}
        {!v.is_editable && v.status !== 'ARCHIVED' && <Lock className="w-3 h-3 text-sage-500" aria-label="Immutable" />}
      </div>
      <p className="text-sm text-sage-700 dark:text-sage-300 truncate">{v.title} · {humanize(v.assessment_type)}</p>
      <p className="text-xs text-sage-500">{v.question_count} question{v.question_count === 1 ? '' : 's'} · {fmtMarks(v.total_marks)} marks{v.duration_minutes ? ` · ${v.duration_minutes} min` : ''} · created {fmtDate(v.created_at)} by {v.created_by.name ?? 'faculty'}
        {v.based_on_version && <span className="inline-flex items-center gap-1 ml-2"><GitBranch className="w-3 h-3" aria-hidden="true" />from {v.based_on_version.version_label ?? `#${v.based_on_version.id}`}</span>}
      </p>
      {v.change_summary && <p className="text-xs text-sage-600 dark:text-sage-400 italic truncate" title={v.change_summary}>“{v.change_summary}”</p>}
    </div>
  </Card>
);
