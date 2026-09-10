/**
 * STEP 33: Constrained Question Generator types.
 */
export type GenerationStatus = 'PENDING' | 'PROCESSING' | 'COMPLETED' | 'FAILED' | 'CANCELLED';
export type ValidationStatus = 'PENDING' | 'PASSED' | 'PASSED_WITH_WARNINGS' | 'FAILED';
export type ReviewStatus = 'DRAFT' | 'REVIEWED' | 'APPROVED' | 'REJECTED';
export type GenQuestionType = 'mcq' | 'short_answer' | 'descriptive' | 'problem_solving' | 'true_false' | 'conceptual' | 'analytical';
export type GenDifficulty = 'easy' | 'medium' | 'hard';
export type GenCognitive = 'Remember' | 'Understand' | 'Apply' | 'Analyze' | 'Evaluate' | 'Create';
export type FeedbackReason = 'too_easy' | 'too_difficult' | 'wrong_topic' | 'wrong_co' | 'too_similar' | 'poor_wording' | 'not_appropriate' | 'other';

export const QUESTION_TYPE_LABELS: Record<GenQuestionType, string> = {
  mcq: 'Multiple choice', short_answer: 'Short answer', descriptive: 'Descriptive', problem_solving: 'Problem solving',
  true_false: 'True / False', conceptual: 'Conceptual', analytical: 'Analytical',
};
export const DIFFICULTY_LEVELS: GenDifficulty[] = ['easy', 'medium', 'hard'];
export const COGNITIVE_LEVELS: GenCognitive[] = ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'];
export const FEEDBACK_REASONS: { value: FeedbackReason; label: string }[] = [
  { value: 'too_easy', label: 'Too easy' }, { value: 'too_difficult', label: 'Too difficult' }, { value: 'wrong_topic', label: 'Wrong topic' },
  { value: 'wrong_co', label: 'Wrong course outcome' }, { value: 'too_similar', label: 'Too similar to existing questions' },
  { value: 'poor_wording', label: 'Poor wording' }, { value: 'not_appropriate', label: 'Not academically appropriate' }, { value: 'other', label: 'Other' },
];

export interface SimilarQuestionMatch {
  existing_id: number | null;
  source: string;
  label: string | null;
  text: string;
  similarity_score: number;
  status: 'POTENTIAL_DUPLICATE' | 'HIGHLY_SIMILAR' | 'SOMEWHAT_SIMILAR' | 'NOT_SIMILAR';
}

export interface ConstraintChecks {
  topic: boolean | null;
  question_type: boolean;
  difficulty: boolean | null;
  cognitive_level: boolean | null;
  co_alignment: boolean | null;
  similarity: boolean;
  marks: boolean;
}

export interface QuestionValidation {
  detected_question_type: string | null;
  detected_difficulty: string | null;
  detected_cognitive_level: string | null;
  detected_topics: string[];
  co_alignment_score: number | null;
  co_alignment_status: 'STRONG' | 'WEAK' | 'NOT_ALIGNED' | null;
  max_similarity_score: number | null;
  similarity_status: SimilarQuestionMatch['status'] | null;
  similar_questions: SimilarQuestionMatch[];
  constraints: ConstraintChecks;
  warnings: string[];
  overall_status: ValidationStatus;
}

export interface GeneratedQuestion {
  id: number;
  generation_request_id: number;
  sequence: number;
  question_text: string;
  original_question_text: string;
  is_edited: boolean;
  question_type: GenQuestionType;
  marks: number;
  difficulty_level: GenDifficulty | null;
  cognitive_level: GenCognitive | null;
  learning_outcome_id: number | null;
  program_outcome_id: number | null;
  topic: string | null;
  options: string[] | null;
  correct_option: string | null;
  expected_answer: string | null;
  explanation: string | null;
  source_chunk_ids: number[];
  validation: QuestionValidation | null;
  validation_status: ValidationStatus;
  review_status: ReviewStatus;
  review_note: string | null;
  version: number;
  edited_at: string | null;
  approved_at: string | null;
  regenerated_from_id: number | null;
  official_question_id: number | null;
  added_to_assessment_at: string | null;
  can_add_to_assessment: boolean;
  created_at: string | null;
}

export interface BlueprintSlot {
  difficulty_level?: GenDifficulty | null;
  cognitive_level?: GenCognitive | null;
  question_type?: GenQuestionType | null;
  marks?: number | null;
  count: number;
}

export interface SetSummary {
  total: number;
  difficulty: Record<string, number>;
  cognitive_level: Record<string, number>;
  question_type: Record<string, number>;
  validation: Record<string, number>;
  potential_duplicates: number;
  weak_alignment: number;
  total_marks: number;
  co_coverage: Record<string, number>;
}

export interface GenerationRequest {
  id: number;
  course_id: number;
  assessment_id: number | null;
  course: { id: number; course_code: string | null; course_name: string | null } | null;
  assessment: { id: number; title: string; total_marks?: number | null; remaining_marks?: number | null } | null;
  learning_outcome: { id: number; code: string; description: string } | null;
  program_outcome: { id: number; code: string; title: string } | null;
  topic: string | null;
  question_type: GenQuestionType;
  difficulty_level: GenDifficulty | null;
  cognitive_level: GenCognitive | null;
  marks: number | null;
  number_of_questions: number;
  language: string;
  document_scope: { scope_type: string; course_id: number; document_id?: number | null; assessment_id?: number | null } | null;
  blueprint: BlueprintSlot[] | null;
  include_expected_answer: boolean;
  include_explanation: boolean;
  generation_status: GenerationStatus;
  generation_method: string | null;
  models: { generation: string | null; generation_version: string | null; embedding: string | null; prompt_version: string | null };
  regeneration_count: number;
  max_regenerations: number;
  feedback: string[];
  warnings: string[];
  set_summary: SetSummary | null;
  blueprint_summary: { requested: Record<string, Record<string, number>>; generated: Record<string, Record<string, number>>; matches: boolean } | null;
  retrieved_chunks: number;
  existing_questions_count: number;
  error_message: string | null;
  generated_questions_count: number | null;
  approved_count: number | null;
  disclaimer: string;
  completed_at: string | null;
  created_at: string | null;
  questions?: GeneratedQuestion[];
}

export interface CreateGenerationInput {
  course_id: number | string;
  assessment_id?: number | string | null;
  topic?: string | null;
  learning_outcome_id?: number | string | null;
  program_outcome_id?: number | string | null;
  question_type: GenQuestionType;
  difficulty_level?: GenDifficulty | null;
  cognitive_level?: GenCognitive | null;
  marks: number;
  number_of_questions?: number;
  language?: string;
  include_expected_answer?: boolean;
  include_explanation?: boolean;
  document_scope?: { scope_type: 'COURSE' | 'DOCUMENT' | 'ASSESSMENT'; document_id?: number | string | null; assessment_id?: number | string | null } | null;
  blueprint?: BlueprintSlot[] | null;
}

export interface UpdateGeneratedQuestionInput {
  question_text?: string;
  question_type?: GenQuestionType;
  marks?: number;
  difficulty_level?: GenDifficulty | null;
  cognitive_level?: GenCognitive | null;
  learning_outcome_id?: number | null;
  topic?: string | null;
  options?: string[] | null;
  correct_option?: string | null;
  expected_answer?: string | null;
  explanation?: string | null;
}

export interface FeedbackInput {
  feedback?: FeedbackReason[];
  feedback_note?: string;
}
