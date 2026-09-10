import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { AiEvaluation } from '@/pages/AiEvaluation';
import { EvaluationEmptyState, EvaluationError, EvaluationProgress, MetricCard, ModelPerformanceCard, formatMetric, getEvaluationErrorMessage } from '@/components/aiEvaluation/EvaluationStates';
import { ClassificationMetrics, ConfusionMatrix, GradingMetrics, PerClassMetrics, QuestionGenerationEvaluation, RagEvaluation, SimilarityMetrics } from '@/components/aiEvaluation/EvaluationMetrics';
import { DatasetValidation, EvaluationRunComparison } from '@/components/aiEvaluation/EvaluationManagement';
import { ApiError } from '@/services/api';
import { EvaluationDataset, EvaluationMetrics, EvaluationOverview, EvaluationRun, RunDetail, TaskOverview } from '@/types/aiEvaluation';

vi.mock('@/services/aiEvaluationService', () => ({
  aiEvaluationService: {
    getOverview: vi.fn(), getDatasets: vi.fn(), createDataset: vi.fn(), validateDataset: vi.fn(), runEvaluation: vi.fn(), deleteDataset: vi.fn(),
    getEvaluationRuns: vi.fn(), getEvaluationRun: vi.fn(), getErrors: vi.fn(), compareRuns: vi.fn(), cancelRun: vi.fn(), exportReport: vi.fn(),
  },
}));
vi.mock('@/context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, name: 'Dr. A', role: 'FACULTY' }, loading: false }) }));

import { aiEvaluationService } from '@/services/aiEvaluationService';
const svc = aiEvaluationService as unknown as Record<string, ReturnType<typeof vi.fn>>;
const ok = <T,>(data: T, extra: Record<string, unknown> = {}) => ({ status: 'success', message: 'ok', data, ...extra });

const emptyOverview: EvaluationOverview = {
  overall_status: 'NOT_EVALUATED', dataset_count: 0, run_count: 0, models: [], prompt_versions: [],
  tasks: (['QUESTION_CLASSIFICATION', 'DIFFICULTY_CLASSIFICATION'] as const).map((task) => ({ task, evaluated: false, run: null, headline_metric: 'macro_f1', headline_value: null, gate_status: null, regression: false, size_category: null, warnings: [] })),
  faculty_signals: { recommendations: {}, generated_questions: {}, rubrics: {}, grading: {}, note: 'Faculty interaction signals reflect review decisions, not AI correctness.' },
  limitations: ['Evaluation dataset size may be limited.'],
};

const run: EvaluationRun = {
  id: 7, task: 'DIFFICULTY_CLASSIFICATION', status: 'COMPLETED', gate_status: 'FAILED', dataset: { id: 3, name: 'Difficulty test set', version: 'v1' },
  model: { id: 1, name: 'facultylens/rule-based-analyzer', version: '2.0.0', type: 'rule_based' }, prompt_version: null, example_count: 4, processed_count: 4, inference_ms: 120,
  configuration: null, failure_reason: null, started_at: null, completed_at: '2026-09-11T10:00:00Z', created_at: '2026-09-11T09:59:00Z', created_by: 1,
  summary: { headline_metric: 'macro_f1', headline_value: 0.7333, metrics: { accuracy: 0.75, macro_f1: 0.7333 }, gates: [{ metric: 'macro_f1', value: 0.7333, min: 0.8, max: null, passed: false }],
    warnings: ['Small evaluation dataset (4 examples). Results should be interpreted cautiously.', 'Macro f1 (0.7333) below configured target 0.8.'],
    regression: { previous_run_id: 5, metric: 'macro_f1', current: 0.7333, previous: 1, delta: -0.2667, regression: true, lower_is_better: false },
    error_breakdown: { WRONG_DIFFICULTY: 1 }, size_category: 'VERY_LIMITED', limitations: ['Ground-truth labels were faculty-validated.'] },
};
const metrics: EvaluationMetrics = {
  task: 'DIFFICULTY_CLASSIFICATION', headline_metric: 'macro_f1', scalars: { accuracy: 0.75, macro_f1: 0.7333, weighted_f1: 0.8333, support: 4 },
  structured: {
    confusion_matrix: { labels: ['EASY', 'MEDIUM', 'HARD'], matrix: [[1, 0, 1], [0, 0, 0], [0, 0, 2]] },
    per_class: [{ label: 'EASY', precision: 1, recall: 0.5, f1: 0.6667, support: 2, tp: 1, fp: 0, fn: 1 }, { label: 'HARD', precision: 0.6667, recall: 1, f1: 0.8, support: 2, tp: 2, fp: 1, fn: 0 }],
  },
  gates: run.summary!.gates, regression: run.summary!.regression,
};
const runDetail: RunDetail = { ...run, metrics, limitations: ['Ground-truth labels were faculty-validated.'] };
const evaluatedOverview: EvaluationOverview = {
  ...emptyOverview, overall_status: 'FAILED', dataset_count: 1, run_count: 2,
  models: [{ id: 1, model_name: 'sentence-transformers/all-MiniLM-L6-v2', provider: 'Hugging Face', model_type: 'embedding', task: 'SIMILARITY', version: '1.0', is_active: true }],
  tasks: [{ task: 'DIFFICULTY_CLASSIFICATION', evaluated: true, run, headline_metric: 'macro_f1', headline_value: 0.7333, gate_status: 'FAILED', regression: true, size_category: 'VERY_LIMITED', warnings: run.summary!.warnings },
    { task: 'QUESTION_CLASSIFICATION', evaluated: false, run: null, headline_metric: 'macro_f1', headline_value: null, gate_status: null, regression: false, size_category: null, warnings: [] }],
};
const dataset: EvaluationDataset = {
  id: 3, name: 'Difficulty test set', description: null, task: 'DIFFICULTY_CLASSIFICATION', version: 'v1', source: 'FACULTY_VALIDATED', split: 'TEST', status: 'READY', course_id: null,
  created_by: { id: 1, name: 'Dr. A' }, examples_count: 4, runs_count: 1, validated_at: null, created_at: null, updated_at: null,
  validation_report: { is_valid: true, total_examples: 4, valid_examples: 4, invalid_examples: 0, duplicate_examples: 0, conflicting_labels: 0, problems: [], label_distribution: { EASY: 2, HARD: 2 }, size_category: 'VERY_LIMITED', small_dataset_warning: true },
};

const renderPage = () => render(<MemoryRouter><AiEvaluation /></MemoryRouter>);

beforeEach(() => {
  vi.clearAllMocks();
  svc.getOverview.mockResolvedValue(ok(emptyOverview));
  svc.getDatasets.mockResolvedValue(ok([]));
  svc.getEvaluationRuns.mockResolvedValue(ok([], { meta: { current_page: 1, last_page: 1, total: 0 } }));
  svc.getErrors.mockResolvedValue(ok([], { meta: { current_page: 1, last_page: 1, total: 0, error_breakdown: {} } }));
});

describe('AiEvaluation page', () => {
  it('shows "Not evaluated yet" with no fake metrics when nothing has been evaluated', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByTestId('evaluation-empty-state')).toBeInTheDocument());
    expect(screen.getByTestId('overall-status')).toHaveTextContent('Not evaluated');
    expect(screen.queryByTestId('model-performance-card')).not.toBeInTheDocument();
    expect(screen.getByTestId('dataset-manager')).toHaveTextContent('No evaluation datasets yet.');
  });

  it('renders real metrics from the overview and marks unevaluated tasks', async () => {
    svc.getOverview.mockResolvedValue(ok(evaluatedOverview));
    svc.getDatasets.mockResolvedValue(ok([dataset]));
    svc.getEvaluationRuns.mockResolvedValue(ok([run], { meta: { current_page: 1, last_page: 1, total: 1 } }));
    renderPage();
    await waitFor(() => expect(screen.getByTestId('evaluation-summary')).toBeInTheDocument());
    const cards = screen.getAllByTestId('model-performance-card');
    expect(cards).toHaveLength(2);
    expect(cards[0]).toHaveTextContent('73.3%');
    expect(cards[0]).toHaveTextContent('Performance regression detected');
    expect(cards[1]).toHaveTextContent('Not evaluated yet');
    expect(screen.getByText('sentence-transformers/all-MiniLM-L6-v2')).toBeInTheDocument();
    expect(screen.getByText('embedding')).toBeInTheDocument();
    expect(screen.getByTestId('evaluation-run-history')).toHaveTextContent('Macro F1 73.3%');
  });

  it('creates a dataset, validates it, starts a run and opens results with confusion matrix and errors', async () => {
    svc.getDatasets.mockResolvedValueOnce(ok([])).mockResolvedValue(ok([dataset]));
    svc.createDataset.mockResolvedValue(ok({ ...dataset, status: 'DRAFT', validation_report: null, import: { added: 4, skipped_duplicates: 0 } }, { message: 'Dataset created.' }));
    svc.validateDataset.mockResolvedValue(ok(dataset.validation_report));
    svc.runEvaluation.mockResolvedValue(ok({ ...run, status: 'PENDING', summary: null, gate_status: null }, { message: 'Evaluation started.' }));
    svc.getEvaluationRun.mockResolvedValue(ok(runDetail));
    svc.getErrors.mockResolvedValue(ok([{ id: 1, example_id: 12, input: { question: 'Hard question' }, expected_output: { expected_difficulty: 'EASY' }, prediction: { label: 'HARD' }, is_correct: false, score: null, error_type: 'WRONG_DIFFICULTY', metadata: null }],
      { meta: { current_page: 1, last_page: 1, total: 1, error_breakdown: { WRONG_DIFFICULTY: 1 } } }));
    renderPage();
    await waitFor(() => expect(screen.getByTestId('dataset-manager')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: 'New dataset' }));
    const form = screen.getByTestId('dataset-form');
    fireEvent.change(within(form).getByLabelText(/Dataset name/), { target: { value: 'Difficulty test set' } });
    fireEvent.change(within(form).getByLabelText(/Examples/), { target: { value: '{not json' } });
    fireEvent.click(within(form).getByRole('button', { name: 'Create dataset' }));
    expect(await within(form).findByRole('alert')).toHaveTextContent('malformed');
    expect(svc.createDataset).not.toHaveBeenCalled();

    fireEvent.change(within(form).getByLabelText(/Examples/), { target: { value: '[{"input_data":{"question":"Define a key."},"expected_output":{"expected_difficulty":"EASY"}}]' } });
    fireEvent.click(within(form).getByRole('button', { name: 'Create dataset' }));
    await waitFor(() => expect(svc.createDataset).toHaveBeenCalledWith(expect.objectContaining({ name: 'Difficulty test set', task: 'DIFFICULTY_CLASSIFICATION', split: 'TEST', source: 'FACULTY_VALIDATED', examples: [expect.objectContaining({ expected_output: { expected_difficulty: 'EASY' } })] })));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Imported 4 example(s)'));

    fireEvent.click(screen.getByRole('button', { name: 'Validate' }));
    await waitFor(() => expect(svc.validateDataset).toHaveBeenCalledWith(3));
    expect(await screen.findByText('Valid dataset')).toBeInTheDocument();
    expect(screen.getByTestId('dataset-validation')).toHaveTextContent('EASY 2 · HARD 2');
    expect(screen.getByTestId('dataset-validation')).toHaveTextContent('Small evaluation dataset');

    fireEvent.click(screen.getByRole('button', { name: 'Run' }));
    await waitFor(() => expect(svc.runEvaluation).toHaveBeenCalledWith(3));
    await waitFor(() => expect(screen.getByTestId('run-results')).toBeInTheDocument());
    expect(screen.getByTestId('run-meta')).toHaveTextContent('facultylens/rule-based-analyzer');
    expect(screen.getByTestId('regression-badge')).toBeInTheDocument();
    expect(screen.getByTestId('quality-gates')).toHaveTextContent('Macro F1: 73.3% (target ≥ 80.0%)');
    const cm = screen.getByTestId('confusion-matrix');
    expect(within(cm).getByLabelText('Actual EASY, predicted HARD: 1')).toBeInTheDocument();
    expect(screen.getByTestId('per-class-metrics')).toHaveTextContent('HARD');
    await waitFor(() => expect(screen.getByTestId('error-analysis')).toHaveTextContent('WRONG_DIFFICULTY'));
    expect(screen.getByTestId('error-analysis')).toHaveTextContent('#12');

    fireEvent.click(screen.getByRole('button', { name: /CSV/ }));
    await waitFor(() => expect(svc.exportReport).toHaveBeenCalledWith(7, 'csv'));
  });

  it('compares two completed runs and shows improvement/degradation', async () => {
    const runB: EvaluationRun = { ...run, id: 9, gate_status: 'PASSED', summary: { ...run.summary!, headline_value: 0.9, regression: null } };
    svc.getOverview.mockResolvedValue(ok(evaluatedOverview));
    svc.getEvaluationRuns.mockResolvedValue(ok([run, runB], { meta: { current_page: 1, last_page: 1, total: 2 } }));
    svc.compareRuns.mockResolvedValue(ok({ run_a: run, run_b: runB, same_task: true, headline_metric: 'macro_f1', note: 'Comparison is informational. No model is selected or promoted automatically.',
      rows: [{ metric: 'macro_f1', run_a: 0.7333, run_b: 0.9, delta: 0.1667, direction: 'improved' }, { metric: 'accuracy', run_a: 0.75, run_b: 0.5, delta: -0.25, direction: 'degraded' }] }));
    renderPage();
    await waitFor(() => expect(screen.getByTestId('evaluation-run-history')).toBeInTheDocument());
    fireEvent.click(screen.getByLabelText('Select run 7 for comparison'));
    fireEvent.click(screen.getByLabelText('Select run 9 for comparison'));
    await waitFor(() => expect(svc.compareRuns).toHaveBeenCalledWith(7, 9));
    const cmp = await screen.findByTestId('evaluation-run-comparison');
    expect(cmp).toHaveTextContent('Run comparison: #7 vs #9');
    expect(within(cmp).getByText('improved')).toBeInTheDocument();
    expect(within(cmp).getByText('degraded')).toBeInTheDocument();
    expect(cmp).toHaveTextContent('No model is selected or promoted automatically');
  });

  it('shows an authorization error state and lets the user retry', async () => {
    svc.getOverview.mockRejectedValueOnce(new ApiError(403, '')).mockResolvedValue(ok(emptyOverview));
    renderPage();
    const alert = await screen.findByTestId('evaluation-error');
    expect(alert).toHaveTextContent('You do not have access');
    fireEvent.click(within(alert).getByRole('button', { name: 'Try again' }));
    await waitFor(() => expect(screen.queryByTestId('evaluation-error')).not.toBeInTheDocument());
  });

  it('shows failed runs as failed, never as completed', async () => {
    const failed: RunDetail = { ...runDetail, status: 'FAILED', gate_status: null, failure_reason: 'AI service unavailable', summary: null };
    svc.getOverview.mockResolvedValue(ok(evaluatedOverview));
    svc.getEvaluationRuns.mockResolvedValue(ok([{ ...run, status: 'FAILED', summary: null }], { meta: { current_page: 1, last_page: 1, total: 1 } }));
    svc.getEvaluationRun.mockResolvedValue(ok(failed));
    renderPage();
    await waitFor(() => expect(screen.getByTestId('evaluation-run-history')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: '#7' }));
    const alert = await screen.findByText(/failed: AI service unavailable/);
    expect(alert).toBeInTheDocument();
    expect(screen.queryByTestId('run-results')).not.toBeInTheDocument();
  });
});

describe('AI evaluation components', () => {
  it('formats metrics and maps error messages', () => {
    expect(formatMetric('accuracy', 0.75)).toBe('75.0%');
    expect(formatMetric('mae', 0.6349)).toBe('0.635');
    expect(formatMetric('support', 42)).toBe('42');
    expect(formatMetric('mae', null)).toBe('—');
    expect(getEvaluationErrorMessage(new ApiError(409, 'Already running'))).toBe('Already running');
    expect(getEvaluationErrorMessage(new ApiError(503, ''))).toBe('The AI service is temporarily unavailable.');
    expect(getEvaluationErrorMessage(new Error('boom'))).toBe('boom');
  });

  it('renders the confusion matrix with actual/predicted labels', () => {
    render(<ConfusionMatrix data={{ labels: ['A', 'B'], matrix: [[1, 1], [0, 2]] }} />);
    expect(screen.getByLabelText('Actual A, predicted B: 1')).toBeInTheDocument();
    expect(screen.getByLabelText('Actual B, predicted B: 2')).toBeInTheDocument();
  });

  it('renders per-class metrics with support', () => {
    render(<PerClassMetrics rows={metrics.structured.per_class!} />);
    expect(screen.getByText('EASY')).toBeInTheDocument();
    expect(screen.getAllByText('0.67')).toHaveLength(2);
    expect(screen.getByText('0.80')).toBeInTheDocument();
  });

  it('renders classification metrics emphasizing macro F1', () => {
    render(<ClassificationMetrics metrics={metrics} />);
    expect(screen.getByTestId('classification-metrics')).toHaveTextContent('Macro F1');
    expect(screen.getByTestId('classification-metrics')).toHaveTextContent('73.3%');
    expect(screen.getByTestId('confusion-matrix')).toBeInTheDocument();
  });

  it('renders similarity metrics with the threshold sweep and production marker', () => {
    const sim: EvaluationMetrics = { task: 'SIMILARITY', headline_metric: 'duplicate_f1', scalars: { duplicate_f1: 0.6667, duplicate_precision: 0.5, duplicate_recall: 1 }, gates: [], regression: null,
      structured: { thresholds: { duplicate: 0.85, high: 0.7, moderate: 0.5 }, threshold_sweep: { positive: 'POTENTIAL_DUPLICATE', production_threshold: 0.85, rows: [{ threshold: 0.7, precision: 0.5, recall: 1, f1: 0.6667 }, { threshold: 0.85, precision: 1, recall: 1, f1: 1 }] } } };
    render(<SimilarityMetrics metrics={sim} />);
    expect(screen.getByTestId('threshold-sweep')).toHaveTextContent('(production)');
    expect(screen.getByTestId('similarity-metrics')).toHaveTextContent('duplicate ≥ 0.85');
  });

  it('renders grading metrics with agreement rates and grouped bias', () => {
    const g: EvaluationMetrics = { task: 'GRADING_ASSISTANCE', headline_metric: 'mae', gates: [], regression: null,
      scalars: { mae: 0.5, rmse: 0.7071, mean_signed_error: -0.5, exact_agreement_rate: 0.5, within_0_5: 0.5, within_1: 1, ai_mean_marks: 7, faculty_mean_marks: 7.5 },
      structured: { error_by_group: [{ dimension: 'question_type', group: 'ANALYTICAL', count: 2, mae: 1, mean_signed_error: -1 }] } };
    render(<GradingMetrics metrics={g} />);
    expect(screen.getByTestId('grading-metrics')).toHaveTextContent('MAE');
    expect(screen.getByTestId('grading-metrics')).toHaveTextContent('0.500');
    expect(screen.getByTestId('grading-metrics')).toHaveTextContent('Within 1');
    expect(screen.getByTestId('grading-bias')).toHaveTextContent('ANALYTICAL');
  });

  it('renders RAG and question-generation metrics', () => {
    render(<RagEvaluation metrics={{ task: 'DOCUMENT_CHAT', headline_metric: 'citation_accuracy', gates: [], regression: null, scalars: { citation_accuracy: 1, correct_refusal_rate: 0.5, injection_leak_rate: 1 }, structured: {} }} />);
    expect(screen.getByTestId('rag-evaluation')).toHaveTextContent('Citation accuracy');
    expect(screen.getByTestId('rag-evaluation')).toHaveTextContent('100.0%');
    render(<QuestionGenerationEvaluation metrics={{ task: 'QUESTION_GENERATION', headline_metric: 'constraint_satisfaction_rate', gates: [], regression: null, scalars: { constraint_satisfaction_rate: 0.3333, generated_questions: 3 },
      structured: { per_constraint: [{ constraint: 'topic', satisfied: 2, total: 3, rate: 0.6667 }] } }} />);
    expect(screen.getByTestId('constraint-satisfaction')).toHaveTextContent('66.7%');
  });

  it('renders dataset validation problems as blocking', () => {
    render(<DatasetValidation datasetStatus="DRAFT" report={{ ...dataset.validation_report!, is_valid: false, invalid_examples: 1, problems: [{ example_id: 4, errors: ["Invalid label 'IMPOSSIBLE'."] }] }} />);
    expect(screen.getByText(/evaluation cannot start/)).toBeInTheDocument();
    expect(screen.getByText(/Example #4: Invalid label/)).toBeInTheDocument();
  });

  it('renders progress, empty and error states, metric and performance cards', () => {
    render(<EvaluationProgress run={{ ...run, status: 'RUNNING', processed_count: 2 }} />);
    expect(screen.getByTestId('evaluation-progress')).toHaveTextContent('2 of 4 examples processed (50%)');
    render(<EvaluationEmptyState />);
    expect(screen.getByTestId('evaluation-empty-state')).toHaveTextContent('Not evaluated yet');
    render(<EvaluationError message="Boom" />);
    expect(screen.getByRole('alert')).toHaveTextContent('Boom');
    render(<MetricCard label="MAE" value="0.63" status="PASSED_WITH_WARNINGS" />);
    expect(screen.getByText('Passed with warnings')).toBeInTheDocument();
    const item: TaskOverview = evaluatedOverview.tasks[0];
    render(<ModelPerformanceCard item={item} />);
    expect(screen.getByText('Difficulty classification')).toBeInTheDocument();
    render(<EvaluationRunComparison comparison={null} onClear={() => undefined} />);
  });
});
