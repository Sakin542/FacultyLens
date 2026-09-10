/** STEP 35: AI Evaluation & Model Performance types (mirror backend/app/Http/Controllers/Api/AiEvaluationController.php). */

export type EvaluationTask =
  | 'QUESTION_CLASSIFICATION' | 'DIFFICULTY_CLASSIFICATION' | 'BLOOM_CLASSIFICATION' | 'LO_ALIGNMENT' | 'SIMILARITY'
  | 'RUBRIC_GENERATION' | 'GRADING_ASSISTANCE' | 'ANSWER_RUBRIC_ALIGNMENT' | 'DOCUMENT_CHAT' | 'QUESTION_GENERATION';

export const EVALUATION_TASKS: EvaluationTask[] = [
  'QUESTION_CLASSIFICATION', 'DIFFICULTY_CLASSIFICATION', 'BLOOM_CLASSIFICATION', 'LO_ALIGNMENT', 'SIMILARITY',
  'RUBRIC_GENERATION', 'GRADING_ASSISTANCE', 'ANSWER_RUBRIC_ALIGNMENT', 'DOCUMENT_CHAT', 'QUESTION_GENERATION',
];

export const TASK_LABELS: Record<EvaluationTask, string> = {
  QUESTION_CLASSIFICATION: 'Question type classification',
  DIFFICULTY_CLASSIFICATION: 'Difficulty classification',
  BLOOM_CLASSIFICATION: "Bloom's level classification",
  LO_ALIGNMENT: 'Learning-outcome alignment',
  SIMILARITY: 'Question similarity / duplicates',
  RUBRIC_GENERATION: 'Rubric generation',
  GRADING_ASSISTANCE: 'Grading assistance',
  ANSWER_RUBRIC_ALIGNMENT: 'Answer–rubric alignment',
  DOCUMENT_CHAT: 'Document chat (RAG)',
  QUESTION_GENERATION: 'Question generation',
};

export type DatasetStatus = 'DRAFT' | 'READY' | 'RUNNING' | 'COMPLETED' | 'ARCHIVED';
export type RunStatus = 'PENDING' | 'RUNNING' | 'COMPLETED' | 'FAILED' | 'CANCELLED';
export type GateStatus = 'PASSED' | 'PASSED_WITH_WARNINGS' | 'FAILED';
export type OverallStatus = 'NOT_EVALUATED' | 'EVALUATING' | GateStatus;
export type DatasetSource = 'FACULTY_VALIDATED' | 'SYNTHETIC' | 'IMPORTED' | 'PRODUCTION_SAMPLE';
export type DatasetSplit = 'ALL' | 'TRAIN' | 'VALIDATION' | 'TEST';
export type SizeCategory = 'VERY_LIMITED' | 'LIMITED' | 'MODERATE' | 'LARGE';

export interface ModelInfo {
  id: number;
  model_name: string;
  provider: string | null;
  model_type: string | null;
  task: EvaluationTask;
  version: string;
  is_active: boolean;
  configuration?: Record<string, unknown> | null;
  registered_at?: string | null;
}

export interface PromptVersion {
  id: number;
  feature: string;
  version: string;
  prompt_hash: string | null;
  description: string | null;
  is_active: boolean;
}

export interface EvaluationExample {
  id: number;
  input_data: Record<string, unknown>;
  expected_output: Record<string, unknown>;
  metadata: Record<string, unknown> | null;
  source: DatasetSource;
  split: DatasetSplit;
}

export interface DatasetValidationProblem { example_id: number | null; errors: string[]; }

export interface DatasetValidationReport {
  task?: EvaluationTask;
  is_valid: boolean;
  total_examples: number;
  valid_examples: number;
  invalid_examples: number;
  duplicate_examples: number;
  conflicting_labels: number;
  problems: DatasetValidationProblem[];
  label_distribution: Record<string, number>;
  size_category: SizeCategory;
  small_dataset_warning: boolean;
  validated_at?: string;
}

export interface EvaluationDataset {
  id: number;
  name: string;
  description: string | null;
  task: EvaluationTask;
  version: string;
  source: DatasetSource;
  split: DatasetSplit;
  status: DatasetStatus;
  course_id: number | null;
  created_by: { id: number; name: string } | null;
  examples_count: number | null;
  runs_count: number | null;
  validation_report: DatasetValidationReport | null;
  validated_at: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface DatasetDetail extends EvaluationDataset {
  examples: EvaluationExample[];
  examples_meta: PageMeta;
  runs: EvaluationRun[];
}

export interface QualityGate { metric: string; value: number | null; min: number | null; max: number | null; passed: boolean | null; }

export interface RegressionInfo {
  previous_run_id: number | null;
  metric: string;
  current: number | null;
  previous: number | null;
  delta: number | null;
  regression: boolean;
  lower_is_better: boolean;
}

export interface RunSummary {
  headline_metric: string;
  headline_value: number | null;
  metrics: Record<string, number>;
  gates: QualityGate[];
  warnings: string[];
  regression: RegressionInfo | null;
  error_breakdown: Record<string, number>;
  size_category: SizeCategory;
  limitations: string[];
}

export interface EvaluationRun {
  id: number;
  task: EvaluationTask;
  status: RunStatus;
  gate_status: GateStatus | null;
  dataset: { id: number; name: string; version: string } | null;
  model: { id: number; name: string; version: string; type: string | null } | null;
  prompt_version: { feature: string; version: string } | null;
  example_count: number;
  processed_count: number;
  inference_ms: number | null;
  configuration: Record<string, unknown> | null;
  summary: RunSummary | null;
  failure_reason: string | null;
  started_at: string | null;
  completed_at: string | null;
  created_at: string | null;
  created_by: number;
}

export interface PerClassMetric { label: string; precision: number; recall: number; f1: number; support: number; tp: number; fp: number; fn: number; }
export interface ConfusionMatrix { labels: string[]; matrix: number[][]; }
export interface ThresholdSweepRow { threshold: number; precision: number; recall: number; f1: number; }
export interface ThresholdSweep { positive: string; production_threshold: number; rows: ThresholdSweepRow[]; }
export interface ErrorByGroup { dimension: string; group: string; count: number; mae: number; mean_signed_error: number; }
export interface PerConstraint { constraint: string; satisfied: number; total: number; rate: number | null; }

/** Metrics payload: `scalars` are numeric metrics; `structured` holds JSON metric metadata keyed by metric name. */
export interface EvaluationMetrics {
  task: EvaluationTask;
  headline_metric: string | null;
  scalars: Record<string, number>;
  structured: Record<string, unknown> & {
    confusion_matrix?: ConfusionMatrix;
    per_class?: PerClassMetric[];
    threshold_sweep?: ThresholdSweep;
    thresholds?: Record<string, number>;
    error_by_group?: ErrorByGroup[];
    tolerances?: number[];
    per_constraint?: PerConstraint[];
    dimension_means?: Record<string, number>;
  };
  gates: QualityGate[];
  regression: RegressionInfo | null;
}

export interface RunDetail extends EvaluationRun { metrics: EvaluationMetrics; limitations: string[]; }

export interface ErrorAnalysisItem {
  id: number;
  example_id: number;
  input: Record<string, unknown>;
  expected_output: Record<string, unknown>;
  prediction: Record<string, unknown> | null;
  is_correct: boolean | null;
  score: number | null;
  error_type: string | null;
  metadata: Record<string, unknown> | null;
}

export interface ComparisonRow { metric: string; run_a: number | null; run_b: number | null; delta: number | null; direction: 'improved' | 'degraded' | 'unchanged'; }
export interface RunComparison { run_a: EvaluationRun; run_b: EvaluationRun; same_task: boolean; headline_metric: string | null; rows: ComparisonRow[]; note: string; }

export interface TaskOverview {
  task: EvaluationTask;
  evaluated: boolean;
  run: EvaluationRun | null;
  headline_metric: string;
  headline_value: number | null;
  gate_status: GateStatus | null;
  regression: boolean;
  size_category: SizeCategory | null;
  warnings: string[];
}

export interface FacultySignals {
  recommendations: Record<string, number>;
  generated_questions: Record<string, number>;
  rubrics: Record<string, number>;
  grading: Record<string, number>;
  note: string;
}

export interface EvaluationOverview {
  overall_status: OverallStatus;
  tasks: TaskOverview[];
  models: ModelInfo[];
  prompt_versions: PromptVersion[];
  faculty_signals: FacultySignals;
  dataset_count: number;
  run_count: number;
  limitations: string[];
}

export interface PageMeta { current_page: number; last_page: number; total: number; error_breakdown?: Record<string, number>; }

export interface CreateDatasetInput {
  name: string;
  description?: string;
  task: EvaluationTask;
  version?: string;
  source?: DatasetSource;
  split?: DatasetSplit;
  course_id?: number | null;
  examples?: Array<{ input_data: Record<string, unknown>; expected_output: Record<string, unknown>; metadata?: Record<string, unknown> }>;
}

export interface RatingInput { rateable_type: 'rubric' | 'generated_question'; rateable_id: number; dimension_scores: Record<string, number>; decision?: string; comment?: string; }
