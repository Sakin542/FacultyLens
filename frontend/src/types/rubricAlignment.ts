/**
 * STEP 28: Answer <-> Rubric Alignment types.
 *
 * Alignment describes how well an answer addresses each rubric criterion. It is not a grade
 * and not correctness; it never changes faculty marks.
 */

export type AlignmentStatus = 'STRONG' | 'PARTIAL' | 'WEAK' | 'NOT_ALIGNED';

export type AlignmentAnalysisStatus = 'PENDING' | 'PROCESSING' | 'COMPLETED' | 'FAILED' | 'REVIEWED' | 'STALE';

export const ALIGNMENT_WEIGHTS: Record<AlignmentStatus, number> = {
  STRONG: 1,
  PARTIAL: 0.5,
  WEAK: 0.25,
  NOT_ALIGNED: 0,
};

export interface AlignmentCounts {
  strong: number;
  partial: number;
  weak: number;
  not_aligned: number;
}

export interface CriterionAlignment {
  id: number;
  rubric_criterion_id: number | null;
  criterion: string;
  max_marks: number;
  alignment_score: number;
  similarity?: number | null;
  alignment_status: AlignmentStatus;
  evidence: string[];
  missing_elements: string[];
  explanation: string;
  sort_order?: number;
}

export interface RubricAlignment {
  id: number;
  student_answer_id: number;
  student_submission_id: number;
  question_id: number;
  rubric_id: number | null;
  rubric_version: number | null;
  /** Mark-weighted alignment percentage (primary). */
  overall_alignment_score: number | null;
  unweighted_alignment_score: number | null;
  alignment_status: AlignmentStatus | null;
  analysis_status: AlignmentAnalysisStatus;
  is_current: boolean;
  is_stale: boolean;
  stale_reasons: string[];
  counts: AlignmentCounts;
  summary: string | null;
  strengths: string[];
  missing_elements: string[];
  error_message: string | null;
  model_name?: string | null;
  model_version?: string | null;
  analysis_method?: string | null;
  thresholds?: Record<string, number> | null;
  generated_at: string | null;
  reviewed_at?: string | null;
  reviewed_by?: number | null;
  created_at?: string;
  updated_at?: string;
  criterion_alignments: CriterionAlignment[];
}

export const isAlignmentActive = (status: AlignmentAnalysisStatus | null | undefined): boolean =>
  status === 'PENDING' || status === 'PROCESSING';

export const isAlignmentCompleted = (status: AlignmentAnalysisStatus | null | undefined): boolean =>
  status === 'COMPLETED' || status === 'REVIEWED' || status === 'STALE';

/** Gap (percentage points) between AI suggested marks and alignment beyond which a review notice is shown. */
export const ALIGNMENT_GRADE_GAP_THRESHOLD = 30;

/**
 * Compare the STEP 27 suggested-marks percentage with the STEP 28 alignment percentage.
 * Returns the gap in points, or null when either signal is unavailable. Never used to change a grade.
 */
export function gradingAlignmentGap(
  suggestedMarks: number | null | undefined,
  maximumMarks: number | null | undefined,
  alignmentScore: number | null | undefined,
): number | null {
  if (suggestedMarks === null || suggestedMarks === undefined) return null;
  if (!maximumMarks || maximumMarks <= 0) return null;
  if (alignmentScore === null || alignmentScore === undefined) return null;
  return Math.round(((suggestedMarks / maximumMarks) * 100 - alignmentScore) * 10) / 10;
}
