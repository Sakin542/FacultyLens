import { apiClient } from './api';
import { Assessment, AssessmentType, AssessmentStatus } from '@/types';

export interface AssessmentPayload {
  title: string;
  type: AssessmentType;
  description?: string;
  assessment_date?: string;
  total_marks: number;
  duration_minutes?: number;
  status: AssessmentStatus;
}

export interface AssessmentListResponse {
  data: Assessment[];
}

export interface AssessmentDetailResponse {
  data: Assessment;
  message?: string;
}

export interface ActionResponse {
  message: string;
}

export interface AssessmentFilterParams {
  course_id?: string | number;
  type?: string;
  status?: string;
  academic_year?: string;
  search?: string;
}

export const assessmentService = {
  /**
   * Get assessments for a specific course
   */
  getByCourse: async (courseId: number | string): Promise<AssessmentListResponse> => {
    return apiClient<AssessmentListResponse>(`/courses/${courseId}/assessments`, {
      method: 'GET',
    });
  },

  /**
   * Get all assessments for authenticated faculty across all courses
   */
  getAll: async (params?: AssessmentFilterParams): Promise<AssessmentListResponse> => {
    const query = new URLSearchParams();
    if (params) {
      if (params.course_id) query.append('course_id', String(params.course_id));
      if (params.type && params.type !== 'all') query.append('type', params.type);
      if (params.status && params.status !== 'all') query.append('status', params.status);
      if (params.search) query.append('search', params.search);
    }
    const queryString = query.toString() ? `?${query.toString()}` : '';
    return apiClient<AssessmentListResponse>(`/assessments${queryString}`, {
      method: 'GET',
    });
  },

  /**
   * Get a single assessment by ID
   */
  getById: async (id: number | string): Promise<AssessmentDetailResponse> => {
    return apiClient<AssessmentDetailResponse>(`/assessments/${id}`, {
      method: 'GET',
    });
  },

  /**
   * Create an assessment for a course
   */
  create: async (courseId: number | string, data: AssessmentPayload): Promise<AssessmentDetailResponse> => {
    return apiClient<AssessmentDetailResponse>(`/courses/${courseId}/assessments`, {
      method: 'POST',
      body: JSON.stringify(data),
    });
  },

  /**
   * Update an assessment
   */
  update: async (
    id: number | string,
    data: Partial<AssessmentPayload>
  ): Promise<AssessmentDetailResponse> => {
    return apiClient<AssessmentDetailResponse>(`/assessments/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    });
  },

  /**
   * Delete an assessment
   */
  delete: async (id: number | string): Promise<ActionResponse> => {
    return apiClient<ActionResponse>(`/assessments/${id}`, {
      method: 'DELETE',
    });
  },

  /**
   * Get assessment history for authenticated faculty with optional filters
   */
  getHistory: async (params?: AssessmentFilterParams): Promise<AssessmentListResponse> => {
    const query = new URLSearchParams();
    if (params) {
      if (params.course_id) query.append('course_id', String(params.course_id));
      if (params.type && params.type !== 'all') query.append('type', params.type);
      if (params.status && params.status !== 'all') query.append('status', params.status);
      if (params.academic_year && params.academic_year !== 'all') query.append('academic_year', params.academic_year);
      if (params.search) query.append('search', params.search);
    }
    const queryString = query.toString() ? `?${query.toString()}` : '';
    return apiClient<AssessmentListResponse>(`/assessments/history${queryString}`, {
      method: 'GET',
    });
  },
};

