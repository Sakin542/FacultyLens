/**
 * STEP 45: AI explainability & transparency types.
 * Mirrors backend/app/Services/Explainability/ExplanationBuilder.php.
 */

export type AiResultType =
  | 'question_type'
  | 'difficulty'
  | 'bloom'
  | 'topic'
  | 'lo_alignment'
  | 'co_po_mapping'
  | 'similarity'
  | 'assessment_quality'
  | 'recommendation'
  | 'rubric'
  | 'generated_question'
  | 'rag_answer'
  | 'ai_grading'
  | 'inter_grader';

export type MethodType = 'RULE_BASED' | 'MODEL_BASED' | 'EMBEDDING_BASED' | 'HYBRID' | 'GENERATIVE' | 'HUMAN_CONFIRMED';

export type ReviewAction = 'ACCEPTED' | 'REJECTED' | 'REVIEWED' | 'OVERRIDE';

export type EvidenceStatus = 'available' | 'unavailable' | 'stale' | 'none' | 'partial';

export interface ExplanationEvidence {
  type: string;
  label: string;
  text?: string | null;
  score?: number | null;
  source_type?: string | null;
  source_id?: number | string | null;
  document_id?: number | string | null;
  document_page?: number | null;
  chunk_id?: number | string | null;
  meta?: Record<string, unknown>;
}

export interface ExplanationDetail {
  label: string;
  value: unknown;
}

export interface ExplanationLink {
  label: string;
  type: string;
  id: number | string | null;
  [key: string]: unknown;
}

export interface OverrideOption {
  value: number | string;
  label: string;
}

export interface OverrideReason {
  code: string;
  label: string;
}

export interface ReviewRecord {
  id: number;
  action: 'ACCEPTED' | 'REJECTED' | 'REVIEWED' | 'OVERRIDDEN';
  override_value?: Record<string, unknown> | null;
  override_reason?: string | null;
  override_reason_label?: string | null;
  comment?: string | null;
  ai_value?: Record<string, unknown> | null;
  analysis_report_id?: number | null;
  user: { id: number; name?: string };
  created_at: string | null;
}

export interface AiExplanation {
  result_type: AiResultType;
  result_id: number;
  result: {
    label: string | null;
    score: number | null;
    display: string | null;
    [key: string]: unknown;
  };
  explanation: { summary: string; details: ExplanationDetail[] };
  evidence: ExplanationEvidence[];
  evidence_status: EvidenceStatus;
  evidence_note?: string;
  method: { type: MethodType; label?: string; description: string; components: string[] };
  model: {
    name: string | null;
    version: string | null;
    prompt_version: string | null;
    embedding_model: string | null;
    rule_version: string | null;
  };
  confidence: { available: boolean; value: number | null; note: string };
  limitations: string[];
  related: { links: ExplanationLink[]; analysis_report_id: number | null; analysis_version: number | null; is_current: boolean | null };
  evaluation: {
    status: 'EVALUATED' | 'EVALUATION_AVAILABLE' | 'LIMITED_EVALUATION_DATA' | 'NOT_EVALUATED';
    label: string;
    task: string | null;
    headline_metric?: string | null;
    headline_value?: number | null;
    gate_status?: string | null;
    example_count?: number | null;
    completed_at?: string | null;
  };
  review: {
    overridable: boolean;
    can_review?: boolean;
    actions: ReviewAction[];
    override_options: Array<OverrideOption | string>;
    override_reasons: OverrideReason[];
    override_field?: string;
    latest: ReviewRecord | null;
    history_count: number;
    faculty_value?: unknown;
    faculty_field?: string | null;
    note?: string;
    accept_label?: string;
    reject_label?: string;
  };
  version: { explanation_version: string; generated_at: string };
  disclaimer: string;
}

export interface ExplanationResponse {
  status: 'success' | 'error';
  message?: string;
  data: AiExplanation;
}

export interface ReviewResponse {
  status: 'success' | 'error';
  message?: string;
  data: { review: ReviewRecord; applied?: Record<string, unknown> };
}

export type ExplainabilityViewEvent = 'AI_RESULT_VIEWED' | 'AI_EVIDENCE_VIEWED' | 'AI_SOURCE_OPENED';
