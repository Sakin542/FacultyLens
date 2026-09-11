import React, { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { academicAnalyticsService } from '@/services/academicAnalyticsService';
import { AnalyticsFilters as Filters, AnalyticsOverview, AssessmentComparison, CourseHistory, FilterOptions } from '@/types/analytics';
import { AnalyticsEmptyState, AnalyticsError, AnalyticsFilters, AnalyticsHeader, AnalyticsLoading, getAnalyticsErrorMessage } from '@/components/analytics/AnalyticsStates';
import { AnalyticsSummary, AssessmentQualityCard, AttentionAreas, CognitiveDistribution, DifficultyDistribution, QualityTrendChart } from '@/components/analytics/AnalyticsSummary';
import { AssessmentComparisonPanel, AssessmentTable, CourseHistoryTable, LearningGapSummary, LearningOutcomeCoverage, PerformanceTrendChart, ProgramOutcomeCoverage, QuestionPerformanceTable, StudentPerformanceCard, TopicPerformanceTable } from '@/components/analytics/AnalyticsOutcomesPerformance';
import { AiEvaluationSummary, BlueprintComplianceSummary, CollaborationSummary, GradingSummary, InterGraderSummary, QuestionBankSummary, RecommendationSummary, RubricSummary, SimilaritySummary } from '@/components/analytics/AnalyticsAiCollab';

const FILTER_KEYS = ['course_id', 'assessment_id', 'semester', 'academic_year', 'assessment_type', 'start_date', 'end_date'] as const;

const fromParams = (p: URLSearchParams): Filters => {
  const f: Filters = {};
  FILTER_KEYS.forEach((k) => {
    const v = p.get(k);
    if (!v) return;
    if (k === 'course_id' || k === 'assessment_id') f[k] = Number(v);
    else f[k] = v;
  });
  return f;
};

/**
 * STEP 36: Academic Analytics Dashboard at /analytics. Every number comes from the backend aggregate for the
 * authenticated user's accessible courses; filters live in the URL so views are shareable and reloadable.
 */
export const AcademicAnalytics: React.FC = () => {
  const [params, setParams] = useSearchParams();
  const filters = fromParams(params);
  const [options, setOptions] = useState<FilterOptions | null>(null);
  const [overview, setOverview] = useState<AnalyticsOverview | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [topic, setTopic] = useState<string | null>(null);
  const [compareIds, setCompareIds] = useState<number[]>([]);
  const [comparison, setComparison] = useState<AssessmentComparison | null>(null);
  const [comparing, setComparing] = useState(false);
  const [history, setHistory] = useState<CourseHistory | null>(null);
  const [historyError, setHistoryError] = useState<string | null>(null);
  const [historyLoading, setHistoryLoading] = useState(false);

  const filterKey = JSON.stringify(filters);

  const load = useCallback(async (fresh = false) => {
    setError(null);
    if (fresh) setBusy(true);
    try {
      const res = await academicAnalyticsService.getOverview(fromParams(new URLSearchParams(params)), fresh);
      setOverview(res.data);
    } catch (e) {
      setError(getAnalyticsErrorMessage(e));
    } finally {
      setLoading(false);
      setBusy(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filterKey]);

  useEffect(() => { academicAnalyticsService.getFilterOptions().then((r) => setOptions(r.data)).catch(() => setOptions(null)); }, []);
  useEffect(() => { setLoading(true); setComparison(null); setCompareIds([]); setTopic(null); void load(); }, [load]);

  const loadHistory = useCallback(async () => {
    if (!filters.course_id) { setHistory(null); setHistoryError(null); return; }
    setHistoryLoading(true); setHistoryError(null);
    try {
      const res = await academicAnalyticsService.getHistoricalAnalytics(filters.course_id);
      setHistory(res.data);
    } catch (e) {
      setHistoryError(getAnalyticsErrorMessage(e));
    } finally {
      setHistoryLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters.course_id]);
  useEffect(() => { void loadHistory(); }, [loadHistory]);

  const apply = (f: Filters) => {
    const next = new URLSearchParams();
    FILTER_KEYS.forEach((k) => { const v = f[k]; if (v !== undefined && v !== '' && v !== null) next.set(k, String(v)); });
    setParams(next, { replace: true });
  };

  const exportAnalytics = async (format: 'pdf' | 'csv') => {
    setBusy(true); setNotice(null);
    try { await academicAnalyticsService.exportAnalytics(filters, format); setNotice(`Exported analytics as ${format.toUpperCase()}.`); } catch (e) { setError(getAnalyticsErrorMessage(e)); } finally { setBusy(false); }
  };

  const compare = async () => {
    setComparing(true);
    try { const res = await academicAnalyticsService.compareAssessments(compareIds); setComparison(res.data); } catch (e) { setError(getAnalyticsErrorMessage(e)); } finally { setComparing(false); }
  };

  const hasData = overview ? overview.kpis.assessments.value !== null && (overview.kpis.assessments.value as number) > 0 : false;
  const restricted = overview?.scope.student_data_restricted ?? false;

  return (
    <div className="space-y-6" data-testid="academic-analytics-page">
      <AnalyticsHeader meta={overview?.meta ?? null} onRefresh={() => void load(true)} onExport={(f) => void exportAnalytics(f)} busy={busy} />
      <AnalyticsFilters options={options} value={filters} onApply={apply} onReset={() => apply({})} disabled={loading} />
      {error && <AnalyticsError message={error} onRetry={() => void load(true)} />}
      {notice && <div role="status" className="rounded-lg border border-sage-200 dark:border-[#2A2A2A] bg-[#FAFAF8] dark:bg-[#1A1A1A] px-3 py-2 text-sm text-sage-600 dark:text-sage-400">{notice}</div>}
      {loading && <AnalyticsLoading />}
      {!loading && overview && (
        <>
          <AnalyticsSummary kpis={overview.kpis} disclaimer={overview.meta.disclaimer} />
          {!hasData && <AnalyticsEmptyState />}
          <AttentionAreas items={overview.attention_areas} />
          <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <QualityTrendChart data={overview.assessment_quality} />
            <DifficultyDistribution data={overview.difficulty} />
            <AssessmentQualityCard data={overview.assessment_quality} />
            <CognitiveDistribution data={overview.cognitive} />
            <LearningOutcomeCoverage data={overview.learning_outcomes} />
            <ProgramOutcomeCoverage data={overview.program_outcomes} />
          </div>
          <StudentPerformanceCard data={overview.performance} restricted={restricted} />
          <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <PerformanceTrendChart data={overview.performance} />
            <LearningGapSummary data={overview.learning_gaps} />
          </div>
          {!restricted && (
            <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
              <TopicPerformanceTable rows={overview.topic_performance} selected={topic} onSelect={(t) => setTopic((cur) => (cur === t ? null : t))} />
              <QuestionPerformanceTable rows={overview.question_performance} topicFilter={topic} onClearTopic={() => setTopic(null)} />
            </div>
          )}
          <AssessmentComparisonPanel assessments={overview.assessments} comparison={comparison} selected={compareIds} loading={comparing} onCompare={() => void compare()} onClear={() => { setComparison(null); setCompareIds([]); }}
            onToggle={(id) => setCompareIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : prev.length < 6 ? [...prev, id] : prev))} />
          {filters.course_id ? <CourseHistoryTable history={history} loading={historyLoading} error={historyError} onRetry={() => void loadHistory()} /> : null}
          <AssessmentTable rows={overview.assessments} />
          <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
            <SimilaritySummary data={overview.similarity} />
            <QuestionBankSummary data={overview.question_bank} />
            <RubricSummary data={overview.rubrics} />
            <GradingSummary data={overview.grading} restricted={restricted} />
            <InterGraderSummary data={overview.inter_grader} />
            <AiEvaluationSummary data={overview.ai_evaluation} />
            <RecommendationSummary data={overview.recommendations} />
            <CollaborationSummary data={overview.collaboration} />
            <BlueprintComplianceSummary data={overview.blueprint_compliance} />
          </div>
        </>
      )}
    </div>
  );
};
