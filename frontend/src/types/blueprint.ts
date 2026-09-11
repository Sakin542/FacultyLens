/** STEP 37: Assessment Blueprint types (mirror backend AssessmentBlueprintService::present / validator output). */

export type BlueprintStatus = 'DRAFT' | 'VALIDATED' | 'FINALIZED' | 'ARCHIVED';
export type BlueprintValidationStatus = 'VALID' | 'VALID_WITH_WARNINGS' | 'INVALID';
export type ComparisonStatus = 'MATCH' | 'CLOSE' | 'MISMATCH' | 'NOT_CONFIGURED';
export type BlueprintDimension = 'DIFFICULTY' | 'COGNITIVE_LEVEL' | 'LEARNING_OUTCOME' | 'PROGRAM_OUTCOME' | 'TOPIC' | 'QUESTION_TYPE' | 'SECTION' | 'ITEM' | 'MARKS' | 'QUESTION_COUNT' | 'TIME';

export const QUESTION_TYPES = ['mcq', 'short_answer', 'descriptive', 'problem_solving', 'true_false', 'conceptual', 'analytical'] as const;
export const DIFFICULTY_LEVELS = ['easy', 'medium', 'hard'] as const;
export const COGNITIVE_LEVELS = ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'] as const;
export type QuestionType = typeof QUESTION_TYPES[number];
export type DifficultyLevel = typeof DIFFICULTY_LEVELS[number];
export type CognitiveLevel = typeof COGNITIVE_LEVELS[number];

export interface BlueprintSection {
  id?: number;
  title: string;
  section_order: number;
  instructions?: string | null;
  question_type: QuestionType | null;
  question_count: number;
  marks_per_question: number;
  total_marks?: number;
  difficulty_distribution?: Record<string, number> | null;
  cognitive_distribution?: Record<string, number> | null;
}

export interface DistributionTarget { key: string; target_percentage: number | null; target_count: number | null; }
export interface OutcomeTarget { learning_outcome_id: number; code?: string | null; target_percentage: number | null; target_marks: number | null; target_count: number | null; }
export interface ProgramOutcomeTarget { program_outcome_id: number; code?: string | null; target_percentage: number | null; }
export interface TopicTarget { topic: string; target_count: number | null; target_marks: number | null; }
export interface QuestionTypeTarget { question_type: QuestionType; target_count: number; marks_each: number | null; target_marks?: number | null; }

export interface BlueprintConstraint {
  difficulty: DistributionTarget[];
  cognitive: DistributionTarget[];
  learning_outcomes: OutcomeTarget[];
  program_outcomes: ProgramOutcomeTarget[];
  topics: TopicTarget[];
  question_types: QuestionTypeTarget[];
}

export interface BlueprintItem {
  id?: number;
  section_id?: number | null;
  section_order: number | null;
  topic: string | null;
  learning_outcome_id: number | null;
  program_outcome_id: number | null;
  question_type: QuestionType | null;
  difficulty_level: DifficultyLevel | null;
  cognitive_level: CognitiveLevel | null;
  question_count: number;
  marks_each: number;
  total_marks?: number;
  sort_order?: number;
}

export interface AssessmentBlueprint {
  id: number;
  assessment_id: number;
  version: number;
  status: BlueprintStatus;
  is_current: boolean;
  title: string | null;
  total_marks: number;
  total_questions: number;
  duration_minutes: number | null;
  instructions: string | null;
  validation_status: BlueprintValidationStatus | null;
  blueprint_completeness: number | null;
  validated_at: string | null;
  finalized_at: string | null;
  created_by: number;
  created_at: string | null;
  updated_at: string | null;
  course: { id: number; code: string; name: string; program_id: number | null } | null;
  assessment: { id: number; title: string; type: string; total_marks: number; duration_minutes: number | null; status: string };
  sections: BlueprintSection[];
  constraints: BlueprintConstraint;
  items: BlueprintItem[];
}

export interface BlueprintMessage { dimension: BlueprintDimension | string; message: string; }
export type BlueprintWarning = BlueprintMessage;
export interface BlueprintRecommendation { category: string; title: string; message: string; }

export interface BlueprintCompleteness { score: number; dimensions: Record<string, boolean>; note: string; }
export interface BlueprintTimeIndicator { available: boolean; duration_minutes?: number; minutes_per_mark?: number; minutes_per_question?: number | null; band?: 'TIGHT' | 'TYPICAL' | 'GENEROUS'; expected_minutes?: number | null; note: string; }

export interface BlueprintValidation {
  status: BlueprintValidationStatus;
  errors: BlueprintMessage[];
  warnings: BlueprintWarning[];
  recommendations: BlueprintRecommendation[];
  completeness: BlueprintCompleteness;
  totals: Record<string, number>;
  time_indicator: BlueprintTimeIndicator;
  validated_at: string;
}

export interface DistributionRow {
  key: string; label: string; target_percentage: number | null; target_count?: number | null; derived_count?: number | null; target_marks?: number | null; marks_each?: number | null;
  configured: boolean; learning_outcome_id?: number; program_outcome_id?: number; code?: string; description?: string;
}
export interface Distribution { configured: boolean; available?: boolean; basis?: string; rows: DistributionRow[]; percentage_total?: number; count_total?: number; marks_total?: number; message?: string; allocation?: { exact: boolean; allocation: Record<string, number> } | null; }
export interface BlueprintMatrix { columns: string[]; rows: { label: string; cells: Record<string, number>; total: number }[]; column_totals: Record<string, number>; total: number; }

export interface BlueprintCoverage {
  distributions: { difficulty: Distribution; cognitive: Distribution; learning_outcomes: Distribution; program_outcomes: Distribution; topics: Distribution; question_types: Distribution };
  matrices: { co_x_difficulty: BlueprintMatrix; co_x_bloom: BlueprintMatrix; topic_x_difficulty: BlueprintMatrix; topic_x_bloom: BlueprintMatrix };
}

export interface ComparisonRow { key: string; label: string; target_percentage: number | null; actual_percentage: number | null; actual_raw: number; difference: number | null; status: ComparisonStatus; }
export interface ComparisonDimension { configured: boolean; basis?: string; rows: ComparisonRow[]; message?: string; }
export interface StructureRow { dimension: string; label: string; target: number; actual: number; difference: number; status: ComparisonStatus; }

export interface BlueprintComparison {
  blueprint_id: number;
  version: number;
  blueprint_status: BlueprintStatus;
  tolerance_percent: number;
  actual: { question_count: number; total_marks: number; has_questions: boolean };
  structure: StructureRow[];
  dimensions: Record<'difficulty' | 'cognitive' | 'learning_outcomes' | 'program_outcomes' | 'topics' | 'question_types', ComparisonDimension>;
  summary: Record<string, ComparisonStatus>;
  compliance_percent: number | null;
  note: string;
  compared_at: string;
  recommendations_sync?: { created: number; skipped: number; note?: string } | null;
}

export interface BlueprintVersion { id: number; version: number; status: BlueprintStatus; is_current: boolean; total_marks: number; total_questions: number; validation_status: BlueprintValidationStatus | null; blueprint_completeness: number | null; finalized_at: string | null; created_at: string | null; }

export interface BlueprintResponse {
  blueprint: AssessmentBlueprint | null;
  validation: BlueprintValidation | null;
  coverage: BlueprintCoverage | null;
  versions: BlueprintVersion[];
  permissions: { edit: boolean; generate: boolean };
}

export interface QuestionValidationResult {
  source: 'question' | 'previous_question';
  id: number;
  text: string;
  metadata: { type: string; difficulty: string | null; cognitive: string | null; learning_outcome_id: number | null; marks: number };
  status: 'MATCH' | 'CONSTRAINT_MISMATCH' | 'NO_PLAN';
  closest_item: { id: number; row: number } | null;
  checks: Record<string, { target: unknown; actual: unknown; ok: boolean }>;
  failed_constraints: string[];
}
export interface QuestionValidationResponse { blueprint_id: number; plan_rows: number; evaluated: number; matched: number; results: QuestionValidationResult[]; note: string; }

export interface GenerationHandoff { blueprint_id: number; requests: { id: number; topic: string | null; learning_outcome_id: number | null; question_type: string; number_of_questions: number; generation_status: string; warnings: string[] }[]; note: string; }

/** Payload for create/update — sections/items are replaced wholesale. */
export interface BlueprintInput {
  title?: string | null;
  total_marks: number;
  total_questions: number;
  duration_minutes?: number | null;
  instructions?: string | null;
  sections: Array<Omit<BlueprintSection, 'id' | 'total_marks'>>;
  constraints: {
    difficulty: DistributionTarget[];
    cognitive: DistributionTarget[];
    learning_outcomes: Array<Omit<OutcomeTarget, 'code'>>;
    program_outcomes: Array<Omit<ProgramOutcomeTarget, 'code'>>;
    topics: TopicTarget[];
    question_types: Array<Omit<QuestionTypeTarget, 'target_marks'>>;
  };
  items: Array<Omit<BlueprintItem, 'id' | 'section_id' | 'total_marks' | 'sort_order'>>;
}
