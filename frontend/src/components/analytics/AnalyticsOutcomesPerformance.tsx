import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import {
  AssessmentComparison, AssessmentRow, CourseHistory, LearningGapSummary as GapData, OutcomeCoverage, PerformanceAnalytics, ProgramOutcomeCoverage as PoData, QuestionPerformance, TopicPerformance, TrendPeriod,
} from '@/types/analytics';
import { Bars, LineChart, PeriodPicker, Section, SectionEmpty, StatusBadge, fmtNum, fmtPct, humanize, periodStart } from './AnalyticsStates';

/** STEP 36: outcome coverage, student performance, learning gaps, question/topic tables, comparison and history. */

export const LearningOutcomeCoverage: React.FC<{ data: OutcomeCoverage }> = ({ data }) => (
  <Section testId="lo-coverage" title="Learning Outcome Coverage" explanation={data.explanation} subtitle={data.total_outcomes ? `${data.covered_outcomes}/${data.total_outcomes} outcomes covered (${fmtPct(data.coverage_percentage)}) from ${data.analyzed_assessments} analyzed assessment(s)` : undefined}>
    {data.total_outcomes === 0 ? <SectionEmpty title="No learning outcomes defined" description="Add course outcomes and run an assessment analysis to see coverage." /> : (
      <>
        <Bars ariaLabel="Learning outcome coverage percentage" rows={data.outcomes.map((o) => ({ label: o.code, value: o.coverage_percentage, tone: o.status === 'COVERED' ? 'good' : o.status === 'WEAK' ? 'warn' : o.status === 'NOT_ALIGNED' ? 'bad' : 'default', display: o.coverage_percentage === null ? 'Not assessed' : undefined }))} />
        <div className="overflow-x-auto">
          <table className="w-full text-sm"><caption className="sr-only">Learning outcome alignment details</caption>
            <thead><tr className="text-left text-xs uppercase text-[#737373]"><th className="py-1 pr-3">Outcome</th><th className="py-1 pr-3 text-right">Questions</th><th className="py-1 pr-3 text-right">Strong</th><th className="py-1 pr-3 text-right">Weak</th><th className="py-1 pr-3 text-right">Not aligned</th><th className="py-1 pr-3 text-right">Coverage</th><th className="py-1">Status</th></tr></thead>
            <tbody>{data.outcomes.map((o) => (
              <tr key={o.learning_outcome_id} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A]">
                <td className="py-1 pr-3"><span className="font-medium">{o.code}</span>{o.course_code && <span className="text-xs text-[#A3A3A3]"> · {o.course_code}</span>}<p className="text-xs text-[#737373] truncate max-w-xs" title={o.description}>{o.description}</p></td>
                <td className="py-1 pr-3 text-right tabular-nums">{o.questions}</td><td className="py-1 pr-3 text-right tabular-nums">{o.strong}</td><td className="py-1 pr-3 text-right tabular-nums">{o.weak}</td><td className="py-1 pr-3 text-right tabular-nums">{o.not_aligned}</td>
                <td className="py-1 pr-3 text-right tabular-nums">{fmtPct(o.coverage_percentage)}</td><td className="py-1"><StatusBadge status={o.status} /></td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      </>
    )}
  </Section>
);

export const ProgramOutcomeCoverage: React.FC<{ data: PoData }> = ({ data }) => (
  <Section testId="po-coverage" title="Program Outcome Coverage" subtitle={data.configured && data.analyzed_courses !== undefined ? `${data.analyzed_courses} course(s) analyzed${data.unanalyzed_courses ? `, ${data.unanalyzed_courses} pending` : ''}` : undefined}>
    {!data.configured ? <SectionEmpty title="PO analysis is not configured for this course." description="Link the course to a program and map course outcomes to program outcomes (CO/PO mapping) to see PO coverage." /> : data.program_outcomes.length === 0 ? (
      <SectionEmpty title="No CO/PO analysis yet" description={data.message ?? 'Run the CO/PO mapping validator to see program-outcome evidence.'} />
    ) : (
      <div className="overflow-x-auto">
        <table className="w-full text-sm"><caption className="sr-only">Program outcome evidence</caption>
          <thead><tr className="text-left text-xs uppercase text-[#737373]"><th className="py-1 pr-3">PO</th><th className="py-1 pr-3 text-right">Mapped COs</th><th className="py-1 pr-3 text-right">Strong</th><th className="py-1 pr-3 text-right">Weak</th><th className="py-1 pr-3 text-right">Questions</th><th className="py-1 pr-3 text-right">Evidence</th><th className="py-1">Status</th></tr></thead>
          <tbody>{data.program_outcomes.map((p) => (
            <tr key={p.code} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A]">
              <td className="py-1 pr-3"><span className="font-medium">{p.code}</span><p className="text-xs text-[#737373] truncate max-w-xs" title={p.title}>{p.title}</p></td>
              <td className="py-1 pr-3 text-right tabular-nums">{p.mapped_cos}</td><td className="py-1 pr-3 text-right tabular-nums">{p.strong_mappings}</td><td className="py-1 pr-3 text-right tabular-nums">{p.weak_mappings}</td><td className="py-1 pr-3 text-right tabular-nums">{p.mapped_questions}</td>
              <td className="py-1 pr-3 text-right tabular-nums">{fmtPct(p.evidence_percent)}</td><td className="py-1"><StatusBadge status={p.status} /></td>
            </tr>
          ))}</tbody>
        </table>
        {data.unmapped_questions ? <p className="text-xs text-[#737373] mt-1">{data.unmapped_questions} question(s) have no confirmed CO mapping.</p> : null}
      </div>
    )}
  </Section>
);

export const StudentPerformanceCard: React.FC<{ data: PerformanceAnalytics; restricted: boolean }> = ({ data, restricted }) => (
  <Section testId="student-performance" title="Student Performance" explanation={data.explanation} actions={data.available ? <StatusBadge status={data.status} /> : undefined}>
    {restricted ? <SectionEmpty title="Restricted for your role" description="Student-level performance is available to course owners and editors only." /> : !data.available ? (
      <SectionEmpty title="No finalized grades are available yet." description="Performance analytics will appear after faculty grades are finalized." />
    ) : (
      <>
        <dl className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2 text-sm">
          {([['Average', fmtPct(data.average_percentage)], ['Median', fmtPct(data.median_percentage)], ['Lowest', fmtPct(data.minimum_percentage)], ['Highest', fmtPct(data.maximum_percentage)], ['Submissions', String(data.submissions)], ['Responses', String(data.responses)]] as const).map(([l, v]) => (
            <div key={l} className="rounded-md border border-[#F0F0F0] dark:border-[#2A2A2A] px-2 py-1"><dt className="text-xs text-[#737373]">{l}</dt><dd className="font-semibold tabular-nums">{v}</dd></div>
          ))}
        </dl>
        <p className="text-xs text-[#737373]">Benchmark {data.benchmark_percent}% · gap {data.gap === null ? 'N/A' : `${data.gap}%`} · status {humanize(data.status)}{data.status === 'INSUFFICIENT_DATA' ? ' (fewer responses than the configured minimum — not classified as a gap)' : ''}.</p>
        {data.assessments_without_analysis > 0 && <p className="text-xs text-amber-800 dark:text-amber-300">{data.assessments_without_analysis} assessment(s) have finalized grades but no performance analysis run yet.</p>}
      </>
    )}
  </Section>
);

export const PerformanceTrendChart: React.FC<{ data: PerformanceAnalytics }> = ({ data }) => {
  const [period, setPeriod] = useState<TrendPeriod>('ALL');
  const points = useMemo(() => {
    const start = periodStart(period);
    return data.trend.filter((p) => !start || (p.date && new Date(p.date) >= start)).map((p) => ({ label: p.title, date: p.date, value: p.average_percentage, note: p.sufficient ? undefined : `insufficient responses (${p.responses})` }));
  }, [data.trend, period]);
  return (
    <Section testId="performance-trend" title="Student Performance Trend" actions={<PeriodPicker value={period} onChange={setPeriod} label="Performance trend period" />}>
      {data.trend.length === 0 ? <SectionEmpty title="No performance trend yet" description="Run a student performance analysis on graded assessments to see the trend." /> : <LineChart points={points} unit="%" ariaLabel="Average student performance per assessment" benchmark={data.benchmark_percent} />}
    </Section>
  );
};

const GAP_ORDER: Array<keyof GapData['counts']> = ['STRONG', 'ON_TARGET', 'MINOR_GAP', 'MODERATE_GAP', 'HIGH_GAP', 'INSUFFICIENT_DATA'];

export const LearningGapSummary: React.FC<{ data: GapData }> = ({ data }) => (
  <Section testId="learning-gaps" title="Learning Gaps" explanation={data.explanation} subtitle={data.analyzed_assessments ? `${data.open_gaps} open gap(s) across ${data.analyzed_assessments} performance analysis run(s)` : undefined}>
    {data.analyzed_assessments === 0 ? <SectionEmpty title="No performance analysis yet" description="Learning gaps appear after a student performance analysis has been run." /> : (
      <>
        <dl className="grid grid-cols-3 md:grid-cols-6 gap-2 text-sm">
          {GAP_ORDER.map((s) => <div key={s} className="rounded-md border border-[#F0F0F0] dark:border-[#2A2A2A] px-2 py-1"><dt className="text-xs"><StatusBadge status={s} /></dt><dd className="font-semibold tabular-nums">{data.counts[s] ?? 0}</dd></div>)}
        </dl>
        {data.top_gaps.length > 0 && (
          <ol className="space-y-1 text-sm" aria-label="Top learning gaps">
            {data.top_gaps.map((g, i) => (
              <li key={`${g.code}-${g.assessment_id}`} className="flex flex-wrap items-baseline gap-x-2 border-t border-[#F0F0F0] dark:border-[#2A2A2A] pt-1">
                <span className="font-medium">{i + 1}. {g.code}</span><span className="text-xs text-[#737373]">{g.description}</span>
                <span className="text-xs tabular-nums">Performance {g.average_percentage}% · Benchmark {g.benchmark_percent}% · Gap {g.gap}% · {g.responses} responses</span><StatusBadge status={g.status} />
                <Link to={`/assessments/${g.assessment_id}`} className="text-xs underline underline-offset-2">{g.assessment_title}</Link>
              </li>
            ))}
          </ol>
        )}
      </>
    )}
  </Section>
);

type QSort = 'worst' | 'gap' | 'number' | 'co' | 'topic';
export const QuestionPerformanceTable: React.FC<{ rows: QuestionPerformance[]; topicFilter?: string | null; onClearTopic?: () => void }> = ({ rows, topicFilter, onClearTopic }) => {
  const [sort, setSort] = useState<QSort>('worst');
  const sorted = useMemo(() => {
    const r = rows.filter((q) => !topicFilter || q.topics.includes(topicFilter));
    const key: Record<QSort, (a: QuestionPerformance, b: QuestionPerformance) => number> = {
      worst: (a, b) => (a.average_percentage ?? 999) - (b.average_percentage ?? 999), gap: (a, b) => (b.gap ?? -999) - (a.gap ?? -999),
      number: (a, b) => a.assessment_id - b.assessment_id || a.question_number - b.question_number, co: (a, b) => (a.co ?? '').localeCompare(b.co ?? ''), topic: (a, b) => (a.topics[0] ?? '').localeCompare(b.topics[0] ?? ''),
    };
    return [...r].sort(key[sort]);
  }, [rows, sort, topicFilter]);
  return (
    <Section testId="question-performance" title="Question Performance" subtitle={topicFilter ? `Topic: ${topicFilter}` : undefined}
      actions={<div className="flex items-center gap-2"><label htmlFor="qp-sort" className="text-xs text-[#737373]">Sort</label>
        <select id="qp-sort" value={sort} onChange={(e) => setSort(e.target.value as QSort)} className="rounded-md border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-2 py-1 text-xs">
          <option value="worst">Worst performance</option><option value="gap">Highest gap</option><option value="number">Question number</option><option value="topic">Topic</option><option value="co">CO</option></select>
        {topicFilter && onClearTopic && <Button size="sm" variant="ghost" onClick={onClearTopic}>Clear topic</Button>}</div>}>
      {sorted.length === 0 ? <SectionEmpty title="No question-level performance" description="Question performance appears after a student performance analysis has been run." /> : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm"><caption className="sr-only">Question-level performance from finalized grades</caption>
            <thead><tr className="text-left text-xs uppercase text-[#737373]"><th className="py-1 pr-3">Question</th><th className="py-1 pr-3">Topic</th><th className="py-1 pr-3">CO</th><th className="py-1 pr-3 text-right">Average</th><th className="py-1 pr-3 text-right">Responses</th><th className="py-1 pr-3 text-right">Gap</th><th className="py-1">Status</th></tr></thead>
            <tbody>{sorted.map((q) => (
              <tr key={`${q.assessment_id}-${q.question_number}`} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A]">
                <td className="py-1 pr-3"><span className="font-medium">Q{q.question_number}</span><span className="text-xs text-[#A3A3A3]"> · {q.assessment_title}</span>{q.excerpt && <p className="text-xs text-[#737373] truncate max-w-xs" title={q.excerpt}>{q.excerpt}</p>}</td>
                <td className="py-1 pr-3 text-xs">{q.topics.join(', ') || '—'}</td><td className="py-1 pr-3 text-xs">{q.co ?? '—'}</td>
                <td className="py-1 pr-3 text-right tabular-nums">{fmtPct(q.average_percentage)}</td><td className="py-1 pr-3 text-right tabular-nums">{q.responses}</td><td className="py-1 pr-3 text-right tabular-nums">{q.gap === null ? 'N/A' : `${q.gap}%`}</td><td className="py-1"><StatusBadge status={q.status} /></td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      )}
    </Section>
  );
};

export const TopicPerformanceTable: React.FC<{ rows: TopicPerformance[]; onSelect?: (topic: string) => void; selected?: string | null }> = ({ rows, onSelect, selected }) => (
  <Section testId="topic-performance" title="Topic Performance" subtitle="Finalized grades aggregated by detected topic (response-weighted).">
    {rows.length === 0 ? <SectionEmpty title="No topic-level performance" description="Topics appear after question analysis and a student performance analysis have been run." /> : (
      <div className="overflow-x-auto">
        <table className="w-full text-sm"><caption className="sr-only">Topic performance</caption>
          <thead><tr className="text-left text-xs uppercase text-[#737373]"><th className="py-1 pr-3">Topic</th><th className="py-1 pr-3 text-right">Questions</th><th className="py-1 pr-3 text-right">Average</th><th className="py-1 pr-3 text-right">Responses</th><th className="py-1 pr-3 text-right">Gap</th><th className="py-1">Status</th></tr></thead>
          <tbody>{rows.map((t) => (
            <tr key={t.topic} className={`border-t border-[#F0F0F0] dark:border-[#2A2A2A] ${selected === t.topic ? 'bg-[#FAFAF8] dark:bg-[#1A1A1A]' : ''}`}>
              <td className="py-1 pr-3">{onSelect ? <button type="button" className="font-medium underline underline-offset-2 text-left" onClick={() => onSelect(t.topic)} aria-pressed={selected === t.topic}>{t.topic}</button> : t.topic}</td>
              <td className="py-1 pr-3 text-right tabular-nums">{t.questions}</td><td className="py-1 pr-3 text-right tabular-nums">{fmtPct(t.average_percentage)}</td><td className="py-1 pr-3 text-right tabular-nums">{t.responses}</td><td className="py-1 pr-3 text-right tabular-nums">{t.gap === null ? 'N/A' : `${t.gap}%`}</td><td className="py-1"><StatusBadge status={t.status} /></td>
            </tr>
          ))}</tbody>
        </table>
      </div>
    )}
  </Section>
);

export const AssessmentComparisonPanel: React.FC<{ assessments: AssessmentRow[]; comparison: AssessmentComparison | null; selected: number[]; onToggle: (id: number) => void; onCompare: () => void; loading?: boolean; onClear: () => void }> = ({ assessments, comparison, selected, onToggle, onCompare, loading, onClear }) => {
  const metric = (label: string, get: (a: AssessmentComparison['assessments'][number]) => string) => comparison && (
    <tr key={label} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A]"><th scope="row" className="py-1 pr-3 text-left font-medium">{label}</th>{comparison.assessments.map((a) => <td key={a.assessment_id} className="py-1 pr-3 tabular-nums">{get(a)}</td>)}</tr>
  );
  return (
    <Section testId="assessment-comparison" title="Compare Assessments" subtitle="Select two or more assessments in scope." actions={<div className="flex gap-2"><Button size="sm" onClick={onCompare} disabled={selected.length < 2 || loading}>{loading ? 'Comparing…' : 'Compare'}</Button>{comparison && <Button size="sm" variant="ghost" onClick={onClear}>Clear</Button>}</div>}>
      {assessments.length === 0 ? <SectionEmpty title="No assessments in scope" description="Adjust the filters to include assessments." /> : (
        <div className="flex flex-wrap gap-2" role="group" aria-label="Assessments to compare">
          {assessments.map((a) => <label key={a.assessment_id} className="inline-flex items-center gap-1 text-xs border border-[#E5E5E5] dark:border-[#2A2A2A] rounded px-2 py-1"><input type="checkbox" checked={selected.includes(a.assessment_id)} onChange={() => onToggle(a.assessment_id)} disabled={!selected.includes(a.assessment_id) && selected.length >= 6} aria-label={`Select ${a.title} for comparison`} />{a.title}{a.course && <span className="text-[#A3A3A3]"> · {a.course.code}</span>}</label>)}
        </div>
      )}
      {comparison && (
        <div className="overflow-x-auto">
          <table className="w-full text-sm"><caption className="sr-only">Assessment comparison</caption>
            <thead><tr className="text-left text-xs uppercase text-[#737373]"><th className="py-1 pr-3">Metric</th>{comparison.assessments.map((a) => <th key={a.assessment_id} className="py-1 pr-3">{a.title}</th>)}</tr></thead>
            <tbody>
              {metric('Quality', (a) => a.quality_score === null ? 'N/A' : `${fmtNum(a.quality_score)} (${humanize(a.quality_rating)})`)}
              {metric('Difficulty balance', (a) => humanize(a.difficulty.balance_status) || 'N/A')}
              {metric('Bloom levels present', (a) => `${a.cognitive.distinct_levels}/6`)}
              {metric('CO coverage', (a) => fmtPct(a.learning_outcomes.coverage_percentage))}
              {metric('Avg performance', (a) => a.performance.restricted ? 'Restricted' : a.performance.available ? fmtPct(a.performance.average_percentage ?? null) : 'No finalized grades')}
              {metric('High gaps', (a) => a.learning_gaps ? String(a.learning_gaps.counts.HIGH_GAP ?? 0) : 'N/A')}
              {metric('Potential duplicates', (a) => String(a.similarity.POTENTIAL_DUPLICATE?.questions ?? 0))}
              {metric('Questions', (a) => String(a.questions))}
            </tbody>
          </table>
          <p className="text-xs text-[#737373] mt-1">{comparison.note}</p>
        </div>
      )}
    </Section>
  );
};

export const CourseHistoryTable: React.FC<{ history: CourseHistory | null; loading?: boolean; error?: string | null; onRetry?: () => void }> = ({ history, loading, error, onRetry }) => (
  <Section testId="course-history" title="Historical Course Analytics" subtitle={history ? `${history.course_code} — ${history.course_name} across ${history.course_ids.length} term(s)` : 'Select a course filter to see term-over-term trends.'}>
    {loading && <p className="text-sm text-[#737373]">Loading history…</p>}
    {error && <p role="alert" className="text-sm text-red-700 dark:text-red-300">{error} {onRetry && <button type="button" className="underline" onClick={onRetry}>Retry</button>}</p>}
    {history && history.terms.length === 0 && <SectionEmpty title="No historical records" description="Only actual analyzed assessments are shown here." />}
    {history && history.terms.length > 0 && (
      <table className="w-full text-sm"><caption className="sr-only">Course analytics by term</caption>
        <thead><tr className="text-left text-xs uppercase text-[#737373]"><th className="py-1 pr-3">Term</th><th className="py-1 pr-3 text-right">Assessments</th><th className="py-1 pr-3 text-right">Quality</th><th className="py-1 pr-3 text-right">LO alignment</th><th className="py-1 pr-3 text-right">Performance</th></tr></thead>
        <tbody>{history.terms.map((t) => <tr key={t.term} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A]"><td className="py-1 pr-3 font-medium">{t.term}</td><td className="py-1 pr-3 text-right tabular-nums">{t.assessments}</td><td className="py-1 pr-3 text-right tabular-nums">{fmtNum(t.average_quality)}<span className="text-xs text-[#A3A3A3]"> ({t.analyzed_assessments})</span></td><td className="py-1 pr-3 text-right tabular-nums">{fmtNum(t.average_lo_alignment)}</td><td className="py-1 pr-3 text-right tabular-nums">{fmtPct(t.average_performance)}<span className="text-xs text-[#A3A3A3]"> ({t.performance_assessments})</span></td></tr>)}</tbody>
      </table>
    )}
  </Section>
);

export const AssessmentTable: React.FC<{ rows: AssessmentRow[] }> = ({ rows }) => (
  <Section testId="assessment-table" title="Assessments in Scope">
    {rows.length === 0 ? <SectionEmpty title="No assessments in scope" description="Adjust the filters or create an assessment." /> : (
      <div className="overflow-x-auto">
        <table className="w-full text-sm"><caption className="sr-only">Assessments with headline analytics</caption>
          <thead><tr className="text-left text-xs uppercase text-[#737373]"><th className="py-1 pr-3">Assessment</th><th className="py-1 pr-3">Date</th><th className="py-1 pr-3 text-right">Questions</th><th className="py-1 pr-3 text-right">Quality</th><th className="py-1 pr-3 text-right">Performance</th><th className="py-1 pr-3 text-right">Similar</th></tr></thead>
          <tbody>{rows.map((a) => (
            <tr key={a.assessment_id} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A]">
              <td className="py-1 pr-3"><Link to={`/assessments/${a.assessment_id}`} className="font-medium underline underline-offset-2">{a.title}</Link><span className="text-xs text-[#A3A3A3]"> · {a.course?.code} · {humanize(a.type)}</span></td>
              <td className="py-1 pr-3 text-xs">{a.date ?? 'No date'}</td><td className="py-1 pr-3 text-right tabular-nums">{a.questions}</td>
              <td className="py-1 pr-3 text-right tabular-nums">{a.quality_score === null ? <span className="text-xs text-[#A3A3A3]">Not analyzed</span> : <>{fmtNum(a.quality_score)} <Badge variant={a.quality_rating ? (a.quality_rating === 'EXCELLENT' || a.quality_rating === 'GOOD' ? 'Good' : a.quality_rating === 'FAIR' ? 'Attention' : 'Critical') : 'neutral'} size="sm">{humanize(a.quality_rating)}</Badge></>}</td>
              <td className="py-1 pr-3 text-right tabular-nums">{a.performance_percentage === null ? <span className="text-xs text-[#A3A3A3]">N/A</span> : fmtPct(a.performance_percentage)}</td>
              <td className="py-1 pr-3 text-right tabular-nums">{a.similar_questions ?? '—'}</td>
            </tr>
          ))}</tbody>
        </table>
      </div>
    )}
  </Section>
);
