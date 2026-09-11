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


// ---------------------------------------------------------------------------
// STEP 39 — Institutional Export & Reporting
// ---------------------------------------------------------------------------

export type ReportTypeKey =
  | 'ASSESSMENT'
  | 'ASSESSMENT_QUALITY'
  | 'ASSESSMENT_BLUEPRINT'
  | 'ASSESSMENT_VERSION_HISTORY'
  | 'QUESTION_ANALYSIS'
  | 'CO_COVERAGE'
  | 'PO_COVERAGE'
  | 'STUDENT_PERFORMANCE'
  | 'LEARNING_GAPS'
  | 'RUBRIC'
  | 'GRADING'
  | 'INTER_GRADER'
  | 'AI_EVALUATION'
  | 'ACADEMIC_ANALYTICS'
  | 'INSTITUTIONAL_SUMMARY';

export type ReportScope = 'FACULTY' | 'COURSE' | 'ASSESSMENT' | 'ASSESSMENT_VERSION' | 'DEPARTMENT' | 'INSTITUTION';

export type ExportFormat = 'PDF' | 'CSV' | 'XLSX';

export type ReportStatus = 'PENDING' | 'PROCESSING' | 'COMPLETED' | 'FAILED' | 'CANCELLED';

export type ReportFilterKey =
  | 'course_id'
  | 'assessment_id'
  | 'assessment_version_id'
  | 'semester'
  | 'academic_year'
  | 'assessment_type'
  | 'department'
  | 'program_id'
  | 'start_date'
  | 'end_date'
  | 'status';

/** Exact filter snapshot sent with a request / stored with a generated report. */
export type ReportFilter = Partial<Record<ReportFilterKey, string | number>>;

export interface ReportType {
  key: ReportTypeKey;
  label: string;
  description: string;
  scopes: ReportScope[];
  student_data: boolean;
  formats: ExportFormat[];
}

export interface ReportTypesResponse {
  types: ReportType[];
  scopes: ReportScope[];
  formats: ExportFormat[];
  scope_filters: Record<ReportScope, ReportFilterKey[]>;
  expiration_days: number;
  async_threshold_records: number;
}

export interface ReportCourseOption { id: number; code: string; name: string; semester: string | null; academic_year: string | null }
export interface ReportAssessmentOption { id: number; course_id: number; title: string; type: string; date: string | null }
export interface ReportVersionOption { id: number; assessment_id: number; version_number: number; version_label: string; status: string; total_marks: number; question_count: number }
export interface ReportProgramOption { id: number; code: string; name: string }

export interface ReportFilterOptions {
  applicable: ReportFilterKey[];
  options: {
    courses: ReportCourseOption[];
    assessments: ReportAssessmentOption[];
    assessment_versions: ReportVersionOption[];
    semesters: string[];
    academic_years: string[];
    assessment_types: string[];
    statuses: string[];
    departments: string[];
    programs: ReportProgramOption[];
  };
}

export interface ReportRequest {
  report_type: ReportTypeKey;
  scope_type: ReportScope;
  filters: ReportFilter;
  format?: ExportFormat;
}

export type ReportCell = string | number | boolean | null;
export type ReportRow = Record<string, ReportCell>;

export interface ReportColumn { key: string; label: string }

export interface ReportTable {
  key: string;
  title: string;
  columns: ReportColumn[];
  rows: ReportRow[];
  note?: string | null;
  /** Present in previews: rows are truncated to a preview limit. */
  total_rows?: number;
}

export interface ReportSummaryItem { label: string; value: ReportCell }

export interface ReportSection {
  key: string;
  title: string;
  description?: string | null;
  text?: string | null;
  items?: ReportSummaryItem[];
}

export interface ReportMetadata {
  product: string;
  note: string;
  report_type: ReportTypeKey;
  report_label: string;
  scope: ReportScope;
  scope_description: string;
  filters: ReportFilter;
  generated_by: { id: number; name: string; department: string | null; designation: string | null };
  generated_at: string;
  data_as_of: string;
  course: { id: number; code: string; name: string; semester: string | null; academic_year: string | null } | null;
  assessment: { id: number; title: string; type: string } | null;
  assessment_version: { id: number; version_label: string; status: string } | null;
  academic_year: string | null;
  semester: string | null;
  data_period: string | null;
  course_count: number;
  assessment_count: number;
  contains_student_data: boolean;
  privacy: string | null;
}

export interface ReportPreview {
  report_type: ReportTypeKey;
  report_label: string;
  scope_type: ReportScope;
  scope_description: string;
  filters: ReportFilter;
  record_count: number;
  estimated_size: string;
  will_queue: boolean;
  contains_student_data: boolean;
  has_data: boolean;
  metadata: ReportMetadata;
  summary: ReportSummaryItem[];
  sections: ReportSection[];
  tables: ReportTable[];
  warnings: string[];
}

export interface InstitutionalReport {
  id: number;
  uuid: string;
  title: string;
  report_type: ReportTypeKey;
  report_label: string;
  scope_type: ReportScope;
  course: { id: number; code: string; name: string; semester?: string | null; academic_year?: string | null } | null;
  assessment: { id: number; title: string } | null;
  assessment_version: { id: number; version_label: string; status: string } | null;
  department: string | null;
  program_id: number | null;
  filters: ReportFilter;
  format: ExportFormat;
  status: ReportStatus;
  is_async: boolean;
  contains_student_data: boolean;
  file_name: string | null;
  file_size: number | null;
  record_count: number | null;
  summary: { items: ReportSummaryItem[]; warnings: string[]; tables: { key: string; title: string; rows: number }[] } | null;
  data_as_of: string | null;
  started_at: string | null;
  generated_at: string | null;
  expires_at: string | null;
  is_expired: boolean;
  downloadable: boolean;
  file_deleted_at: string | null;
  error_message: string | null;
  created_by: { id: number; name: string } | null;
  created_at: string;
  updated_at: string;
}

export interface ReportListResponse {
  items: InstitutionalReport[];
  pagination: { current_page: number; last_page: number; per_page: number; total: number };
}
