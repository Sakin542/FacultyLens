import { apiClient } from './api';
import {
  AnalysisHistoryItem,
  AssessmentHistoryResponse,
  HistoricalAnalysisDetails,
  AnalysisComparisonData,
  CourseTrendData,
  ImprovementSummaryData,
  HistoryFilterParams,
  HistoryPaginationMeta,
} from '../types/analysisHistory';

export interface HistoryListResponse {
  status: string;
  success: boolean;
  data: AnalysisHistoryItem[];
  meta: HistoryPaginationMeta;
}

export const analysisHistoryService = {
  /**
   * Fetch paginated list of all analysis history records with multi-criteria filtering.
   */
  async getHistory(params?: HistoryFilterParams): Promise<{ data: AnalysisHistoryItem[]; meta: HistoryPaginationMeta }> {
    const query = new URLSearchParams();

    if (params) {
      if (params.course_id && params.course_id !== 'all') {
        query.append('course_id', String(params.course_id));
      }
      if (params.assessment_type && params.assessment_type !== 'all') {
        query.append('assessment_type', params.assessment_type);
      }
      if (params.academic_year && params.academic_year !== 'all') {
        query.append('academic_year', params.academic_year);
      }
      if (params.semester && params.semester !== 'all') {
        query.append('semester', params.semester);
      }
      if (params.search && params.search.trim()) {
        query.append('search', params.search.trim());
      }
      if (params.sort) {
        query.append('sort', params.sort);
      }
      if (params.page) {
        query.append('page', String(params.page));
      }
      if (params.per_page) {
        query.append('per_page', String(params.per_page));
      }
    }

    const endpoint = `/analysis/history${query.toString() ? `?${query.toString()}` : ''}`;
    const response = await apiClient<HistoryListResponse>(endpoint);

    return {
      data: response.data || [],
      meta: response.meta || {
        current_page: 1,
        last_page: 1,
        per_page: 15,
        total: (response.data || []).length,
      },
    };
  },

  /**
   * Fetch all historical analysis versions for a specific assessment.
   */
  async getAssessmentHistory(assessmentId: number | string): Promise<AssessmentHistoryResponse> {
    const response = await apiClient<{ status: string; success: boolean; data: AssessmentHistoryResponse }>(
      `/assessments/${assessmentId}/analysis-history`
    );
    return response.data;
  },

  /**
   * Fetch the full historical analysis snapshot for a specific AnalysisReport ID.
   */
  async getAnalysisDetails(analysisId: number | string): Promise<HistoricalAnalysisDetails> {
    const response = await apiClient<{ status: string; success: boolean; data: HistoricalAnalysisDetails }>(
      `/analysis/${analysisId}`
    );
    return response.data;
  },

  /**
   * Run side-by-side comparison between two distinct historical analyses.
   */
  async compareAnalyses(
    leftId: number | string,
    rightId: number | string
  ): Promise<AnalysisComparisonData> {
    const response = await apiClient<{ status: string; success: boolean; data: AnalysisComparisonData }>(
      `/analysis/compare?left=${leftId}&right=${rightId}`
    );
    return response.data;
  },

  /**
   * Fetch chronological quality trend data for a course/assessment.
   */
  async getTrendData(
    courseId: number | string,
    assessmentType?: string
  ): Promise<CourseTrendData> {
    const query = new URLSearchParams();
    query.append('course_id', String(courseId));
    if (assessmentType && assessmentType !== 'all') {
      query.append('assessment_type', assessmentType);
    }

    const response = await apiClient<{ status: string; success: boolean; data: CourseTrendData }>(
      `/analysis/trends?${query.toString()}`
    );
    return response.data;
  },

  /**
   * Fetch summary of quality changes across the course timeline.
   */
  async getImprovementSummary(
    courseId: number | string,
    assessmentType?: string
  ): Promise<ImprovementSummaryData> {
    const query = new URLSearchParams();
    query.append('course_id', String(courseId));
    if (assessmentType && assessmentType !== 'all') {
      query.append('assessment_type', assessmentType);
    }

    const response = await apiClient<{ status: string; success: boolean; data: ImprovementSummaryData }>(
      `/analysis/improvement-summary?${query.toString()}`
    );
    return response.data;
  },
};

