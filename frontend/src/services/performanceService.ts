import { apiClient } from './api';
import {
  LearningOutcomePerformance,
  PerformanceAnalysis,
  PerformanceMeta,
  QuestionPerformance,
  StudentPerformance,
  TopicPerformance,
} from '@/types/performance';

export interface PerformanceResponse {
  status: string;
  message?: string;
  data: PerformanceAnalysis | null;
  meta?: PerformanceMeta;
}

export interface PerformancePartResponse<T> {
  status: string;
  data: T[];
  run: PerformanceAnalysis | null;
}

export interface StudentPerformanceResponse {
  status: string;
  data: StudentPerformance;
}

/**
 * STEP 30: Student Performance / Gap Analysis API. All aggregation happens in Laravel from
 * finalized faculty marks; nothing here can change a grade.
 */
export const performanceService = {
  getAssessmentPerformance: (assessmentId: number | string): Promise<PerformanceResponse> =>
    apiClient<PerformanceResponse>(`/assessments/${assessmentId}/performance`, { method: 'GET' }),

  /** Generates a new snapshot (200 inline, or 202 when queued for large assessments). */
  analyzeAssessmentPerformance: (assessmentId: number | string, force = false): Promise<PerformanceResponse> =>
    apiClient<PerformanceResponse>(`/assessments/${assessmentId}/performance/analyze${force ? '?force=1' : ''}`, { method: 'POST' }),

  getQuestionPerformance: (assessmentId: number | string): Promise<PerformancePartResponse<QuestionPerformance>> =>
    apiClient<PerformancePartResponse<QuestionPerformance>>(`/assessments/${assessmentId}/performance/questions`, { method: 'GET' }),

  getTopicPerformance: (assessmentId: number | string): Promise<PerformancePartResponse<TopicPerformance>> =>
    apiClient<PerformancePartResponse<TopicPerformance>>(`/assessments/${assessmentId}/performance/topics`, { method: 'GET' }),

  getLearningOutcomePerformance: (assessmentId: number | string): Promise<PerformancePartResponse<LearningOutcomePerformance>> =>
    apiClient<PerformancePartResponse<LearningOutcomePerformance>>(`/assessments/${assessmentId}/performance/learning-outcomes`, { method: 'GET' }),

  getPerformanceHistory: (assessmentId: number | string): Promise<{ status: string; data: PerformanceAnalysis[] }> =>
    apiClient(`/assessments/${assessmentId}/performance/history`, { method: 'GET' }),

  /** Authorized faculty only; describes performance on this assessment, not ability. */
  getStudentPerformance: (studentId: number | string, assessmentId: number | string): Promise<StudentPerformanceResponse> =>
    apiClient<StudentPerformanceResponse>(`/students/${studentId}/assessments/${assessmentId}/performance`, { method: 'GET' }),
};
