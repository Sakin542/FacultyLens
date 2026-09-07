export type DecisionType = 'ACCEPTED' | 'DISMISSED' | 'REVIEWED';

export type FeedbackReason =
  | 'USEFUL_INSIGHT'
  | 'WILL_IMPLEMENT'
  | 'ALREADY_ADDRESSED'
  | 'NOT_APPLICABLE'
  | 'INCORRECT_CONTEXT'
  | 'NEEDS_MODIFICATION'
  | 'DUPLICATE'
  | 'OTHER';

export interface SubmitFeedbackPayload {
  decision: DecisionType;
  usefulness_rating?: number | null;
  reason?: FeedbackReason | string | null;
  comment?: string | null;
  faculty_notes?: string | null;
}

export interface RecommendationFeedbackItem {
  id: number;
  recommendation_id: number;
  decision: DecisionType;
  usefulness_rating: number | null;
  reason: string | null;
  comment: string | null;
  created_at: string;
  recommendation?: {
    id: number;
    category: string;
    priority: string;
    problem: string;
    recommendation: string;
    status: string;
  };
  assessment?: {
    id: number;
    title: string;
    type: string;
  };
  course?: {
    id: number;
    code: string;
    name: string;
  };
}

export interface RecommendationDecisionItem {
  id: number;
  recommendation_id: number;
  previous_status: string;
  new_status: string;
  reason: string | null;
  created_at: string;
}

export interface AiImprovementSignalItem {
  id: number;
  signal_type: string;
  signal_value: 'positive' | 'negative' | 'neutral';
  source: string;
  metadata?: {
    category?: string;
    priority?: string;
    source_metric?: string;
    decision?: string;
    usefulness_rating?: number;
    reason?: string;
    problem_title?: string;
    comment_preview?: string;
  };
  created_at: string;
}

export interface FeedbackSummaryData {
  total: number;
  accepted: number;
  dismissed: number;
  reviewed: number;
  average_usefulness: number | null;
  reason_breakdown: Record<string, number>;
  analytics?: {
    total_feedbacks: number;
    acceptance_rate: number;
    dismissal_rate: number;
    review_rate: number;
    average_usefulness: number | null;
    category_breakdown: Array<{
      category: string;
      total: number;
      accepted: number;
      dismissed: number;
      acceptance_rate: number;
      average_usefulness: number | null;
    }>;
    top_reasons: Record<string, number>;
  };
}

export interface ImprovementSignalsResponse {
  summary: {
    total_signals: number;
    positive_signals: number;
    needs_review_signals: number;
    context_neutral_signals: number;
    disclaimer: string;
  };
  signals: AiImprovementSignalItem[];
}

export interface FeedbackFilterParams {
  course_id?: string | number;
  assessment_id?: string | number;
  decision?: string;
  rating?: string | number;
  reason?: string;
  search?: string;
  page?: number;
  per_page?: number;
}

