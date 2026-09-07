import { apiClient, API_BASE_URL } from './api';
import { CourseMaterial } from '@/types';

export interface CourseMaterialListResponse {
  data: CourseMaterial[];
}

export interface CourseMaterialDetailResponse {
  data: CourseMaterial;
  message?: string;
}

export interface ActionResponse {
  message: string;
}

export const courseMaterialService = {
  /**
   * List materials for a specific course
   */
  getByCourse: async (courseId: number | string): Promise<CourseMaterialListResponse> => {
    return apiClient<CourseMaterialListResponse>(`/courses/${courseId}/materials`, {
      method: 'GET',
    });
  },

  /**
   * Upload a new material (syllabus / reference file)
   */
  upload: async (
    courseId: number | string,
    formData: FormData
  ): Promise<CourseMaterialDetailResponse> => {
    return apiClient<CourseMaterialDetailResponse>(`/courses/${courseId}/materials`, {
      method: 'POST',
      body: formData,
    });
  },

  /**
   * Download a material securely as a file blob
   */
  download: async (materialId: number | string, fileName: string): Promise<void> => {
    const url = `${API_BASE_URL}/materials/${materialId}`;
    const response = await fetch(url, {
      method: 'GET',
      credentials: 'include',
    });

    if (!response.ok) {
      throw new Error('Failed to download material file');
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

  /**
   * Delete a course material
   */
  delete: async (id: number | string): Promise<ActionResponse> => {
    return apiClient<ActionResponse>(`/materials/${id}`, {
      method: 'DELETE',
    });
  },
};

