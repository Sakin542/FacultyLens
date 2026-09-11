import React from 'react';
import { Link } from 'react-router-dom';
import { History } from 'lucide-react';
import { VersionStatusBadge } from './VersionStatusBadge';
import { humanize } from './versionUtils';

/** STEP 38: breadcrumb + title for the versions list, detail and compare pages. */
export const VersionHeader: React.FC<{
  assessment: { id: number | string; title: string; type?: string } | null;
  course?: { id: number; code: string; name: string } | null;
  version?: { version_label: string; status: string } | null;
  title?: string;
  subtitle?: string;
  actions?: React.ReactNode;
}> = ({ assessment, course, version, title = 'Assessment Versions', subtitle, actions }) => (
  <header data-testid="version-header" className="space-y-1">
    <nav aria-label="Breadcrumb" className="text-xs text-[#737373] flex flex-wrap gap-1">
      <Link to="/assessments" className="hover:underline">Assessments</Link><span>/</span>
      {assessment && <><Link to={`/assessments/${assessment.id}`} className="hover:underline">{assessment.title}</Link><span>/</span></>}
      {version && assessment ? <><Link to={`/assessments/${assessment.id}/versions`} className="hover:underline">Versions</Link><span>/</span><span>{version.version_label}</span></> : <span>Versions</span>}
    </nav>
    <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
      <div className="flex items-center gap-2 flex-wrap">
        <History className="w-5 h-5" aria-hidden="true" />
        <h1 className="text-2xl font-bold text-[#111111] dark:text-white">{title}</h1>
        {version && <VersionStatusBadge status={version.status} size="md" label={`${humanize(version.status)} · ${version.version_label}`} />}
      </div>
      {actions}
    </div>
    {course && assessment && <p className="text-sm text-[#737373]">{course.code} — {course.name} · {assessment.title}{assessment.type ? ` (${humanize(assessment.type)})` : ''}</p>}
    <p className="text-xs text-[#737373] max-w-3xl">{subtitle ?? 'Every version is a snapshot of the assessment at a point in time. Finalized versions are immutable; to change anything, create a new version.'}</p>
  </header>
);
