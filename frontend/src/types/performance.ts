/**
 * STEP 30: Student Performance / Gap Analysis types.
 *
 * All values derive from FINALIZED faculty marks. Statuses are review signals for faculty,
 * never verdicts about students. No causal claims are made anywhere in this feature.
 */

export type PerformanceStatus = 'STRONG' | 'ON_TARGET' | 'MINOR_GAP' | 'MODERATE_GAP' | 'HIGH_GAP' | 'INSUFFICIENT_DATA';

export type PerformanceRunStatus = 'PENDING' | 'PROCESSING' | 'COMPLETED' | 'FAILED' | 'STALE';

export const GAP_STATUSES: PerformanceStatus[] = ['MINOR_GAP', 'MODERATE_GAP', 'HIGH_GAP'];

export interface PerformanceThresholds {
  expected_performance_percent: number;
  strong_performance_percent: number;
  gap_low_threshold: number;
  gap_moderate_threshold: number;
  gap_high_threshold: number;
  min_responses_for_gap_analysis: number;
  finalized_grading_statuses: string[];
}

export interface QuestionPerformance {
  id: number;
  question_id: number | null;
  question_number: number | null;
  question_text_excerpt: string | null;
  maximum_marks: number;
  response_count: number;
  submission_count: number;
  average_marks: number | null;
  average_percentage: number | null;
  median_marks: number | null;
  minimum_marks: number | null;
  max_awarded_marks: number | null;
  performance_gap: number | null;
  performance_status: PerformanceStatus;
  difficulty_level: string | null;
  cognitive_level: string | null;
  topics: string[];
  ai_suggested_average_percentage: number | null;
  rubric_alignment_average: number | null;
  review_signals: string[];
}

export interface TopicPerformance {
  id: number;
  topic: string;
  question_count: number;
  question_ids: number[];
  response_count: number;
  total_marks: number | null;
  average_percentage: number | null;
  performance_gap: number | null;
  performance_status: PerformanceStatus;
}

export interface LearningOutcomePerformance {
  id: number;
  learning_outcome_id: number | null;
  lo_code: string | null;
  lo_description: string | null;
  question_count: number;
  question_ids: number[];
  response_count: number;
  total_marks: number | null;
  average_percentage: number | null;
  performance_gap: number | null;
  performance_status: PerformanceStatus;
}

export interface PerformanceArea {
  type: 'learning_outcome' | 'topic' | 'question';
  label: string;
  description: string | null;
  average_percentage: number | null;
  performance_gap: number | null;
  performance_status: PerformanceStatus;
  reference_id: number | null;
}

export interface PerformanceSummary {
  status_counts: Partial<Record<PerformanceStatus, number>>;
  gap_areas: PerformanceArea[];
  strong_areas: PerformanceArea[];
  los_with_gaps?: number;
  topics_with_gaps?: number;
  questions_with_gaps?: number;
  insufficient_data_count?: number;
}

export interface PerformanceAnalysis {
  id: number;
  assessment_id: number;
  course_id: number;
  status: PerformanceRunStatus;
  is_current: boolean;
  is_stale: boolean;
  stale_reasons: string[];
  expected_performance_percent: number;
  minimum_responses: number;
  thresholds: PerformanceThresholds | null;
  student_count: number;
  submission_count: number;
  finalized_answer_count: number;
  question_count: number;
  overall_average_percentage: number | null;
  overall_gap: number | null;
  overall_status: PerformanceStatus | null;
  summary: PerformanceSummary;
  error_message: string | null;
  analyzed_at: string | null;
  created_at?: string;
  limitations: string;
  questions?: QuestionPerformance[];
  topics?: TopicPerformance[];
  learning_outcomes?: LearningOutcomePerformance[];
}

export interface PerformanceMeta {
  finalized_answer_count: number;
  expected_performance_percent: number;
  minimum_responses: number;
}

export interface StudentQuestionPerformance {
  question_id: number;
  question_number: number | null;
  question_text_excerpt: string | null;
  maximum_marks: number;
  answered: boolean;
  awarded_marks: number | null;
  is_finalized: boolean;
  percentage: number | null;
  topics: string[];
  learning_outcome_code: string | null;
}

export interface StudentAreaForReview {
  type: 'topic' | 'learning_outcome';
  label: string;
  description: string | null;
  percentage: number;
  gap: number;
}

export interface StudentPerformance {
  student: { id: number; student_identifier: string; name: string };
  assessment: { id: number; title: string };
  submission_id: number | null;
  submission_status: string | null;
  grading_status: string | null;
  has_finalized_grades: boolean;
  finalized_question_count: number;
  question_count: number;
  total_awarded_marks: number | null;
  total_maximum_marks: number | null;
  overall_percentage: number | null;
  expected_performance_percent: number;
  questions: StudentQuestionPerformance[];
  areas_for_review: StudentAreaForReview[];
  note: string;
}

export const isPerformanceRunActive = (status: PerformanceRunStatus | null | undefined): boolean =>
  status === 'PENDING' || status === 'PROCESSING';

export const isGapStatus = (status: PerformanceStatus | null | undefined): boolean =>
  !!status && GAP_STATUSES.includes(status);
