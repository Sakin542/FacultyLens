import { apiClient } from './api';
import { LearningOutcome, CognitiveLevel } from '@/types';

export interface LearningOutcomePayload {
  code: string;
  description: string;
  cognitive_level: CognitiveLevel;
  sort_order?: number;
}

export interface LearningOutcomeListResponse {
  data: LearningOutcome[];
}

export interface LearningOutcomeDetailResponse {
  data: LearningOutcome;
  message?: string;
}

export interface ActionResponse {
  message: string;
}

export const learningOutcomeService = {
  /**
   * List all learning outcomes for a course
   */
  getByCourse: async (courseId: number | string): Promise<LearningOutcomeListResponse> => {
    return apiClient<LearningOutcomeListResponse>(`/courses/${courseId}/learning-outcomes`, {
      method: 'GET',
    });
  },

  /**
   * Add a new learning outcome to a course
   */
  create: async (courseId: number | string, data: LearningOutcomePayload): Promise<LearningOutcomeDetailResponse> => {
    return apiClient<LearningOutcomeDetailResponse>(`/courses/${courseId}/learning-outcomes`, {
      method: 'POST',
      body: JSON.stringify(data),
    });
  },

  /**
   * Update an existing learning outcome
   */
  update: async (
    id: number | string,
    data: Partial<LearningOutcomePayload>
  ): Promise<LearningOutcomeDetailResponse> => {
    return apiClient<LearningOutcomeDetailResponse>(`/learning-outcomes/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    });
  },

  /**
   * Delete a learning outcome
   */
  delete: async (id: number | string): Promise<ActionResponse> => {
    return apiClient<ActionResponse>(`/learning-outcomes/${id}`, {
      method: 'DELETE',
    });
  },
};

