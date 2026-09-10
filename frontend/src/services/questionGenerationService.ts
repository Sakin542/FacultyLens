import { apiClient } from './api';
import {
  CreateGenerationInput, FeedbackInput, GeneratedQuestion, GenerationRequest, UpdateGeneratedQuestionInput,
} from '@/types/questionGeneration';

interface Envelope<T> { status: string; message?: string; data: T; }

/**
 * STEP 33: Constrained Question Generator API. Drafts only — approval and "add to assessment" are explicit.
 */
export const questionGenerationService = {
  createGenerationRequest: (input: CreateGenerationInput): Promise<Envelope<GenerationRequest>> =>
    apiClient('/question-generation', { method: 'POST', body: JSON.stringify(input) }),

  getGenerationRequests: (params?: { course_id?: number | string; assessment_id?: number | string }): Promise<Envelope<GenerationRequest[]>> => {
    const q = new URLSearchParams();
    if (params?.course_id) q.append('course_id', String(params.course_id));
    if (params?.assessment_id) q.append('assessment_id', String(params.assessment_id));
    const qs = q.toString();
    return apiClient(`/question-generation${qs ? `?${qs}` : ''}`, { method: 'GET' });
  },

  getGenerationRequest: (id: number | string): Promise<Envelope<GenerationRequest>> =>
    apiClient(`/question-generation/${id}`, { method: 'GET' }),

  getGeneratedQuestions: (id: number | string): Promise<Envelope<GeneratedQuestion[]>> =>
    apiClient(`/question-generation/${id}/questions`, { method: 'GET' }),

  regenerateRequest: (id: number | string, feedback: FeedbackInput = {}): Promise<Envelope<GenerationRequest>> =>
    apiClient(`/question-generation/${id}/regenerate`, { method: 'POST', body: JSON.stringify(feedback) }),

  updateGeneratedQuestion: (id: number | string, data: UpdateGeneratedQuestionInput): Promise<Envelope<GeneratedQuestion>> =>
    apiClient(`/generated-questions/${id}`, { method: 'PUT', body: JSON.stringify(data) }),

  approveGeneratedQuestion: (id: number | string, note?: string): Promise<Envelope<GeneratedQuestion>> =>
    apiClient(`/generated-questions/${id}/approve`, { method: 'POST', body: JSON.stringify({ note: note ?? null }) }),

  rejectGeneratedQuestion: (id: number | string, note?: string): Promise<Envelope<GeneratedQuestion>> =>
    apiClient(`/generated-questions/${id}/reject`, { method: 'POST', body: JSON.stringify({ note: note ?? null }) }),

  regenerateQuestion: (id: number | string, feedback: FeedbackInput = {}): Promise<Envelope<GeneratedQuestion>> =>
    apiClient(`/generated-questions/${id}/regenerate`, { method: 'POST', body: JSON.stringify(feedback) }),

  addToAssessment: (id: number | string, assessmentId?: number | string | null): Promise<Envelope<{ question: { id: number; assessment_id: number; question_number: number }; generated_question: GeneratedQuestion }>> =>
    apiClient(`/generated-questions/${id}/add-to-assessment`, { method: 'POST', body: JSON.stringify({ assessment_id: assessmentId ?? null }) }),
};
