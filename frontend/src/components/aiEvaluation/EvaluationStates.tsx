import React from 'react';
import { AlertTriangle, CheckCircle2, Gauge, Loader2, MinusCircle, XCircle } from 'lucide-react';
import { Badge, BadgeVariant } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { Card } from '@/components/common/Card';
import { ApiError } from '@/services/api';
import { cn } from '@/utils/cn';
import { EvaluationOverview, EvaluationRun, GateStatus, OverallStatus, RunStatus, TASK_LABELS, TaskOverview } from '@/types/aiEvaluation';

/** STEP 35: shared header/summary/state components for the AI evaluation dashboard. */

export const HEADLINE_LABELS: Record<string, string> = {
  macro_f1: 'Macro F1', accuracy: 'Accuracy', duplicate_f1: 'Duplicate F1', mae: 'MAE', criterion_f1: 'Criterion F1',
  citation_accuracy: 'Citation accuracy', constraint_satisfaction_rate: 'Constraint satisfaction', marks_validity_rate: 'Marks validity',
  rmse: 'RMSE', mape: 'MAPE', exact_agreement_rate: 'Exact agreement', answer_supported_rate: 'Answer supported', unsupported_answer_rate: 'Unsupported answers',
  false_refusal_rate: 'False refusals', correct_refusal_rate: 'Correct refusals', injection_leak_rate: 'Injection leaks', citation_coverage: 'Citation coverage',
  macro_precision: 'Macro precision', macro_recall: 'Macro recall', weighted_f1: 'Weighted F1', support: 'Examples', mean_signed_error: 'Mean signed error',
  duplicate_precision: 'Duplicate precision', duplicate_recall: 'Duplicate recall', generation_completeness: 'Generation completeness', overall_agreement: 'Overall agreement',
};

export const metricLabel = (name: string): string => HEADLINE_LABELS[name] ?? name.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

const RATE_METRICS = /(rate|accuracy|precision|recall|f1|coverage|agreement|completeness|within_)/;
export const formatMetric = (name: string, value: number | null | undefined): string => {
  if (value === null || value === undefined || Number.isNaN(value)) return '—';
  if (RATE_METRICS.test(name) && value >= 0 && value <= 1) return `${(value * 100).toFixed(1)}%`;
  return Number.isInteger(value) ? String(value) : value.toFixed(name === 'mape' ? 1 : 3);
};

export const statusVariant = (status: OverallStatus | GateStatus | RunStatus | null | undefined): BadgeVariant => {
  switch (status) {
    case 'PASSED': case 'COMPLETED': return 'Good';
    case 'PASSED_WITH_WARNINGS': return 'Attention';
    case 'FAILED': return 'Critical';
    case 'EVALUATING': case 'RUNNING': case 'PENDING': return 'Pending';
    default: return 'neutral';
  }
};

export const statusText = (status: string | null | undefined): string => (status ?? 'NOT_EVALUATED').replace(/_/g, ' ').toLowerCase().replace(/^\w/, (c) => c.toUpperCase());

export function getEvaluationErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.status === 401) return 'Your session has expired. Please sign in again.';
    if (err.status === 403) return err.message || 'You do not have access to this evaluation data.';
    if (err.status === 404) return 'The requested evaluation item no longer exists.';
    if (err.status === 409) return err.message || 'An evaluation is already running for this dataset.';
    if (err.status === 422) return err.message || 'Please fix the dataset problems and try again.';
    if (err.status === 429) return 'Evaluation requests are rate-limited. Please wait a moment.';
    if (err.status >= 502 && err.status <= 504) return err.message || 'The AI service is temporarily unavailable.';
    return err.message || 'Something went wrong.';
  }
  return err instanceof Error ? err.message : 'Something went wrong.';
}

export const EvaluationHeader: React.FC<{ overview: EvaluationOverview | null; onRefresh: () => void; refreshing?: boolean }> = ({ overview, onRefresh, refreshing }) => (
  <header data-testid="evaluation-header" className="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
    <div>
      <div className="flex items-center gap-2 mb-1">
        <Gauge className="w-5 h-5 text-[#111111] dark:text-white" aria-hidden="true" />
        <h1 className="text-2xl font-bold text-[#111111] dark:text-white">AI Evaluation &amp; Model Performance</h1>
      </div>
      <p className="text-sm text-[#737373] max-w-2xl">
        Measures FacultyLens AI features against faculty-validated datasets. Evaluation is monitoring only — it never retrains models, changes thresholds, or promotes a model automatically.
      </p>
    </div>
    <div className="flex items-center gap-3">
      {overview && (
        <Badge variant={statusVariant(overview.overall_status)} dot size="md" data-testid="overall-status">
          Status: {statusText(overview.overall_status)}
        </Badge>
      )}
      <Button variant="outline" size="sm" onClick={onRefresh} disabled={refreshing} aria-label="Refresh evaluation data">{refreshing ? 'Refreshing…' : 'Refresh'}</Button>
    </div>
  </header>
);

export const MetricCard: React.FC<{ label: string; value: string; hint?: string; status?: GateStatus | null; className?: string; 'data-testid'?: string }> = ({ label, value, hint, status, className, ...rest }) => (
  <div data-testid={rest['data-testid'] ?? 'metric-card'} className={cn('rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-4 py-3', className)}>
    <p className="text-xs uppercase tracking-wide text-[#737373]">{label}</p>
    <p className="text-2xl font-semibold text-[#111111] dark:text-white mt-1 tabular-nums">{value}</p>
    {(hint || status) && (
      <div className="flex items-center gap-2 mt-1">
        {status && <Badge variant={statusVariant(status)} size="sm">{statusText(status)}</Badge>}
        {hint && <p className="text-xs text-[#737373]">{hint}</p>}
      </div>
    )}
  </div>
);

export const ModelPerformanceCard: React.FC<{ item: TaskOverview; onOpen?: (run: EvaluationRun) => void }> = ({ item, onOpen }) => (
  <Card data-testid="model-performance-card" className="p-4 flex flex-col gap-2">
    <div className="flex items-start justify-between gap-2">
      <h3 className="text-sm font-semibold text-[#111111] dark:text-white">{TASK_LABELS[item.task]}</h3>
      {item.evaluated ? <Badge variant={statusVariant(item.gate_status)} size="sm">{statusText(item.gate_status)}</Badge> : <Badge variant="neutral" size="sm">Not evaluated yet</Badge>}
    </div>
    {item.evaluated ? (
      <>
        <p className="text-2xl font-semibold tabular-nums text-[#111111] dark:text-white">{formatMetric(item.headline_metric, item.headline_value)}</p>
        <p className="text-xs text-[#737373]">{metricLabel(item.headline_metric)} · {item.run?.example_count ?? 0} examples · {item.size_category?.replace('_', ' ').toLowerCase()}</p>
        {item.run?.model && <p className="text-xs text-[#737373] truncate" title={item.run.model.name}>Model: {item.run.model.name} ({item.run.model.version})</p>}
        {item.regression && <p className="text-xs text-red-700 dark:text-red-300 flex items-center gap-1"><AlertTriangle className="w-3 h-3" aria-hidden="true" /> Performance regression detected</p>}
        {item.warnings.length > 0 && <p className="text-xs text-amber-800 dark:text-amber-300">{item.warnings[0]}</p>}
        {item.run && onOpen && <button type="button" onClick={() => onOpen(item.run as EvaluationRun)} className="text-xs font-medium underline underline-offset-2 text-left">View run #{item.run.id}</button>}
      </>
    ) : (
      <p className="text-sm text-[#737373]">Not evaluated yet. Create a {TASK_LABELS[item.task].toLowerCase()} dataset and run an evaluation to see real metrics.</p>
    )}
  </Card>
);

export const EvaluationSummary: React.FC<{ overview: EvaluationOverview; onOpenRun?: (run: EvaluationRun) => void }> = ({ overview, onOpenRun }) => {
  const evaluated = overview.tasks.filter((t) => t.evaluated).length;
  const models = overview.models;
  return (
    <section data-testid="evaluation-summary" aria-label="Evaluation summary" className="space-y-4">
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <MetricCard label="Overall" value={statusText(overview.overall_status)} hint={`${evaluated}/${overview.tasks.length} tasks evaluated`} />
        <MetricCard label="Datasets" value={String(overview.dataset_count)} />
        <MetricCard label="Evaluation runs" value={String(overview.run_count)} />
        <MetricCard label="Registered models" value={String(models.length)} hint={models.length ? undefined : 'Sync the registry from the AI service'} />
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
        {overview.tasks.map((t) => <ModelPerformanceCard key={t.task} item={t} onOpen={onOpenRun} />)}
      </div>
      {models.length > 0 && (
        <Card className="p-4">
          <h3 className="text-sm font-semibold text-[#111111] dark:text-white mb-2">Model information</h3>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <caption className="sr-only">Registered AI models</caption>
              <thead><tr className="text-left text-xs uppercase text-[#737373]"><th className="py-1 pr-3">Model</th><th className="py-1 pr-3">Provider</th><th className="py-1 pr-3">Type</th><th className="py-1 pr-3">Task</th><th className="py-1 pr-3">Version</th></tr></thead>
              <tbody>
                {models.map((m) => (
                  <tr key={m.id} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A]">
                    <td className="py-1 pr-3 font-mono text-xs">{m.model_name}</td><td className="py-1 pr-3">{m.provider ?? '—'}</td><td className="py-1 pr-3">{m.model_type ?? '—'}</td>
                    <td className="py-1 pr-3">{TASK_LABELS[m.task] ?? m.task}</td><td className="py-1 pr-3">{m.version}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {overview.prompt_versions.length > 0 && (
            <p className="text-xs text-[#737373] mt-2">Prompt versions: {overview.prompt_versions.map((p) => `${p.feature} ${p.version}`).join(', ')}</p>
          )}
        </Card>
      )}
      <Card className="p-4">
        <h3 className="text-sm font-semibold text-[#111111] dark:text-white mb-1">Faculty interaction signals</h3>
        <p className="text-xs text-[#737373] mb-2">{overview.faculty_signals.note}</p>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
          {(['recommendations', 'generated_questions', 'rubrics', 'grading'] as const).map((k) => {
            const entries = Object.entries(overview.faculty_signals[k] ?? {});
            return (
              <div key={k}>
                <p className="text-xs uppercase text-[#737373] mb-1">{k.replace('_', ' ')}</p>
                {entries.length === 0 ? <p className="text-xs text-[#A3A3A3]">No signals yet</p> : entries.map(([d, c]) => <p key={d} className="flex justify-between"><span>{statusText(d)}</span><span className="tabular-nums">{c}</span></p>)}
              </div>
            );
          })}
        </div>
      </Card>
      <div className="rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-[#FAFAF8] dark:bg-[#1A1A1A] p-4">
        <h3 className="text-sm font-semibold text-[#111111] dark:text-white mb-1">Known limitations</h3>
        <ul className="list-disc pl-5 text-xs text-[#525252] dark:text-[#A3A3A3] space-y-0.5">{overview.limitations.map((l) => <li key={l}>{l}</li>)}</ul>
      </div>
    </section>
  );
};

export const EvaluationProgress: React.FC<{ run: EvaluationRun; onCancel?: () => void }> = ({ run, onCancel }) => {
  const pct = run.example_count ? Math.round((run.processed_count / run.example_count) * 100) : 0;
  return (
    <div data-testid="evaluation-progress" role="status" aria-live="polite" className="rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] p-4">
      <div className="flex items-center justify-between gap-2 mb-2">
        <p className="text-sm font-medium flex items-center gap-2"><Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" /> Evaluation run #{run.id} is {run.status.toLowerCase()}…</p>
        {onCancel && <Button size="sm" variant="ghost" onClick={onCancel}>Cancel</Button>}
      </div>
      <div className="h-2 w-full rounded bg-[#F0F0F0] dark:bg-[#2A2A2A]" aria-hidden="true"><div className="h-2 rounded bg-[#111111] dark:bg-white transition-all" style={{ width: `${pct}%` }} /></div>
      <p className="text-xs text-[#737373] mt-1">{run.processed_count} of {run.example_count} examples processed ({pct}%)</p>
    </div>
  );
};

export const EvaluationEmptyState: React.FC<{ title?: string; description?: string; action?: React.ReactNode }> = ({
  title = 'Not evaluated yet', description = 'No evaluation has been run. Create a dataset with faculty-validated examples, validate it, and start an evaluation. Metrics are never estimated or hard-coded.', action,
}) => (
  <div data-testid="evaluation-empty-state" className="flex flex-col items-center justify-center text-center py-12 px-6 rounded-xl border border-dashed border-[#E5E5E5] dark:border-[#2A2A2A]">
    <div className="w-12 h-12 rounded-full bg-[#F7F7F5] dark:bg-[#1F1F1F] border border-[#E5E5E5] dark:border-[#2A2A2A] flex items-center justify-center text-[#737373] mb-4"><MinusCircle className="w-6 h-6" aria-hidden="true" /></div>
    <h4 className="text-base font-semibold text-[#111111] dark:text-white mb-1">{title}</h4>
    <p className="text-sm text-[#737373] max-w-md">{description}</p>
    {action && <div className="mt-4">{action}</div>}
  </div>
);

export const EvaluationError: React.FC<{ message: string; onRetry?: () => void; className?: string }> = ({ message, onRetry, className }) => (
  <div data-testid="evaluation-error" role="alert" className={cn('flex items-start gap-2 rounded-lg border border-red-200 dark:border-red-900/50 bg-red-50 dark:bg-red-950/30 px-3 py-2 text-sm text-red-700 dark:text-red-300', className)}>
    <XCircle className="w-4 h-4 mt-0.5 flex-shrink-0" aria-hidden="true" />
    <div className="flex-1"><p>{message}</p>{onRetry && <button type="button" onClick={onRetry} className="mt-1 text-xs font-medium underline underline-offset-2">Try again</button>}</div>
  </div>
);

export const GateList: React.FC<{ gates: { metric: string; value: number | null; min: number | null; max: number | null; passed: boolean | null }[]; warnings?: string[] }> = ({ gates, warnings }) => (
  <div data-testid="quality-gates" className="space-y-1">
    {gates.length === 0 && <p className="text-xs text-[#737373]">No quality gates configured for this task.</p>}
    {gates.map((g) => (
      <p key={g.metric} className="flex items-center gap-2 text-sm">
        {g.passed === true && <CheckCircle2 className="w-4 h-4 text-green-700" aria-hidden="true" />}
        {g.passed === false && <XCircle className="w-4 h-4 text-red-700" aria-hidden="true" />}
        {g.passed === null && <MinusCircle className="w-4 h-4 text-[#A3A3A3]" aria-hidden="true" />}
        <span>{metricLabel(g.metric)}: <span className="tabular-nums">{formatMetric(g.metric, g.value)}</span>{g.min !== null && ` (target ≥ ${formatMetric(g.metric, g.min)})`}{g.max !== null && ` (max ≤ ${formatMetric(g.metric, g.max)})`}</span>
        <span className="sr-only">{g.passed === true ? 'passed' : g.passed === false ? 'failed' : 'not produced'}</span>
      </p>
    ))}
    {warnings && warnings.length > 0 && <ul className="mt-2 list-disc pl-5 text-xs text-amber-800 dark:text-amber-300">{warnings.map((w) => <li key={w}>{w}</li>)}</ul>}
  </div>
);
