import React from 'react';
import { Link } from 'react-router-dom';
import { GitBranch, MessageSquareText } from 'lucide-react';
import { Card } from '@/components/common/Card';
import { AssessmentVersionSummary } from '@/types/assessmentVersion';
import { fmtDate } from './versionUtils';

/** STEP 38: the faculty-written change summary (never generated) and provenance of a version. */
export const VersionChangeSummary: React.FC<{ version: AssessmentVersionSummary }> = ({ version: v }) => (
  <Card data-testid="version-change-summary" className="p-4 space-y-2">
    <div className="flex items-center gap-2"><MessageSquareText className="w-4 h-4 text-[#737373]" aria-hidden="true" /><h3 className="text-sm font-semibold text-[#111111] dark:text-white">Change summary</h3></div>
    <p className="text-sm text-[#262626] dark:text-[#D4D4D4]">{v.change_summary?.trim() ? v.change_summary : <span className="text-[#737373] italic">{v.version_number === 1 ? 'Initial snapshot of the assessment.' : 'No change summary was provided.'}</span>}</p>
    <dl className="grid grid-cols-2 md:grid-cols-4 gap-2 text-xs text-[#737373]">
      <div><dt className="uppercase tracking-wide">Version type</dt><dd className="text-[#262626] dark:text-[#D4D4D4]">{v.version_type}</dd></div>
      <div><dt className="uppercase tracking-wide">Based on</dt><dd className="text-[#262626] dark:text-[#D4D4D4] inline-flex items-center gap-1">{v.based_on_version ? <><GitBranch className="w-3 h-3" aria-hidden="true" /><Link className="hover:underline" to={`/assessments/${v.assessment_id}/versions/${v.based_on_version.id}`}>{v.based_on_version.version_label ?? `#${v.based_on_version.id}`}</Link></> : '—'}</dd></div>
      <div><dt className="uppercase tracking-wide">Created</dt><dd className="text-[#262626] dark:text-[#D4D4D4]">{fmtDate(v.created_at)} · {v.created_by.name ?? 'faculty'}</dd></div>
      <div><dt className="uppercase tracking-wide">{v.finalized_at ? 'Finalized' : v.archived_at ? 'Archived' : v.approved_at ? 'Approved' : v.submitted_at ? 'Submitted' : 'Updated'}</dt><dd className="text-[#262626] dark:text-[#D4D4D4]">{fmtDate(v.finalized_at ?? v.archived_at ?? v.approved_at ?? v.submitted_at ?? v.updated_at)}</dd></div>
    </dl>
  </Card>
);
