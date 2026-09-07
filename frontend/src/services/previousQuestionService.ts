import { apiClient, API_BASE_URL } from './api';
import { PreviousQuestion, PaginatedResponse, CognitiveLevel } from '@/types';

export interface PreviousQuestionPayload {
  question_text?: string;
  question_type?: string;
  marks?: number;
  difficulty_level?: string;
  cognitive_level?: CognitiveLevel | string;
  source?: string;
  source_year?: string;
  source_assessment?: string;
}

export interface PreviousQuestionQueryParams {
  page?: number;
  per_page?: number;
  search?: string;
  question_type?: string;
  difficulty_level?: string;
  cognitive_level?: string;
  source_year?: string;
  source?: string;
  sort_by?: 'newest' | 'oldest' | 'marks_asc' | 'marks_desc' | 'difficulty' | string;
}

export interface PreviousQuestionDetailResponse {
  data: PreviousQuestion;
  message?: string;
}

export interface ActionResponse {
  message: string;
}

export const previousQuestionService = {
  /**
   * Get paginated previous questions for a course with search and filters
   */
  getByCourse: async (
    courseId: number | string,
    params?: PreviousQuestionQueryParams
  ): Promise<PaginatedResponse<PreviousQuestion>> => {
    const query = new URLSearchParams();
    if (params) {
      if (params.page) query.append('page', String(params.page));
      if (params.per_page) query.append('per_page', String(params.per_page));
      if (params.search) query.append('search', params.search);
      if (params.question_type && params.question_type !== 'all') {
        query.append('question_type', params.question_type);
      }
      if (params.difficulty_level && params.difficulty_level !== 'all') {
        query.append('difficulty_level', params.difficulty_level);
      }
      if (params.cognitive_level && params.cognitive_level !== 'all') {
        query.append('cognitive_level', params.cognitive_level);
      }
      if (params.source_year && params.source_year !== 'all') {
        query.append('source_year', params.source_year);
      }
      if (params.source && params.source !== 'all') {
        query.append('source', params.source);
      }
      if (params.sort_by) query.append('sort_by', params.sort_by);
    }
    const queryString = query.toString() ? `?${query.toString()}` : '';
    return apiClient<PaginatedResponse<PreviousQuestion>>(
      `/courses/${courseId}/previous-questions${queryString}`,
      { method: 'GET' }
    );
  },

  /**
   * Create a new previous question (manual JSON payload or FormData with file)
   */
  create: async (
    courseId: number | string,
    data: PreviousQuestionPayload | FormData
  ): Promise<PreviousQuestionDetailResponse> => {
    return apiClient<PreviousQuestionDetailResponse>(
      `/courses/${courseId}/previous-questions`,
      {
        method: 'POST',
        body: data instanceof FormData ? data : JSON.stringify(data),
      }
    );
  },

  /**
   * Update an existing previous question
   */
  update: async (
    id: number | string,
    data: Partial<PreviousQuestionPayload> | FormData
  ): Promise<PreviousQuestionDetailResponse> => {
    return apiClient<PreviousQuestionDetailResponse>(`/previous-questions/${id}`, {
      method: 'PUT',
      body: data instanceof FormData ? data : JSON.stringify(data),
    });
  },

  /**
   * Delete a previous question
   */
  delete: async (id: number | string): Promise<ActionResponse> => {
    return apiClient<ActionResponse>(`/previous-questions/${id}`, {
      method: 'DELETE',
    });
  },

  /**
   * Download the attached previous question file
   */
  download: async (id: number | string, fileName: string): Promise<void> => {
    const url = `${API_BASE_URL}/previous-questions/${id}?download=1`;
    const response = await fetch(url, {
      method: 'GET',
      credentials: 'include',
    });

    if (!response.ok) {
      throw new Error('Failed to download previous question file.');
    }

    const blob = await response.blob();
    const downloadUrl = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = downloadUrl;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(downloadUrl);
  },
};

