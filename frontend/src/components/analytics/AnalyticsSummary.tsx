import React, { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertOctagon, AlertTriangle, Info } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { AnalyticsKpis, AssessmentQualityAnalytics, AttentionArea, CognitiveDistribution as CognitiveData, DifficultyDistribution as DifficultyData, Kpi, TrendPeriod } from '@/types/analytics';
import { Bars, Explain, LineChart, PeriodPicker, Section, SectionEmpty, StatusBadge, fmtNum, fmtPct, humanize, periodStart } from './AnalyticsStates';

/** STEP 36: KPI grid, attention areas, assessment quality, difficulty and Bloom distributions. */

const kpiValue = (k: Kpi): string => {
  if (k.value === null || k.value === undefined) return 'N/A';
  if (k.unit === 'percent') return fmtPct(k.value);
  if (k.unit === 'score') return fmtNum(k.value, 1);
  return String(k.value);
};

export const KPIGrid: React.FC<{ kpis: AnalyticsKpis }> = ({ kpis }) => (
  <dl data-testid="kpi-grid" className="grid grid-cols-2 md:grid-cols-4 gap-3">
    {(Object.keys(kpis) as Array<keyof AnalyticsKpis>).map((key) => {
      const k = kpis[key];
      return (
        <div key={key} data-testid={`kpi-${key}`} className="rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-4 py-3">
          <dt className="text-xs uppercase tracking-wide text-[#737373] inline-flex items-center">{k.label}<Explain text={k.explanation} /></dt>
          <dd className="text-2xl font-semibold text-[#111111] dark:text-white mt-1 tabular-nums">{kpiValue(k)}</dd>
          {k.basis && <p className="text-xs text-[#A3A3A3] mt-0.5">{k.basis}</p>}
        </div>
      );
    })}
  </dl>
);

export const AnalyticsSummary: React.FC<{ kpis: AnalyticsKpis; disclaimer: string }> = ({ kpis, disclaimer }) => (
  <section data-testid="analytics-summary" aria-label="Summary" className="space-y-2">
    <KPIGrid kpis={kpis} />
    <p className="text-xs text-[#737373]">{disclaimer}</p>
  </section>
);

const severityIcon = (s: AttentionArea['severity']) => (s === 'HIGH' ? <AlertOctagon className="w-4 h-4 text-[#DC2626]" aria-hidden="true" /> : s === 'MEDIUM' ? <AlertTriangle className="w-4 h-4 text-amber-500" aria-hidden="true" /> : <Info className="w-4 h-4 text-[#737373]" aria-hidden="true" />);

export const AttentionAreas: React.FC<{ items: AttentionArea[] }> = ({ items }) => (
  <Section testId="attention-areas" title="Areas Needing Attention" subtitle="Prioritised signals from existing analyses — not automatic decisions.">
    {items.length === 0 ? <SectionEmpty title="No attention signals" description="Nothing in the current scope crosses the configured gap, quality, coverage or similarity thresholds." /> : (
      <ol className="space-y-2">
        {items.map((a, i) => (
          <li key={i} className="flex items-start gap-2 text-sm">
            {severityIcon(a.severity)}
            <div className="flex-1">
              <p className="font-medium text-[#111111] dark:text-white">{a.title} <span className="sr-only">({a.severity.toLowerCase()} severity)</span></p>
              <p className="text-xs text-[#737373]">{a.detail}</p>
            </div>
            {a.link?.type === 'assessment' && <Link to={`/assessments/${a.link.id}`} className="text-xs underline underline-offset-2 whitespace-nowrap">View</Link>}
            {a.link?.type === 'course' && <Link to={`/courses/${a.link.id}`} className="text-xs underline underline-offset-2 whitespace-nowrap">View</Link>}
          </li>
        ))}
      </ol>
    )}
  </Section>
);

const RATING_ORDER: Array<keyof AssessmentQualityAnalytics['counts']> = ['EXCELLENT', 'GOOD', 'FAIR', 'NEEDS_REVIEW', 'REQUIRES_ATTENTION'];

export const AssessmentQualityCard: React.FC<{ data: AssessmentQualityAnalytics }> = ({ data }) => (
  <Section testId="assessment-quality" title="Assessment Quality" explanation={data.explanation} subtitle={data.analyzed_assessments ? `${data.analyzed_assessments} analyzed · average ${fmtNum(data.average_score)}` : undefined}>
    {data.analyzed_assessments === 0 ? <SectionEmpty title="No assessment data available." description="Create and analyze an assessment to see academic quality analytics here." /> : (
      <table className="w-full text-sm"><caption className="sr-only">Assessments by STEP 13 quality rating</caption>
        <tbody>{RATING_ORDER.map((r) => <tr key={r} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A]"><td className="py-1"><StatusBadge status={r} /></td><td className="py-1 text-right tabular-nums">{data.counts[r] ?? 0}</td></tr>)}</tbody>
      </table>
    )}
  </Section>
);

export const QualityTrendChart: React.FC<{ data: AssessmentQualityAnalytics }> = ({ data }) => {
  const [period, setPeriod] = useState<TrendPeriod>('ALL');
  const points = useMemo(() => {
    const start = periodStart(period);
    return data.trend.filter((p) => !start || (p.date && new Date(p.date) >= start)).map((p) => ({ label: p.title, date: p.date, value: p.score, note: p.date_source === 'analyzed_at' ? 'analysis date (no assessment date)' : undefined }));
  }, [data.trend, period]);
  return (
    <Section testId="quality-trend" title="Assessment Quality Trend" actions={<PeriodPicker value={period} onChange={setPeriod} label="Quality trend period" />}>
      {data.trend.length === 0 ? <SectionEmpty title="No quality trend yet" description="Quality scores appear here after assessments are analyzed." /> : <LineChart points={points} ariaLabel="Assessment quality score over time" />}
    </Section>
  );
};

export const DifficultyDistribution: React.FC<{ data: DifficultyData }> = ({ data }) => (
  <Section testId="difficulty-distribution" title="Difficulty Distribution" explanation={data.explanation}
    actions={data.balance_status ? <Badge variant={data.balance_status === 'BALANCED' ? 'Good' : data.balance_status === 'SLIGHTLY_UNBALANCED' ? 'Attention' : 'Critical'} size="sm">{humanize(data.balance_status)}</Badge> : undefined}>
    {data.total_questions === 0 ? <SectionEmpty title="No questions in scope" description="Add questions to an assessment to see the difficulty profile." /> : (
      <>
        <Bars ariaLabel="Difficulty distribution versus target" rows={data.distribution.map((d) => ({ label: humanize(d.level), value: d.percentage, target: d.target_percentage, count: d.count, tone: d.level === 'easy' ? 'good' : d.level === 'hard' ? 'bad' : 'default' }))} />
        <table className="w-full text-xs"><caption className="sr-only">Difficulty actual versus target</caption>
          <thead><tr className="text-left text-[#737373]"><th className="py-0.5">Level</th><th className="py-0.5 text-right">Actual</th><th className="py-0.5 text-right">Target</th><th className="py-0.5 text-right">Difference</th></tr></thead>
          <tbody>{data.distribution.map((d) => <tr key={d.level} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A]"><td className="py-0.5">{humanize(d.level)}</td><td className="py-0.5 text-right tabular-nums">{fmtPct(d.percentage)}</td><td className="py-0.5 text-right tabular-nums">{d.target_percentage}%</td><td className="py-0.5 text-right tabular-nums">{d.difference === null ? 'N/A' : `${d.difference > 0 ? '+' : ''}${d.difference}%`}</td></tr>)}</tbody>
        </table>
        {data.unclassified > 0 && <p className="text-xs text-[#737373]">{data.unclassified} question(s) have no difficulty level yet.</p>}
        {data.total_deviation !== null && <p className="text-xs text-[#737373]">Total deviation from target {data.total_deviation}% (slight &gt; {data.bands.slight_deviation}%, significant &gt; {data.bands.significant_deviation}%).</p>}
      </>
    )}
  </Section>
);

export const CognitiveDistribution: React.FC<{ data: CognitiveData }> = ({ data }) => (
  <Section testId="cognitive-distribution" title="Cognitive Level Distribution" explanation={data.explanation} subtitle={data.total_questions ? `${data.distinct_levels} of 6 Bloom levels present` : undefined}>
    {data.total_questions === 0 ? <SectionEmpty title="No questions in scope" description="Add questions to an assessment to see the Bloom profile." /> : (
      <>
        <Bars ariaLabel="Bloom level distribution" rows={data.distribution.map((d) => ({ label: d.level, value: d.percentage, count: d.count }))} />
        {data.unclassified > 0 && <p className="text-xs text-[#737373]">{data.unclassified} question(s) have no cognitive level yet.</p>}
      </>
    )}
  </Section>
);
