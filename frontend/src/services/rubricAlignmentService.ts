import { apiClient } from './api';
import { RubricAlignment } from '@/types/rubricAlignment';

export interface RubricAlignmentResponse {
  status: string;
  message?: string;
  data: RubricAlignment;
}

export interface RubricAlignmentHistoryResponse {
  status: string;
  data: RubricAlignment[];
}

/**
 * STEP 28: Answer <-> Rubric Alignment API. React talks to Laravel only; Laravel loads the
 * authoritative answer/question/rubric data and calls the AI service asynchronously.
 */
export const rubricAlignmentService = {
  /** Queue an alignment analysis (202 Accepted). Idempotent while a run is pending/processing. */
  requestAlignment: (answerId: number | string): Promise<RubricAlignmentResponse> =>
    apiClient<RubricAlignmentResponse>(`/student-answers/${answerId}/rubric-alignment`, { method: 'POST' }),

  /** Current alignment for an answer (404 when none exists). */
  getAlignment: (answerId: number | string): Promise<RubricAlignmentResponse> =>
    apiClient<RubricAlignmentResponse>(`/student-answers/${answerId}/rubric-alignment`, { method: 'GET' }),

  getAlignmentHistory: (answerId: number | string): Promise<RubricAlignmentHistoryResponse> =>
    apiClient<RubricAlignmentHistoryResponse>(`/student-answers/${answerId}/rubric-alignment/history`, { method: 'GET' }),

  /** New analysis run; previous runs are preserved for audit. */
  regenerateAlignment: (alignmentId: number | string): Promise<RubricAlignmentResponse> =>
    apiClient<RubricAlignmentResponse>(`/rubric-alignments/${alignmentId}/regenerate`, { method: 'POST' }),

  /** Faculty marks the analysis as reviewed. Marks are never changed. */
  markReviewed: (alignmentId: number | string): Promise<RubricAlignmentResponse> =>
    apiClient<RubricAlignmentResponse>(`/rubric-alignments/${alignmentId}/review`, { method: 'POST' }),
};
