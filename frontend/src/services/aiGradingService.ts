import { apiClient } from './api';
import { AIGradingResult, FinalGradePayload } from '@/types/grading';
import { StudentAnswer } from '@/types/submission';

export interface AIGradingResponse {
  status: string;
  message?: string;
  data: AIGradingResult;
}

export interface AIGradingHistoryResponse {
  status: string;
  data: AIGradingResult[];
}

export interface FinalGradeResponse {
  status: string;
  message?: string;
  data: StudentAnswer;
}

/**
 * STEP 27: AI Grading Assistance API. React talks to Laravel only; Laravel loads the
 * authoritative question/rubric/answer data and calls the AI service asynchronously.
 * Only the answer id is ever sent from the browser.
 */
export const aiGradingService = {
  /** Queue an AI grading run (202 Accepted). Idempotent while a run is pending/processing. */
  requestAIGrading: (answerId: number | string): Promise<AIGradingResponse> =>
    apiClient<AIGradingResponse>(`/student-answers/${answerId}/ai-grade`, { method: 'POST' }),

  /** Current AI grading result for an answer (404 when none exists). */
  getAIGradingResult: (answerId: number | string): Promise<AIGradingResponse> =>
    apiClient<AIGradingResponse>(`/student-answers/${answerId}/ai-grading`, { method: 'GET' }),

  getAIGradingHistory: (answerId: number | string): Promise<AIGradingHistoryResponse> =>
    apiClient<AIGradingHistoryResponse>(`/student-answers/${answerId}/ai-grading/history`, { method: 'GET' }),

  /** New evaluation run; previous runs are preserved for audit. */
  regenerateAIGrading: (resultId: number | string): Promise<AIGradingResponse> =>
    apiClient<AIGradingResponse>(`/ai-grading/${resultId}/regenerate`, { method: 'POST' }),

  /** Faculty rejects the suggestion; marks are untouched. */
  rejectAIGrading: (resultId: number | string): Promise<AIGradingResponse> =>
    apiClient<AIGradingResponse>(`/ai-grading/${resultId}/reject`, { method: 'POST' }),

  /** Faculty final marks (accept or edit). Never automatic. */
  finalizeGrade: (answerId: number | string, data: FinalGradePayload): Promise<FinalGradeResponse> =>
    apiClient<FinalGradeResponse>(`/student-answers/${answerId}/finalize-grade`, {
      method: 'POST',
      body: JSON.stringify(data),
    }),

  updateFinalGrade: (answerId: number | string, data: FinalGradePayload): Promise<FinalGradeResponse> =>
    apiClient<FinalGradeResponse>(`/student-answers/${answerId}/final-grade`, {
      method: 'PUT',
      body: JSON.stringify(data),
    }),
};
