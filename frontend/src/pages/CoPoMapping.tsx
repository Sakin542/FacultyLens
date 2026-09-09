import React, { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { AlertTriangle, ArrowLeft, Grid3X3, Play, RotateCcw } from 'lucide-react';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { coPoMappingService } from '@/services/coPoMappingService';
import { courseService } from '@/services/courseService';
import { ApiError } from '@/services/api';
import { CoPoOverview, MappingAnalysisRun, MappingLevel, Program, QuestionCoMappingReview } from '@/types/coPo';
import { CoPoMappingEditor } from '@/components/coPo/CoPoMappingEditor';
import { CourseOutcomeList, ProgramOutcomeList } from '@/components/coPo/OutcomeLists';
import { MappingFindings, MappingValidationSummary } from '@/components/coPo/MappingFindings';
import { CoPerformanceMatrix, PoEvidenceMatrix } from '@/components/coPo/EvidenceTables';
import { QuestionCoMappingReviewList } from '@/components/coPo/QuestionCoMappingReview';
import { MappingDisclaimer, MappingEmptyState, MappingError, MappingLoading } from '@/components/coPo/MappingStates';

/**
 * STEP 31: CO / PO Mapping page for a course. Deterministic analysis; faculty own every mapping decision.
 */
export const CoPoMapping: React.FC = () => {
  const { courseId } = useParams<{ courseId: string }>();
  const [overview, setOverview] = useState<CoPoOverview | null>(null);
  const [run, setRun] = useState<MappingAnalysisRun | null>(null);
  const [questions, setQuestions] = useState<QuestionCoMappingReview[]>([]);
  const [programs, setPrograms] = useState<Program[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isAnalyzing, setIsAnalyzing] = useState(false);
  const [error, setError] = useState<Error | null>(null);
  const [actionError, setActionError] = useState<Error | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [newProgram, setNewProgram] = useState({ code: '', name: '' });

  const flash = (msg: string) => { setNotice(msg); setTimeout(() => setNotice(null), 4000); };

  const load = useCallback(async () => {
    if (!courseId) return;
    try {
      setError(null);
      const [ov, qm] = await Promise.all([coPoMappingService.getCourseMapping(courseId), coPoMappingService.getQuestionMappings(courseId)]);
      setOverview(ov.data);
      setQuestions(qm.data);
      if (ov.data.current_run && ov.data.current_run.status !== 'FAILED') {
        const f = await coPoMappingService.getMappingFindings(courseId);
        setRun(f.run ? { ...f.run, findings: f.data } : ov.data.current_run);
      } else {
        setRun(ov.data.current_run);
      }
      if (!ov.data.program) {
        const p = await coPoMappingService.getPrograms();
        setPrograms(p.data);
      }
    } catch (err) {
      setError(err instanceof Error ? err : new Error('Failed to load CO/PO mapping.'));
    } finally {
      setIsLoading(false);
    }
  }, [courseId]);

  useEffect(() => { load(); }, [load]);

  const act = async (fn: () => Promise<void>, success?: string) => {
    try { setActionError(null); await fn(); await load(); if (success) flash(success); }
    catch (err) { setActionError(err instanceof Error ? err : new Error('The action could not be completed.')); throw err; }
  };

  const analyze = async (force = false) => {
    if (!courseId) return;
    try {
      setIsAnalyzing(true);
      setActionError(null);
      const res = await coPoMappingService.analyzeCourseMapping(courseId, force);
      setRun(res.data);
      await load();
      flash('Mapping analysis generated.');
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) { await load(); return; }
      setActionError(err instanceof Error ? err : new Error('The analysis could not be generated.'));
    } finally {
      setIsAnalyzing(false);
    }
  };

  const changeLevel = async (loId: number, poId: number, mappingId: number | null, level: MappingLevel) => {
    if (!courseId) return;
    if (mappingId && level === 0) await coPoMappingService.deleteMapping(mappingId);
    else if (mappingId) await coPoMappingService.updateMapping(mappingId, { mapping_level: level });
    else if (level > 0) await coPoMappingService.createMapping(courseId, { learning_outcome_id: loId, program_outcome_id: poId, mapping_level: level });
    await load();
  };

  const assignProgram = async (programId: number) => {
    if (!courseId) return;
    await act(async () => { await courseService.update(courseId, { program_id: programId } as never); }, 'Program assigned to course.');
  };

  const createProgram = async () => {
    if (!newProgram.code.trim() || !newProgram.name.trim()) return;
    await act(async () => {
      const p = await coPoMappingService.createProgram({ code: newProgram.code.trim(), name: newProgram.name.trim() });
      await courseService.update(courseId!, { program_id: p.data.id } as never);
      setNewProgram({ code: '', name: '' });
    }, 'Program created and assigned.');
  };

  if (isLoading) return <MappingLoading />;
  if (error || !overview) return <MappingError error={error ?? new Error('Failed to load.')} onRetry={load} />;

  const canEdit = true;
  const matrix = run?.matrix && !run.is_stale ? run.matrix : null;

  return (
    <div className="space-y-6" data-testid="co-po-page">
      {notice && <div className="p-3 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-xl text-emerald-700 dark:text-emerald-300 text-sm font-medium">{notice}</div>}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div className="space-y-1">
          <div className="flex items-center gap-2 text-xs text-[#737373]">
            <Link to="/courses" className="hover:text-[#111111] dark:hover:text-white">Courses</Link><span>/</span>
            <Link to={`/courses/${overview.course.id}`} className="hover:text-[#111111] dark:hover:text-white">{overview.course.course_code}</Link><span>/</span>
            <span className="font-semibold text-[#111111] dark:text-white">CO / PO Mapping</span>
          </div>
          <h1 className="text-2xl font-bold text-[#111111] dark:text-white flex items-center gap-2"><Grid3X3 className="w-5 h-5 text-[#737373]" /> CO / PO Mapping</h1>
          <p className="text-xs text-[#737373]">
            Course: <strong>{overview.course.course_code} — {overview.course.course_name}</strong> · Program: <strong data-testid="program-name">{overview.program ? `${overview.program.code} — ${overview.program.name}` : 'Not assigned'}</strong>
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Link to={`/courses/${overview.course.id}`}><Button variant="ghost" size="sm" leftIcon={<ArrowLeft className="w-3.5 h-3.5" />}>Back to course</Button></Link>
          <Button variant="primary" size="sm" leftIcon={run ? <RotateCcw className={`w-3.5 h-3.5 ${isAnalyzing ? 'animate-spin' : ''}`} /> : <Play className="w-3.5 h-3.5" />} onClick={() => analyze(!!run)} isLoading={isAnalyzing} disabled={overview.course_outcomes.length === 0} data-testid="analyze-mapping">
            {run ? 'Re-analyze Mapping' : 'Analyze Mapping'}
          </Button>
        </div>
      </div>

      {actionError && <MappingError error={actionError} />}

      {!overview.program && (
        <Card variant="default" className="p-5 space-y-3" data-testid="program-setup">
          <h3 className="text-sm font-bold text-[#111111] dark:text-white">Assign a program</h3>
          <p className="text-xs text-[#737373]">CO → PO mapping needs program outcomes. Choose one of your programs or create a new one; define its POs with your institution's wording.</p>
          {programs.length > 0 && (
            <div className="flex flex-wrap gap-2">
              {programs.map((p) => <Button key={p.id} variant="outline" size="sm" onClick={() => assignProgram(p.id)} data-testid="assign-program">{p.code} — {p.name}</Button>)}
            </div>
          )}
          <div className="grid grid-cols-1 sm:grid-cols-[120px_1fr_auto] gap-2 items-end">
            <Input id="program-code" label="Code" value={newProgram.code} onChange={(e) => setNewProgram((s) => ({ ...s, code: e.target.value }))} placeholder="CSE" />
            <Input id="program-name" label="Name" value={newProgram.name} onChange={(e) => setNewProgram((s) => ({ ...s, name: e.target.value }))} placeholder="Computer Science and Engineering" />
            <Button variant="primary" size="sm" onClick={createProgram} data-testid="create-program">Create &amp; assign</Button>
          </div>
        </Card>
      )}

      <Card variant="default" className="p-5 space-y-4">
        <MappingValidationSummary summary={run?.summary && !run.is_stale ? run.summary : overview.summary} />
        {run?.is_stale && (
          <div className="p-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 flex items-start gap-2 text-amber-800 dark:text-amber-300 text-xs" role="alert" data-testid="mapping-stale">
            <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
            <div><p className="font-semibold">The last analysis may be outdated.</p>{run.stale_reasons.map((r, i) => <p key={i}>{r}</p>)}<p>Re-analyze to refresh findings. Previous runs are preserved.</p></div>
          </div>
        )}
      </Card>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card variant="default" className="p-5"><CourseOutcomeList outcomes={overview.course_outcomes} /></Card>
        <Card variant="default" className="p-5">
          <ProgramOutcomeList
            outcomes={overview.program_outcomes}
            canEdit={canEdit && !!overview.program}
            onAdd={overview.program ? (d) => act(() => coPoMappingService.createProgramOutcome(overview.program!.id, d).then(() => undefined), 'Program outcome added.') : undefined}
            onDelete={(id) => act(() => coPoMappingService.deleteProgramOutcome(id).then(() => undefined), 'Program outcome removed.')}
          />
        </Card>
      </div>

      <Card variant="default" className="p-5">
        {overview.program_outcomes.length > 0 && overview.course_outcomes.length > 0 ? (
          <CoPoMappingEditor
            matrix={matrix ?? { program_outcomes: overview.program_outcomes.map((p) => ({ id: p.id, code: p.code, title: p.title })), rows: overview.course_outcomes.map((co) => ({ learning_outcome_id: co.id, code: co.display_code, description: co.description, cells: overview.program_outcomes.map((po) => { const m = overview.mappings.find((x) => x.learning_outcome_id === co.id && x.program_outcome_id === po.id); return { program_outcome_id: po.id, level: (m?.mapping_level ?? 0) as MappingLevel, mapping_id: m?.id ?? null, justification: m?.justification ?? null }; }) })), active_mappings: overview.summary.active_mapping_count, possible_mappings: overview.summary.possible_mapping_count, density_percent: overview.summary.mapping_density_percent, legend: {} }}
            canEdit={canEdit}
            onChangeLevel={changeLevel}
          />
        ) : (
          <MappingEmptyState title="Matrix not available yet" description="Add course outcomes and program outcomes to build the CO → PO matrix." />
        )}
      </Card>

      {run && run.status !== 'FAILED' && run.findings !== undefined && (
        <>
          <Card variant="default" className="p-5"><MappingFindings findings={run.findings} /></Card>
          <Card variant="default" className="p-5"><CoPerformanceMatrix rows={run.co_coverage} /></Card>
          <Card variant="default" className="p-5"><PoEvidenceMatrix rows={run.po_evidence} /></Card>
        </>
      )}
      {run?.status === 'FAILED' && <MappingError message={run.error_message} onRetry={() => analyze(true)} isRetrying={isAnalyzing} />}
      {!run && overview.course_outcomes.length > 0 && (
        <MappingEmptyState title="No mapping analysis yet" description="Run the analysis to validate CO coverage, PO evidence and question mappings. Findings are review signals, not an accreditation decision." />
      )}

      <Card variant="default" className="p-5">
        <QuestionCoMappingReviewList
          questions={questions}
          canEdit={canEdit}
          onConfirm={(qid, loId) => act(() => coPoMappingService.confirmQuestionCoMapping(qid, loId).then(() => undefined), 'Mapping confirmed.')}
          onReject={(qid, loId) => act(() => coPoMappingService.rejectQuestionCoMapping(qid, loId).then(() => undefined), 'Suggestion rejected.')}
        />
      </Card>

      <MappingDisclaimer />
    </div>
  );
};
