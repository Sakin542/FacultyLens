import React, { useState } from 'react';
import { Trash2 } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { Card } from '@/components/common/Card';
import { Input } from '@/components/common/Input';
import { cn } from '@/utils/cn';
import {
  CreateDatasetInput, DatasetSource, DatasetSplit, DatasetValidationReport, EVALUATION_TASKS, EvaluationDataset, EvaluationRun, EvaluationTask, RunComparison, TASK_LABELS,
} from '@/types/aiEvaluation';
import { formatMetric, metricLabel, statusText, statusVariant } from './EvaluationStates';

/** STEP 35: dataset management, validation, run history and run comparison. */

const SOURCES: DatasetSource[] = ['FACULTY_VALIDATED', 'SYNTHETIC', 'IMPORTED', 'PRODUCTION_SAMPLE'];
const SPLITS: DatasetSplit[] = ['ALL', 'TRAIN', 'VALIDATION', 'TEST'];

const EXAMPLE_HINTS: Record<EvaluationTask, string> = {
  QUESTION_CLASSIFICATION: '[{"input_data":{"question":"Explain normalization."},"expected_output":{"expected_type":"DESCRIPTIVE"}}]',
  DIFFICULTY_CLASSIFICATION: '[{"input_data":{"question":"Define a primary key."},"expected_output":{"expected_difficulty":"EASY"}}]',
  BLOOM_CLASSIFICATION: '[{"input_data":{"question":"Compare 2NF and 3NF."},"expected_output":{"expected_cognitive_level":"ANALYZE"}}]',
  LO_ALIGNMENT: '[{"input_data":{"question":"...","learning_outcome":"..."},"expected_output":{"expected_alignment":"STRONG"}}]',
  SIMILARITY: '[{"input_data":{"question_a":"...","question_b":"..."},"expected_output":{"expected_relationship":"NOT_SIMILAR"}}]',
  RUBRIC_GENERATION: '[{"input_data":{"question":"...","total_marks":10},"expected_output":{"ratings":{"criterion_relevance":4},"decision":"ACCEPTED"}}]',
  GRADING_ASSISTANCE: '[{"input_data":{"ai_marks":9,"question_type":"DESCRIPTIVE"},"expected_output":{"faculty_marks":10}}]',
  ANSWER_RUBRIC_ALIGNMENT: '[{"input_data":{"ai_criterion_statuses":{"1":"FULLY_ALIGNED"}},"expected_output":{"criterion_statuses":{"1":"FULLY_ALIGNED"}}}]',
  DOCUMENT_CHAT: '[{"input_data":{"question":"...","documents":[{"name":"notes.pdf","text":"...","page":1}]},"expected_output":{"answer_present":true,"expected_source":"notes.pdf","expected_keywords":["redundancy"]}}]',
  QUESTION_GENERATION: '[{"input_data":{"topic":"Normalization","question_type":"DESCRIPTIVE","difficulty":"MEDIUM","cognitive_level":"ANALYZE","marks":10,"count":3},"expected_output":{"constraints_required":true}}]',
};

export const DatasetValidation: React.FC<{ report: DatasetValidationReport | null; datasetStatus: EvaluationDataset['status'] }> = ({ report, datasetStatus }) => {
  if (!report) return <p data-testid="dataset-validation" className="text-sm text-sage-500">Not validated yet. Validate the dataset before running an evaluation.</p>;
  return (
    <div data-testid="dataset-validation" className="space-y-2">
      <div className="flex flex-wrap items-center gap-2">
        <Badge variant={report.is_valid ? 'Good' : 'Critical'} dot size="md">{report.is_valid ? 'Valid dataset' : 'Invalid dataset — evaluation cannot start'}</Badge>
        <Badge variant="neutral" size="md">{statusText(datasetStatus)}</Badge>
        <Badge variant={report.small_dataset_warning ? 'Attention' : 'neutral'} size="md">{report.size_category.replace('_', ' ')}</Badge>
      </div>
      <dl className="grid grid-cols-2 sm:grid-cols-5 gap-2 text-sm">
        {([['Total', report.total_examples], ['Valid', report.valid_examples], ['Invalid', report.invalid_examples], ['Duplicates', report.duplicate_examples], ['Conflicts', report.conflicting_labels]] as const).map(([l, v]) => (
          <div key={l} className="rounded-md border border-sage-100 dark:border-[#2A2A2A] px-2 py-1"><dt className="text-xs text-sage-500">{l}</dt><dd className="font-semibold tabular-nums">{v}</dd></div>
        ))}
      </dl>
      {Object.keys(report.label_distribution).length > 0 && (
        <p className="text-xs text-sage-500">Label distribution: {Object.entries(report.label_distribution).map(([l, c]) => `${l} ${c}`).join(' · ')}</p>
      )}
      {report.small_dataset_warning && <p className="text-xs text-amber-800 dark:text-amber-300">Small evaluation dataset. Results should be interpreted cautiously.</p>}
      {report.problems.length > 0 && (
        <ul className="list-disc pl-5 text-xs text-red-700 dark:text-red-300 max-h-40 overflow-y-auto">
          {report.problems.slice(0, 50).map((p, i) => <li key={i}>{p.example_id ? `Example #${p.example_id}: ` : ''}{p.errors.join(' ')}</li>)}
        </ul>
      )}
    </div>
  );
};

interface DatasetManagerProps {
  datasets: EvaluationDataset[];
  selected: EvaluationDataset | null;
  onSelect: (d: EvaluationDataset) => void;
  onCreate: (input: CreateDatasetInput) => Promise<void>;
  onValidate: (d: EvaluationDataset) => Promise<void>;
  onRun: (d: EvaluationDataset) => Promise<void>;
  onDelete: (d: EvaluationDataset) => Promise<void>;
  busy?: boolean;
  taskFilter: EvaluationTask | '';
  onTaskFilter: (t: EvaluationTask | '') => void;
}

export const DatasetManager: React.FC<DatasetManagerProps> = ({ datasets, selected, onSelect, onCreate, onValidate, onRun, onDelete, busy, taskFilter, onTaskFilter }) => {
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [version, setVersion] = useState('v1');
  const [task, setTask] = useState<EvaluationTask>('DIFFICULTY_CLASSIFICATION');
  const [source, setSource] = useState<DatasetSource>('FACULTY_VALIDATED');
  const [split, setSplit] = useState<DatasetSplit>('TEST');
  const [description, setDescription] = useState('');
  const [examples, setExamples] = useState('');
  const [formError, setFormError] = useState<string | null>(null);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormError(null);
    if (!name.trim()) { setFormError('Dataset name is required.'); return; }
    let parsed: CreateDatasetInput['examples'] = [];
    if (examples.trim()) {
      try {
        const raw: unknown = JSON.parse(examples);
        if (!Array.isArray(raw)) throw new Error('Examples must be a JSON array.');
        parsed = raw as CreateDatasetInput['examples'];
      } catch (err) {
        setFormError(err instanceof Error ? `Examples JSON is malformed: ${err.message}` : 'Examples JSON is malformed.');
        return;
      }
    }
    await onCreate({ name: name.trim(), version: version.trim() || 'v1', task, source, split, description: description.trim() || undefined, examples: parsed });
    setOpen(false); setName(''); setExamples(''); setDescription('');
  };

  return (
    <Card data-testid="dataset-manager" className="p-4 space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-sage-800 dark:text-white">Evaluation datasets</h3>
        <div className="flex items-center gap-2">
          <label htmlFor="dataset-task-filter" className="sr-only">Filter datasets by task</label>
          <select id="dataset-task-filter" value={taskFilter} onChange={(e) => onTaskFilter(e.target.value as EvaluationTask | '')} className="rounded-md border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-2 py-1 text-sm">
            <option value="">All tasks</option>{EVALUATION_TASKS.map((t) => <option key={t} value={t}>{TASK_LABELS[t]}</option>)}
          </select>
          <Button size="sm" onClick={() => setOpen((o) => !o)} aria-expanded={open}>{open ? 'Close' : 'New dataset'}</Button>
        </div>
      </div>

      {open && (
        <form onSubmit={submit} noValidate data-testid="dataset-form" className="rounded-lg border border-sage-200 dark:border-[#2A2A2A] p-3 space-y-3 bg-[#FAFAF8] dark:bg-[#1A1A1A]">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <Input label="Dataset name" value={name} onChange={(e) => setName(e.target.value)} required placeholder="FacultyLens Evaluation v1" />
            <Input label="Version" value={version} onChange={(e) => setVersion(e.target.value)} />
            <div className="space-y-1.5">
              <label htmlFor="ds-task" className="block text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-300">Task</label>
              <select id="ds-task" value={task} onChange={(e) => setTask(e.target.value as EvaluationTask)} className="w-full rounded-md border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-2 py-2 text-sm">
                {EVALUATION_TASKS.map((t) => <option key={t} value={t}>{TASK_LABELS[t]}</option>)}
              </select>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <label htmlFor="ds-source" className="block text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-300">Ground-truth source</label>
                <select id="ds-source" value={source} onChange={(e) => setSource(e.target.value as DatasetSource)} className="w-full rounded-md border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-2 py-2 text-sm">
                  {SOURCES.map((s) => <option key={s} value={s}>{statusText(s)}</option>)}
                </select>
              </div>
              <div className="space-y-1.5">
                <label htmlFor="ds-split" className="block text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-300">Split</label>
                <select id="ds-split" value={split} onChange={(e) => setSplit(e.target.value as DatasetSplit)} className="w-full rounded-md border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-2 py-2 text-sm">
                  {SPLITS.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
              </div>
            </div>
          </div>
          <Input label="Description" value={description} onChange={(e) => setDescription(e.target.value)} placeholder="Optional" />
          <div className="space-y-1.5">
            <label htmlFor="ds-examples" className="block text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-300">Examples (JSON array, faculty-validated ground truth)</label>
            <textarea id="ds-examples" value={examples} onChange={(e) => setExamples(e.target.value)} rows={5} placeholder={EXAMPLE_HINTS[task]} className="w-full rounded-md border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-2 py-2 text-xs font-mono" />
            <p className="text-xs text-sage-500">Held-out TEST examples are recommended. Do not evaluate only on data used to tune prompts or rules.</p>
          </div>
          {formError && <p role="alert" className="text-sm text-red-700 dark:text-red-300">{formError}</p>}
          <div className="flex justify-end gap-2"><Button type="button" variant="ghost" size="sm" onClick={() => setOpen(false)}>Cancel</Button><Button type="submit" size="sm" disabled={busy}>Create dataset</Button></div>
        </form>
      )}

      {datasets.length === 0 ? (
        <p className="text-sm text-sage-500">No evaluation datasets yet.</p>
      ) : (
        <ul className="divide-y divide-sage-100 dark:divide-[#2A2A2A]" aria-label="Evaluation datasets">
          {datasets.map((d) => (
            <li key={d.id} className={cn('py-2 flex flex-wrap items-center gap-2', selected?.id === d.id && 'bg-[#FAFAF8] dark:bg-[#1A1A1A] -mx-2 px-2 rounded')}>
              <button type="button" onClick={() => onSelect(d)} className="flex-1 text-left min-w-0" aria-current={selected?.id === d.id ? 'true' : undefined}>
                <p className="text-sm font-medium text-sage-800 dark:text-white truncate">{d.name} <span className="text-xs text-sage-500">{d.version}</span></p>
                <p className="text-xs text-sage-500">{TASK_LABELS[d.task]} · {d.examples_count ?? 0} examples · {statusText(d.source)} · {d.split}</p>
              </button>
              <Badge variant={d.status === 'READY' || d.status === 'COMPLETED' ? 'Good' : d.status === 'RUNNING' ? 'Pending' : 'neutral'} size="sm">{statusText(d.status)}</Badge>
              <div className="flex items-center gap-1">
                <Button size="sm" variant="outline" disabled={busy || d.status === 'RUNNING'} onClick={() => void onValidate(d)}>Validate</Button>
                <Button size="sm" disabled={busy || d.status === 'RUNNING' || !(d.validation_report?.is_valid)} onClick={() => void onRun(d)} title={d.validation_report?.is_valid ? 'Start evaluation' : 'Validate the dataset first'}>Run</Button>
                <Button size="sm" variant="ghost" aria-label={`Delete dataset ${d.name}`} disabled={busy || d.status === 'RUNNING'} onClick={() => void onDelete(d)}><Trash2 className="w-4 h-4" /></Button>
              </div>
            </li>
          ))}
        </ul>
      )}
      {selected && <div className="pt-2 border-t border-sage-100 dark:border-[#2A2A2A]"><DatasetValidation report={selected.validation_report} datasetStatus={selected.status} /></div>}
    </Card>
  );
};

interface HistoryProps {
  runs: EvaluationRun[];
  selectedId: number | null;
  onOpen: (run: EvaluationRun) => void;
  compareIds: number[];
  onToggleCompare: (id: number) => void;
  taskFilter: EvaluationTask | '';
  onTaskFilter: (t: EvaluationTask | '') => void;
}

export const EvaluationRunHistory: React.FC<HistoryProps> = ({ runs, selectedId, onOpen, compareIds, onToggleCompare, taskFilter, onTaskFilter }) => (
  <Card data-testid="evaluation-run-history" className="p-4 space-y-3">
    <div className="flex flex-wrap items-center justify-between gap-2">
      <h3 className="text-sm font-semibold text-sage-800 dark:text-white">Evaluation history</h3>
      <div className="flex items-center gap-2">
        <label htmlFor="run-task-filter" className="sr-only">Filter runs by task</label>
        <select id="run-task-filter" value={taskFilter} onChange={(e) => onTaskFilter(e.target.value as EvaluationTask | '')} className="rounded-md border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-2 py-1 text-sm">
          <option value="">All tasks</option>{EVALUATION_TASKS.map((t) => <option key={t} value={t}>{TASK_LABELS[t]}</option>)}
        </select>
      </div>
    </div>
    {runs.length === 0 ? <p className="text-sm text-sage-500">No evaluation runs yet.</p> : (
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <caption className="sr-only">Evaluation runs</caption>
          <thead><tr className="text-left text-xs uppercase text-sage-500"><th className="py-1 pr-2"><span className="sr-only">Compare</span></th><th className="py-1 pr-3">Run</th><th className="py-1 pr-3">Task</th><th className="py-1 pr-3">Dataset</th><th className="py-1 pr-3">Model</th><th className="py-1 pr-3">Headline</th><th className="py-1 pr-3">Status</th><th className="py-1 pr-3">Gate</th><th className="py-1 pr-3">Date</th></tr></thead>
          <tbody>
            {runs.map((r) => (
              <tr key={r.id} className={cn('border-t border-sage-100 dark:border-[#2A2A2A]', selectedId === r.id && 'bg-[#FAFAF8] dark:bg-[#1A1A1A]')}>
                <td className="py-1 pr-2"><input type="checkbox" aria-label={`Select run ${r.id} for comparison`} checked={compareIds.includes(r.id)} onChange={() => onToggleCompare(r.id)} disabled={r.status !== 'COMPLETED' || (!compareIds.includes(r.id) && compareIds.length >= 2)} /></td>
                <td className="py-1 pr-3"><button type="button" className="font-medium underline underline-offset-2" onClick={() => onOpen(r)}>#{r.id}</button></td>
                <td className="py-1 pr-3 text-xs">{TASK_LABELS[r.task]}</td>
                <td className="py-1 pr-3 text-xs">{r.dataset ? `${r.dataset.name} ${r.dataset.version}` : '—'}</td>
                <td className="py-1 pr-3 text-xs font-mono truncate max-w-[10rem]" title={r.model?.name}>{r.model ? r.model.name.split('/').pop() : '—'}</td>
                <td className="py-1 pr-3 tabular-nums">{r.summary ? `${metricLabel(r.summary.headline_metric)} ${formatMetric(r.summary.headline_metric, r.summary.headline_value)}` : '—'}{r.summary?.regression?.regression && <span className="ml-1 text-xs text-red-700">↓ regression</span>}</td>
                <td className="py-1 pr-3"><Badge variant={statusVariant(r.status)} size="sm">{statusText(r.status)}</Badge></td>
                <td className="py-1 pr-3">{r.gate_status ? <Badge variant={statusVariant(r.gate_status)} size="sm">{statusText(r.gate_status)}</Badge> : '—'}</td>
                <td className="py-1 pr-3 text-xs text-sage-500">{r.created_at ? new Date(r.created_at).toLocaleDateString() : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    )}
  </Card>
);

export const EvaluationRunComparison: React.FC<{ comparison: RunComparison | null; loading?: boolean; onClear: () => void }> = ({ comparison, loading, onClear }) => {
  if (loading) return <Card className="p-4 text-sm text-sage-500" data-testid="evaluation-run-comparison">Comparing runs…</Card>;
  if (!comparison) return null;
  const { run_a: a, run_b: b } = comparison;
  return (
    <Card data-testid="evaluation-run-comparison" className="p-4 space-y-3">
      <div className="flex items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-sage-800 dark:text-white">Run comparison: #{a.id} vs #{b.id}</h3>
        <Button size="sm" variant="ghost" onClick={onClear}>Clear</Button>
      </div>
      {!comparison.same_task && <p className="text-xs text-amber-800 dark:text-amber-300">These runs evaluate different tasks; only shared metrics are comparable.</p>}
      <p className="text-xs text-sage-500">A: {a.model?.name ?? 'no model'} ({a.prompt_version ? a.prompt_version.version : 'no prompt'}) on {a.dataset?.name ?? '—'} · B: {b.model?.name ?? 'no model'} ({b.prompt_version ? b.prompt_version.version : 'no prompt'}) on {b.dataset?.name ?? '—'}</p>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <caption className="sr-only">Metric comparison between two runs</caption>
          <thead><tr className="text-left text-xs uppercase text-sage-500"><th className="py-1 pr-3">Metric</th><th className="py-1 pr-3">Run #{a.id}</th><th className="py-1 pr-3">Run #{b.id}</th><th className="py-1 pr-3">Δ</th><th className="py-1 pr-3">Direction</th></tr></thead>
          <tbody>
            {comparison.rows.map((r) => (
              <tr key={r.metric} className={cn('border-t border-sage-100 dark:border-[#2A2A2A]', r.metric === comparison.headline_metric && 'font-semibold')}>
                <td className="py-1 pr-3">{metricLabel(r.metric)}</td>
                <td className="py-1 pr-3 tabular-nums">{formatMetric(r.metric, r.run_a)}</td>
                <td className="py-1 pr-3 tabular-nums">{formatMetric(r.metric, r.run_b)}</td>
                <td className="py-1 pr-3 tabular-nums">{r.delta === null ? '—' : `${r.delta > 0 ? '+' : ''}${r.delta.toFixed(4)}`}</td>
                <td className="py-1 pr-3"><Badge variant={r.direction === 'improved' ? 'Good' : r.direction === 'degraded' ? 'Critical' : 'neutral'} size="sm">{r.direction}</Badge></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-sage-500">{comparison.note}</p>
    </Card>
  );
};
