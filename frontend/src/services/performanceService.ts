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

export interface ClassSummaryArea {
  question_id: number; question_number: string; criterion: string; responses: number; weak: number;
  levels: { STRONG: number; PARTIAL: number; LIMITED: number; NOT_ADDRESSED: number };
  top_missing: { text: string; count: number }[];
}
export interface ClassSummary {
  status: 'ready' | 'no_data';
  message?: string;
  stats?: { answers: number; students: number; questions: number; average: number | null; expected: number; rubric_answers: number };
  questions?: { question_id: number; number: string; text: string; responses: number; average: number }[];
  struggles?: ClassSummaryArea[];
  strengths?: ClassSummaryArea[];
  paragraphs: string[];
  note: string;
  method?: string;
  generated_at?: string;
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

  /** Plain-language class summary from finalized marks and rubric evidence (computed on request, not stored). */
  classSummary: (assessmentId: number | string): Promise<{ status: string; data: ClassSummary }> =>
    apiClient<{ status: string; data: ClassSummary }>(`/assessments/${assessmentId}/performance/class-summary`, { method: 'POST' }),

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
