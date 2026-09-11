import React, { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { GitCompare, Plus } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { assessmentVersionService } from '@/services/assessmentVersionService';
import { CreateVersionInput, VersionListResponse } from '@/types/assessmentVersion';
import { VersionHeader } from '@/components/assessmentVersion/VersionHeader';
import { VersionList } from '@/components/assessmentVersion/VersionList';
import { VersionTimeline, toTimeline } from '@/components/assessmentVersion/VersionTimeline';
import { VersionCreateDialog } from '@/components/assessmentVersion/VersionCreateDialog';
import { VersionLoading } from '@/components/assessmentVersion/VersionLoading';
import { VersionError } from '@/components/assessmentVersion/VersionError';
import { VersionEmptyState } from '@/components/assessmentVersion/VersionEmptyState';
import { getVersionErrorMessage } from '@/components/assessmentVersion/versionUtils';

/** STEP 38: /assessments/:assessmentId/versions — history, timeline, create, and pick two versions to compare. */
export const AssessmentVersions: React.FC = () => {
  const { assessmentId } = useParams<{ assessmentId: string }>();
  const navigate = useNavigate();
  const [data, setData] = useState<VersionListResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [dialogError, setDialogError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [selected, setSelected] = useState<number[]>([]);

  const load = useCallback(async () => {
    if (!assessmentId) return;
    setError(null);
    try {
      const res = await assessmentVersionService.getVersions(assessmentId);
      setData(res.data);
    } catch (e) {
      setError(getVersionErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, [assessmentId]);

  useEffect(() => { void load(); }, [load]);

  const create = async (input: CreateVersionInput) => {
    if (!assessmentId) return;
    setBusy(true); setDialogError(null);
    try {
      const res = await assessmentVersionService.createVersion(assessmentId, input);
      setCreating(false);
      setNotice(res.message ?? 'Version created.');
      navigate(`/assessments/${assessmentId}/versions/${res.data.version.id}`);
    } catch (e) {
      setDialogError(getVersionErrorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  const toggle = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id].slice(-2)));
  const compare = () => {
    if (selected.length !== 2 || !assessmentId) return;
    const ordered = [...selected].sort((a, b) => (data?.versions.find((v) => v.id === a)?.version_number ?? 0) - (data?.versions.find((v) => v.id === b)?.version_number ?? 0));
    navigate(`/assessments/${assessmentId}/versions/${ordered[0]}/compare?with=${ordered[1]}`);
  };

  const canEdit = data?.permissions.edit ?? false;
  const versions = data?.versions ?? [];

  return (
    <div className="space-y-5" data-testid="assessment-versions-page">
      <VersionHeader
        assessment={data ? { id: data.assessment.id, title: data.assessment.title, type: data.assessment.type } : null}
        actions={data && versions.length > 0 ? (
          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="outline" onClick={compare} disabled={selected.length !== 2} leftIcon={<GitCompare className="w-3.5 h-3.5" />} title="Select exactly two versions to compare">Compare selected ({selected.length}/2)</Button>
            {canEdit && <Button size="sm" onClick={() => setCreating(true)} leftIcon={<Plus className="w-3.5 h-3.5" />}>Create new version</Button>}
          </div>
        ) : undefined}
      />
      {notice && <p role="status" className="text-sm text-[#166534]">{notice}</p>}
      {error && <VersionError message={error} onRetry={load} />}
      {loading ? <VersionLoading /> : data && (
        versions.length === 0
          ? <VersionEmptyState canEdit={canEdit} onCreate={() => setCreating(true)} questionCount={data.assessment.question_count} />
          : (
            <div className="grid grid-cols-1 xl:grid-cols-3 gap-4">
              <div className="xl:col-span-2 space-y-2">
                <p className="text-xs text-[#737373]">{data.total_versions} version{data.total_versions === 1 ? '' : 's'} · tick two to compare</p>
                <VersionList versions={versions} currentVersionId={data.current_version_id} selected={selected} onToggleSelect={toggle} />
              </div>
              <VersionTimeline items={toTimeline(versions, data.current_version_id)} assessmentId={data.assessment.id} />
            </div>
          )
      )}
      <VersionCreateDialog key={creating ? 'open' : 'closed'} open={creating} versions={versions} busy={busy} error={dialogError} onClose={() => setCreating(false)} onSubmit={create} />
    </div>
  );
};
