import { apiClient, API_BASE_URL } from './api';
import {
  CreateDatasetInput, DatasetDetail, DatasetValidationReport, ErrorAnalysisItem, EvaluationDataset, EvaluationMetrics, EvaluationOverview,
  EvaluationRun, EvaluationTask, ModelInfo, PageMeta, PromptVersion, RatingInput, RunComparison, RunDetail, RunStatus,
} from '@/types/aiEvaluation';

interface Envelope<T> { status: string; message?: string; data: T; meta?: PageMeta; }

const qs = (params?: Record<string, string | number | boolean | undefined | null>) => {
  const q = new URLSearchParams();
  Object.entries(params ?? {}).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') q.append(k, String(v)); });
  const s = q.toString();
  return s ? `?${s}` : '';
};

/**
 * STEP 35: AI Evaluation & Model Performance API. All metrics come from real evaluation runs; nothing is estimated client-side.
 */
export const aiEvaluationService = {
  getOverview: (): Promise<Envelope<EvaluationOverview>> => apiClient('/ai/evaluation', { method: 'GET' }),

  getModels: (sync = false): Promise<Envelope<{ models: ModelInfo[]; prompt_versions: PromptVersion[] }>> =>
    apiClient(`/ai/evaluation/models${sync ? '?sync=1' : ''}`, { method: 'GET' }),

  getPromptVersions: (): Promise<Envelope<PromptVersion[]>> => apiClient('/ai/evaluation/prompts', { method: 'GET' }),

  getDatasets: (task?: EvaluationTask | ''): Promise<Envelope<EvaluationDataset[]>> => apiClient(`/ai/evaluation/datasets${qs({ task })}`, { method: 'GET' }),

  createDataset: (data: CreateDatasetInput): Promise<Envelope<EvaluationDataset & { import: { added: number; skipped_duplicates: number } }>> =>
    apiClient('/ai/evaluation/datasets', { method: 'POST', body: JSON.stringify(data) }),

  getDataset: (id: number | string, page = 1): Promise<Envelope<DatasetDetail>> => apiClient(`/ai/evaluation/datasets/${id}?page=${page}`, { method: 'GET' }),

  updateDataset: (id: number | string, data: Partial<CreateDatasetInput> & { status?: 'ARCHIVED' | 'DRAFT' }): Promise<Envelope<EvaluationDataset>> =>
    apiClient(`/ai/evaluation/datasets/${id}`, { method: 'PUT', body: JSON.stringify(data) }),

  deleteDataset: (id: number | string): Promise<Envelope<null>> => apiClient(`/ai/evaluation/datasets/${id}`, { method: 'DELETE' }),

  deleteExample: (datasetId: number | string, exampleId: number | string): Promise<Envelope<null>> =>
    apiClient(`/ai/evaluation/datasets/${datasetId}/examples/${exampleId}`, { method: 'DELETE' }),

  validateDataset: (id: number | string): Promise<Envelope<DatasetValidationReport>> => apiClient(`/ai/evaluation/datasets/${id}/validate`, { method: 'POST' }),

  runEvaluation: (datasetId: number | string, opts?: { note?: string }): Promise<Envelope<EvaluationRun>> =>
    apiClient(`/ai/evaluation/datasets/${datasetId}/run`, { method: 'POST', body: JSON.stringify(opts ?? {}) }),

  getEvaluationRuns: (params?: { task?: EvaluationTask | ''; status?: RunStatus | ''; dataset_id?: number; model_id?: number; page?: number; per_page?: number }): Promise<Envelope<EvaluationRun[]>> =>
    apiClient(`/ai/evaluation/runs${qs(params)}`, { method: 'GET' }),

  getEvaluationRun: (id: number | string): Promise<Envelope<RunDetail>> => apiClient(`/ai/evaluation/runs/${id}`, { method: 'GET' }),

  getMetrics: (id: number | string): Promise<Envelope<EvaluationMetrics>> => apiClient(`/ai/evaluation/runs/${id}/metrics`, { method: 'GET' }),

  getErrors: (id: number | string, params?: { only_errors?: boolean; error_type?: string; page?: number }): Promise<Envelope<ErrorAnalysisItem[]>> =>
    apiClient(`/ai/evaluation/runs/${id}/errors${qs({ ...params, only_errors: params?.only_errors === undefined ? undefined : (params.only_errors ? 1 : 0) })}`, { method: 'GET' }),

  cancelRun: (id: number | string): Promise<Envelope<EvaluationRun>> => apiClient(`/ai/evaluation/runs/${id}/cancel`, { method: 'POST' }),

  compareRuns: (runA: number | string, runB: number | string): Promise<Envelope<RunComparison>> =>
    apiClient(`/ai/evaluation/compare?run_a=${runA}&run_b=${runB}`, { method: 'GET' }),

  getReport: (id: number | string): Promise<Envelope<Record<string, unknown>>> => apiClient(`/ai/evaluation/runs/${id}/report`, { method: 'GET' }),

  rate: (data: RatingInput): Promise<Envelope<unknown>> => apiClient('/ai/evaluation/ratings', { method: 'POST', body: JSON.stringify(data) }),

  /** Download a report (json | csv | pdf) as a file; the server audits every export. */
  exportReport: async (id: number | string, format: 'json' | 'csv' | 'pdf'): Promise<void> => {
    const response = await fetch(`${API_BASE_URL}/ai/evaluation/runs/${id}/export?format=${format}`, { method: 'GET', credentials: 'include', headers: { Accept: '*/*' } });
    if (!response.ok) throw new Error('Failed to export the evaluation report.');
    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `ai-evaluation-run-${id}.${format}`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
  },
};
