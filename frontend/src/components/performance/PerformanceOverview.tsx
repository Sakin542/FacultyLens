import React, { useCallback, useEffect, useRef, useState } from 'react';
import { AlertTriangle, BarChart3, RotateCcw } from 'lucide-react';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { performanceService } from '@/services/performanceService';
import { ApiError } from '@/services/api';
import { PerformanceAnalysis, PerformanceMeta, isPerformanceRunActive } from '@/types/performance';
import { PerformanceSummary } from './PerformanceSummary';
import { QuestionPerformanceTable } from './QuestionPerformanceTable';
import { TopicPerformance } from './TopicPerformance';
import { LearningOutcomePerformance } from './LearningOutcomePerformance';
import { GapAreas } from './GapAreas';
import { StrongAreas } from './StrongAreas';
import { PerformanceLoading } from './PerformanceLoading';
import { PerformanceEmptyState } from './PerformanceEmptyState';
import { PerformanceError } from './PerformanceError';
import { PerformanceDisclaimer } from './PerformanceDisclaimer';

interface PerformanceOverviewProps {
  assessmentId: number | string;
  pollIntervalMs?: number;
}

/**
 * STEP 30 dashboard section: loads the current snapshot, lets faculty (re)generate it, polls
 * while queued, and renders question / topic / LO performance with gap and strong areas.
 * Separate from the STEP 13 assessment-quality analysis — the two are never combined.
 */
export const PerformanceOverview: React.FC<PerformanceOverviewProps> = ({ assessmentId, pollIntervalMs = 3000 }) => {
  const [analysis, setAnalysis] = useState<PerformanceAnalysis | null>(null);
  const [meta, setMeta] = useState<PerformanceMeta | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isAnalyzing, setIsAnalyzing] = useState(false);
  const [error, setError] = useState<Error | null>(null);
  const [actionError, setActionError] = useState<Error | null>(null);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const mountedRef = useRef(true);

  const stopPolling = useCallback(() => {
    if (timerRef.current !== null) { clearInterval(timerRef.current); timerRef.current = null; }
  }, []);

  const load = useCallback(async () => {
    try {
      const res = await performanceService.getAssessmentPerformance(assessmentId);
      if (!mountedRef.current) return;
      setAnalysis(res.data);
      setMeta(res.meta ?? null);
      setError(null);
      if (!res.data || !isPerformanceRunActive(res.data.status)) stopPolling();
    } catch (err) {
      if (!mountedRef.current) return;
      setError(err instanceof Error ? err : new Error('Failed to load performance analysis.'));
      stopPolling();
    } finally {
      if (mountedRef.current) setIsLoading(false);
    }
  }, [assessmentId, stopPolling]);

  useEffect(() => {
    mountedRef.current = true;
    setIsLoading(true);
    setAnalysis(null);
    load();
    return () => { mountedRef.current = false; stopPolling(); };
  }, [load, stopPolling]);

  useEffect(() => {
    if (analysis && isPerformanceRunActive(analysis.status) && timerRef.current === null) {
      timerRef.current = setInterval(load, pollIntervalMs);
    }
  }, [analysis, load, pollIntervalMs]);

  const analyze = async (force = false) => {
    try {
      setIsAnalyzing(true);
      setActionError(null);
      const res = await performanceService.analyzeAssessmentPerformance(assessmentId, force);
      setAnalysis(res.data);
      if (res.meta) setMeta(res.meta);
      if (res.data && !isPerformanceRunActive(res.data.status)) await load();
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) { await load(); return; }
      setActionError(err instanceof Error ? err : new Error('The analysis could not be started.'));
    } finally {
      setIsAnalyzing(false);
    }
  };

  const expected = analysis?.expected_performance_percent ?? meta?.expected_performance_percent ?? 70;

  return (
    <Card variant="default" className="p-5 space-y-4" data-testid="performance-overview">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="text-sm font-bold text-sage-800 dark:text-white flex items-center gap-2">
            <BarChart3 className="w-4 h-4 text-sage-500" /> Student Performance
          </h3>
          <p className="text-[11px] text-sage-500">How students actually performed, from finalized faculty marks. Separate from assessment-quality analysis.</p>
        </div>
        {analysis && !isPerformanceRunActive(analysis.status) && (
          <Button variant="outline" size="sm" leftIcon={<RotateCcw className={`w-3.5 h-3.5 ${isAnalyzing ? 'animate-spin' : ''}`} />} onClick={() => analyze(true)} isLoading={isAnalyzing} data-testid="regenerate-performance">
            Regenerate
          </Button>
        )}
      </header>

      {isLoading ? (
        <PerformanceLoading />
      ) : error ? (
        <PerformanceError error={error} onRetry={load} />
      ) : !analysis ? (
        <>
          <PerformanceEmptyState meta={meta} onAnalyze={() => analyze(false)} isAnalyzing={isAnalyzing} />
          {actionError && <PerformanceError error={actionError} />}
        </>
      ) : isPerformanceRunActive(analysis.status) ? (
        <PerformanceLoading queued={analysis.status === 'PENDING'} />
      ) : analysis.status === 'FAILED' ? (
        <PerformanceError message={analysis.error_message} onRetry={() => analyze(true)} isRetrying={isAnalyzing} />
      ) : (
        <div className="space-y-5">
          {actionError && <PerformanceError error={actionError} />}
          {analysis.is_stale && (
            <div className="p-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 flex items-start gap-2 text-amber-800 dark:text-amber-300 text-xs" role="alert" data-testid="performance-stale">
              <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
              <div>
                <p className="font-semibold">This analysis may be outdated.</p>
                {analysis.stale_reasons.map((r, i) => <p key={i}>{r}</p>)}
                <p>Regenerate to include the latest finalized grades. The previous snapshot is preserved.</p>
              </div>
            </div>
          )}

          <PerformanceSummary analysis={analysis} />

          {analysis.finalized_answer_count === 0 ? (
            <p className="text-xs text-sage-500 italic" data-testid="no-finalized">No finalized grades were available when this analysis ran. Finalize faculty marks and regenerate.</p>
          ) : (
            <>
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <GapAreas areas={analysis.summary.gap_areas} minResponses={analysis.minimum_responses} />
                <StrongAreas areas={analysis.summary.strong_areas} />
              </div>
              <QuestionPerformanceTable questions={analysis.questions ?? []} expected={expected} />
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                <TopicPerformance topics={analysis.topics ?? []} expected={expected} hasQuestions={(analysis.questions ?? []).length > 0} />
                <LearningOutcomePerformance outcomes={analysis.learning_outcomes ?? []} questions={analysis.questions ?? []} expected={expected} />
              </div>
            </>
          )}

          <PerformanceDisclaimer text={analysis.limitations} />
        </div>
      )}
    </Card>
  );
};
