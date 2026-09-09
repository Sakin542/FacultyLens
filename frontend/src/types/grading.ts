/**
 * STEP 27: AI Grading Assistance types.
 *
 * AI suggested marks are recommendations only. Faculty final marks live on
 * StudentAnswer.awarded_marks and are never overwritten by AI output.
 */

export type AIGradingStatus = 'PENDING' | 'PROCESSING' | 'COMPLETED' | 'FAILED' | 'REVIEWED' | 'FINALIZED';

export type AIGradingDecision = 'ACCEPTED' | 'MODIFIED' | 'REJECTED';

export type CoverageLevel = 'STRONG' | 'PARTIAL' | 'LIMITED' | 'NOT_ADDRESSED';

export interface AIGradingCriterionResult {
  id: number;
  rubric_criterion_id: number | null;
  criterion: string;
  suggested_marks: number;
  maximum_marks: number;
  evaluation: string;
  evidence: string[];
  missing_elements: string[];
  coverage_level?: CoverageLevel | string | null;
  sort_order?: number;
}

export interface AIGradingResult {
  id: number;
  student_answer_id: number;
  student_submission_id: number;
  question_id: number;
  rubric_id: number | null;
  rubric_version: number | null;
  suggested_marks: number | null;
  maximum_marks: number;
  overall_feedback: string | null;
  strengths: string[];
  missing_elements: string[];
  evaluation_summary: string | null;
  grading_status: AIGradingStatus;
  is_current: boolean;
  is_stale: boolean;
  stale_reasons: string[];
  faculty_decision: AIGradingDecision | null;
  error_message: string | null;
  model_name?: string | null;
  model_version?: string | null;
  generation_method?: string | null;
  generated_at: string | null;
  reviewed_at?: string | null;
  reviewed_by?: number | null;
  created_at?: string;
  updated_at?: string;
  criterion_results: AIGradingCriterionResult[];
}

export interface FinalGradePayload {
  final_marks: number;
  faculty_feedback?: string | null;
  decision?: AIGradingDecision | null;
}

export const isAIGradingActive = (status: AIGradingStatus | null | undefined): boolean =>
  status === 'PENDING' || status === 'PROCESSING';

export const isAIGradingCompleted = (status: AIGradingStatus | null | undefined): boolean =>
  status === 'COMPLETED' || status === 'REVIEWED' || status === 'FINALIZED';
