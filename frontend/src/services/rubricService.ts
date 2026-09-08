import { apiClient } from './api';
import { Rubric, RubricUpdatePayload } from '@/types/rubric';

export interface RubricResponse {
  status: string;
  message?: string;
  data: Rubric;
}

export interface RubricListResponse {
  status: string;
  data: Rubric[];
  question: {
    id: number;
    question_number: number | null;
    marks: number;
  };
}

export interface RubricActionResponse {
  status: string;
  message: string;
}

/**
 * STEP 25: AI Rubric Generator API. All endpoints are Sanctum-protected and
 * authorized server-side through Course -> Assessment -> Question ownership.
 */
export const rubricService = {
  /** Generate a new draft rubric version for a question (AI-assisted). */
  generate: (questionId: number | string): Promise<RubricResponse> =>
    apiClient<RubricResponse>(`/questions/${questionId}/rubrics/generate`, { method: 'POST' }),

  /** All rubric versions for a question, newest first. */
  listForQuestion: (questionId: number | string): Promise<RubricListResponse> =>
    apiClient<RubricListResponse>(`/questions/${questionId}/rubrics`, { method: 'GET' }),

  getById: (rubricId: number | string): Promise<RubricResponse> =>
    apiClient<RubricResponse>(`/rubrics/${rubricId}`, { method: 'GET' }),

  /** Save faculty edits to a draft (criteria are replaced atomically). */
  update: (rubricId: number | string, data: RubricUpdatePayload): Promise<RubricResponse> =>
    apiClient<RubricResponse>(`/rubrics/${rubricId}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    }),

  approve: (rubricId: number | string): Promise<RubricResponse> =>
    apiClient<RubricResponse>(`/rubrics/${rubricId}/approve`, { method: 'POST' }),

  /** Creates a new draft version; existing versions are preserved. */
  regenerate: (rubricId: number | string): Promise<RubricResponse> =>
    apiClient<RubricResponse>(`/rubrics/${rubricId}/regenerate`, { method: 'POST' }),

  delete: (rubricId: number | string): Promise<RubricActionResponse> =>
    apiClient<RubricActionResponse>(`/rubrics/${rubricId}`, { method: 'DELETE' }),
};
