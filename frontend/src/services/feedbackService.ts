import { apiClient } from './api';
import {
  SubmitFeedbackPayload,
  RecommendationFeedbackItem,
  FeedbackSummaryData,
  ImprovementSignalsResponse,
  FeedbackFilterParams,
} from '../types/feedback';
import { HistoryPaginationMeta } from '../types/analysisHistory';
import { EvidenceBasedRecommendation } from '../types';

export interface FeedbackHistoryResponse {
  status: string;
  success: boolean;
  data: RecommendationFeedbackItem[];
  meta: HistoryPaginationMeta;
}

export const feedbackService = {
  /**
   * Submit faculty decision, rating, reason, and optional qualitative comments on a recommendation.
   */
  async submitFeedback(
    recommendationId: number | string,
    data: SubmitFeedbackPayload
  ): Promise<{
    recommendation: EvidenceBasedRecommendation;
    feedback: RecommendationFeedbackItem;
  }> {
    const response = await apiClient<{
      status: string;
      success: boolean;
      data: {
        recommendation: EvidenceBasedRecommendation;
        feedback: RecommendationFeedbackItem;
      };
    }>(`/recommendations/${recommendationId}/feedback`, {
      method: 'POST',
      body: JSON.stringify(data),
    });

    return response.data;
  },

  /**
   * Quick status transition update on a recommendation.
   */
  async updateStatus(
    recommendationId: number | string,
    status: string,
    notes?: string,
    reason?: string
  ): Promise<EvidenceBasedRecommendation> {
    const response = await apiClient<{
      status: string;
      success: boolean;
      data: EvidenceBasedRecommendation;
    }>(`/recommendations/${recommendationId}/status`, {
      method: 'PATCH',
      body: JSON.stringify({
        status,
        faculty_notes: notes,
        reason,
      }),
    });

    return response.data;
  },

  /**
   * Fetch paginated feedback history for the authenticated faculty member.
   */
  async getFeedbackHistory(
    params?: FeedbackFilterParams
  ): Promise<{ data: RecommendationFeedbackItem[]; meta: HistoryPaginationMeta }> {
    const query = new URLSearchParams();

    if (params) {
      if (params.course_id && params.course_id !== 'all') {
        query.append('course_id', String(params.course_id));
      }
      if (params.assessment_id && params.assessment_id !== 'all') {
        query.append('assessment_id', String(params.assessment_id));
      }
      if (params.decision && params.decision !== 'all') {
        query.append('decision', params.decision);
      }
      if (params.rating && params.rating !== 'all') {
        query.append('rating', String(params.rating));
      }
      if (params.reason && params.reason !== 'all') {
        query.append('reason', params.reason);
      }
      if (params.search && params.search.trim()) {
        query.append('search', params.search.trim());
      }
      if (params.page) {
        query.append('page', String(params.page));
      }
      if (params.per_page) {
        query.append('per_page', String(params.per_page));
      }
    }

    const endpoint = `/feedback${query.toString() ? `?${query.toString()}` : ''}`;
    const response = await apiClient<FeedbackHistoryResponse>(endpoint);

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
   * Fetch feedback entries for a specific recommendation.
   */
  async getRecommendationFeedback(
    recommendationId: number | string
  ): Promise<RecommendationFeedbackItem[]> {
    const response = await apiClient<{
      status: string;
      success: boolean;
      data: RecommendationFeedbackItem[];
    }>(`/recommendations/${recommendationId}/feedback`);

    return response.data || [];
  },

  /**
   * Fetch aggregated feedback summary metrics and category analytics.
   */
  async getFeedbackSummary(courseId?: number | string): Promise<FeedbackSummaryData> {
    const query = new URLSearchParams();
    if (courseId && courseId !== 'all') {
      query.append('course_id', String(courseId));
    }

    const endpoint = `/feedback/summary${query.toString() ? `?${query.toString()}` : ''}`;
    const response = await apiClient<{
      status: string;
      success: boolean;
      data: FeedbackSummaryData;
    }>(endpoint);

    return response.data;
  },

  /**
   * Fetch paginated AI improvement signals with summary counts.
   */
  async getImprovementSignals(params?: {
    signal_type?: string;
    signal_value?: string;
    assessment_id?: number | string;
    page?: number;
    per_page?: number;
  }): Promise<{
    summary: ImprovementSignalsResponse['summary'];
    signals: ImprovementSignalsResponse['signals'];
    meta: HistoryPaginationMeta;
  }> {
    const query = new URLSearchParams();

    if (params) {
      if (params.signal_type && params.signal_type !== 'all') {
        query.append('signal_type', params.signal_type);
      }
      if (params.signal_value && params.signal_value !== 'all') {
        query.append('signal_value', params.signal_value);
      }
      if (params.assessment_id && params.assessment_id !== 'all') {
        query.append('assessment_id', String(params.assessment_id));
      }
      if (params.page) {
        query.append('page', String(params.page));
      }
      if (params.per_page) {
        query.append('per_page', String(params.per_page));
      }
    }

    const endpoint = `/ai/improvement-signals${query.toString() ? `?${query.toString()}` : ''}`;
    const response = await apiClient<{
      status: string;
      success: boolean;
      data: {
        summary: ImprovementSignalsResponse['summary'];
        signals: ImprovementSignalsResponse['signals'];
      };
      meta: HistoryPaginationMeta;
    }>(endpoint);

    return {
      summary: response.data.summary,
      signals: response.data.signals || [],
      meta: response.meta,
    };
  },
};

