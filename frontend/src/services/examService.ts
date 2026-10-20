import { apiClient, API_BASE_URL, getCookie } from './api';
import type {
  ExamPaper, ExamPaperSummary, FeedbackComment, FeedbackTone, ItemAnalysis, PoolCandidate, SimilarityMatch, SimilarityRun, VariantResult,
} from '@/types/exam';

interface Envelope<T> { status: string; message?: string; data: T }

const json = (method: string, body?: unknown): RequestInit => ({ method, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });

export interface BuildPayload {
  mode: 'blueprint' | 'targets' | 'empty';
  title?: string;
  instructions?: string;
  duration_minutes?: number;
  seed?: number;
  targets?: {
    question_count: number;
    total_marks?: number;
    marks_each?: number;
    cognitive_mix?: Record<string, number>;
    difficulty_mix?: Record<string, number>;
    topic?: string;
  };
}

export const examService = {
  papers: (assessmentId: number | string) =>
    apiClient<Envelope<ExamPaperSummary[]>>(`/assessments/${assessmentId}/exam-papers`).then((r) => r.data),

  pool: (assessmentId: number | string, filters: Record<string, string> = {}) => {
    const q = new URLSearchParams(Object.entries(filters).filter(([, v]) => v)).toString();
    return apiClient<{ data: PoolCandidate[]; meta: { total: number; by_source: Record<string, number>; by_bloom: Record<string, number> } }>(`/assessments/${assessmentId}/question-pool${q ? `?${q}` : ''}`);
  },

  build: (assessmentId: number | string, payload: BuildPayload) =>
    apiClient<Envelope<ExamPaper>>(`/assessments/${assessmentId}/exam-papers`, json('POST', payload)),

  paper: (id: number | string) => apiClient<Envelope<ExamPaper>>(`/exam-papers/${id}`).then((r) => r.data),

  updatePaper: (id: number, changes: Partial<Pick<ExamPaper, 'title' | 'instructions' | 'duration_minutes' | 'status'>>) =>
    apiClient<Envelope<ExamPaper>>(`/exam-papers/${id}`, json('PATCH', changes)),

  deletePaper: (id: number) => apiClient<{ message: string }>(`/exam-papers/${id}`, json('DELETE')),

  addItem: (id: number, payload: { candidate_key?: string; question_text?: string; expected_answer?: string; marks?: number; cognitive_level?: string; difficulty_level?: string }) =>
    apiClient<Envelope<ExamPaper>>(`/exam-papers/${id}/items`, json('POST', payload)),

  updateItem: (id: number, itemId: number, changes: Record<string, unknown>) =>
    apiClient<Envelope<ExamPaper>>(`/exam-papers/${id}/items/${itemId}`, json('PATCH', changes)),

  removeItem: (id: number, itemId: number) => apiClient<Envelope<ExamPaper>>(`/exam-papers/${id}/items/${itemId}`, json('DELETE')),

  reorder: (id: number, itemIds: number[]) => apiClient<Envelope<ExamPaper>>(`/exam-papers/${id}/order`, json('PUT', { item_ids: itemIds })),

  variants: (id: number, itemId: number, mode: 'numbers' | 'wording' | 'both', count = 3) =>
    apiClient<Envelope<VariantResult>>(`/exam-papers/${id}/items/${itemId}/variants`, json('POST', { mode, count })).then((r) => r.data),

  createSets: (id: number, count: number, vary: 'shuffle' | 'numbers' | 'wording' | 'both') =>
    apiClient<Envelope<ExamPaper[]> & { message: string; warnings: string[] }>(`/exam-papers/${id}/sets`, json('POST', { count, vary })),

  apply: (id: number) => apiClient<Envelope<ExamPaper> & { message: string }>(`/exam-papers/${id}/apply`, json('POST')),

  /** Downloads through fetch so the session cookie is sent and errors surface as messages. */
  download: async (id: number, format: 'pdf' | 'docx', answerKey: boolean): Promise<void> => {
    const res = await fetch(`${API_BASE_URL}/exam-papers/${id}/export?format=${format}&answer_key=${answerKey ? 1 : 0}`, {
      credentials: 'include',
      headers: { Accept: '*/*', ...(getCookie('XSRF-TOKEN') ? { 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') as string } : {}) },
    });
    if (!res.ok) {
      let message = 'The export failed.';
      try { message = (await res.json()).message ?? message; } catch { /* binary or empty */ }
      throw new Error(message);
    }
    const blob = await res.blob();
    const name = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') ?? '')?.[1] ?? `exam-paper.${format}`;
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  },

  itemAnalysis: (assessmentId: number | string) =>
    apiClient<Envelope<ItemAnalysis>>(`/assessments/${assessmentId}/item-analysis`).then((r) => r.data),

  latestPlagiarism: (assessmentId: number | string) =>
    apiClient<Envelope<SimilarityRun | null>>(`/assessments/${assessmentId}/plagiarism-checks/latest`).then((r) => r.data),

  runPlagiarism: (assessmentId: number | string, threshold?: number) =>
    apiClient<Envelope<SimilarityRun> & { message: string }>(`/assessments/${assessmentId}/plagiarism-checks`, json('POST', threshold ? { threshold } : {})),

  reviewMatch: (matchId: number, review_status: SimilarityMatch['review_status'], review_note?: string) =>
    apiClient<Envelope<SimilarityMatch>>(`/plagiarism-matches/${matchId}`, json('PATCH', { review_status, review_note })).then((r) => r.data),

  comments: (filters: { course_id?: number | string; tone?: FeedbackTone | ''; search?: string; criterion?: string } = {}) => {
    const q = new URLSearchParams(Object.entries(filters).filter(([, v]) => v !== undefined && v !== '').map(([k, v]) => [k, String(v)])).toString();
    return apiClient<Envelope<FeedbackComment[]>>(`/feedback-comments${q ? `?${q}` : ''}`).then((r) => r.data);
  },

  createComment: (payload: { title: string; body: string; tone?: FeedbackTone; criterion?: string | null; course_id?: number | null }) =>
    apiClient<Envelope<FeedbackComment>>('/feedback-comments', json('POST', payload)).then((r) => r.data),

  updateComment: (id: number, payload: Partial<{ title: string; body: string; tone: FeedbackTone; criterion: string | null; course_id: number | null }>) =>
    apiClient<Envelope<FeedbackComment>>(`/feedback-comments/${id}`, json('PATCH', payload)).then((r) => r.data),

  deleteComment: (id: number) => apiClient<{ message: string }>(`/feedback-comments/${id}`, json('DELETE')),

  useComment: (id: number) => apiClient<Envelope<FeedbackComment>>(`/feedback-comments/${id}/use`, json('POST')).then((r) => r.data),
};
