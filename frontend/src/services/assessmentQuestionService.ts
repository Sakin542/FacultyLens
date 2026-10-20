import { apiClient } from './api';
import { QuestionDetail } from '@/types';

export type QuestionType = 'descriptive' | 'short_answer' | 'mcq' | 'true_false' | 'problem_solving' | 'other';
export type BloomLevel = 'Remember' | 'Understand' | 'Apply' | 'Analyze' | 'Evaluate' | 'Create';

export interface QuestionInput {
  question_text: string;
  marks: number;
  question_type?: QuestionType | null;
  difficulty_level?: 'easy' | 'medium' | 'hard' | null;
  cognitive_level?: BloomLevel | null;
  learning_outcome_id?: number | null;
  expected_answer?: string | null;
}

export interface QuestionTotals { questions: number; marks_sum: number; total_marks: number | null }
export interface PaperPreview {
  questions: { number: number; text: string; marks: number | null }[];
  warnings: string[];
  file_name: string;
  existing_questions: number;
}

interface Envelope<T> { status: string; message?: string; data: T; meta?: QuestionTotals }

/** Faculty-authored assessment questions. Nothing is imported from a paper until `bulkCreate` is called. */
export const assessmentQuestionService = {
  create: (assessmentId: number | string, input: QuestionInput) =>
    apiClient<Envelope<QuestionDetail>>(`/assessments/${assessmentId}/questions`, { method: 'POST', body: JSON.stringify(input) }),
  update: (questionId: number | string, input: Partial<QuestionInput>) =>
    apiClient<Envelope<QuestionDetail>>(`/questions/${questionId}`, { method: 'PUT', body: JSON.stringify(input) }),
  remove: (questionId: number | string) =>
    apiClient<{ status: string; message: string; meta: QuestionTotals }>(`/questions/${questionId}`, { method: 'DELETE' }),
  extractFromPaper: (assessmentId: number | string) =>
    apiClient<Envelope<PaperPreview>>(`/assessments/${assessmentId}/question-paper/extract`, { method: 'POST' }).then((r) => r.data),
  bulkCreate: (assessmentId: number | string, questions: QuestionInput[], source = 'question_paper') =>
    apiClient<Envelope<{ ids: number[] }>>(`/assessments/${assessmentId}/questions/bulk`, { method: 'POST', body: JSON.stringify({ questions, source }) }),
};
