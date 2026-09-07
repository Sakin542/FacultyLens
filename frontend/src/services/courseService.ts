import { apiClient } from './api';
import { Course } from '@/types';

export interface CoursePayload {
  course_code: string;
  course_name: string;
  description?: string;
  semester: string;
  academic_year: string;
  credits: number;
  status?: string;
}

export interface CourseListResponse {
  data: Course[];
}

export interface CourseDetailResponse {
  data: Course;
  message?: string;
}

export interface ActionResponse {
  message: string;
}

export const courseService = {
  /**
   * List all courses owned by authenticated faculty
   */
  getAll: async (): Promise<CourseListResponse> => {
    return apiClient<CourseListResponse>('/courses', {
      method: 'GET',
    });
  },

  /**
   * Get single course with learning outcomes, materials, and assessment counts
   */
  getById: async (id: number | string): Promise<CourseDetailResponse> => {
    return apiClient<CourseDetailResponse>(`/courses/${id}`, {
      method: 'GET',
    });
  },

  /**
   * Create a new course
   */
  create: async (data: CoursePayload): Promise<CourseDetailResponse> => {
    return apiClient<CourseDetailResponse>('/courses', {
      method: 'POST',
      body: JSON.stringify(data),
    });
  },

  /**
   * Update an existing course
   */
  update: async (id: number | string, data: Partial<CoursePayload>): Promise<CourseDetailResponse> => {
    return apiClient<CourseDetailResponse>(`/courses/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    });
  },

  /**
   * Delete a course and its associated materials
   */
  delete: async (id: number | string): Promise<ActionResponse> => {
    return apiClient<ActionResponse>(`/courses/${id}`, {
      method: 'DELETE',
    });
  },
};

