import React, { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Save } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Card } from '@/components/common/Card';
import { assessmentVersionService } from '@/services/assessmentVersionService';
import { learningOutcomeService } from '@/services/learningOutcomeService';
import { coPoMappingService } from '@/services/coPoMappingService';
import { CreateVersionInput, RestoreVersionInput, UpdateVersionInput, VersionProfile, VersionQuestionInput, VersionResponse } from '@/types/assessmentVersion';
import { VersionHeader } from '@/components/assessmentVersion/VersionHeader';
import { VersionSummary } from '@/components/assessmentVersion/VersionSummary';
import { VersionActions } from '@/components/assessmentVersion/VersionActions';
import { VersionChangeSummary } from '@/components/assessmentVersion/VersionChangeSummary';
import { OutcomeOption, VersionQuestionList, toInputRows } from '@/components/assessmentVersion/VersionQuestionList';
import { VersionBlueprintSummary } from '@/components/assessmentVersion/VersionBlueprintSummary';
import { VersionAnalysisSummary } from '@/components/assessmentVersion/VersionAnalysisSummary';
import { VersionCreateDialog } from '@/components/assessmentVersion/VersionCreateDialog';
import { VersionRestoreDialog } from '@/components/assessmentVersion/VersionRestoreDialog';
import { VersionLoading } from '@/components/assessmentVersion/VersionLoading';
import { VersionError } from '@/components/assessmentVersion/VersionError';
import { getVersionErrorMessage, humanize } from '@/components/assessmentVersion/versionUtils';

const ASSESSMENT_TYPES = ['quiz', 'midterm', 'final', 'assignment', 'lab', 'project', 'presentation', 'viva', 'other'];
const field = 'w-full rounded-lg border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-sage-700 px-3 py-2 text-sm text-sage-800 dark:text-white';

/** STEP 38: /assessments/:assessmentId/versions/:versionId — snapshot detail, draft editing, workflow actions. */
export const AssessmentVersionDetail: React.FC = () => {
  const { assessmentId, versionId } = useParams<{ assessmentId: string; versionId: string }>();
  const navigate = useNavigate();
  const [data, setData] = useState<VersionResponse | null>(null);
  const [profile, setProfile] = useState<VersionProfile | null>(null);
  const [outcomes, setOutcomes] = useState<OutcomeOption[]>([]);
  const [programOutcomes, setProgramOutcomes] = useState<OutcomeOption[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [editing, setEditing] = useState(false);
  const [meta, setMeta] = useState<UpdateVersionInput>({});
  const [rows, setRows] = useState<VersionQuestionInput[]>([]);
  const [creating, setCreating] = useState(false);
  const [restoring, setRestoring] = useState(false);
  const [dialogError, setDialogError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!assessmentId || !versionId) return;
    setError(null);
    try {
      const res = await assessmentVersionService.getVersion(assessmentId, versionId);
      setData(res.data);
      assessmentVersionService.getBlueprint(versionId).then((b) => setProfile(b.data.profile)).catch(() => setProfile(null));
      const courseId = res.data.version.course?.id;
      if (courseId) {
        learningOutcomeService.getByCourse(courseId).then((lo) => setOutcomes(lo.data.map((o) => ({ id: Number(o.id), code: o.code })))).catch(() => setOutcomes([]));
        coPoMappingService.getCourseMapping(courseId).then((cp) => setProgramOutcomes(cp.data.program ? cp.data.program_outcomes.map((p) => ({ id: p.id, code: p.code })) : [])).catch(() => setProgramOutcomes([]));
      }
    } catch (e) {
      setError(getVersionErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, [assessmentId, versionId]);

  useEffect(() => { setEditing(false); void load(); }, [load]);

  const run = async (fn: () => Promise<void>) => {
    setBusy(true); setError(null); setNotice(null);
    try { await fn(); } catch (e) { setError(getVersionErrorMessage(e)); } finally { setBusy(false); }
  };

  const v = data?.version ?? null;
  const p = data?.permissions ?? null;

  const startEdit = () => {
    if (!v) return;
    if (editing) { setEditing(false); return; }
    setMeta({ title: v.title, description: v.description, instructions: v.instructions, assessment_type: v.assessment_type, duration_minutes: v.duration_minutes, change_summary: v.change_summary });
    setRows(toInputRows(v.questions));
    setEditing(true);
  };

  const save = () => v && run(async () => {
    const res = await assessmentVersionService.updateVersion(v.id, { ...meta, questions: rows });
    setData(res.data); setEditing(false); setNotice(res.message ?? 'Draft saved.');
    assessmentVersionService.getBlueprint(v.id).then((b) => setProfile(b.data.profile)).catch(() => undefined);
  });

  const transition = (fn: (id: number) => Promise<{ message?: string; data: VersionResponse }>, confirmText?: string) => v && (!confirmText || window.confirm(confirmText)) && run(async () => {
    const res = await fn(v.id); setData(res.data); setNotice(res.message ?? null);
  });

  const create = async (input: CreateVersionInput) => {
    if (!assessmentId) return;
    setBusy(true); setDialogError(null);
    try {
      const res = await assessmentVersionService.createVersion(assessmentId, input);
      setCreating(false);
      navigate(`/assessments/${assessmentId}/versions/${res.data.version.id}`);
    } catch (e) { setDialogError(getVersionErrorMessage(e)); } finally { setBusy(false); }
  };

  const restore = async (input: RestoreVersionInput) => {
    if (!v || !assessmentId) return;
    setBusy(true); setDialogError(null);
    try {
      const res = await assessmentVersionService.restoreVersion(v.id, input);
      setRestoring(false);
      navigate(`/assessments/${assessmentId}/versions/${res.data.version.id}`);
    } catch (e) { setDialogError(getVersionErrorMessage(e)); } finally { setBusy(false); }
  };

  return (
    <div className="space-y-5" data-testid="assessment-version-detail-page">
      <VersionHeader assessment={v ? v.assessment : null} course={v?.course ?? null} version={v} title={v ? `${v.title}` : 'Assessment Version'}
        subtitle={v ? `Version ${v.version_label} · ${humanize(v.status)}. ${v.is_editable ? 'This draft can still be edited.' : 'This version is an immutable historical record; create or restore a new version to make changes.'}` : undefined} />
      {notice && <p role="status" className="text-sm text-[#166534]">{notice}</p>}
      {error && <VersionError message={error} onRetry={load} />}
      {loading ? <VersionLoading label="Loading version…" /> : v && p && (
        <>
          <VersionActions version={v} permissions={p} busy={busy} editing={editing}
            onEdit={startEdit}
            onSubmitReview={() => transition(assessmentVersionService.submitForReview)}
            onApprove={() => transition(assessmentVersionService.approveVersion)}
            onFinalize={() => transition(assessmentVersionService.finalizeVersion, `Finalize ${v.version_label}? The snapshot becomes immutable and any previously finalized version is archived. Validation must pass first.`)}
            onArchive={() => transition(assessmentVersionService.archiveVersion, `Archive ${v.version_label}? It stays available for historical reference but is no longer the current version.`)}
            onRestore={() => { setDialogError(null); setRestoring(true); }}
            onNewVersion={() => { setDialogError(null); setCreating(true); }}
            onCompare={() => navigate(`/assessments/${assessmentId}/versions/${v.id}/compare${v.based_on_version ? `?with=${v.based_on_version.id}` : ''}`)}
          />
          <VersionSummary version={v} />
          {v.validation && v.validation.status !== 'VALID' && (
            <Card data-testid="version-validation" className={`p-4 space-y-1 ${v.validation.status === 'INVALID' ? 'border-red-200' : ''}`}>
              <h3 className="text-sm font-semibold text-sage-800 dark:text-white">Validation: {humanize(v.validation.status)}</h3>
              {v.validation.errors.length > 0 && <ul className="text-sm text-red-700 list-disc ml-5">{v.validation.errors.map((e, i) => <li key={`${e.code}-${i}`}>{e.message}</li>)}</ul>}
              {v.validation.warnings.length > 0 && <ul className="text-xs text-[#92400E] list-disc ml-5">{v.validation.warnings.map((w, i) => <li key={`${w.code}-${i}`}>{w.message}</li>)}</ul>}
            </Card>
          )}
          <VersionChangeSummary version={v} />

          {editing ? (
            <form noValidate onSubmit={(e) => { e.preventDefault(); save(); }} className="space-y-4" data-testid="version-edit-form">
              <Card className="p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                <label className="text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-300">Title<input aria-label="Version title" value={meta.title ?? ''} onChange={(e) => setMeta({ ...meta, title: e.target.value })} className={`${field} mt-1 font-normal normal-case tracking-normal`} /></label>
                <label className="text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-300">Assessment type<select aria-label="Assessment type" value={meta.assessment_type ?? 'other'} onChange={(e) => setMeta({ ...meta, assessment_type: e.target.value })} className={`${field} mt-1 font-normal normal-case tracking-normal`}>{ASSESSMENT_TYPES.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</select></label>
                <label className="text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-300">Duration (minutes)<input aria-label="Duration minutes" type="number" min={1} value={meta.duration_minutes ?? ''} onChange={(e) => setMeta({ ...meta, duration_minutes: e.target.value ? Number(e.target.value) : null })} className={`${field} mt-1 font-normal normal-case tracking-normal`} /></label>
                <label className="text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-300">Change summary<input aria-label="Change summary" value={meta.change_summary ?? ''} onChange={(e) => setMeta({ ...meta, change_summary: e.target.value })} className={`${field} mt-1 font-normal normal-case tracking-normal`} /></label>
                <label className="text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-300 md:col-span-2">Instructions<textarea aria-label="Instructions" rows={2} value={meta.instructions ?? ''} onChange={(e) => setMeta({ ...meta, instructions: e.target.value || null })} className={`${field} mt-1 font-normal normal-case tracking-normal`} /></label>
                <p className="text-[11px] text-sage-500 md:col-span-2">Total marks are recomputed from the question marks on save. Saving a draft resets its validation and review state.</p>
              </Card>
              <VersionQuestionList questions={v.questions} editable rows={rows} onChangeRows={setRows} outcomes={outcomes} programOutcomes={programOutcomes} />
              <div className="flex gap-2">
                <Button type="submit" size="sm" isLoading={busy} leftIcon={<Save className="w-3.5 h-3.5" />}>Save draft</Button>
                <Button type="button" size="sm" variant="outline" onClick={() => setEditing(false)} disabled={busy}>Cancel</Button>
              </div>
            </form>
          ) : (
            <>
              {v.instructions && <Card className="p-4"><h3 className="text-sm font-semibold text-sage-800 dark:text-white mb-1">Instructions</h3><p className="text-sm whitespace-pre-wrap text-sage-700 dark:text-sage-300">{v.instructions}</p></Card>}
              <VersionQuestionList questions={v.questions} />
            </>
          )}

          <VersionBlueprintSummary blueprint={v.blueprint} profile={profile} />
          <VersionAnalysisSummary analysis={data?.analysis ?? null} assessmentId={v.assessment.id} />
        </>
      )}
      <VersionCreateDialog key={creating ? 'open' : 'closed'} open={creating} versions={v ? [v] : []} defaultBasedOnId={v?.id ?? null} busy={busy} error={dialogError} onClose={() => setCreating(false)} onSubmit={create} />
      <VersionRestoreDialog key={restoring ? 'r-open' : 'r-closed'} open={restoring} version={v} busy={busy} error={dialogError} onClose={() => setRestoring(false)} onConfirm={restore} />
    </div>
  );
};
