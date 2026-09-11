import React, { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { assessmentBlueprintService } from '@/services/assessmentBlueprintService';
import { assessmentService } from '@/services/assessmentService';
import { learningOutcomeService } from '@/services/learningOutcomeService';
import { coPoMappingService } from '@/services/coPoMappingService';
import { Assessment } from '@/types';
import { BlueprintComparison, BlueprintInput, BlueprintResponse, QuestionValidationResponse } from '@/types/blueprint';
import { BlueprintActions, BlueprintEmptyState, BlueprintError, BlueprintHeader, BlueprintLoading, BlueprintSummary, BlueprintVersions, getBlueprintErrorMessage } from '@/components/blueprint/BlueprintStates';
import { BlueprintForm, OutcomeOption, ProgramOutcomeOption, emptyInput, inputFromBlueprint } from '@/components/blueprint/BlueprintForm';
import { BlueprintComparisonPanel, BlueprintCoverageMatrix, BlueprintPreview, BlueprintRecommendations, BlueprintValidationPanel, BlueprintWarnings, QuestionValidationPanel } from '@/components/blueprint/BlueprintResults';

/**
 * STEP 37: /assessments/:assessmentId/blueprint — plan, validate, finalize, compare and hand off to STEP 33.
 * The page never publishes the assessment or changes its questions.
 */
export const AssessmentBlueprintPage: React.FC = () => {
  const { assessmentId } = useParams<{ assessmentId: string }>();
  const navigate = useNavigate();
  const [assessment, setAssessment] = useState<Assessment | null>(null);
  const [outcomes, setOutcomes] = useState<OutcomeOption[]>([]);
  const [programOutcomes, setProgramOutcomes] = useState<ProgramOutcomeOption[] | null>(null);
  const [data, setData] = useState<BlueprintResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [editing, setEditing] = useState(false);
  const [comparison, setComparison] = useState<BlueprintComparison | null>(null);
  const [questionCheck, setQuestionCheck] = useState<QuestionValidationResponse | null>(null);

  const load = useCallback(async () => {
    if (!assessmentId) return;
    setError(null);
    try {
      const res = await assessmentBlueprintService.getBlueprint(assessmentId);
      setData(res.data);
    } catch (e) {
      setError(getBlueprintErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, [assessmentId]);

  useEffect(() => {
    if (!assessmentId) return;
    assessmentService.getById(assessmentId).then((r) => {
      setAssessment(r.data);
      const courseId = r.data.course_id ?? r.data.course?.id;
      if (courseId) {
        learningOutcomeService.getByCourse(courseId).then((lo) => setOutcomes(lo.data.map((o) => ({ id: Number(o.id), code: o.code, description: o.description })))).catch(() => setOutcomes([]));
        coPoMappingService.getCourseMapping(courseId).then((cp) => setProgramOutcomes(cp.data.program ? cp.data.program_outcomes.map((p) => ({ id: p.id, code: p.code, title: p.title })) : null)).catch(() => setProgramOutcomes(null));
      }
    }).catch((e) => setError(getBlueprintErrorMessage(e)));
    void load();
  }, [assessmentId, load]);

  const run = async (fn: () => Promise<void>) => {
    setBusy(true); setError(null); setNotice(null);
    try { await fn(); } catch (e) { setError(getBlueprintErrorMessage(e)); } finally { setBusy(false); }
  };

  const bp = data?.blueprint ?? null;
  const canEdit = data?.permissions.edit ?? false;
  const canGenerate = data?.permissions.generate ?? false;

  const save = async (input: BlueprintInput) => {
    if (!assessmentId) return;
    const res = bp ? await assessmentBlueprintService.updateBlueprint(bp.id, input) : await assessmentBlueprintService.createBlueprint(assessmentId, input);
    setData(res.data); setEditing(false); setComparison(null); setNotice(res.message ?? 'Blueprint saved.');
  };
  const validate = () => bp && run(async () => { const r = await assessmentBlueprintService.validateBlueprint(bp.id); setData(r.data); setNotice(r.message ?? null); });
  const finalize = () => bp && window.confirm(`Finalize blueprint v${bp.version}? Finalized blueprints are protected; later edits create a new version.`) && run(async () => { const r = await assessmentBlueprintService.finalizeBlueprint(bp.id); setData(r.data); setNotice(r.message ?? null); });
  const remove = () => bp && window.confirm('Delete this draft blueprint?') && run(async () => { await assessmentBlueprintService.deleteBlueprint(bp.id); await load(); setNotice('Draft blueprint deleted.'); });
  const compare = () => bp && run(async () => { const r = await assessmentBlueprintService.compareWithQuestions(bp.id, canEdit); setComparison(r.data); });
  const generate = () => bp && window.confirm('Create STEP 33 question-generation requests from this blueprint? Generated questions stay drafts until you approve them.') && run(async () => {
    const r = await assessmentBlueprintService.generateQuestions(bp.id);
    setNotice(`${r.data.requests.length} generation request(s) started. ${r.data.note}`);
    if (bp.course) navigate(`/courses/${bp.course.id}/question-generator?assessment=${bp.assessment_id}${r.data.requests[0] ? `&request=${r.data.requests[0].id}` : ''}`);
  });
  const checkQuestions = () => bp && assessment && run(async () => {
    const ids = (assessment.questions ?? []).map((q) => Number(q.id)).filter(Boolean);
    if (ids.length === 0) { setNotice('The assessment has no questions to validate yet.'); return; }
    const r = await assessmentBlueprintService.validateQuestions(bp.id, { question_ids: ids });
    setQuestionCheck(r.data);
  });

  const headerAssessment = assessment ? { id: Number(assessment.id), title: assessment.title, type: assessment.type } : null;
  const headerCourse = bp?.course ?? (assessment?.course ? { id: Number(assessment.course.id), code: String(assessment.course.course_code ?? ''), name: String(assessment.course.course_name ?? '') } : null);

  if (loading) return <BlueprintLoading />;

  return (
    <div className="space-y-5" data-testid="assessment-blueprint-page">
      <BlueprintHeader assessment={headerAssessment} course={headerCourse} blueprint={bp} />
      {error && <BlueprintError message={error} onRetry={() => void load()} />}
      {notice && <div role="status" className="rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-[#FAFAF8] dark:bg-[#1A1A1A] px-3 py-2 text-sm text-[#525252] dark:text-[#A3A3A3]">{notice}</div>}

      {!bp && !editing && <BlueprintEmptyState canEdit={canEdit} onCreate={() => setEditing(true)} />}

      {editing && (
        <BlueprintForm key={bp ? `edit-${bp.id}-${bp.version}` : 'new'} initial={bp ? inputFromBlueprint(bp) : emptyInput(assessment ? { total_marks: assessment.total_marks, duration_minutes: assessment.duration_minutes, title: assessment.title } : null)}
          assessment={headerAssessment} outcomes={outcomes} programOutcomes={programOutcomes} onSave={save} onCancel={() => setEditing(false)} saving={busy}
          submitLabel={bp?.status === 'FINALIZED' ? 'Save as new version' : 'Save & validate'} />
      )}

      {bp && !editing && (
        <>
          <BlueprintSummary blueprint={bp} validation={data?.validation ?? null} />
          <div className="flex flex-wrap items-center justify-between gap-2">
            <BlueprintActions blueprint={bp} canEdit={canEdit} canGenerate={canGenerate} busy={busy} onValidate={() => void validate()} onFinalize={() => void finalize()} onDelete={() => void remove()} onGenerate={() => void generate()} onCompare={() => void compare()} onNewVersion={() => setEditing(true)} />
            <div className="flex gap-2">
              {canEdit && bp.status !== 'FINALIZED' && <button type="button" className="text-xs underline underline-offset-2" onClick={() => setEditing(true)}>Edit blueprint</button>}
              <button type="button" className="text-xs underline underline-offset-2" onClick={() => void checkQuestions()} disabled={busy}>Validate assessment questions</button>
            </div>
          </div>
          {data?.validation && <BlueprintValidationPanel validation={data.validation} />}
          <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
            {data?.validation && <BlueprintWarnings warnings={data.validation.warnings} />}
            {data?.validation && <BlueprintRecommendations recommendations={data.validation.recommendations} />}
          </div>
          <BlueprintPreview blueprint={bp} coverage={data?.coverage ?? null} />
          {data?.coverage && <BlueprintCoverageMatrix coverage={data.coverage} />}
          {comparison && <BlueprintComparisonPanel comparison={comparison} assessmentId={bp.assessment_id} />}
          {questionCheck && <QuestionValidationPanel result={questionCheck} />}
          <BlueprintVersions versions={data?.versions ?? []} />
        </>
      )}
    </div>
  );
};
