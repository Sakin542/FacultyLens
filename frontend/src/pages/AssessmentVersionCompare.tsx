import React, { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeftRight } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { assessmentVersionService } from '@/services/assessmentVersionService';
import { VersionComparison as VersionComparisonData, VersionListResponse } from '@/types/assessmentVersion';
import { VersionHeader } from '@/components/assessmentVersion/VersionHeader';
import { VersionComparison } from '@/components/assessmentVersion/VersionComparison';
import { VersionLoading } from '@/components/assessmentVersion/VersionLoading';
import { VersionError } from '@/components/assessmentVersion/VersionError';
import { getVersionErrorMessage } from '@/components/assessmentVersion/versionUtils';

/** STEP 38: /assessments/:assessmentId/versions/:versionId/compare?with=:otherId — deterministic diff of two versions. */
export const AssessmentVersionCompare: React.FC = () => {
  const { assessmentId, versionId } = useParams<{ assessmentId: string; versionId: string }>();
  const [params, setParams] = useSearchParams();
  const navigate = useNavigate();
  const withId = params.get('with');
  const [list, setList] = useState<VersionListResponse | null>(null);
  const [comparison, setComparison] = useState<VersionComparisonData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!assessmentId) return;
    assessmentVersionService.getVersions(assessmentId).then((r) => setList(r.data)).catch((e) => setError(getVersionErrorMessage(e)));
  }, [assessmentId]);

  const load = useCallback(async () => {
    if (!versionId || !withId) { setLoading(false); return; }
    setLoading(true); setError(null);
    try {
      const res = await assessmentVersionService.compareVersions(versionId, withId);
      setComparison(res.data);
    } catch (e) {
      setComparison(null);
      setError(getVersionErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, [versionId, withId]);

  useEffect(() => { void load(); }, [load]);

  const versions = list?.versions ?? [];
  const swap = () => withId && navigate(`/assessments/${assessmentId}/versions/${withId}/compare?with=${versionId}`);

  return (
    <div className="space-y-5" data-testid="assessment-version-compare-page">
      <VersionHeader assessment={list ? { id: list.assessment.id, title: list.assessment.title, type: list.assessment.type } : null} title="Compare Versions"
        subtitle="Changed, added and removed questions, marks, CO/Bloom/difficulty, blueprint distributions and STEP 13 analysis metrics are detected deterministically — no AI is involved." />
      <div className="flex flex-wrap items-end gap-2" data-testid="compare-controls">
        <label className="text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-300">From
          <select aria-label="From version" value={versionId ?? ''} onChange={(e) => navigate(`/assessments/${assessmentId}/versions/${e.target.value}/compare${withId ? `?with=${withId}` : ''}`)} className="mt-1 block rounded-lg border border-sage-200 bg-white dark:bg-sage-700 px-3 py-2 text-sm font-normal normal-case tracking-normal">
            {versions.map((v) => <option key={v.id} value={v.id}>{v.version_label} · {v.status.replace('_', ' ').toLowerCase()}</option>)}
          </select>
        </label>
        <Button type="button" size="sm" variant="ghost" onClick={swap} disabled={!withId} aria-label="Swap versions"><ArrowLeftRight className="w-4 h-4" /></Button>
        <label className="text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-300">To
          <select aria-label="To version" value={withId ?? ''} onChange={(e) => setParams(e.target.value ? { with: e.target.value } : {})} className="mt-1 block rounded-lg border border-sage-200 bg-white dark:bg-sage-700 px-3 py-2 text-sm font-normal normal-case tracking-normal">
            <option value="">Select a version…</option>
            {versions.filter((v) => String(v.id) !== versionId).map((v) => <option key={v.id} value={v.id}>{v.version_label} · {v.status.replace('_', ' ').toLowerCase()}</option>)}
          </select>
        </label>
      </div>
      {error && <VersionError message={error} onRetry={load} />}
      {loading ? <VersionLoading label="Comparing versions…" /> : !withId ? <p className="text-sm text-sage-500" data-testid="compare-prompt">Choose a second version to compare against.</p> : comparison && <VersionComparison comparison={comparison} />}
    </div>
  );
};
