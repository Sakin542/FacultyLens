export interface HistoryCourseInfo {
  id: number;
  course_code: string;
  course_name: string;
  semester?: string;
  academic_year?: string;
}

export interface HistoryAssessmentInfo {
  id: number;
  title: string;
  type: string;
  total_marks?: number;
  course?: HistoryCourseInfo;
}

export interface AnalysisHistoryItem {
  id: number;
  assessment_id: number;
  analysis_version: number;
  is_current: boolean;
  overall_score: number | null;
  rating: string;
  topic_coverage_score: number | null;
  learning_outcome_alignment_score: number | null;
  difficulty_balance_score: number | null;
  cognitive_level_balance_score: number | null;
  similarity_score: number | null;
  total_questions: number;
  similar_questions_count: number;
  analysis_status: string;
  analyzed_at: string;
  assessment: HistoryAssessmentInfo;
  course?: HistoryCourseInfo;
  has_report: boolean;
  report_id?: number | null;
  report_uuid?: string | null;
}

export interface AssessmentVersionItem {
  id: number;
  version: number;
  is_current: boolean;
  overall_score: number | null;
  rating: string;
  topic_coverage_score: number | null;
  learning_outcome_alignment_score: number | null;
  difficulty_balance_score: number | null;
  cognitive_level_balance_score: number | null;
  similarity_score: number | null;
  total_questions: number;
  analysis_status: string;
  analyzed_at: string;
  has_report: boolean;
  report_id?: number | null;
  report_uuid?: string | null;
}

export interface AssessmentHistoryResponse {
  assessment_id: number;
  assessment_title: string;
  course_code: string;
  current_analysis_version: number;
  total_versions: number;
  history: AssessmentVersionItem[];
}

export interface QualityDimensionDetail {
  name: string;
  score: number;
  rating: string;
  weight: string;
}

export interface HistoricalAnalysisDetails {
  analysis_id: number;
  analysis_version: number;
  is_current: boolean;
  analysis_status: string;
  analyzed_at: string;
  assessment: {
    id: number;
    title: string;
    type: string;
    total_marks: number;
    total_questions: number;
    duration_minutes: number | null;
    course_id: number;
    course_code: string;
    course_name: string;
    semester: string;
    academic_year: string;
  };
  overall_quality: {
    score: number;
    rating: string;
    dimensions: Record<string, QualityDimensionDetail>;
  };
  report_summary: {
    total_questions: number;
    similar_questions_count: number;
  };
  findings: Array<{
    severity?: string;
    category?: string;
    problem?: string;
    evidence?: string;
    explanation?: string;
  }>;
  recommendations: Array<{
    id: number;
    category: string;
    priority: string;
    status: string;
    problem: string;
    recommendation: string;
    evidence?: string;
    explanation?: string;
  }>;
  has_report: boolean;
  report_id?: number | null;
  report_uuid?: string | null;
}

export interface ComparisonAnalysisItem {
  id: number;
  assessment_id: number;
  assessment_title: string;
  assessment_type: string;
  course_code: string;
  course_name: string;
  version: number;
  analyzed_at: string;
  overall_score: number | null;
  topic_coverage_score: number | null;
  learning_outcome_alignment_score: number | null;
  difficulty_balance_score: number | null;
  cognitive_level_balance_score: number | null;
  similarity_score: number | null;
  question_diversity_score: number | null;
}

export interface AnalysisComparisonData {
  is_same_assessment: boolean;
  context_notice: string;
  left: ComparisonAnalysisItem;
  right: ComparisonAnalysisItem;
  changes: {
    overall_score: number | null;
    topic_coverage_score: number | null;
    learning_outcome_alignment_score: number | null;
    difficulty_balance_score: number | null;
    cognitive_level_balance_score: number | null;
    similarity_score: number | null;
    question_diversity_score: number | null;
  };
  interpretations: string[];
}

export interface TrendDataPoint {
  analysis_id: number;
  assessment_id: number;
  assessment_title: string;
  assessment_type: string;
  version: number;
  analyzed_at: string;
  overall_score: number;
  topic_coverage_score: number;
  learning_outcome_alignment_score: number;
  difficulty_balance_score: number;
  cognitive_level_balance_score: number;
  similarity_score: number;
}

export interface CourseTrendData {
  course: {
    id: number;
    code: string;
    name: string;
  };
  total_data_points: number;
  series: TrendDataPoint[];
  summary_text: string;
}

export interface ImprovementSummaryData {
  has_sufficient_data: boolean;
  message?: string;
  baseline_date?: string;
  latest_date?: string;
  improvements?: {
    overall_score: number;
    topic_coverage: number;
    learning_outcome_alignment: number;
    difficulty_balance: number;
    cognitive_diversity: number;
  };
}

export interface HistoryFilterParams {
  course_id?: string | number;
  assessment_type?: string;
  academic_year?: string;
  semester?: string;
  search?: string;
  sort?: 'newest' | 'oldest' | 'highest_score' | 'lowest_score';
  page?: number;
  per_page?: number;
}

export interface HistoryPaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

