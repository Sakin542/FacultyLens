/** STEP 36: Academic Analytics Dashboard types (mirror AcademicAnalyticsController / Analytics services). */

export interface AnalyticsFilters {
  course_id?: number | '';
  assessment_id?: number | '';
  semester?: string;
  academic_year?: string;
  assessment_type?: string;
  start_date?: string;
  end_date?: string;
  sort?: 'worst' | 'gap' | 'number' | 'co';
}

export type TrendPeriod = '7D' | '30D' | '3M' | '6M' | '1Y' | 'ALL';

export interface FilterOptions {
  courses: { id: number; code: string; name: string; semester: string | null; academic_year: string | null }[];
  assessments: { id: number; course_id: number; title: string; type: string; date: string | null }[];
  semesters: string[];
  academic_years: string[];
  assessment_types: string[];
}

export interface Kpi { value: number | null; label: string; unit?: 'percent' | 'score'; explanation?: string; basis?: string; }
export type AnalyticsKpis = Record<'courses' | 'assessments' | 'questions' | 'average_quality' | 'student_performance' | 'co_coverage' | 'open_gaps' | 'ai_analysis_runs', Kpi>;

export type QualityRating = 'EXCELLENT' | 'GOOD' | 'FAIR' | 'NEEDS_REVIEW' | 'REQUIRES_ATTENTION';
export interface QualityTrendPoint { assessment_id: number; title: string; type: string; course_id: number; date: string | null; date_source: 'assessment_date' | 'analyzed_at'; score: number; rating: QualityRating; }
export interface AssessmentQualityAnalytics { analyzed_assessments: number; average_score: number | null; counts: Record<QualityRating, number>; trend: QualityTrendPoint[]; explanation: string; }

export type DifficultyBalance = 'BALANCED' | 'SLIGHTLY_UNBALANCED' | 'SIGNIFICANTLY_UNBALANCED';
export interface DifficultyRow { level: 'easy' | 'medium' | 'hard'; count: number; percentage: number | null; target_percentage: number; difference: number | null; }
export interface DifficultyDistribution { total_questions: number; unclassified: number; distribution: DifficultyRow[]; total_deviation: number | null; balance_status: DifficultyBalance | null; bands: { slight_deviation: number; significant_deviation: number }; explanation: string; }

export interface CognitiveRow { level: string; count: number; percentage: number | null; }
export interface CognitiveDistribution { total_questions: number; unclassified: number; distribution: CognitiveRow[]; distinct_levels: number; explanation: string; }

export type OutcomeStatus = 'COVERED' | 'WEAK' | 'NOT_ALIGNED' | 'NOT_ASSESSED';
export interface OutcomeRow { learning_outcome_id: number; code: string; description: string; course_id: number; course_code: string | null; questions: number; strong: number; weak: number; not_aligned: number; coverage_percentage: number | null; status: OutcomeStatus; }
export interface OutcomeCoverage { total_outcomes: number; covered_outcomes: number; coverage_percentage: number | null; analyzed_assessments: number; outcomes: OutcomeRow[]; weak_outcomes: OutcomeRow[]; explanation: string; }

export interface ProgramOutcomeRow { program_outcome_id: number | null; code: string; title: string; courses: number; mapped_cos: number; mapped_questions: number; strong_mappings: number; weak_mappings: number; evidence_percent: number | null; student_performance_percent: number | null; status: 'ASSESSED' | 'LIMITED_EVIDENCE' | 'NOT_MAPPED'; }
export interface ProgramOutcomeCoverage {
  configured: boolean; message?: string | null; analyzed_courses?: number; unanalyzed_courses?: number; unmapped_questions?: number;
  courses: { course_id: number; course_code: string; analyzed: boolean; is_stale: boolean; analyzed_at: string | null; co_coverage_percent: number | null; po_evidence_percent: number | null; question_mapping_percent: number | null }[];
  program_outcomes: ProgramOutcomeRow[];
}

export type PerformanceStatus = 'STRONG' | 'ON_TARGET' | 'MINOR_GAP' | 'MODERATE_GAP' | 'HIGH_GAP' | 'INSUFFICIENT_DATA';
export interface PerformanceTrendPoint { assessment_id: number; title: string; type: string; date: string | null; average_percentage: number | null; responses: number; status: PerformanceStatus; sufficient: boolean; }
export interface PerformanceAnalytics {
  available: boolean; restricted?: boolean; average_percentage: number | null; median_percentage: number | null; minimum_percentage: number | null; maximum_percentage: number | null;
  submissions: number; responses: number; benchmark_percent: number; gap: number | null; status: PerformanceStatus; explanation: string; trend: PerformanceTrendPoint[]; assessments_without_analysis: number;
}

export interface LearningGap { learning_outcome_id: number | null; code: string; description: string; assessment_id: number; assessment_title: string; average_percentage: number; benchmark_percent: number; gap: number; responses: number; status: PerformanceStatus; }
export interface LearningGapSummary { analyzed_assessments: number; counts: Record<PerformanceStatus, number>; open_gaps: number; top_gaps: LearningGap[]; benchmark_percent: number; explanation: string; }

export interface QuestionPerformance { question_id: number | null; assessment_id: number; assessment_title: string; question_number: number; excerpt: string | null; topics: string[]; co: string | null; difficulty: string | null; cognitive_level: string | null; average_percentage: number | null; responses: number; gap: number | null; status: PerformanceStatus; }
export interface TopicPerformance { topic: string; questions: number; question_ids: number[]; responses: number; assessments: number; average_percentage: number | null; gap: number | null; status: PerformanceStatus; }

export type SimilarityStatus = 'POTENTIAL_DUPLICATE' | 'HIGHLY_SIMILAR' | 'SOMEWHAT_SIMILAR' | 'NOT_SIMILAR';
export interface SimilarityAnalytics { analyzed_assessments: number; by_status: Record<SimilarityStatus, { matches: number; questions: number }>; flagged_assessments: { assessment_id: number; flagged_questions: number }[]; thresholds: Record<string, number>; explanation: string; }

export interface QuestionBankAnalytics { bank_questions: number; assessment_questions: number; bank_previously_matched: number; bank_by_difficulty: Record<string, number>; bank_by_cognitive: Record<string, number>; bank_by_source: Record<string, number>; assessment_questions_with_lo: number; assessment_questions_without_lo: number; }

export interface RubricAnalytics { total: number; draft: number; approved: number; archived: number; average_criteria: number | null; by_generation_method: Record<string, number>; faculty_ratings: { rated: number; average_rating: number | null; acceptance_rate: number | null; revision_rate: number | null; note: string }; }

export interface GradingAnalytics { available: boolean; ai_assisted_answers: number; faculty_accepted?: number; faculty_modified?: number; faculty_rejected?: number; compared_answers?: number; mae?: number | null; mean_signed_difference?: number | null; ai_suggestion_mean?: number | null; final_faculty_grade_mean?: number | null; exact_agreement_rate?: number | null; explanation?: string; }

export interface InterGraderAnalytics { available: boolean; message: string; indicator_name: string; }

export interface AiEvaluationTaskSummary { task: string; evaluated: boolean; headline_metric: string | null; headline_value: number | null; gate_status: string | null; run_id: number | null; completed_at: string | null; example_count: number | null; regression: boolean; }
export interface AiEvaluationTrendPoint { run_id: number; task: string; model_id: number | null; prompt_version_id: number | null; dataset_id: number; metric: string | null; value: number | null; gate_status: string | null; completed_at: string | null; }
export interface AiEvaluationAnalytics { overall_status: string; evaluated_tasks: number; tasks: AiEvaluationTaskSummary[]; trend: AiEvaluationTrendPoint[]; run_count: number; explanation: string; }

export interface RecommendationAnalytics {
  total: number; active: number; accepted: number; dismissed: number; under_review: number; active_by_priority: { high: number; medium: number; low: number };
  feedback: { total: number; accepted_percent: number | null; dismissed_percent: number | null; needs_review_percent: number | null; average_usefulness: number | null; label: string; explanation: string };
}

export interface CollaborationAnalytics {
  shared_courses: number; active_collaborators: number; pending_invitations: number; open_discussions: number; resolved_discussions: number; open_reviews: number; by_role: Record<string, number>;
  activity: { days: number; since: string; series: Array<{ date: string } & Record<string, number | string>>; totals: Record<string, number> };
}

export interface AssessmentRow {
  assessment_id: number; title: string; type: string; status: string; date: string | null; course: { id: number; code: string; name: string; semester: string | null; academic_year: string | null } | null;
  questions: number; quality_score: number | null; quality_rating: QualityRating | null; difficulty_balance_score: number | null; cognitive_balance_score: number | null; lo_alignment_score: number | null; similar_questions: number | null;
  performance_percentage: number | null; performance_status: PerformanceStatus | null; performance_responses: number | null; high_gaps: number | null;
}

export interface HistoricalTrend { term: string; academic_year: string | null; semester: string | null; assessments: number; average_quality: number | null; analyzed_assessments: number; average_performance: number | null; performance_assessments: number; average_lo_alignment: number | null; }
export interface CourseHistory { course_code: string; course_name: string; course_ids: number[]; terms: HistoricalTrend[]; assessments: AssessmentRow[]; }

export type AttentionSeverity = 'HIGH' | 'MEDIUM' | 'LOW';
export interface AttentionArea { severity: AttentionSeverity; type: 'LEARNING_GAP' | 'QUALITY' | 'CO_COVERAGE' | 'PO_COVERAGE' | 'SIMILARITY' | 'COGNITIVE_DIVERSITY' | 'DIFFICULTY'; title: string; detail: string; status: string; link: { type: 'assessment' | 'course'; id: number } | null; }

export interface AnalyticsMeta { generated_at: string; served_at?: string; cached?: boolean; cache_ttl_seconds?: number; benchmark_percent: number; disclaimer: string; }

export interface BlueprintComplianceRow {
  assessment_id: number;
  assessment_title: string;
  blueprint_id: number;
  version: number;
  status: string;
  validation_status: string | null;
  completeness: number | null;
  compliance_percent: number | null;
  summary: Record<string, 'MATCH' | 'CLOSE' | 'MISMATCH' | 'NOT_CONFIGURED'>;
  has_questions: boolean;
}
export interface BlueprintComplianceAnalytics { assessments_with_blueprint: number; average_compliance: number | null; rows: BlueprintComplianceRow[]; }

export interface AnalyticsOverview {
  filters: Record<string, string | number>;
  scope: { course_ids: number[]; assessment_ids: number[]; student_data_course_ids: number[]; student_data_restricted: boolean };
  kpis: AnalyticsKpis;
  assessment_quality: AssessmentQualityAnalytics;
  difficulty: DifficultyDistribution;
  cognitive: CognitiveDistribution;
  learning_outcomes: OutcomeCoverage;
  program_outcomes: ProgramOutcomeCoverage;
  performance: PerformanceAnalytics;
  learning_gaps: LearningGapSummary;
  question_performance: QuestionPerformance[];
  topic_performance: TopicPerformance[];
  similarity: SimilarityAnalytics;
  question_bank: QuestionBankAnalytics;
  rubrics: RubricAnalytics;
  grading: GradingAnalytics;
  inter_grader: InterGraderAnalytics;
  ai_evaluation: AiEvaluationAnalytics;
  recommendations: RecommendationAnalytics;
  collaboration: CollaborationAnalytics;
  assessments: AssessmentRow[];
  blueprint_compliance?: BlueprintComplianceAnalytics;
  attention_areas: AttentionArea[];
  meta: AnalyticsMeta;
}

export interface ComparedAssessment extends AssessmentRow {
  difficulty: DifficultyDistribution; cognitive: CognitiveDistribution; learning_outcomes: OutcomeCoverage; similarity: SimilarityAnalytics['by_status'];
  performance: Partial<PerformanceAnalytics> & { available: boolean; restricted?: boolean }; learning_gaps: LearningGapSummary | null;
}
export interface AssessmentComparison { assessments: ComparedAssessment[]; requested: number; authorized: number; note: string; }
