import { apiClient, API_BASE_URL, ApiError } from './api';
import {
  AnswerPayload,
  CreateSubmissionPayload,
  PaginationMeta,
  Student,
  StudentPayload,
  StudentAnswer,
  StudentSubmission,
  StudentSubmissionDetail,
  SubmissionFilterParams,
  SubmissionStatus,
  SubmissionSummaryStats,
} from '@/types/submission';

export interface SubmissionListResponse {
  status: string;
  data: StudentSubmission[];
  meta: PaginationMeta;
}

export interface SubmissionResponse {
  status: string;
  message?: string;
  data: StudentSubmission;
}

export interface SubmissionDetailResponse {
  status: string;
  data: StudentSubmissionDetail;
}

export interface SubmissionSummaryResponse {
  status: string;
  data: SubmissionSummaryStats;
}

export interface AnswerResponse {
  status: string;
  message?: string;
  data: StudentAnswer;
}

export interface ImportResponse {
  status: string;
  message: string;
  data: { submissions_created: number; answers_created: number; rows: number };
}

export interface StudentListResponse {
  status: string;
  data: Student[];
  meta: PaginationMeta;
}

export interface StudentResponse {
  status: string;
  message?: string;
  data: Student;
}

export interface ActionResponse {
  status: string;
  message: string;
}

function buildQuery(params?: Record<string, string | number | undefined | null>): string {
  const query = new URLSearchParams();
  Object.entries(params ?? {}).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') query.append(k, String(v));
  });
  const s = query.toString();
  return s ? `?${s}` : '';
}

function answerFormData(data: AnswerPayload, file?: File | null): FormData {
  const fd = new FormData();
  if (data.question_id !== undefined) fd.append('question_id', String(data.question_id));
  if (data.answer_text !== undefined && data.answer_text !== null) fd.append('answer_text', data.answer_text);
  if (data.answer_type) fd.append('answer_type', data.answer_type);
  if (data.answer_status) fd.append('answer_status', data.answer_status);
  if (data.awarded_marks !== undefined && data.awarded_marks !== null) fd.append('awarded_marks', String(data.awarded_marks));
  if (data.faculty_feedback !== undefined && data.faculty_feedback !== null) fd.append('faculty_feedback', data.faculty_feedback);
  if (data.remove_file) fd.append('remove_file', '1');
  if (file) fd.append('file', file);
  return fd;
}

/**
 * STEP 26: Student submissions & answers. All endpoints are Sanctum-protected and
 * authorized server-side through Course -> Assessment ownership.
 */
export const studentSubmissionService = {
  getSubmissions: (assessmentId: number | string, params?: SubmissionFilterParams): Promise<SubmissionListResponse> =>
    apiClient<SubmissionListResponse>(`/assessments/${assessmentId}/submissions${buildQuery(params as Record<string, string | number | undefined>)}`, { method: 'GET' }),

  getSummary: (assessmentId: number | string): Promise<SubmissionSummaryResponse> =>
    apiClient<SubmissionSummaryResponse>(`/assessments/${assessmentId}/submissions/summary`, { method: 'GET' }),

  getSubmission: (submissionId: number | string): Promise<SubmissionDetailResponse> =>
    apiClient<SubmissionDetailResponse>(`/submissions/${submissionId}`, { method: 'GET' }),

  createSubmission: (assessmentId: number | string, data: CreateSubmissionPayload): Promise<SubmissionResponse> =>
    apiClient<SubmissionResponse>(`/assessments/${assessmentId}/submissions`, {
      method: 'POST',
      body: JSON.stringify(data),
    }),

  updateSubmissionStatus: (submissionId: number | string, status: SubmissionStatus): Promise<SubmissionResponse> =>
    apiClient<SubmissionResponse>(`/submissions/${submissionId}/status`, {
      method: 'PATCH',
      body: JSON.stringify({ status }),
    }),

  deleteSubmission: (submissionId: number | string): Promise<ActionResponse> =>
    apiClient<ActionResponse>(`/submissions/${submissionId}`, { method: 'DELETE' }),

  /** Text-only answer. */
  addAnswer: (submissionId: number | string, data: AnswerPayload & { question_id: number }): Promise<AnswerResponse> =>
    apiClient<AnswerResponse>(`/submissions/${submissionId}/answers`, {
      method: 'POST',
      body: JSON.stringify(data),
    }),

  /** Answer with a private file (optionally with text). */
  uploadAnswer: (submissionId: number | string, questionId: number, file: File, extra?: AnswerPayload): Promise<AnswerResponse> =>
    apiClient<AnswerResponse>(`/submissions/${submissionId}/answers`, {
      method: 'POST',
      body: answerFormData({ ...(extra ?? {}), question_id: questionId }, file),
    }),

  updateAnswer: (answerId: number | string, data: AnswerPayload, file?: File | null): Promise<AnswerResponse> => {
    if (file) {
      // Multipart updates go through POST (PHP cannot parse multipart PUT bodies).
      return apiClient<AnswerResponse>(`/student-answers/${answerId}`, { method: 'POST', body: answerFormData(data, file) });
    }
    return apiClient<AnswerResponse>(`/student-answers/${answerId}`, { method: 'PUT', body: JSON.stringify(data) });
  },

  deleteAnswer: (answerId: number | string): Promise<ActionResponse> =>
    apiClient<ActionResponse>(`/student-answers/${answerId}`, { method: 'DELETE' }),

  downloadAnswerFile: async (answerId: number | string, fileName: string): Promise<void> => {
    const response = await fetch(`${API_BASE_URL}/student-answers/${answerId}/download`, {
      method: 'GET',
      credentials: 'include',
      headers: { Accept: 'application/json, */*' },
    });
    if (!response.ok) {
      throw new ApiError(response.status, 'Failed to download the answer file.');
    }
    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = fileName || 'answer';
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
  },

  importCsv: (assessmentId: number | string, file: File): Promise<ImportResponse> => {
    const fd = new FormData();
    fd.append('file', file);
    return apiClient<ImportResponse>(`/assessments/${assessmentId}/submissions/import`, { method: 'POST', body: fd });
  },
};

export const studentService = {
  getAll: (params?: { search?: string; page?: number; per_page?: number }): Promise<StudentListResponse> =>
    apiClient<StudentListResponse>(`/students${buildQuery(params)}`, { method: 'GET' }),

  create: (data: StudentPayload): Promise<StudentResponse> =>
    apiClient<StudentResponse>('/students', { method: 'POST', body: JSON.stringify(data) }),

  update: (id: number | string, data: Partial<StudentPayload>): Promise<StudentResponse> =>
    apiClient<StudentResponse>(`/students/${id}`, { method: 'PUT', body: JSON.stringify(data) }),

  delete: (id: number | string): Promise<ActionResponse> =>
    apiClient<ActionResponse>(`/students/${id}`, { method: 'DELETE' }),
};
