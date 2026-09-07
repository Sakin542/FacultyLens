import { apiClient, API_BASE_URL } from './api';
import { DocumentProcessing } from '@/types';

export interface DocumentListResponse {
  data: DocumentProcessing[];
}

export interface DocumentDetailResponse {
  data: DocumentProcessing;
  message?: string;
}

export interface ActionResponse {
  message: string;
}

export const documentService = {
  /**
   * List processed documents with optional course_id, assessment_id, or document_type filtering
   */
  getAll: async (params?: {
    course_id?: number | string;
    assessment_id?: number | string;
    document_type?: string;
  }): Promise<DocumentListResponse> => {
    const query = new URLSearchParams();
    if (params?.course_id) query.append('course_id', String(params.course_id));
    if (params?.assessment_id) query.append('assessment_id', String(params.assessment_id));
    if (params?.document_type) query.append('document_type', params.document_type);

    const queryString = query.toString();
    const endpoint = queryString ? `/documents?${queryString}` : '/documents';

    return apiClient<DocumentListResponse>(endpoint, {
      method: 'GET',
    });
  },

  /**
   * Get single document details including extracted and cleaned text
   */
  getById: async (id: number | string): Promise<DocumentDetailResponse> => {
    return apiClient<DocumentDetailResponse>(`/documents/${id}`, {
      method: 'GET',
    });
  },

  /**
   * Upload and process a document (PDF, DOCX, TXT)
   */
  upload: async (formData: FormData): Promise<DocumentDetailResponse> => {
    return apiClient<DocumentDetailResponse>('/documents', {
      method: 'POST',
      body: formData,
    });
  },

  /**
   * Reprocess text extraction for a document
   */
  reprocess: async (id: number | string): Promise<DocumentDetailResponse> => {
    return apiClient<DocumentDetailResponse>(`/documents/${id}/reprocess`, {
      method: 'POST',
    });
  },

  /**
   * Download the original uploaded document file
   */
  download: async (id: number | string, fileName: string): Promise<void> => {
    const url = `${API_BASE_URL}/documents/${id}/download`;
    const response = await fetch(url, {
      method: 'GET',
      credentials: 'include',
    });

    if (!response.ok) {
      throw new Error('Failed to download document file');
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
   * Delete a document
   */
  delete: async (id: number | string): Promise<ActionResponse> => {
    return apiClient<ActionResponse>(`/documents/${id}`, {
      method: 'DELETE',
    });
  },
};

