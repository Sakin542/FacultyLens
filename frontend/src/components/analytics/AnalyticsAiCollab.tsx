import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Badge } from '@/components/common/Badge';
import { AiEvaluationAnalytics, BlueprintComplianceAnalytics, CollaborationAnalytics as CollabData, GradingAnalytics, InterGraderAnalytics, RecommendationAnalytics as RecData, RubricAnalytics, SimilarityAnalytics, QuestionBankAnalytics } from '@/types/analytics';
import { Bars, LineChart, Section, SectionEmpty, StatusBadge, fmtNum, fmtPct, humanize, statusVariant } from './AnalyticsStates';
import { TASK_LABELS, EvaluationTask } from '@/types/aiEvaluation';

/** STEP 36: similarity, question bank, rubric, AI grading, inter-grader, AI evaluation, recommendation and collaboration summaries. */

const Stat: React.FC<{ label: string; value: string; hint?: string }> = ({ label, value, hint }) => (
  <div className="rounded-md border border-sage-100 dark:border-[#2A2A2A] px-2 py-1"><dt className="text-xs text-sage-500">{label}</dt><dd className="font-semibold tabular-nums">{value}</dd>{hint && <dd className="text-[10px] text-sage-400">{hint}</dd>}</div>
);

const SIM_ORDER: Array<keyof SimilarityAnalytics['by_status']> = ['POTENTIAL_DUPLICATE', 'HIGHLY_SIMILAR', 'SOMEWHAT_SIMILAR', 'NOT_SIMILAR'];

export const SimilaritySummary: React.FC<{ data: SimilarityAnalytics }> = ({ data }) => (
  <Section testId="similarity-summary" title="Question Similarity" explanation={data.explanation} subtitle={data.analyzed_assessments ? `${data.analyzed_assessments} analyzed assessment(s)` : undefined}>
    {data.analyzed_assessments === 0 ? <SectionEmpty title="No similarity analysis yet" description="Similarity categories appear after an assessment analysis has compared questions with the question bank." /> : (
      <>
        <dl className="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
          {SIM_ORDER.map((s) => <div key={s} className="rounded-md border border-sage-100 dark:border-[#2A2A2A] px-2 py-1"><dt className="text-xs"><StatusBadge status={s} /></dt><dd className="font-semibold tabular-nums">{data.by_status[s].questions} <span className="text-xs text-sage-400">question(s)</span></dd></div>)}
        </dl>
        {data.flagged_assessments.length > 0 && <p className="text-xs text-sage-500">Inspect flagged questions: {data.flagged_assessments.map((f) => <Link key={f.assessment_id} to={`/assessments/${f.assessment_id}/analysis`} className="underline underline-offset-2 mr-2">Assessment #{f.assessment_id} ({f.flagged_questions})</Link>)}</p>}
      </>
    )}
  </Section>
);

export const QuestionBankSummary: React.FC<{ data: QuestionBankAnalytics }> = ({ data }) => (
  <Section testId="question-bank-summary" title="Question Bank">
    <dl className="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
      <Stat label="Bank questions" value={String(data.bank_questions)} /><Stat label="Assessment questions" value={String(data.assessment_questions)} />
      <Stat label="Bank questions matched" value={String(data.bank_previously_matched)} hint="Potential duplicate / highly similar" /><Stat label="Questions without CO" value={String(data.assessment_questions_without_lo)} />
    </dl>
    {data.bank_questions > 0 && <Bars ariaLabel="Question bank by difficulty" max={data.bank_questions} rows={['easy', 'medium', 'hard'].map((l) => ({ label: humanize(l), value: data.bank_by_difficulty[l] ?? 0, display: String(data.bank_by_difficulty[l] ?? 0) }))} />}
  </Section>
);

export const RubricSummary: React.FC<{ data: RubricAnalytics }> = ({ data }) => (
  <Section testId="rubric-summary" title="Rubrics">
    {data.total === 0 ? <SectionEmpty title="No rubrics yet" description="Generate or create rubrics for assessment questions to see rubric activity." /> : (
      <>
        <dl className="grid grid-cols-2 md:grid-cols-5 gap-2 text-sm">
          <Stat label="Total" value={String(data.total)} /><Stat label="Draft" value={String(data.draft)} /><Stat label="Approved" value={String(data.approved)} /><Stat label="Archived" value={String(data.archived)} /><Stat label="Avg criteria" value={fmtNum(data.average_criteria, 1)} />
        </dl>
        {data.faculty_ratings.rated > 0 && <p className="text-xs text-sage-500">Faculty rubric ratings: {data.faculty_ratings.rated} rated · average {fmtNum(data.faculty_ratings.average_rating, 2)}/5 · acceptance {fmtPct(data.faculty_ratings.acceptance_rate)} · revision {fmtPct(data.faculty_ratings.revision_rate)}. {data.faculty_ratings.note}</p>}
      </>
    )}
  </Section>
);

export const GradingSummary: React.FC<{ data: GradingAnalytics; restricted: boolean }> = ({ data, restricted }) => (
  <Section testId="grading-summary" title="AI Grading Assistance" explanation={data.explanation}>
    {restricted ? <SectionEmpty title="Restricted for your role" description="Grading analytics are available to course owners and editors." /> : !data.available ? <SectionEmpty title="No AI-assisted grading yet" description="Request AI grading assistance on student answers to see suggestion vs final-grade analytics." /> : (
      <>
        <dl className="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
          <Stat label="AI-assisted answers" value={String(data.ai_assisted_answers)} /><Stat label="Faculty accepted" value={String(data.faculty_accepted ?? 0)} hint="AI suggestion kept" /><Stat label="Faculty modified" value={String(data.faculty_modified ?? 0)} /><Stat label="Faculty rejected" value={String(data.faculty_rejected ?? 0)} />
          <Stat label="MAE" value={fmtNum(data.mae ?? null, 2)} hint="AI suggestion vs final faculty grade" /><Stat label="Mean signed difference" value={data.mean_signed_difference === null || data.mean_signed_difference === undefined ? 'N/A' : `${data.mean_signed_difference > 0 ? '+' : ''}${data.mean_signed_difference}`} hint="+ = AI suggests higher" />
          <Stat label="AI suggestion mean" value={fmtNum(data.ai_suggestion_mean ?? null, 2)} /><Stat label="Final faculty grade mean" value={fmtNum(data.final_faculty_grade_mean ?? null, 2)} />
        </dl>
        <p className="text-xs text-sage-500">Compared on {data.compared_answers ?? 0} finalized answer(s) · exact agreement {fmtPct(data.exact_agreement_rate ?? null)}. AI suggestions are never official grades.</p>
      </>
    )}
  </Section>
);

export const InterGraderSummary: React.FC<{ data: InterGraderAnalytics }> = ({ data }) => (
  <Section testId="inter-grader-summary" title="Inter-Grader Consistency" subtitle={data.indicator_name}>
    {!data.available ? <SectionEmpty title="Not available" description={data.message} /> : null}
  </Section>
);

export const AiEvaluationSummary: React.FC<{ data: AiEvaluationAnalytics }> = ({ data }) => {
  const [task, setTask] = useState<string>('');
  const trend = useMemo(() => data.trend.filter((t) => !task || t.task === task).map((t) => ({ label: `Run #${t.run_id}`, date: t.completed_at ? t.completed_at.slice(0, 10) : null, value: t.value, note: t.metric ?? undefined })), [data.trend, task]);
  const isRate = (m: string | null) => !!m && /f1|rate|accuracy/.test(m);
  return (
    <Section testId="ai-evaluation-summary" title="AI Evaluation" explanation={data.explanation} actions={<Badge variant={statusVariant(data.overall_status === 'NOT_EVALUATED' ? null : data.overall_status)} size="sm">{humanize(data.overall_status)}</Badge>}>
      {data.evaluated_tasks === 0 ? <SectionEmpty title="No AI evaluation has been completed yet." description="Run an evaluation from the AI Evaluation page to see headline metrics here." /> : null}
      <table className="w-full text-sm"><caption className="sr-only">AI evaluation headline metrics by task</caption>
        <tbody>{data.tasks.map((t) => (
          <tr key={t.task} className="border-t border-sage-100 dark:border-[#2A2A2A]">
            <td className="py-1 pr-3">{TASK_LABELS[t.task as EvaluationTask] ?? humanize(t.task)}</td>
            <td className="py-1 pr-3 text-xs text-sage-500">{t.headline_metric ? humanize(t.headline_metric) : ''}</td>
            <td className="py-1 pr-3 text-right tabular-nums">{t.evaluated && t.headline_value !== null ? (isRate(t.headline_metric) ? fmtPct(t.headline_value * 100) : fmtNum(t.headline_value, 3)) : <span className="text-xs text-sage-400">Not evaluated yet</span>}</td>
            <td className="py-1">{t.evaluated && <StatusBadge status={t.gate_status} />}{t.regression && <Badge variant="Critical" size="sm">Regression</Badge>}</td>
          </tr>
        ))}</tbody>
      </table>
      {data.trend.length > 0 && (
        <div className="space-y-1">
          <div className="flex items-center gap-2"><label htmlFor="ai-trend-task" className="text-xs text-sage-500">Trend task</label>
            <select id="ai-trend-task" value={task} onChange={(e) => setTask(e.target.value)} className="rounded-md border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-2 py-1 text-xs"><option value="">All tasks</option>{[...new Set(data.trend.map((t) => t.task))].map((t) => <option key={t} value={t}>{TASK_LABELS[t as EvaluationTask] ?? t}</option>)}</select></div>
          <LineChart points={trend} max={1} ariaLabel="AI model performance across evaluation runs" />
          <Link to="/ai-evaluation" className="text-xs underline underline-offset-2">Open AI Evaluation</Link>
        </div>
      )}
    </Section>
  );
};

export const RecommendationSummary: React.FC<{ data: RecData }> = ({ data }) => (
  <Section testId="recommendation-summary" title="Recommendations">
    {data.total === 0 ? <SectionEmpty title="No recommendations yet" description="Recommendations are generated when an assessment is analyzed." /> : (
      <>
        <dl className="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
          <Stat label="Active" value={String(data.active)} hint={`high ${data.active_by_priority.high} · medium ${data.active_by_priority.medium} · low ${data.active_by_priority.low}`} /><Stat label="Accepted" value={String(data.accepted)} /><Stat label="Dismissed" value={String(data.dismissed)} /><Stat label="Under review" value={String(data.under_review)} />
        </dl>
        <div data-testid="feedback-signal">
          <p className="text-xs font-medium text-sage-800 dark:text-white">{data.feedback.label} <span className="text-sage-500 font-normal">— {data.feedback.explanation}</span></p>
          {data.feedback.total === 0 ? <p className="text-xs text-sage-500">No faculty feedback recorded yet.</p> : (
            <p className="text-xs text-sage-500">Accepted {fmtPct(data.feedback.accepted_percent)} · Dismissed {fmtPct(data.feedback.dismissed_percent)} · Needs review {fmtPct(data.feedback.needs_review_percent)} · Avg usefulness {fmtNum(data.feedback.average_usefulness, 2)}/5 ({data.feedback.total} responses)</p>
          )}
        </div>
      </>
    )}
  </Section>
);

export const CollaborationSummary: React.FC<{ data: CollabData }> = ({ data }) => {
  const families = Object.keys(data.activity.totals);
  return (
    <Section testId="collaboration-summary" title="Collaboration" subtitle={`Activity over the last ${data.activity.days} days (audit log)`}>
      <dl className="grid grid-cols-2 md:grid-cols-5 gap-2 text-sm">
        <Stat label="Shared courses" value={String(data.shared_courses)} /><Stat label="Active collaborators" value={String(data.active_collaborators)} /><Stat label="Open reviews" value={String(data.open_reviews)} hint="Generated question drafts" /><Stat label="Unresolved discussions" value={String(data.open_discussions)} /><Stat label="Resolved discussions" value={String(data.resolved_discussions)} />
      </dl>
      {families.some((f) => data.activity.totals[f] > 0) ? (
        <Bars ariaLabel="Collaboration activity by type" max={Math.max(1, ...families.map((f) => data.activity.totals[f]))} rows={families.map((f) => ({ label: humanize(f), value: data.activity.totals[f], display: String(data.activity.totals[f]) }))} />
      ) : <p className="text-xs text-sage-500">No collaboration activity recorded in this period.</p>}
    </Section>
  );
};

/** STEP 37: blueprint compliance card (links back to each assessment's blueprint). */
export const BlueprintComplianceSummary: React.FC<{ data: BlueprintComplianceAnalytics | undefined }> = ({ data }) => (
  <Section testId="blueprint-compliance" title="Assessment Blueprint Compliance" subtitle="Actual question sets compared with each assessment's current blueprint (STEP 37).">
    {!data || data.assessments_with_blueprint === 0 ? <SectionEmpty title="No blueprints yet" description="Create an assessment blueprint to plan and validate structure before generating or selecting questions." /> : (
      <>
        <p className="text-sm">Average compliance <span className="font-semibold tabular-nums">{fmtPct(data.average_compliance)}</span> across {data.assessments_with_blueprint} assessment(s)</p>
        <ul className="space-y-2 text-sm">{data.rows.map((r) => (
          <li key={r.blueprint_id} className="border-t border-sage-100 dark:border-[#2A2A2A] pt-1">
            <div className="flex flex-wrap items-center gap-2"><Link to={`/assessments/${r.assessment_id}/blueprint`} className="font-medium underline underline-offset-2">{r.assessment_title}</Link><span className="text-xs text-sage-500">v{r.version} · {humanize(r.status)}</span>
              <span className="tabular-nums font-semibold">{r.compliance_percent === null ? (r.has_questions ? 'N/A' : 'No questions yet') : fmtPct(r.compliance_percent)}</span></div>
            <ul className="flex flex-wrap gap-1 mt-1">{Object.entries(r.summary).map(([k, v]) => <li key={k}><Badge variant={v === 'MATCH' ? 'Good' : v === 'CLOSE' ? 'Attention' : v === 'MISMATCH' ? 'Critical' : 'neutral'} size="sm">{humanize(k)} {v === 'MATCH' ? '✓' : v === 'CLOSE' ? '~' : v === 'MISMATCH' ? '⚠' : '—'}</Badge></li>)}</ul>
          </li>
        ))}</ul>
      </>
    )}
  </Section>
);
