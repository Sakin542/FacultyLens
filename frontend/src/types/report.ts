export interface ReportAssessmentInfo {
  id: number;
  title: string;
  type: string;
  total_marks: number;
  total_questions: number;
  duration_minutes: number | null;
  assessment_date: string | null;
  course_id: number;
  course_code: string;
  course_name: string;
  department: string;
  faculty_name: string;
  faculty_email: string;
  analyzed_at: string;
  generated_at: string;
}

export interface ReportQualityDimension {
  name: string;
  score: number;
  rating: string;
  weight: string;
  description: string;
}

export interface ReportOverallQuality {
  score: number;
  rating: string;
  dimensions: {
    topic_coverage: ReportQualityDimension;
    learning_outcome_alignment: ReportQualityDimension;
    difficulty_balance: ReportQualityDimension;
    cognitive_diversity: ReportQualityDimension;
    question_diversity: ReportQualityDimension;
    marks_distribution: ReportQualityDimension;
  };
}

export interface ReportTopic {
  topic: string;
  status: 'COVERED' | 'LOW_COVERAGE' | 'NOT_COVERED' | string;
  question_count: number;
  marks: number;
}

export interface ReportTopicCoverage {
  score: number;
  rating: string;
  total_topics: number;
  covered_topics: number;
  low_coverage_topics: number;
  not_covered_topics: number;
  topics: ReportTopic[];
}

export interface ReportLOOutcome {
  id: number;
  code: string;
  description: string;
  question_count: number;
  average_score: number;
  alignment_status: 'STRONG' | 'WEAK' | 'NOT_ALIGNED' | string;
}

export interface ReportQuestionLOMapping {
  question_number: number;
  question_text: string;
  lo_code: string;
  lo_description: string;
  similarity_score: number;
  alignment: string;
  reasoning: string;
}

export interface ReportLOAlignment {
  score: number;
  rating: string;
  outcomes: ReportLOOutcome[];
  question_mappings: ReportQuestionLOMapping[];
}

export interface ReportDifficultyTier {
  label: string;
  question_count: number;
  marks: number;
  actual_percentage: number;
  target_percentage: number;
}

export interface ReportDifficultyDistribution {
  score: number;
  rating: string;
  levels: {
    easy: ReportDifficultyTier;
    medium: ReportDifficultyTier;
    hard: ReportDifficultyTier;
  };
}

export interface ReportBloomLevel {
  label: string;
  count: number;
  marks: number;
  percentage: number;
}

export interface ReportCognitiveDistribution {
  score: number;
  rating: string;
  levels: {
    remember: ReportBloomLevel;
    understand: ReportBloomLevel;
    apply: ReportBloomLevel;
    analyze: ReportBloomLevel;
    evaluate: ReportBloomLevel;
    create: ReportBloomLevel;
    [key: string]: ReportBloomLevel;
  };
}

export interface ReportSimilarQuestionItem {
  current_question_number: number;
  current_question_text: string;
  previous_question_text: string;
  previous_assessment_title: string;
  previous_year: string | number;
  similarity_score: number;
  similarity_status: string;
  reasoning: string;
}

export interface ReportSimilarQuestions {
  score: number;
  rating: string;
  total_matches: number;
  potential_duplicates_count: number;
  highly_similar_count: number;
  somewhat_similar_count: number;
  potential_duplicates: ReportSimilarQuestionItem[];
  highly_similar: ReportSimilarQuestionItem[];
  somewhat_similar: ReportSimilarQuestionItem[];
}

export interface ReportFinding {
  category: string;
  severity: 'critical' | 'warning' | 'info' | string;
  problem: string;
  evidence: string | null;
  explanation: string | null;
}

export interface ReportRecommendation {
  id: number;
  category: string;
  priority: 'high' | 'medium' | 'low';
  status: 'pending' | 'accepted' | 'dismissed' | 'reviewed';
  problem: string;
  recommendation: string;
  evidence: string | string[] | null;
  explanation: string | null;
  action_taken: string;
}

export interface ReportRecommendationSummary {
  total: number;
  high: number;
  medium: number;
  low: number;
  accepted: number;
  dismissed: number;
  reviewed: number;
  pending: number;
}

export interface GeneratedPdfReportInfo {
  id: number;
  uuid: string;
  file_name: string;
  file_size: number | null;
  generation_status: 'pending' | 'generating' | 'completed' | 'failed' | string;
  is_shareable: boolean;
  share_token: string | null;
  generated_at: string | null;
}

export interface AssessmentReportData {
  assessment: ReportAssessmentInfo;
  overall_quality: ReportOverallQuality;
  topic_coverage: ReportTopicCoverage;
  learning_outcome_alignment: ReportLOAlignment;
  difficulty_distribution: ReportDifficultyDistribution;
  cognitive_distribution: ReportCognitiveDistribution;
  similar_questions: ReportSimilarQuestions;
  findings: ReportFinding[];
  recommendations: ReportRecommendation[];
  recommendation_summary: ReportRecommendationSummary;
  generated_report: GeneratedPdfReportInfo | null;
  disclaimer: string;
  engine_metadata: {
    system: string;
    version: string;
    analysis_report_id: number;
  };
}

