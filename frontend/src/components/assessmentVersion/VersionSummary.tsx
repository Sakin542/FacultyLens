import React from 'react';
import { AssessmentVersion } from '@/types/assessmentVersion';
import { fmtDate, fmtMarks, humanize } from './versionUtils';

/** STEP 38: headline figures for one version. */
export const VersionSummary: React.FC<{ version: AssessmentVersion }> = ({ version: v }) => (
  <dl data-testid="version-summary" className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-3">
    {([
      ['Questions', String(v.question_count)], ['Total marks', fmtMarks(v.total_marks)], ['Duration', v.duration_minutes ? `${v.duration_minutes} min` : '—'],
      ['Type', humanize(v.assessment_type)], ['Validation', v.validation_status ? humanize(v.validation_status) : 'Not validated'],
      [v.finalized_at ? 'Finalized' : v.approved_at ? 'Approved' : 'Created', fmtDate(v.finalized_at ?? v.approved_at ?? v.created_at)],
    ] as const).map(([l, val]) => (
      <div key={l} className="rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-4 py-3">
        <dt className="text-xs uppercase tracking-wide text-[#737373]">{l}</dt>
        <dd className="text-xl font-semibold text-[#111111] dark:text-white mt-1 tabular-nums">{val}</dd>
      </div>
    ))}
  </dl>
);
