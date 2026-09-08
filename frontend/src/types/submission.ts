/**
 * STEP 26: Student Answer Management types.
 * Faculty-side records only; no AI grading fields exist yet.
 */
import { LearningOutcome } from './index';

export type SubmissionStatus = 'DRAFT' | 'SUBMITTED' | 'UNDER_REVIEW' | 'GRADED' | 'RETURNED';

export type GradingStatus = 'NOT_STARTED' | 'IN_PROGRESS' | 'AI_ASSISTED' | 'FACULTY_REVIEWED' | 'FINALIZED';

export type AnswerStatus = 'NOT_REVIEWED' | 'UNDER_REVIEW' | 'REVIEWED';

export type AnswerType = 'TEXT' | 'FILE' | 'IMAGE' | 'MCQ';

export const SUBMISSION_STATUSES: SubmissionStatus[] = ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'GRADED', 'RETURNED'];
export const GRADING_STATUSES: GradingStatus[] = ['NOT_STARTED', 'IN_PROGRESS', 'AI_ASSISTED', 'FACULTY_REVIEWED', 'FINALIZED'];
export const ANSWER_STATUSES: AnswerStatus[] = ['NOT_REVIEWED', 'UNDER_REVIEW', 'REVIEWED'];

export interface Student {
  id: number;
  student_identifier: string;
  name: string;
  email?: string | null;
  department?: string | null;
  program?: string | null;
  academic_year?: string | null;
  section?: string | null;
  submissions_count?: number;
  created_at?: string;
  updated_at?: string;
}

export interface StudentPayload {
  student_identifier: string;
  name: string;
  email?: string | null;
  department?: string | null;
  program?: string | null;
  academic_year?: string | null;
  section?: string | null;
}

export interface StudentSummary {
  id: number;
  student_identifier: string;
  name: string;
  section?: string | null;
  program?: string | null;
}

export interface StudentAnswer {
  id: number;
  student_submission_id: number;
  question_id: number;
  answer_type: AnswerType;
  answer_text?: string | null;
  original_answer_text?: string | null;
  is_faculty_edited: boolean;
  has_file: boolean;
  answer_file_name?: string | null;
  answer_file_type?: string | null;
  answer_file_size?: number | null;
  awarded_marks?: number | null;
  faculty_feedback?: string | null;
  answer_status: AnswerStatus;
  created_at?: string;
  updated_at?: string;
}

/** Summary row returned by the submissions list (no answer bodies). */
export interface StudentSubmission {
  id: number;
  assessment_id: number;
  student_id: number;
  submission_identifier?: string | null;
  submitted_at?: string | null;
  status: SubmissionStatus;
  grading_status: GradingStatus;
  total_marks?: number | null;
  awarded_marks?: number | null;
  answers_count: number;
  reviewed_answers_count?: number;
  student?: StudentSummary | null;
  allowed_transitions?: SubmissionStatus[];
  created_at?: string;
  updated_at?: string;
}

export interface ApprovedRubricRef {
  id: number;
  title: string;
  status: string;
  version: number;
}

/** A question of the assessment with the student's answer (if any) for the detail view. */
export interface SubmissionQuestion {
  id: number;
  question_number: number | null;
  question_text: string;
  question_type: string;
  marks: number;
  learning_outcome?: LearningOutcome | null;
  approved_rubric: ApprovedRubricRef | null;
  answer: StudentAnswer | null;
}

export interface StudentSubmissionDetail {
  id: number;
  assessment_id: number;
  student_id: number;
  submission_identifier?: string | null;
  submitted_at?: string | null;
  status: SubmissionStatus;
  grading_status: GradingStatus;
  total_marks?: number | null;
  awarded_marks?: number | null;
  allowed_transitions: SubmissionStatus[];
  student: Student;
  assessment: {
    id: number;
    title: string;
    type: string;
    total_marks: number | null;
    course?: { id: number; course_code: string; course_name: string } | null;
  };
  questions: SubmissionQuestion[];
  answers_count: number;
  created_at?: string;
  updated_at?: string;
}

export interface SubmissionSummaryStats {
  total_submissions: number;
  by_status: Record<SubmissionStatus, number>;
  by_grading_status: Record<GradingStatus, number>;
  total_answers: number;
  answers_by_status: Record<AnswerStatus, number>;
  questions_count: number;
}

export interface SubmissionFilterParams {
  page?: number;
  per_page?: number;
  status?: SubmissionStatus | '';
  grading_status?: GradingStatus | '';
  submitted_from?: string;
  submitted_to?: string;
  search?: string;
  sort?: 'submitted_at' | 'created_at' | 'status' | 'grading_status' | 'awarded_marks';
  direction?: 'asc' | 'desc';
}

export interface CreateSubmissionPayload {
  student_id: number;
  submission_identifier?: string | null;
  submitted_at?: string | null;
  status?: 'DRAFT' | 'SUBMITTED';
}

export interface AnswerPayload {
  question_id?: number;
  answer_text?: string | null;
  answer_type?: AnswerType;
  answer_status?: AnswerStatus;
  awarded_marks?: number | null;
  faculty_feedback?: string | null;
  remove_file?: boolean;
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}
