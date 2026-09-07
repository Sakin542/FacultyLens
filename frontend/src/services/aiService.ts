import { apiClient } from './api';

export interface SingleQuestionAnalysisPayload {
  question: string;
  course_topics?: string[];
}

export interface BatchQuestionAnalysisItem {
  number?: number;
  text: string;
}

export interface BatchQuestionAnalysisPayload {
  questions: BatchQuestionAnalysisItem[];
  course_topics?: string[];
}

export interface AiTopicResult {
  name: string;
  confidence: number;
}

export interface AiQuestionClassification {
  question_type: string;
  confidence: number;
}

export interface AiLevelResult {
  level: string;
  method?: string;
  keywords?: string[];
}

export interface AnalyzedQuestionDetail {
  number?: number;
  question: string;
  classification: AiQuestionClassification;
  topics: AiTopicResult[];
  difficulty: AiLevelResult;
  cognitive_level: AiLevelResult;
}

export interface AiBatchAnalysisSummary {
  question_types?: Record<string, number>;
  difficulty_distribution?: Record<string, number>;
  cognitive_distribution?: Record<string, number>;
  topics_detected?: string[];
}

export interface AiBatchAnalysisResponseData {
  status: string;
  total_questions: number;
  questions: AnalyzedQuestionDetail[];
  summary: AiBatchAnalysisSummary;
}

export interface AiSingleAnalysisResponseData {
  status: string;
  question: string;
  classification: AiQuestionClassification;
  topics: AiTopicResult[];
  difficulty: AiLevelResult;
  cognitive_level: AiLevelResult;
}

export interface ApiResponseWrapper<T> {
  status: string;
  data: T;
  message?: string;
}

export const aiService = {
  /**
   * Check FastAPI AI service health via Laravel proxy
   */
  checkHealth: async () => {
    return apiClient<ApiResponseWrapper<{ status: string; ai_service?: unknown }>>('/ai/health', {
      method: 'GET',
    });
  },

  /**
   * General text preprocessing & question segmentation analysis
   */
  analyzeText: async (payload: { text: string; course_context?: string }) => {
    return apiClient<ApiResponseWrapper<unknown>>('/ai/analyze', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  /**
   * Analyze a single question (Classification, Bloom Cognitive, Difficulty, Topics)
   */
  analyzeQuestion: async (payload: SingleQuestionAnalysisPayload) => {
    return apiClient<ApiResponseWrapper<AiSingleAnalysisResponseData>>('/ai/analyze-question', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  /**
   * Analyze a batch of questions (Classification, Bloom Cognitive, Difficulty, Topics, Summary)
   */
  analyzeQuestions: async (payload: BatchQuestionAnalysisPayload) => {
    return apiClient<ApiResponseWrapper<AiBatchAnalysisResponseData>>('/ai/analyze-questions', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  /**
   * Run AI question analysis on an entire assessment's persisted questions and store AI results in database
   */
  analyzeAssessmentQuestions: async (assessmentId: number | string, payload?: { course_topics?: string[] }) => {
    return apiClient<ApiResponseWrapper<AiBatchAnalysisResponseData>>(`/ai/assessments/${assessmentId}/analyze-questions`, {
      method: 'POST',
      body: JSON.stringify(payload || {}),
    });
  },
};

