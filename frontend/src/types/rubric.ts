/**
 * STEP 25: AI Rubric Generator types.
 * A rubric is an AI-generated DRAFT until faculty explicitly approve it.
 */

export type RubricStatus = 'DRAFT' | 'APPROVED' | 'ARCHIVED';

export type RubricGenerationMethod = 'ai_assisted' | 'template_based' | 'manual';

export interface RubricCriterion {
  id?: number;
  criterion: string;
  description: string;
  max_marks: number;
  scoring_guidance?: string | null;
  expected_indicators: string[];
  sort_order: number;
}

export interface Rubric {
  id: number;
  question_id: number;
  assessment_id: number;
  created_by: number | null;
  title: string;
  total_marks: number;
  criteria_total: number;
  status: RubricStatus;
  version: number;
  generation_method: RubricGenerationMethod;
  is_ai_generated: boolean;
  ai_model: string | null;
  ai_model_version: string | null;
  general_guidance: string | null;
  generated_at: string | null;
  approved_at: string | null;
  approved_by: number | null;
  created_at?: string;
  updated_at?: string;
  criteria: RubricCriterion[];
}

export interface RubricCriterionPayload {
  criterion: string;
  description: string;
  max_marks: number;
  scoring_guidance?: string | null;
  expected_indicators?: string[];
  sort_order?: number;
}

export interface RubricUpdatePayload {
  title?: string;
  general_guidance?: string | null;
  criteria?: RubricCriterionPayload[];
}

/** Editable, client-side representation of a criterion (marks kept as string while typing). */
export interface EditableCriterion {
  key: string;
  criterion: string;
  description: string;
  max_marks: string;
  scoring_guidance: string;
  expected_indicators: string[];
}
