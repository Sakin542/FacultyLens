import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Download } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Card } from '@/components/common/Card';
import { aiEvaluationService } from '@/services/aiEvaluationService';
import { CreateDatasetInput, ErrorAnalysisItem, EvaluationDataset, EvaluationOverview, EvaluationRun, EvaluationTask, RunComparison, RunDetail } from '@/types/aiEvaluation';
import { EvaluationEmptyState, EvaluationError, EvaluationHeader, EvaluationProgress, EvaluationSummary, getEvaluationErrorMessage } from '@/components/aiEvaluation/EvaluationStates';
import { ErrorAnalysis, RunGates, RunMeta, TaskMetrics } from '@/components/aiEvaluation/EvaluationMetrics';
import { DatasetManager, EvaluationRunComparison, EvaluationRunHistory } from '@/components/aiEvaluation/EvaluationManagement';

const POLL_MS = 3000;

/**
 * STEP 35: AI Evaluation & Model Performance dashboard at /ai-evaluation.
 * All metrics come from persisted evaluation runs; tasks without a run show "Not evaluated yet".
 */
export const AiEvaluation: React.FC = () => {
  const [overview, setOverview] = useState<EvaluationOverview | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const [datasets, setDatasets] = useState<EvaluationDataset[]>([]);
  const [datasetTask, setDatasetTask] = useState<EvaluationTask | ''>('');
  const [selectedDataset, setSelectedDataset] = useState<EvaluationDataset | null>(null);

  const [runs, setRuns] = useState<EvaluationRun[]>([]);
  const [runTask, setRunTask] = useState<EvaluationTask | ''>('');
  const [activeRun, setActiveRun] = useState<RunDetail | null>(null);
  const [errors, setErrors] = useState<ErrorAnalysisItem[]>([]);
  const [errorsMeta, setErrorsMeta] = useState<{ total: number; last_page: number; page: number; breakdown: Record<string, number> }>({ total: 0, last_page: 1, page: 1, breakdown: {} });
  const [errorFilter, setErrorFilter] = useState('');
  const [errorsLoading, setErrorsLoading] = useState(false);

  const [compareIds, setCompareIds] = useState<number[]>([]);
  const [comparison, setComparison] = useState<RunComparison | null>(null);
  const [comparing, setComparing] = useState(false);
  const pollRef = useRef<number | null>(null);
  const detailRef = useRef<HTMLDivElement | null>(null);

  const loadOverview = useCallback(async () => {
    const res = await aiEvaluationService.getOverview();
    setOverview(res.data);
  }, []);
  const loadDatasets = useCallback(async (task: EvaluationTask | '') => {
    const res = await aiEvaluationService.getDatasets(task);
    setDatasets(res.data);
    setSelectedDataset((prev) => (prev ? res.data.find((d) => d.id === prev.id) ?? null : null));
  }, []);
  const loadRuns = useCallback(async (task: EvaluationTask | '') => {
    const res = await aiEvaluationService.getEvaluationRuns({ task, per_page: 50 });
    setRuns(res.data);
  }, []);

  const refreshAll = useCallback(async () => {
    setError(null);
    try {
      await Promise.all([loadOverview(), loadDatasets(datasetTask), loadRuns(runTask)]);
    } catch (e) {
      setError(getEvaluationErrorMessage(e));
    } finally {
      setLoading(false);
    }
  }, [loadOverview, loadDatasets, loadRuns, datasetTask, runTask]);

  useEffect(() => { void refreshAll(); }, [refreshAll]);

  const stopPolling = () => { if (pollRef.current) { window.clearTimeout(pollRef.current); pollRef.current = null; } };
  useEffect(() => stopPolling, []);

  const loadErrors = useCallback(async (runId: number, filter: string, page: number, append: boolean) => {
    setErrorsLoading(true);
    try {
      const res = await aiEvaluationService.getErrors(runId, { only_errors: true, error_type: filter || undefined, page });
      setErrors((prev) => (append ? [...prev, ...res.data] : res.data));
      setErrorsMeta({ total: res.meta?.total ?? res.data.length, last_page: res.meta?.last_page ?? 1, page, breakdown: res.meta?.error_breakdown ?? {} });
    } catch (e) {
      setError(getEvaluationErrorMessage(e));
    } finally {
      setErrorsLoading(false);
    }
  }, []);

  const openRun = useCallback(async (run: EvaluationRun | { id: number }) => {
    stopPolling();
    setError(null);
    try {
      const res = await aiEvaluationService.getEvaluationRun(run.id);
      setActiveRun(res.data);
      if (res.data.status === 'PENDING' || res.data.status === 'RUNNING') {
        pollRef.current = window.setTimeout(() => void openRun(run), POLL_MS);
      } else {
        setErrorFilter('');
        if (res.data.status === 'COMPLETED') void loadErrors(res.data.id, '', 1, false);
        void loadRuns(runTask); void loadOverview(); void loadDatasets(datasetTask);
      }
      detailRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {
      setError(getEvaluationErrorMessage(e));
    }
  }, [loadErrors, loadRuns, loadOverview, loadDatasets, runTask, datasetTask]);

  const withBusy = async (fn: () => Promise<void>) => {
    setBusy(true); setError(null); setNotice(null);
    try { await fn(); } catch (e) { setError(getEvaluationErrorMessage(e)); } finally { setBusy(false); }
  };

  const createDataset = (input: CreateDatasetInput) => withBusy(async () => {
    const res = await aiEvaluationService.createDataset(input);
    setNotice(`${res.message ?? 'Dataset created.'} Imported ${res.data.import.added} example(s), skipped ${res.data.import.skipped_duplicates} duplicate(s).`);
    await loadDatasets(datasetTask);
    setSelectedDataset(res.data);
    await loadOverview();
  });
  const validateDataset = (d: EvaluationDataset) => withBusy(async () => {
    const res = await aiEvaluationService.validateDataset(d.id);
    setNotice(res.data.is_valid ? 'Dataset is valid and ready for evaluation.' : 'Dataset has problems; fix them before running an evaluation.');
    await loadDatasets(datasetTask);
    setSelectedDataset({ ...d, validation_report: res.data, status: res.data.is_valid ? 'READY' : 'DRAFT' });
  });
  const runEvaluation = (d: EvaluationDataset) => withBusy(async () => {
    const res = await aiEvaluationService.runEvaluation(d.id);
    setNotice(res.message ?? 'Evaluation started.');
    await Promise.all([loadRuns(runTask), loadDatasets(datasetTask), loadOverview()]);
    await openRun(res.data);
  });
  const deleteDataset = (d: EvaluationDataset) => {
    if (!window.confirm(`Delete dataset "${d.name}" and all of its evaluation runs?`)) return Promise.resolve();
    return withBusy(async () => {
      await aiEvaluationService.deleteDataset(d.id);
      if (selectedDataset?.id === d.id) setSelectedDataset(null);
      if (activeRun?.dataset?.id === d.id) setActiveRun(null);
      await Promise.all([loadDatasets(datasetTask), loadRuns(runTask), loadOverview()]);
    });
  };
  const cancelRun = () => activeRun && withBusy(async () => { await aiEvaluationService.cancelRun(activeRun.id); await openRun(activeRun); });
  const exportRun = (format: 'json' | 'csv' | 'pdf') => activeRun && withBusy(async () => { await aiEvaluationService.exportReport(activeRun.id, format); setNotice(`Exported ${format.toUpperCase()} report for run #${activeRun.id}.`); });

  const toggleCompare = (id: number) => setCompareIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : prev.length < 2 ? [...prev, id] : prev));
  useEffect(() => {
    if (compareIds.length !== 2) { setComparison(null); return; }
    setComparing(true);
    aiEvaluationService.compareRuns(compareIds[0], compareIds[1]).then((r) => setComparison(r.data)).catch((e) => setError(getEvaluationErrorMessage(e))).finally(() => setComparing(false));
  }, [compareIds]);

  const changeErrorFilter = (t: string) => { setErrorFilter(t); if (activeRun) void loadErrors(activeRun.id, t, 1, false); };

  if (loading) {
    return <div className="flex items-center justify-center min-h-[40vh]" role="status"><div className="h-8 w-8 rounded-full border-4 border-[#111111] border-t-transparent animate-spin" /><span className="sr-only">Loading AI evaluation…</span></div>;
  }

  return (
    <div className="space-y-6" data-testid="ai-evaluation-page">
      <EvaluationHeader overview={overview} onRefresh={() => void refreshAll()} refreshing={busy} />
      {error && <EvaluationError message={error} onRetry={() => void refreshAll()} />}
      {notice && <div role="status" className="rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-[#FAFAF8] dark:bg-[#1A1A1A] px-3 py-2 text-sm text-[#525252] dark:text-[#A3A3A3]">{notice}</div>}

      {overview && (overview.run_count > 0 || overview.dataset_count > 0 ? <EvaluationSummary overview={overview} onOpenRun={(r) => void openRun(r)} /> : <EvaluationEmptyState />)}

      <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <DatasetManager datasets={datasets} selected={selectedDataset} onSelect={setSelectedDataset} onCreate={createDataset} onValidate={validateDataset} onRun={runEvaluation} onDelete={deleteDataset} busy={busy}
          taskFilter={datasetTask} onTaskFilter={(t) => { setDatasetTask(t); void loadDatasets(t); }} />
        <EvaluationRunHistory runs={runs} selectedId={activeRun?.id ?? null} onOpen={(r) => void openRun(r)} compareIds={compareIds} onToggleCompare={toggleCompare}
          taskFilter={runTask} onTaskFilter={(t) => { setRunTask(t); void loadRuns(t); }} />
      </div>

      <EvaluationRunComparison comparison={comparison} loading={comparing} onClear={() => setCompareIds([])} />

      <div ref={detailRef}>
        {activeRun && (activeRun.status === 'PENDING' || activeRun.status === 'RUNNING') && <EvaluationProgress run={activeRun} onCancel={() => void cancelRun()} />}
        {activeRun && activeRun.status === 'FAILED' && <EvaluationError message={`Evaluation run #${activeRun.id} failed: ${activeRun.failure_reason ?? 'unknown reason'}. Results were not recorded.`} />}
        {activeRun && activeRun.status === 'CANCELLED' && <Card className="p-4 text-sm text-[#737373]">Evaluation run #{activeRun.id} was cancelled.</Card>}
        {activeRun && activeRun.status === 'COMPLETED' && (
          <section aria-label={`Evaluation run ${activeRun.id} results`} data-testid="run-results" className="space-y-4">
            <Card className="p-4 space-y-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-base font-semibold text-[#111111] dark:text-white">Evaluation run #{activeRun.id}</h2>
                <div className="flex items-center gap-1" role="group" aria-label="Export report">
                  {(['pdf', 'csv', 'json'] as const).map((f) => <Button key={f} size="sm" variant="outline" disabled={busy} onClick={() => void exportRun(f)}><Download className="w-3.5 h-3.5 mr-1" aria-hidden="true" />{f.toUpperCase()}</Button>)}
                </div>
              </div>
              <RunMeta run={activeRun} />
            </Card>
            <RunGates run={activeRun} />
            <TaskMetrics run={activeRun} />
            <ErrorAnalysis items={errors} breakdown={errorsMeta.breakdown} total={errorsMeta.total} loading={errorsLoading} filter={errorFilter} onFilter={changeErrorFilter}
              hasMore={errorsMeta.page < errorsMeta.last_page} onLoadMore={() => void loadErrors(activeRun.id, errorFilter, errorsMeta.page + 1, true)} />
          </section>
        )}
      </div>
    </div>
  );
};
