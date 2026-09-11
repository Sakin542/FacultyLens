import React, { useState } from 'react';
import { Card } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { cn } from '@/utils/cn';
import { ConfusionMatrix as ConfusionMatrixData, ErrorAnalysisItem, EvaluationMetrics, PerClassMetric, PerConstraint, RunDetail, TASK_LABELS } from '@/types/aiEvaluation';
import { formatMetric, GateList, metricLabel, statusText, statusVariant } from './EvaluationStates';

/** STEP 35: task-specific metric displays. Every number comes from a persisted evaluation run. */

const Section: React.FC<{ title: string; description?: string; children: React.ReactNode; testId: string }> = ({ title, description, children, testId }) => (
  <Card data-testid={testId} className="p-4 space-y-3">
    <div><h3 className="text-sm font-semibold text-sage-800 dark:text-white">{title}</h3>{description && <p className="text-xs text-sage-500 mt-0.5">{description}</p>}</div>
    {children}
  </Card>
);

const ScalarGrid: React.FC<{ scalars: Record<string, number>; keys: string[] }> = ({ scalars, keys }) => (
  <dl className="grid grid-cols-2 md:grid-cols-4 gap-3">
    {keys.filter((k) => scalars[k] !== undefined).map((k) => (
      <div key={k} className="rounded-md border border-sage-100 dark:border-[#2A2A2A] px-3 py-2">
        <dt className="text-xs text-sage-500">{metricLabel(k)}</dt>
        <dd className="text-lg font-semibold tabular-nums text-sage-800 dark:text-white">{formatMetric(k, scalars[k])}</dd>
      </div>
    ))}
  </dl>
);

export const ConfusionMatrix: React.FC<{ data: ConfusionMatrixData; title?: string }> = ({ data, title = 'Confusion matrix' }) => {
  const max = Math.max(1, ...data.matrix.flat());
  return (
    <div data-testid="confusion-matrix" className="overflow-x-auto">
      <table className="text-sm border-collapse">
        <caption className="text-xs text-sage-500 text-left mb-1">{title} — rows: actual, columns: predicted</caption>
        <thead>
          <tr><th scope="col" className="p-2 text-xs text-sage-500 text-left">Actual \ Predicted</th>{data.labels.map((l) => <th key={l} scope="col" className="p-2 text-xs font-medium">{l}</th>)}</tr>
        </thead>
        <tbody>
          {data.matrix.map((row, i) => (
            <tr key={data.labels[i]}>
              <th scope="row" className="p-2 text-xs font-medium text-left">{data.labels[i]}</th>
              {row.map((v, j) => {
                const shade = v === 0 ? 0 : 0.15 + 0.6 * (v / max);
                return (
                  <td key={j} className={cn('p-2 text-center tabular-nums border border-sage-100 dark:border-[#2A2A2A]', i === j && 'font-semibold')}
                    style={{ backgroundColor: v ? `rgba(17,17,17,${shade})` : undefined, color: shade > 0.45 ? '#fff' : undefined }}
                    aria-label={`Actual ${data.labels[i]}, predicted ${data.labels[j]}: ${v}`}>{v}</td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};

export const PerClassMetrics: React.FC<{ rows: PerClassMetric[] }> = ({ rows }) => (
  <div data-testid="per-class-metrics" className="overflow-x-auto">
    <table className="w-full text-sm">
      <caption className="sr-only">Per-class precision, recall, F1 and support</caption>
      <thead><tr className="text-left text-xs uppercase text-sage-500"><th className="py-1 pr-3">Class</th><th className="py-1 pr-3">Precision</th><th className="py-1 pr-3">Recall</th><th className="py-1 pr-3">F1</th><th className="py-1 pr-3">Support</th></tr></thead>
      <tbody>
        {rows.map((r) => (
          <tr key={r.label} className="border-t border-sage-100 dark:border-[#2A2A2A]">
            <td className="py-1 pr-3 font-medium">{r.label}</td><td className="py-1 pr-3 tabular-nums">{r.precision.toFixed(2)}</td><td className="py-1 pr-3 tabular-nums">{r.recall.toFixed(2)}</td>
            <td className="py-1 pr-3 tabular-nums">{r.f1.toFixed(2)}</td><td className="py-1 pr-3 tabular-nums">{r.support}</td>
          </tr>
        ))}
      </tbody>
    </table>
  </div>
);

export const ClassificationMetrics: React.FC<{ metrics: EvaluationMetrics }> = ({ metrics }) => (
  <Section testId="classification-metrics" title="Classification metrics" description="Macro F1 is emphasised because classes are usually imbalanced; accuracy alone can hide weak classes.">
    <ScalarGrid scalars={metrics.scalars} keys={['macro_f1', 'accuracy', 'weighted_f1', 'macro_precision', 'macro_recall', 'support', 'inference_failures']} />
    {metrics.structured.confusion_matrix && <ConfusionMatrix data={metrics.structured.confusion_matrix} />}
    {metrics.structured.per_class && <PerClassMetrics rows={metrics.structured.per_class} />}
  </Section>
);

export const AlignmentMetrics: React.FC<{ metrics: EvaluationMetrics }> = ({ metrics }) => {
  const lo = metrics.task === 'LO_ALIGNMENT';
  return (
    <Section testId="alignment-metrics" title={lo ? 'Learning-outcome alignment' : 'Answer–rubric alignment'} description={lo ? 'MiniLM embeddings + cosine similarity at the production STEP 11 thresholds compared with faculty-validated labels.' : 'Criterion-level agreement with faculty-reviewed alignment statuses (positive = aligned).'}>
      <ScalarGrid scalars={metrics.scalars} keys={lo
        ? ['macro_f1', 'accuracy', 'strong_precision', 'strong_recall', 'strong_f1', 'weak_precision', 'weak_recall', 'weak_f1', 'not_aligned_precision', 'not_aligned_recall', 'not_aligned_f1']
        : ['criterion_f1', 'criterion_precision', 'criterion_recall', 'overall_agreement', 'false_positive_alignments', 'false_negative_alignments', 'criteria_compared']} />
      {metrics.structured.confusion_matrix && <ConfusionMatrix data={metrics.structured.confusion_matrix} />}
      {metrics.structured.per_class && <PerClassMetrics rows={metrics.structured.per_class} />}
    </Section>
  );
};

export const SimilarityMetrics: React.FC<{ metrics: EvaluationMetrics }> = ({ metrics }) => {
  const sweep = metrics.structured.threshold_sweep;
  const t = metrics.structured.thresholds;
  return (
    <Section testId="similarity-metrics" title="Semantic similarity" description={t ? `Production thresholds: duplicate ≥ ${t.duplicate}, high ≥ ${t.high}, moderate ≥ ${t.moderate}. Evaluation never changes them.` : undefined}>
      <ScalarGrid scalars={metrics.scalars} keys={['duplicate_f1', 'duplicate_precision', 'duplicate_recall', 'macro_f1', 'accuracy', 'similar_f1', 'mean_similarity', 'support']} />
      {metrics.structured.confusion_matrix && <ConfusionMatrix data={metrics.structured.confusion_matrix} />}
      {sweep && (
        <div data-testid="threshold-sweep" className="overflow-x-auto">
          <table className="w-full text-sm">
            <caption className="text-xs text-sage-500 text-left mb-1">Threshold analysis for {sweep.positive} (evaluation-only; production threshold {sweep.production_threshold} is unchanged)</caption>
            <thead><tr className="text-left text-xs uppercase text-sage-500"><th className="py-1 pr-3">Threshold</th><th className="py-1 pr-3">Precision</th><th className="py-1 pr-3">Recall</th><th className="py-1 pr-3">F1</th></tr></thead>
            <tbody>
              {sweep.rows.map((r) => (
                <tr key={r.threshold} className={cn('border-t border-sage-100 dark:border-[#2A2A2A]', r.threshold === sweep.production_threshold && 'font-semibold')}>
                  <td className="py-1 pr-3 tabular-nums">{r.threshold.toFixed(2)}{r.threshold === sweep.production_threshold && <span className="ml-1 text-xs text-sage-500">(production)</span>}</td>
                  <td className="py-1 pr-3 tabular-nums">{r.precision.toFixed(2)}</td><td className="py-1 pr-3 tabular-nums">{r.recall.toFixed(2)}</td><td className="py-1 pr-3 tabular-nums">{r.f1.toFixed(2)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Section>
  );
};

export const GradingMetrics: React.FC<{ metrics: EvaluationMetrics }> = ({ metrics }) => {
  const s = metrics.scalars;
  const within = Object.keys(s).filter((k) => k.startsWith('within_'));
  const groups = metrics.structured.error_by_group ?? [];
  return (
    <Section testId="grading-metrics" title="AI grading assistance" description="AI suggested marks compared with finalized faculty marks. Closeness is not proof of correctness.">
      <ScalarGrid scalars={s} keys={['mae', 'rmse', 'mape', 'mean_signed_error', 'exact_agreement_rate', ...within, 'ai_mean_marks', 'faculty_mean_marks', 'compared_answers']} />
      {groups.length > 0 && (
        <div data-testid="grading-bias" className="overflow-x-auto">
          <table className="w-full text-sm">
            <caption className="text-xs text-sage-500 text-left mb-1">Error by academic group (question type / difficulty / Bloom level). Positive signed error = AI over-scores.</caption>
            <thead><tr className="text-left text-xs uppercase text-sage-500"><th className="py-1 pr-3">Dimension</th><th className="py-1 pr-3">Group</th><th className="py-1 pr-3">Answers</th><th className="py-1 pr-3">MAE</th><th className="py-1 pr-3">Signed error</th></tr></thead>
            <tbody>{groups.map((g) => (
              <tr key={`${g.dimension}-${g.group}`} className="border-t border-sage-100 dark:border-[#2A2A2A]"><td className="py-1 pr-3">{g.dimension}</td><td className="py-1 pr-3">{g.group}</td><td className="py-1 pr-3 tabular-nums">{g.count}</td><td className="py-1 pr-3 tabular-nums">{g.mae.toFixed(2)}</td><td className="py-1 pr-3 tabular-nums">{g.mean_signed_error > 0 ? '+' : ''}{g.mean_signed_error.toFixed(2)}</td></tr>
            ))}</tbody>
          </table>
        </div>
      )}
    </Section>
  );
};

export const RubricEvaluation: React.FC<{ metrics: EvaluationMetrics }> = ({ metrics }) => {
  const dims = metrics.structured.dimension_means ?? {};
  return (
    <Section testId="rubric-evaluation" title="Rubric generation" description="Marks validity (sum of criteria = total marks) is checked automatically; quality dimensions come from faculty 1–5 ratings.">
      <ScalarGrid scalars={metrics.scalars} keys={['marks_validity_rate', 'generated_count', 'valid_rubric_count', 'invalid_rubric_count', 'mean_rating', 'median_rating', 'rating_std_dev', 'rated_count', 'acceptance_rate', 'revision_rate', 'rejection_rate']} />
      {Object.keys(dims).length > 0 && (
        <dl className="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
          {Object.entries(dims).map(([d, v]) => <div key={d} className="flex justify-between border-b border-sage-100 dark:border-[#2A2A2A] py-1"><dt className="text-sage-500">{statusText(d)}</dt><dd className="tabular-nums">{v.toFixed(2)} / 5</dd></div>)}
        </dl>
      )}
    </Section>
  );
};

export const RagEvaluation: React.FC<{ metrics: EvaluationMetrics }> = ({ metrics }) => (
  <Section testId="rag-evaluation" title="Document chat (RAG)" description="Groundedness, citation quality, refusal behaviour on unanswerable questions, and prompt-injection resistance.">
    <ScalarGrid scalars={metrics.scalars} keys={['citation_accuracy', 'citation_coverage', 'answer_supported_rate', 'unsupported_answer_rate', 'correct_refusal_rate', 'false_refusal_rate', 'injection_leak_rate', 'injection_tests', 'answerable_count', 'unanswerable_count', 'evaluated']} />
  </Section>
);

export const ConstraintSatisfaction: React.FC<{ rows: PerConstraint[] }> = ({ rows }) => (
  <div data-testid="constraint-satisfaction" className="overflow-x-auto">
    <table className="w-full text-sm">
      <caption className="text-xs text-sage-500 text-left mb-1">Per-constraint satisfaction</caption>
      <thead><tr className="text-left text-xs uppercase text-sage-500"><th className="py-1 pr-3">Constraint</th><th className="py-1 pr-3">Satisfied</th><th className="py-1 pr-3">Checked</th><th className="py-1 pr-3">Rate</th></tr></thead>
      <tbody>{rows.map((r) => (
        <tr key={r.constraint} className="border-t border-sage-100 dark:border-[#2A2A2A]"><td className="py-1 pr-3">{statusText(r.constraint)}</td><td className="py-1 pr-3 tabular-nums">{r.satisfied}</td><td className="py-1 pr-3 tabular-nums">{r.total}</td><td className="py-1 pr-3 tabular-nums">{r.rate === null ? '—' : `${(r.rate * 100).toFixed(1)}%`}</td></tr>
      ))}</tbody>
    </table>
  </div>
);

export const QuestionGenerationEvaluation: React.FC<{ metrics: EvaluationMetrics }> = ({ metrics }) => (
  <Section testId="question-generation-evaluation" title="Constrained question generation" description="Share of generated drafts that satisfy every requested constraint (topic, CO, type, difficulty, Bloom, marks, similarity).">
    <ScalarGrid scalars={metrics.scalars} keys={['constraint_satisfaction_rate', 'generation_completeness', 'requested_questions', 'generated_questions', 'fully_valid', 'with_warnings', 'failed']} />
    {metrics.structured.per_constraint && metrics.structured.per_constraint.length > 0 && <ConstraintSatisfaction rows={metrics.structured.per_constraint} />}
  </Section>
);

/** Picks the right task display for a completed run. */
export const TaskMetrics: React.FC<{ run: RunDetail }> = ({ run }) => {
  const m = run.metrics;
  switch (run.task) {
    case 'QUESTION_CLASSIFICATION': case 'DIFFICULTY_CLASSIFICATION': case 'BLOOM_CLASSIFICATION': return <ClassificationMetrics metrics={m} />;
    case 'LO_ALIGNMENT': case 'ANSWER_RUBRIC_ALIGNMENT': return <AlignmentMetrics metrics={m} />;
    case 'SIMILARITY': return <SimilarityMetrics metrics={m} />;
    case 'GRADING_ASSISTANCE': return <GradingMetrics metrics={m} />;
    case 'RUBRIC_GENERATION': return <RubricEvaluation metrics={m} />;
    case 'DOCUMENT_CHAT': return <RagEvaluation metrics={m} />;
    case 'QUESTION_GENERATION': return <QuestionGenerationEvaluation metrics={m} />;
    default: return null;
  }
};

export const RunGates: React.FC<{ run: RunDetail }> = ({ run }) => (
  <Section testId="run-gates" title="Quality gates" description="Engineering gates configured in ai_evaluation.php — not claims of academic validity. A failing run is never deployed or rolled back automatically.">
    <div className="flex items-center gap-2 mb-1"><Badge variant={statusVariant(run.gate_status)} dot size="md">{statusText(run.gate_status)}</Badge>
      {run.summary?.regression?.regression && <Badge variant="Critical" size="md" data-testid="regression-badge">Performance regression detected</Badge>}</div>
    <GateList gates={run.metrics.gates} warnings={run.summary?.warnings} />
    {run.summary?.regression && run.summary.regression.previous_run_id && (
      <p className="text-xs text-sage-500">Compared with run #{run.summary.regression.previous_run_id}: {metricLabel(run.summary.regression.metric)} {formatMetric(run.summary.regression.metric, run.summary.regression.previous)} → {formatMetric(run.summary.regression.metric, run.summary.regression.current)}</p>
    )}
    {run.limitations.length > 0 && <ul className="list-disc pl-5 text-xs text-sage-600 dark:text-sage-400">{run.limitations.map((l) => <li key={l}>{l}</li>)}</ul>}
  </Section>
);

const summarize = (v: unknown): string => {
  if (v === null || v === undefined) return '—';
  if (typeof v === 'string') return v.length > 160 ? `${v.slice(0, 160)}…` : v;
  if (typeof v === 'number' || typeof v === 'boolean') return String(v);
  const s = JSON.stringify(v);
  return s.length > 160 ? `${s.slice(0, 160)}…` : s;
};

export const ErrorAnalysis: React.FC<{ items: ErrorAnalysisItem[]; breakdown: Record<string, number>; total: number; loading?: boolean; filter: string; onFilter: (t: string) => void; onLoadMore?: () => void; hasMore?: boolean }> = ({ items, breakdown, total, loading, filter, onFilter, onLoadMore, hasMore }) => (
  <Section testId="error-analysis" title="Error analysis" description={`${total} example-level result${total === 1 ? '' : 's'} (errors only). Inputs are truncated; student answers are never exported by default.`}>
    <div className="flex flex-wrap gap-2" role="group" aria-label="Filter by error type">
      <Button size="sm" variant={filter === '' ? 'primary' : 'outline'} onClick={() => onFilter('')}>All errors</Button>
      {Object.entries(breakdown).map(([t, c]) => <Button key={t} size="sm" variant={filter === t ? 'primary' : 'outline'} onClick={() => onFilter(t)}>{t} ({c})</Button>)}
    </div>
    {items.length === 0 && !loading && <p className="text-sm text-sage-500">No errors recorded for this run.</p>}
    {items.length > 0 && (
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <caption className="sr-only">Example-level evaluation errors</caption>
          <thead><tr className="text-left text-xs uppercase text-sage-500"><th className="py-1 pr-3">Example</th><th className="py-1 pr-3">Input</th><th className="py-1 pr-3">Expected</th><th className="py-1 pr-3">Predicted</th><th className="py-1 pr-3">Error</th></tr></thead>
          <tbody>{items.map((it) => (
            <tr key={it.id} className="border-t border-sage-100 dark:border-[#2A2A2A] align-top">
              <td className="py-1 pr-3 tabular-nums">#{it.example_id}</td>
              <td className="py-1 pr-3 max-w-xs text-xs text-sage-600 dark:text-sage-400">{summarize(it.input.question ?? it.input.question_a ?? it.input.answer ?? it.input)}</td>
              <td className="py-1 pr-3 text-xs font-mono">{summarize(it.expected_output)}</td>
              <td className="py-1 pr-3 text-xs font-mono">{summarize(it.prediction)}</td>
              <td className="py-1 pr-3"><Badge variant="Attention" size="sm">{it.error_type ?? 'OTHER'}</Badge></td>
            </tr>
          ))}</tbody>
        </table>
      </div>
    )}
    {hasMore && onLoadMore && <Button size="sm" variant="ghost" onClick={onLoadMore} disabled={loading}>{loading ? 'Loading…' : 'Load more'}</Button>}
  </Section>
);

export const RunMeta: React.FC<{ run: RunDetail }> = ({ run }) => (
  <dl data-testid="run-meta" className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
    <div><dt className="text-xs text-sage-500">Task</dt><dd className="font-medium">{TASK_LABELS[run.task]}</dd></div>
    <div><dt className="text-xs text-sage-500">Dataset</dt><dd className="font-medium">{run.dataset ? `${run.dataset.name} (${run.dataset.version})` : '—'}</dd></div>
    <div><dt className="text-xs text-sage-500">Model</dt><dd className="font-medium font-mono text-xs break-all">{run.model ? `${run.model.name} · ${run.model.version}` : 'No model registered'}</dd></div>
    <div><dt className="text-xs text-sage-500">Prompt version</dt><dd className="font-medium">{run.prompt_version ? `${run.prompt_version.feature} ${run.prompt_version.version}` : '—'}</dd></div>
    <div><dt className="text-xs text-sage-500">Examples</dt><dd className="font-medium tabular-nums">{run.example_count} <span className="text-xs text-sage-500">({run.summary?.size_category?.replace('_', ' ').toLowerCase() ?? '—'})</span></dd></div>
    <div><dt className="text-xs text-sage-500">Inference time</dt><dd className="font-medium tabular-nums">{run.inference_ms !== null ? `${(run.inference_ms / 1000).toFixed(1)} s` : '—'}</dd></div>
    <div><dt className="text-xs text-sage-500">Completed</dt><dd className="font-medium">{run.completed_at ? new Date(run.completed_at).toLocaleString() : '—'}</dd></div>
    <div><dt className="text-xs text-sage-500">Status</dt><dd><Badge variant={statusVariant(run.status)} size="sm">{statusText(run.status)}</Badge></dd></div>
  </dl>
);

/** Small task selector used to switch which classification confusion matrix is shown on the overview. */
export const TaskPicker: React.FC<{ tasks: RunDetail['task'][]; value: RunDetail['task'] | ''; onChange: (t: RunDetail['task'] | '') => void; label?: string }> = ({ tasks, value, onChange, label = 'Task' }) => {
  const [id] = useState(() => `task-picker-${Math.random().toString(36).slice(2, 8)}`);
  return (
    <div className="flex items-center gap-2">
      <label htmlFor={id} className="text-xs text-sage-500">{label}</label>
      <select id={id} value={value} onChange={(e) => onChange(e.target.value as RunDetail['task'] | '')} className="rounded-md border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-2 py-1 text-sm">
        <option value="">All tasks</option>
        {tasks.map((t) => <option key={t} value={t}>{TASK_LABELS[t]}</option>)}
      </select>
    </div>
  );
};
