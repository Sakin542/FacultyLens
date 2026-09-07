import { apiClient } from './api';
import {
  AlignmentAnalysisResult,
  SimilarityAnalysisResult,
  AssessmentQualityResult,
  EvidenceBasedRecommendation,
} from '../types';

export interface ApiResponseWrapper<T> {
  status: string;
  message?: string;
  data: T;
}

export interface UnifiedAnalysisSummary {
  total_questions: number;
  overall_quality_score?: number | null;
  quality_rating?: string | null;
  lo_coverage_percentage?: number | null;
  topic_coverage_percentage?: number | null;
  difficulty_balance_score?: number | null;
  cognitive_diversity_score?: number | null;
  total_recommendations: number;
  high_priority_recommendations?: number;
  potential_duplicates_count?: number;
}

export interface UnifiedAssessmentAnalysisData {
  status: string;
  method: string;
  assessment_id?: number | string | null;
  course_id?: number | string | null;
  questions_analysis?: {
    status: string;
    total_questions: number;
    questions: any[];
    summary: Record<string, any>;
  };
  alignment_analysis?: AlignmentAnalysisResult | { status: string; message: string };
  similarity_analysis?: SimilarityAnalysisResult | { status: string; message: string };
  quality_analysis?: AssessmentQualityResult;
  recommendations?: {
    status: string;
    total_recommendations: number;
    high_priority_count: number;
    medium_priority_count: number;
    low_priority_count: number;
    recommendations: EvidenceBasedRecommendation[];
  };
  summary: UnifiedAnalysisSummary;
}

export interface UnifiedAssessmentAnalysisPayload {
  assessment_id?: number | string;
  course_id?: number | string;
  questions?: Array<{
    id?: number | string;
    number?: number | string;
    text: string;
    marks?: number;
    question_type?: string;
    difficulty?: string;
    cognitive_level?: string;
    topics?: string[];
    learning_outcome_code?: string;
  }>;
  learning_outcomes?: Array<{
    id?: number | string;
    code?: string;
    description: string;
  }>;
  course_topics?: string[];
  previous_questions?: Array<{
    id?: number | string;
    number?: number | string;
    text: string;
    assessment_title?: string;
    term?: string;
    year?: string | number;
  }>;
  weights?: Record<string, number>;
  difficulty_targets?: Record<string, number>;
  custom_rules?: Record<string, any>;
}

export const aiAnalysisService = {
  /**
   * Run full consolidated assessment analysis on a persisted assessment.
   */
  async analyzeAssessment(assessmentId: number | string): Promise<ApiResponseWrapper<UnifiedAssessmentAnalysisData>> {
    return apiClient<ApiResponseWrapper<UnifiedAssessmentAnalysisData>>(
      `/ai/assessments/${assessmentId}/analyze`,
      {
        method: 'POST',
        body: JSON.stringify({}),
      }
    );
  },

  /**
   * Run direct/custom assessment analysis with optional custom weights and rules.
   */
  async analyzeAssessmentDirect(
    payload: UnifiedAssessmentAnalysisPayload
  ): Promise<ApiResponseWrapper<UnifiedAssessmentAnalysisData>> {
    return apiClient<ApiResponseWrapper<UnifiedAssessmentAnalysisData>>(
      '/ai/analyze-assessment',
      {
        method: 'POST',
        body: JSON.stringify(payload),
      }
    );
  },

  /**
   * Batch question analysis (alias endpoint).
   */
  async questionAnalysis(payload: {
    questions: Array<{ number?: number; text: string }>;
    course_topics?: string[];
  }) {
    return apiClient<ApiResponseWrapper<any>>('/ai/question-analysis', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  /**
   * Semantic similarity analysis (alias endpoint).
   */
  async similarityAnalysis(payload: {
    current_questions: Array<{ id?: number | string; text: string }>;
    previous_questions?: Array<{ id?: number | string; text: string }>;
  }) {
    return apiClient<ApiResponseWrapper<any>>('/ai/similarity-analysis', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  /**
   * Learning Outcome alignment analysis (alias endpoint).
   */
  async alignmentAnalysis(payload: {
    questions: Array<{ id?: number | string; text: string }>;
    learning_outcomes: Array<{ id?: number | string; code?: string; description: string }>;
  }) {
    return apiClient<ApiResponseWrapper<any>>('/ai/alignment-analysis', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },
};

