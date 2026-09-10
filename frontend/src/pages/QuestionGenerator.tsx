import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Sparkles } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { questionGenerationService } from '@/services/questionGenerationService';
import { courseService } from '@/services/courseService';
import { assessmentService } from '@/services/assessmentService';
import { learningOutcomeService } from '@/services/learningOutcomeService';
import { coPoMappingService } from '@/services/coPoMappingService';
import { documentService } from '@/services/documentService';
import { Assessment, Course, DocumentProcessing, LearningOutcome } from '@/types';
import { ProgramOutcome } from '@/types/coPo';
import { CreateGenerationInput, FeedbackInput, GeneratedQuestion, GenerationRequest, UpdateGeneratedQuestionInput } from '@/types/questionGeneration';
import { GenerationForm } from '@/components/questionGenerator/GenerationForm';
import { AddToAssessmentModal, GeneratedQuestionList, GenerationRequestSummary, QuestionGenerationHistory, RegenerateQuestionModal } from '@/components/questionGenerator/GenerationPanels';
import { GenerationDisclaimer, GenerationError, GenerationLoading, getGenerationErrorMessage } from '@/components/questionGenerator/GenerationStates';

const POLL_MS = 3000;

/**
 * STEP 33: Constrained Question Generator. Routes: /question-generator and /courses/:courseId/question-generator
 * (optional ?assessment=ID&request=ID). Drafts are never published without approve + explicit add-to-assessment.
 */
export const QuestionGenerator: React.FC = () => {
  const { courseId: routeCourseId } = useParams<{ courseId: string }>();
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();

  const [courses, setCourses] = useState<Course[]>([]);
  const [assessments, setAssessments] = useState<Assessment[]>([]);
  const [outcomes, setOutcomes] = useState<LearningOutcome[]>([]);
  const [programOutcomes, setProgramOutcomes] = useState<ProgramOutcome[]>([]);
  const [documents, setDocuments] = useState<DocumentProcessing[]>([]);
  const [courseId, setCourseId] = useState(routeCourseId ?? '');
  const [assessmentId, setAssessmentId] = useState(searchParams.get('assessment') ?? '');

  const [history, setHistory] = useState<GenerationRequest[]>([]);
  const [active, setActive] = useState<GenerationRequest | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [regenTarget, setRegenTarget] = useState<GeneratedQuestion | 'request' | null>(null);
  const [addTarget, setAddTarget] = useState<GeneratedQuestion | null>(null);
  const [busy, setBusy] = useState(false);
  const pollRef = useRef<number | null>(null);

  const outcomeOptions = useMemo(() => outcomes.map((o) => ({ id: o.id, code: o.code, description: o.description })), [outcomes]);
  const assessmentOptions = useMemo(() => assessments.map((a) => ({ id: a.id, title: a.title, total_marks: a.total_marks })), [assessments]);
  const questions = active?.questions ?? [];
  const regenerationsLeft = active ? Math.max(0, active.max_regenerations - active.regeneration_count) : 0;

  const loadHistory = useCallback(async (cid: string) => {
    try {
      const res = await questionGenerationService.getGenerationRequests(cid ? { course_id: cid } : undefined);
      setHistory(res.data);
    } catch (e) {
      setError(getGenerationErrorMessage(e));
    }
  }, []);

  useEffect(() => {
    courseService.getAll().then((r) => setCourses(r.data)).catch(() => undefined);
    void loadHistory(routeCourseId ?? '');
  }, [routeCourseId, loadHistory]);

  useEffect(() => {
    if (!courseId) { setAssessments([]); setOutcomes([]); setProgramOutcomes([]); setDocuments([]); return; }
    assessmentService.getByCourse(courseId).then((r) => setAssessments(r.data)).catch(() => setAssessments([]));
    learningOutcomeService.getByCourse(courseId).then((r) => setOutcomes(r.data)).catch(() => setOutcomes([]));
    coPoMappingService.getCourseMapping(courseId).then((r) => setProgramOutcomes(r.data.program_outcomes ?? [])).catch(() => setProgramOutcomes([]));
    documentService.getAll({ course_id: courseId }).then((r) => setDocuments(r.data)).catch(() => setDocuments([]));
  }, [courseId]);

  const stopPolling = () => { if (pollRef.current) { window.clearTimeout(pollRef.current); pollRef.current = null; } };

  const refreshActive = useCallback(async (id: number) => {
    try {
      const res = await questionGenerationService.getGenerationRequest(id);
      setActive(res.data);
      setHistory((prev) => prev.map((h) => (h.id === id ? { ...h, ...res.data, questions: undefined } : h)));
      if (res.data.generation_status === 'PENDING' || res.data.generation_status === 'PROCESSING') {
        pollRef.current = window.setTimeout(() => void refreshActive(id), POLL_MS);
      }
    } catch (e) {
      setError(getGenerationErrorMessage(e));
    }
  }, []);

  useEffect(() => {
    const id = searchParams.get('request');
    if (id) void refreshActive(Number(id));
    return stopPolling;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams.get('request')]);

  const selectRequest = (r: GenerationRequest) => {
    stopPolling();
    setError(null);
    setSearchParams((p) => { p.set('request', String(r.id)); return p; }, { replace: true });
  };

  const submit = async (input: CreateGenerationInput) => {
    setSubmitting(true); setError(null); setNotice(null);
    try {
      const res = await questionGenerationService.createGenerationRequest(input);
      setHistory((prev) => [{ ...res.data, questions: undefined }, ...prev]);
      setNotice(res.message ?? null);
      selectRequest(res.data);
    } catch (e) {
      setError(getGenerationErrorMessage(e));
    } finally {
      setSubmitting(false);
    }
  };

  const patchQuestion = (q: GeneratedQuestion) => setActive((prev) => prev ? { ...prev, questions: (prev.questions ?? []).map((x) => (x.id === q.id ? q : x)) } : prev);
  const act = async (fn: () => Promise<void>) => { setBusy(true); setError(null); try { await fn(); } catch (e) { setError(getGenerationErrorMessage(e)); } finally { setBusy(false); } };

  const onEdit = async (q: GeneratedQuestion, data: UpdateGeneratedQuestionInput) => act(async () => { const r = await questionGenerationService.updateGeneratedQuestion(q.id, data); patchQuestion(r.data); });
  const onApprove = (q: GeneratedQuestion) => void act(async () => { const r = await questionGenerationService.approveGeneratedQuestion(q.id); patchQuestion(r.data); setNotice(r.message ?? null); });
  const onReject = (q: GeneratedQuestion) => {
    const note = window.prompt('Reason for rejecting this draft (optional):') ?? undefined;
    void act(async () => { const r = await questionGenerationService.rejectGeneratedQuestion(q.id, note || undefined); patchQuestion(r.data); });
  };
  const onRegenerateConfirm = (feedback: FeedbackInput) => void act(async () => {
    if (!active) return;
    if (regenTarget === 'request') {
      const r = await questionGenerationService.regenerateRequest(active.id, feedback);
      setRegenTarget(null); setNotice(r.message ?? null);
      void refreshActive(r.data.id);
    } else if (regenTarget) {
      const r = await questionGenerationService.regenerateQuestion(regenTarget.id, feedback);
      setRegenTarget(null); setNotice(r.message ?? null);
      void refreshActive(active.id);
    }
  });
  const onAddConfirm = (targetAssessmentId: string) => void act(async () => {
    if (!addTarget) return;
    const r = await questionGenerationService.addToAssessment(addTarget.id, targetAssessmentId);
    patchQuestion(r.data.generated_question);
    setAddTarget(null);
    setNotice(`${r.message ?? 'Official question created.'} Question #${r.data.question.question_number} was added.`);
    if (active) void refreshActive(active.id);
  });

  const backLink = routeCourseId ? `/courses/${routeCourseId}` : '/dashboard';
  const isProcessing = active && (active.generation_status === 'PENDING' || active.generation_status === 'PROCESSING');

  return (
    <div className="space-y-4" data-testid="question-generator-page">
      <div className="flex items-center justify-between gap-2">
        <div>
          <Link to={backLink} className="inline-flex items-center gap-1 text-xs text-[#737373] hover:text-[#111111] dark:hover:text-white"><ArrowLeft className="w-3.5 h-3.5" /> {routeCourseId ? 'Back to course' : 'Dashboard'}</Link>
          <h1 className="text-2xl font-bold text-[#111111] dark:text-white flex items-center gap-2"><Sparkles className="w-5 h-5" /> Constrained Question Generator</h1>
          <p className="text-sm text-[#737373]">AI drafts under your constraints — grounded in your course outcomes and documents, validated, and always reviewed by you.</p>
        </div>
      </div>

      <GenerationDisclaimer />
      {error && <GenerationError message={error} />}
      {notice && <p className="text-sm text-emerald-700 dark:text-emerald-300" data-testid="generation-notice">{notice}</p>}

      <div className="grid grid-cols-1 lg:grid-cols-[1fr_300px] gap-4">
        <div className="space-y-4">
          <GenerationForm
            courses={courses} assessments={assessmentOptions} outcomes={outcomeOptions} programOutcomes={programOutcomes}
            documents={documents} courseId={courseId} assessmentId={assessmentId}
            onCourseChange={(id) => { setCourseId(id); setAssessmentId(''); void loadHistory(id); }}
            onAssessmentChange={setAssessmentId} onSubmit={submit} submitting={submitting} lockCourse={!!routeCourseId}
          />

          {active && (
            <>
              <GenerationRequestSummary request={active} />
              {isProcessing && <GenerationLoading />}
              {active.generation_status === 'FAILED' && (
                <div className="flex gap-2">
                  <Button size="sm" variant="outline" onClick={() => setRegenTarget('request')} disabled={regenerationsLeft <= 0}>Retry with feedback</Button>
                </div>
              )}
              {active.generation_status === 'COMPLETED' && (
                <>
                  <div className="flex flex-wrap gap-2 justify-end">
                    <Button size="sm" variant="outline" onClick={() => setRegenTarget('request')} disabled={regenerationsLeft <= 0 || busy} data-testid="regenerate-all-button">Regenerate unapproved drafts</Button>
                  </div>
                  <GeneratedQuestionList
                    questions={questions} outcomes={outcomeOptions} regenerationsLeft={regenerationsLeft} hasAssessment={!!active.assessment_id}
                    onEdit={onEdit} onApprove={onApprove} onReject={onReject} onRegenerate={(q) => setRegenTarget(q)} onAddToAssessment={(q) => setAddTarget(q)}
                    onGenerateRubric={(q) => q.official_question_id && active.assessment_id && navigate(`/assessments/${active.assessment_id}?question=${q.official_question_id}`)}
                    onViewSimilar={(_, source) => { if (active.assessment_id && source === 'assessment') navigate(`/assessments/${active.assessment_id}/analysis`); else navigate(`/courses/${active.course_id}/question-bank`); }}
                  />
                </>
              )}
            </>
          )}
        </div>
        <QuestionGenerationHistory requests={history} activeId={active?.id ?? null} onSelect={selectRequest} />
      </div>

      {regenTarget && (
        <RegenerateQuestionModal title={regenTarget === 'request' ? 'Regenerate unapproved drafts' : `Regenerate question ${regenTarget.sequence}`}
          onConfirm={onRegenerateConfirm} onClose={() => setRegenTarget(null)} submitting={busy} regenerationsLeft={regenerationsLeft} />
      )}
      {addTarget && (
        <AddToAssessmentModal question={addTarget} assessments={assessmentOptions} defaultAssessmentId={active?.assessment_id ? String(active.assessment_id) : assessmentId}
          outcomeCode={outcomeOptions.find((o) => String(o.id) === String(addTarget.learning_outcome_id))?.code} onConfirm={onAddConfirm} onClose={() => setAddTarget(null)} submitting={busy} />
      )}
    </div>
  );
};
