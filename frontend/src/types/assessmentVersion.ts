/** STEP 38: Assessment Versioning types (mirror of the Laravel presenters). */

export type VersionStatus = 'DRAFT' | 'IN_REVIEW' | 'APPROVED' | 'FINALIZED' | 'ARCHIVED';
export type VersionType = 'MAJOR' | 'MINOR';
export type VersionValidationStatus = 'VALID' | 'VALID_WITH_WARNINGS' | 'INVALID';
export type AnalysisFreshness = 'CURRENT' | 'STALE' | 'NONE';
export type QuestionChangeStatus = 'ADDED' | 'REMOVED' | 'MODIFIED' | 'UNCHANGED';

export interface VersionUserRef { id: number; name?: string }
export interface VersionRef { id: number; version_number?: number; version_label?: string }

export interface RubricSnapshot {
  rubric_id: number;
  rubric_version: number;
  status: string;
  total_marks: number;
  criteria: { criterion: string; max_marks: number; description: string | null }[];
}

export interface AssessmentVersionQuestion {
  id: number;
  original_question_id: number | null;
  question_number: number;
  section_name: string | null;
  question_text: string;
  question_type: string;
  marks: number;
  difficulty_level: string | null;
  cognitive_level: string | null;
  topic: string | null;
  learning_outcome_id: number | null;
  learning_outcome_code: string | null;
  program_outcome_id: number | null;
  program_outcome_code: string | null;
  expected_answer: string | null;
  rubric_snapshot: RubricSnapshot | null;
  sort_order: number;
}

/** Editable question row sent to PUT /assessment-versions/{id}. */
export type VersionQuestionInput = Pick<AssessmentVersionQuestion, 'question_text' | 'marks'> &
  Partial<Pick<AssessmentVersionQuestion, 'original_question_id' | 'question_number' | 'section_name' | 'question_type' | 'difficulty_level' | 'cognitive_level' | 'topic' | 'learning_outcome_id' | 'program_outcome_id' | 'expected_answer'>>;

export type DistributionMap = Record<string, { label: string; percentage: number; learning_outcome_id?: number; program_outcome_id?: number }>;

export interface AssessmentVersionBlueprint {
  id: number;
  blueprint_id: number | null;
  blueprint_version: number | null;
  blueprint_status: string | null;
  validation_status: string | null;
  total_marks: number;
  question_count: number;
  duration_minutes: number | null;
  difficulty_distribution: DistributionMap;
  cognitive_distribution: DistributionMap;
  learning_outcome_distribution: DistributionMap;
  program_outcome_distribution: DistributionMap;
  topic_distribution: DistributionMap;
  question_type_distribution: DistributionMap;
  sections: { title: string; question_type: string | null; question_count: number; marks_per_question: number; total_marks: number }[];
  constraints: { dimension: string; key: string; target_type: string; target_percentage: number | null; target_count: number | null; target_marks: number | null }[];
}

export interface VersionValidationIssue { code: string; message: string; question_number?: number; [key: string]: unknown }

export interface VersionValidation {
  status: VersionValidationStatus;
  errors: VersionValidationIssue[];
  warnings: VersionValidationIssue[];
  checks: Record<string, boolean | null>;
  totals: Record<string, unknown>;
  error_count: number;
  warning_count: number;
  validated_at: string;
}

export interface AssessmentVersionSummary {
  id: number;
  assessment_id: number;
  version_number: number;
  version_label: string;
  version_type: VersionType;
  status: VersionStatus;
  title: string;
  assessment_type: string;
  total_marks: number;
  duration_minutes: number | null;
  question_count: number;
  change_summary: string | null;
  based_on_version_id: number | null;
  based_on_version: VersionRef | null;
  created_by: VersionUserRef;
  validation_status: VersionValidationStatus | null;
  has_submissions: boolean;
  is_editable: boolean;
  created_at: string | null;
  updated_at: string | null;
  submitted_at: string | null;
  approved_at: string | null;
  finalized_at: string | null;
  archived_at: string | null;
}

export interface AssessmentVersion extends AssessmentVersionSummary {
  description: string | null;
  instructions: string | null;
  content_hash: string | null;
  validation: VersionValidation | null;
  assessment: { id: number; title: string; type: string; status: string };
  course: { id: number; code: string; name: string; program_id: number | null } | null;
  questions: AssessmentVersionQuestion[];
  blueprint: AssessmentVersionBlueprint | null;
}

export interface VersionAnalysisReport {
  id: number;
  analysis_version: number;
  is_current: boolean;
  status: 'CURRENT' | 'STALE';
  overall_score: number;
  topic_coverage_score: number;
  learning_outcome_alignment_score: number;
  difficulty_balance_score: number;
  cognitive_level_balance_score: number;
  similarity_score: number;
  total_questions: number;
  similar_questions_count: number;
  recommendations_count: number;
  analyzed_at: string | null;
}

export interface VersionAnalysis {
  assessment_version_id: number;
  version_label: string;
  status: AnalysisFreshness;
  latest: VersionAnalysisReport | null;
  reports: VersionAnalysisReport[];
  note: string;
}

export interface VersionPermissions { view: boolean; edit: boolean; approve: boolean; finalize: boolean; archive: boolean; restore: boolean; view_analysis: boolean }

export interface VersionListResponse {
  assessment: { id: number; title: string; type: string; status: string; course_id: number; total_marks: number; question_count: number };
  versions: AssessmentVersionSummary[];
  current_version_id: number | null;
  working_version_id: number | null;
  total_versions: number;
  permissions: VersionPermissions;
}

export interface VersionResponse { version: AssessmentVersion; analysis: VersionAnalysis; permissions: VersionPermissions }

export interface VersionBlueprintResponse {
  assessment_version_id: number;
  version_label: string;
  blueprint: AssessmentVersionBlueprint | null;
  profile: VersionProfile;
}

export type ProfileMap = Record<string, { label: string; count?: number; marks?: number; percentage: number }>;
export interface VersionProfile {
  question_count: number;
  total_marks: number;
  difficulty: ProfileMap;
  cognitive: ProfileMap;
  question_types: ProfileMap;
  learning_outcomes: ProfileMap;
  program_outcomes: ProfileMap;
  topics: ProfileMap;
}

// ------------------------------------------------------------- comparison

export interface FieldChange { field: string; from: unknown; to: unknown; from_label?: string | null; to_label?: string | null }

export interface QuestionChange {
  status: QuestionChangeStatus;
  replaced: boolean;
  question_number: number;
  from: AssessmentVersionQuestion | null;
  to: AssessmentVersionQuestion | null;
  changes: FieldChange[];
}

export interface MetadataChange { field: string; label: string; from: unknown; to: unknown; changed: boolean }

export interface BlueprintChange { key: string; label: string; from: number | null; to: number | null; difference: number; changed: boolean }
export interface BlueprintDimensionDiff { label: string; rows: BlueprintChange[] }

export interface MarksComparison {
  total: { from: number; to: number; difference: number };
  questions_sum: { from: number; to: number };
  items: { question_number: number; status: QuestionChangeStatus; from: number | null; to: number | null; difference: number }[];
}

export interface BlueprintComparisonBlock {
  changed: boolean;
  threshold_pp: number;
  profile: Record<string, BlueprintDimensionDiff>;
  planned: {
    configured: boolean;
    changed: boolean;
    from: { blueprint_id: number | null; blueprint_version: number | null; status: string | null } | null;
    to: { blueprint_id: number | null; blueprint_version: number | null; status: string | null } | null;
    dimensions: Record<string, BlueprintDimensionDiff>;
    structure?: { rows: BlueprintChange[] };
  };
}

export interface CoverageDiff { from: string[]; to: string[]; added: string[]; removed: string[]; unchanged: string[] }
export interface MappingsComparison { learning_outcomes: CoverageDiff; program_outcomes: CoverageDiff; items: { question_number: number; changes: FieldChange[] }[] }

export interface AnalysisMetricDiff { key: string; label: string; from: number | null; to: number | null; difference: number | null }
export interface AnalysisComparison { available: boolean; from: VersionAnalysisReport | null; to: VersionAnalysisReport | null; metrics: AnalysisMetricDiff[]; note: string }

export interface VersionComparisonSummary {
  added: number;
  removed: number;
  modified: number;
  unchanged: number;
  total_from: number;
  total_to: number;
  metadata_changes: number;
  marks_difference: number;
  question_count_difference: number;
  blueprint_changed: boolean;
  detected_change_type: VersionType | 'NONE';
}

export interface VersionComparison {
  from: AssessmentVersionSummary;
  to: AssessmentVersionSummary;
  metadata: MetadataChange[];
  questions: { items: QuestionChange[]; summary: Omit<VersionComparisonSummary, 'metadata_changes' | 'marks_difference' | 'question_count_difference' | 'blueprint_changed' | 'detected_change_type'> };
  marks: MarksComparison;
  blueprint: BlueprintComparisonBlock;
  mappings: MappingsComparison;
  analysis: AnalysisComparison;
  summary: VersionComparisonSummary;
  compared_at: string;
}

export interface VersionTimelineItem {
  id: number;
  version_label: string;
  status: VersionStatus;
  version_type: VersionType;
  created_by: VersionUserRef;
  created_at: string | null;
  change_summary: string | null;
  based_on_version: VersionRef | null;
  is_current: boolean;
}

// ------------------------------------------------------------- inputs

export interface CreateVersionInput { based_on_version_id?: number | null; version_type?: VersionType; change_summary?: string; title?: string }

export interface UpdateVersionInput {
  title?: string;
  description?: string | null;
  instructions?: string | null;
  assessment_type?: string;
  total_marks?: number;
  duration_minutes?: number | null;
  change_summary?: string | null;
  sync_from_assessment?: boolean;
  blueprint_id?: number | null;
  questions?: VersionQuestionInput[];
}

export interface RestoreVersionInput { change_summary?: string; version_type?: VersionType }
