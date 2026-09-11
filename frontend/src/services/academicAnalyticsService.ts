import { apiClient, API_BASE_URL } from './api';
import { AnalyticsFilters, AnalyticsOverview, AssessmentComparison, CourseHistory, FilterOptions } from '@/types/analytics';

interface Envelope<T> { status: string; message?: string; data: T; }

const qs = (filters?: AnalyticsFilters | Record<string, string | number | boolean | undefined | null>) => {
  const q = new URLSearchParams();
  Object.entries(filters ?? {}).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') q.append(k, String(v)); });
  const s = q.toString();
  return s ? `?${s}` : '';
};

/**
 * STEP 36: Academic Analytics API. The backend aggregates and authorizes; the client never computes statistics from raw records.
 */
export const academicAnalyticsService = {
  getOverview: (filters?: AnalyticsFilters, fresh = false): Promise<Envelope<AnalyticsOverview>> =>
    apiClient(`/analytics/overview${qs({ ...filters, fresh: fresh ? 1 : undefined })}`, { method: 'GET' }),

  getFilterOptions: (): Promise<Envelope<FilterOptions>> => apiClient('/analytics/filters', { method: 'GET' }),

  getCourseAnalytics: (courseId: number | string, filters?: AnalyticsFilters): Promise<Envelope<AnalyticsOverview>> =>
    apiClient(`/analytics/courses/${courseId}${qs(filters)}`, { method: 'GET' }),

  getAssessmentAnalytics: (assessmentId: number | string): Promise<Envelope<AnalyticsOverview>> =>
    apiClient(`/analytics/overview${qs({ assessment_id: assessmentId })}`, { method: 'GET' }),

  getPerformanceAnalytics: (courseId: number | string, filters?: AnalyticsFilters): Promise<Envelope<Pick<AnalyticsOverview, 'performance' | 'learning_gaps' | 'question_performance' | 'topic_performance' | 'meta' | 'scope'>>> =>
    apiClient(`/analytics/courses/${courseId}/performance${qs(filters)}`, { method: 'GET' }),

  getOutcomeAnalytics: (courseId: number | string, filters?: AnalyticsFilters): Promise<Envelope<Pick<AnalyticsOverview, 'learning_outcomes' | 'program_outcomes' | 'meta' | 'scope'>>> =>
    apiClient(`/analytics/courses/${courseId}/outcomes${qs(filters)}`, { method: 'GET' }),

  getAiAnalytics: (courseId: number | string, filters?: AnalyticsFilters): Promise<Envelope<Pick<AnalyticsOverview, 'rubrics' | 'grading' | 'inter_grader' | 'ai_evaluation' | 'recommendations' | 'meta' | 'scope'>>> =>
    apiClient(`/analytics/courses/${courseId}/ai${qs(filters)}`, { method: 'GET' }),

  getHistoricalAnalytics: (courseId: number | string, filters?: AnalyticsFilters): Promise<Envelope<CourseHistory>> =>
    apiClient(`/analytics/courses/${courseId}/history${qs(filters)}`, { method: 'GET' }),

  compareAssessments: (assessmentIds: number[]): Promise<Envelope<AssessmentComparison>> =>
    apiClient(`/analytics/compare?${assessmentIds.map((id) => `assessment_ids[]=${id}`).join('&')}`, { method: 'GET' }),

  /** Downloads a PDF/CSV export of the current filtered overview; exports are audited server-side. */
  exportAnalytics: async (filters: AnalyticsFilters | undefined, format: 'pdf' | 'csv'): Promise<void> => {
    const response = await fetch(`${API_BASE_URL}/analytics/export${qs({ ...filters, format })}`, { method: 'GET', credentials: 'include', headers: { Accept: '*/*' } });
    if (!response.ok) throw new Error('Failed to export analytics.');
    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `academic-analytics.${format}`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
  },
};
