import { apiClient, API_BASE_URL } from './api';
import { AssessmentQuestionPaper } from '@/types';

export interface QuestionPaperDetailResponse {
  data: AssessmentQuestionPaper;
  message?: string;
}

export interface ActionResponse {
  message: string;
}

export const questionPaperService = {
  /**
   * Upload or replace question paper file for an assessment
   */
  upload: async (
    assessmentId: number | string,
    formData: FormData
  ): Promise<QuestionPaperDetailResponse> => {
    return apiClient<QuestionPaperDetailResponse>(`/assessments/${assessmentId}/question-paper`, {
      method: 'POST',
      body: formData,
    });
  },

  /**
   * Download the assessment question paper file safely as a blob
   */
  download: async (assessmentId: number | string, fileName: string): Promise<void> => {
    const url = `${API_BASE_URL}/assessments/${assessmentId}/question-paper`;
    const response = await fetch(url, {
      method: 'GET',
      credentials: 'include',
    });

    if (!response.ok) {
      throw new Error('Failed to download question paper file.');
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
   * Delete the question paper file for an assessment
   */
  delete: async (assessmentId: number | string): Promise<ActionResponse> => {
    return apiClient<ActionResponse>(`/assessments/${assessmentId}/question-paper`, {
      method: 'DELETE',
    });
  },
};

