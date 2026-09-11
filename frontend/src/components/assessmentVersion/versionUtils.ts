import { BadgeVariant } from '@/components/common/Badge';
import { ApiError } from '@/services/api';
import { AnalysisFreshness, QuestionChangeStatus, VersionStatus, VersionValidationStatus } from '@/types/assessmentVersion';

/** STEP 38: shared formatting helpers for the Assessment Versioning UI. */

export const humanize = (s: string | null | undefined): string => (s ?? '').replace(/_/g, ' ').toLowerCase().replace(/^\w/, (c) => c.toUpperCase());

export const fmtMarks = (v: number | null | undefined): string => (v === null || v === undefined ? '—' : Number.isInteger(v) ? String(v) : v.toFixed(2).replace(/\.?0+$/, ''));

export const fmtDate = (iso: string | null | undefined): string => (iso ? new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '—');

export const fmtSigned = (v: number | null | undefined, suffix = ''): string => (v === null || v === undefined ? '—' : `${v > 0 ? '+' : ''}${fmtMarks(v)}${suffix}`);

export const statusVariant = (s: VersionStatus | VersionValidationStatus | AnalysisFreshness | QuestionChangeStatus | string | null | undefined): BadgeVariant => {
  switch (s) {
    case 'FINALIZED': case 'VALID': case 'CURRENT': case 'ADDED': return 'Good';
    case 'APPROVED': case 'IN_REVIEW': case 'VALID_WITH_WARNINGS': case 'MODIFIED': case 'STALE': return 'Attention';
    case 'INVALID': case 'REMOVED': return 'Critical';
    case 'DRAFT': return 'Pending';
    case 'ARCHIVED': case 'UNCHANGED': case 'NONE': return 'neutral';
    default: return 'neutral';
  }
};

export const fieldLabel = (field: string): string => ({
  question_number: 'Question number', question_text: 'Question text', question_type: 'Question type', marks: 'Marks', difficulty_level: 'Difficulty', cognitive_level: 'Bloom level',
  learning_outcome_id: 'CO / LO', program_outcome_id: 'PO', topic: 'Topic', expected_answer: 'Expected answer', section_name: 'Section',
}[field] ?? humanize(field));

export function getVersionErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.status === 401) return 'Your session has expired. Please sign in again.';
    if (err.status === 403) return err.message || 'You do not have access to this assessment version.';
    if (err.status === 404) return 'The assessment or version no longer exists.';
    if (err.status === 409) return err.message || 'This version cannot be changed in its current state.';
    if (err.status === 422) return err.message || 'Please correct the highlighted values.';
    if (err.status === 429) return 'Too many requests. Please wait a moment.';
    if (err.status >= 500) return 'The versioning service is temporarily unavailable.';
    return err.message || 'Something went wrong.';
  }
  return err instanceof Error ? err.message : 'Something went wrong.';
}
