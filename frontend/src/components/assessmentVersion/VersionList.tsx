import React from 'react';
import { AssessmentVersionSummary } from '@/types/assessmentVersion';
import { VersionCard } from './VersionCard';

/** STEP 38: version history list with optional two-item compare selection. */
export const VersionList: React.FC<{
  versions: AssessmentVersionSummary[];
  currentVersionId: number | null;
  selected?: number[];
  onToggleSelect?: (id: number) => void;
}> = ({ versions, currentVersionId, selected = [], onToggleSelect }) => (
  <div data-testid="version-list" className="space-y-2" role="list" aria-label="Version history">
    {versions.map((v) => (
      <div role="listitem" key={v.id}>
        <VersionCard version={v} isCurrent={v.id === currentVersionId} selected={selected.includes(v.id)} onToggleSelect={onToggleSelect} />
      </div>
    ))}
  </div>
);
