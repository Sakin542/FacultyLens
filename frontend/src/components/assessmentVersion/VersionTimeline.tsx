import React from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/common/Card';
import { AssessmentVersionSummary, VersionTimelineItem } from '@/types/assessmentVersion';
import { VersionStatusBadge } from './VersionStatusBadge';
import { fmtDate } from './versionUtils';

export const toTimeline = (versions: AssessmentVersionSummary[], currentVersionId: number | null): VersionTimelineItem[] =>
  [...versions].sort((a, b) => a.version_number - b.version_number).map((v) => ({
    id: v.id, version_label: v.version_label, status: v.status, version_type: v.version_type, created_by: v.created_by, created_at: v.created_at,
    change_summary: v.change_summary, based_on_version: v.based_on_version, is_current: v.id === currentVersionId,
  }));

/** STEP 38: chronological timeline (oldest → newest) of an assessment's versions. */
export const VersionTimeline: React.FC<{ items: VersionTimelineItem[]; assessmentId: number | string }> = ({ items, assessmentId }) => (
  <Card data-testid="version-timeline" className="p-4">
    <h3 className="text-sm font-semibold text-[#111111] dark:text-white mb-3">Timeline</h3>
    <ol className="relative border-l border-[#E5E5E5] dark:border-[#2A2A2A] ml-2 space-y-4">
      {items.map((it) => (
        <li key={it.id} className="ml-4" data-testid={`timeline-item-${it.id}`}>
          <span aria-hidden="true" className={`absolute -left-[5px] mt-1.5 w-2.5 h-2.5 rounded-full border ${it.is_current ? 'bg-[#111111] border-[#111111] dark:bg-white dark:border-white' : 'bg-white border-[#737373] dark:bg-[#161616]'}`} />
          <div className="flex flex-wrap items-center gap-2">
            <Link to={`/assessments/${assessmentId}/versions/${it.id}`} className="text-sm font-semibold text-[#111111] dark:text-white hover:underline">{it.version_label}</Link>
            <VersionStatusBadge status={it.status} />
            {it.is_current && <span className="text-[10px] uppercase tracking-wide text-[#166534] font-semibold">Current</span>}
          </div>
          <p className="text-xs text-[#737373]">Created {fmtDate(it.created_at)} · {it.created_by.name ?? 'faculty'}{it.based_on_version ? ` · from ${it.based_on_version.version_label ?? `#${it.based_on_version.id}`}` : ' · initial snapshot'}</p>
          {it.change_summary && <p className="text-xs text-[#525252] dark:text-[#A3A3A3] mt-0.5">{it.change_summary}</p>}
        </li>
      ))}
    </ol>
  </Card>
);
