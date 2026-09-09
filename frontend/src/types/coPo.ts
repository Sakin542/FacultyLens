/**
 * STEP 31: CO/PO Mapping Validator types.
 *
 * Learning outcomes act as Course Outcomes (CO). Findings are review signals — never an
 * accreditation decision. AI suggestions are distinct from faculty-confirmed mappings.
 */

export type MappingLevel = 0 | 1 | 2 | 3;
export const MAPPING_LEVEL_LABELS: Record<MappingLevel, string> = { 0: 'None', 1: 'Low', 2: 'Medium', 3: 'High' };

export type FindingSeverity = 'INFO' | 'LOW' | 'MEDIUM' | 'HIGH';
export type FindingType =
  | 'UNMAPPED_QUESTION' | 'UNASSESSED_CO' | 'LOW_CO_COVERAGE' | 'CO_CONCENTRATION' | 'UNMAPPED_PO'
  | 'LOW_PO_EVIDENCE' | 'MAPPING_DENSITY' | 'CO_COGNITIVE_MISMATCH' | 'CO_PERFORMANCE_GAP' | 'MAPPING_REVIEW';

export type CoStatus = 'STRONG' | 'ON_TARGET' | 'REVIEW' | 'LOW_COVERAGE' | 'NOT_ASSESSED' | 'NO_PERFORMANCE_DATA';
export type PoStatus = 'EVIDENCE_AVAILABLE' | 'REVIEW' | 'LIMITED_EVIDENCE' | 'NOT_MAPPED';
export type RunStatus = 'PENDING' | 'PROCESSING' | 'COMPLETED' | 'FAILED' | 'STALE';
export type QuestionCoStatus = 'PENDING' | 'CONFIRMED' | 'REJECTED';

export interface Program {
  id: number;
  code: string;
  name: string;
  description?: string | null;
  department?: string | null;
  status: 'ACTIVE' | 'ARCHIVED';
  created_by?: number;
  courses_count?: number;
  outcomes?: ProgramOutcome[];
}

export interface ProgramOutcome {
  id: number;
  program_id: number;
  code: string;
  title: string;
  description?: string | null;
  sort_order: number;
  status: string;
}

export interface CourseOutcome {
  id: number;
  code: string;
  display_code: string;
  description: string;
  cognitive_level?: string | null;
  sort_order?: number;
}

export interface CoPoMapping {
  id: number;
  course_id: number;
  learning_outcome_id: number;
  program_outcome_id: number;
  mapping_level: MappingLevel;
  level_label: string;
  justification?: string | null;
  updated_at?: string;
}

export interface MatrixCell {
  program_outcome_id: number;
  level: MappingLevel;
  mapping_id: number | null;
  justification?: string | null;
}

export interface MatrixRow {
  learning_outcome_id: number;
  code: string;
  description: string;
  cells: MatrixCell[];
}

export interface CoPoMatrix {
  program_outcomes: Array<{ id: number; code: string; title: string }>;
  rows: MatrixRow[];
  active_mappings: number;
  possible_mappings: number;
  density_percent: number;
  legend: Record<string, string>;
}

export interface CoCoverage {
  learning_outcome_id: number;
  code: string;
  display_code: string;
  description: string;
  cognitive_level?: string | null;
  question_count: number;
  question_ids: number[];
  mapped_marks: number;
  coverage_percent: number;
  coverage_status: 'ASSESSED' | 'LOW_COVERAGE' | 'CONCENTRATED' | 'NOT_ASSESSED';
  po_mapping_count: number;
  performance_percent: number | null;
  performance_gap: number | null;
  performance_status: string;
  response_count: number;
  status: CoStatus;
}

export interface PoEvidence {
  program_outcome_id: number;
  code: string;
  title: string;
  mapped_co_count: number;
  mapped_cos: Array<{ learning_outcome_id: number; code: string; level: MappingLevel; level_label: string }>;
  co_evidence: string;
  contribution_percent: number;
  assessment_evidence_percent: number;
  question_ids: number[];
  student_performance_percent: number | null;
  evidence_status: 'ASSESSED' | 'LIMITED_EVIDENCE' | 'NOT_MAPPED';
  status: PoStatus;
}

export interface MappingFinding {
  id: number;
  type: FindingType;
  severity: FindingSeverity;
  title: string;
  description: string;
  recommendation: string | null;
  category: string | null;
  priority: 'low' | 'medium' | 'high' | null;
  course_outcome_id: number | null;
  program_outcome_id: number | null;
  question_id: number | null;
  evidence: Record<string, unknown>;
}

export interface ValidationCheck {
  ok: boolean;
  label: string;
}

export interface MappingSummary {
  co_count: number;
  po_count: number;
  active_mapping_count: number;
  possible_mapping_count: number;
  mapping_density_percent: number;
  question_count: number;
  questions_mapped: number;
  total_marks?: number;
  cos_with_evidence?: number;
  cos_with_po_mapping?: number;
  pos_with_evidence?: number;
  pos_mapped?: number;
  co_coverage_percent?: number;
  po_evidence_percent?: number;
  question_mapping_percent?: number;
  finding_counts?: Record<FindingSeverity, number>;
  validation_checks?: ValidationCheck[];
}

export interface MappingAnalysisRun {
  id: number;
  course_id: number;
  program_id: number | null;
  status: RunStatus;
  is_current: boolean;
  is_stale: boolean;
  stale_reasons: string[];
  mapping_version: string | null;
  summary: MappingSummary | null;
  matrix: CoPoMatrix | null;
  co_coverage: CoCoverage[];
  po_evidence: PoEvidence[];
  thresholds: Record<string, unknown> | null;
  error_message: string | null;
  analyzed_at: string | null;
  disclaimer: string;
  findings?: MappingFinding[];
}

export interface CoPoOverview {
  course: { id: number; course_code: string; course_name: string };
  program: { id: number; code: string; name: string } | null;
  course_outcomes: CourseOutcome[];
  program_outcomes: ProgramOutcome[];
  mappings: CoPoMapping[];
  summary: MappingSummary;
  thresholds: Record<string, unknown>;
  current_run: MappingAnalysisRun | null;
  disclaimer: string;
}

export interface QuestionCoSuggestion {
  learning_outcome_id: number;
  code: string;
  similarity_score: number;
  alignment: string;
  status: QuestionCoStatus;
  mapping_source: string;
  reviewed_at?: string | null;
}

export interface QuestionCoMappingReview {
  question_id: number;
  assessment_id: number;
  assessment_title: string | null;
  question_number: number | null;
  question_text_excerpt: string;
  marks: number;
  cognitive_level?: string | null;
  faculty_learning_outcome_id: number | null;
  confirmed: Array<{ learning_outcome_id: number; code: string; source: string }>;
  ai_suggestions: QuestionCoSuggestion[];
  is_mapped: boolean;
}

export const CO_PO_DISCLAIMER =
  'CO/PO mapping analysis provides evidence and review signals based on configured course outcomes, program outcomes, assessment mappings, and available performance data. It does not constitute an accreditation decision or guarantee institutional compliance. Faculty and authorized academic personnel remain responsible for final curriculum and outcome-mapping decisions.';
