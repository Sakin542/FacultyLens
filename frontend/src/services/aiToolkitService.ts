import { apiClient, API_BASE_URL, getCookie, getCsrfCookie } from './api';

export type ToolkitTool = 'ocr' | 'transcription' | 'study_pack';
export type ToolkitLanguage = 'en' | 'bn';

export interface ToolkitJob {
  id: number;
  tool: ToolkitTool;
  status: 'queued' | 'processing' | 'completed' | 'failed';
  input_filename: string;
  input_bytes: number;
  language: ToolkitLanguage | null;
  error: string | null;
  model: string | null;
  duration_seconds: number | null;
  line_count: number | null;
  preview: string | null;
  created_at: string | null;
  started_at: string | null;
  completed_at: string | null;
}

export interface ToolkitJobDetail extends ToolkitJob {
  text: string | null;
  segments: { start: number; end: number; text: string }[];
  pages: { page: number; lines: string[]; text: string }[];
  note: string | null;
  summary: string | null;
  summary_method: 'generative' | 'extractive' | null;
  key_points: string[];
  quiz: QuizItem[];
  quiz_method: 'generative' | 'template' | null;
  quiz_rejected: number | null;
}

export interface QuizItem { question: string; answer: string; type: 'short_answer' | 'fill_in_blank'; bloom_level: string | null; evidence: string }

export interface Readability { flesch_reading_ease: number | null; grade_level: number | null; words: number }
export interface QualityIssue { code: string; severity: 'high' | 'medium' | 'low'; message: string }
export interface QualityResult {
  question: string; score: number; issues: QualityIssue[]; readability: Readability; bloom_level: string | null;
  suggested_rewrite: string | null; rewrite_bloom_level: string | null;
}
export interface QualityReport { results: QualityResult[]; rewrites_available: boolean; model: string | null; note: string }
export interface SimplifyResult { text: string; level: string; readability_before: Readability; readability_after: Readability; simpler: boolean; terms: string[]; model: string | null; note: string }
export type BloomLevel = 'REMEMBER' | 'UNDERSTAND' | 'APPLY' | 'ANALYZE' | 'EVALUATE' | 'CREATE';
export interface BloomResult {
  original: string; original_level: string | null; target_level: BloomLevel; rewritten: string; detected_level: string | null;
  matches_target: boolean; on_topic: boolean; attempts: number; model: string | null; note: string;
}

export type FeedbackTone = 'encouraging' | 'neutral' | 'concise';
export interface FeedbackCriterionInput { criterion: string; description?: string | null; indicators?: string[] }
export interface FeedbackDraft {
  feedback: string; template_feedback: string; tone: FeedbackTone;
  improvements: string[]; next_step: string;
  criteria: { criterion: string; coverage: string; coverage_level: string; evidence: string[]; missing: string[] }[];
  method: 'ai_polished' | 'rubric_template'; model: string | null; note: string;
}
export type FeedbackDraftInput =
  | { student_answer_id: number; tone?: FeedbackTone }
  | { question: string; answer: string; criteria: FeedbackCriterionInput[]; tone?: FeedbackTone };

export interface ComparePair {
  a: string; b: string; lexical_score: number; semantic_score: number | null; combined_score: number; flagged: boolean; shared_excerpt: string | null;
}
export interface CompareResult {
  method: 'lexical' | 'lexical+semantic'; semantic_unavailable: boolean; threshold: number; answers_compared: number;
  too_short: string[]; min_words: number; pairs_compared: number; pairs_flagged: number; pairs: ComparePair[]; note: string;
}

export type BloomBand = 'lower' | 'middle' | 'higher';
export interface PaperQuestionInput { number?: string; text: string; marks?: number | null }
export interface PaperReview {
  questions: { number: string; text: string; marks: number | null; bloom_level: string; bloom_source: 'given' | 'detected'; band: BloomBand; issues: QualityIssue[] }[];
  total_marks: number | null; weighted_by: 'marks' | 'question count';
  bloom: {
    levels: Record<string, { count: number; weight: number; share: number }>;
    bands: Record<BloomBand, { label: string; share: number; target: [number, number]; status: 'ok' | 'low' | 'high' }>;
    missing_levels: string[];
  };
  duplicates: { a: string; b: string; similarity: number; kind: 'duplicate' | 'overlap' }[];
  topics: { topic: string; covered: boolean; questions: string[]; share: number }[] | null;
  findings: { severity: 'high' | 'medium' | 'low'; message: string }[];
  similarity_method: 'semantic' | 'lexical';
  topics_source?: 'pasted' | 'course_outcomes' | null;
  note: string;
}
export type PaperReviewInput =
  | { assessment_id: number; topics?: string[] }
  | { questions: PaperQuestionInput[]; topics?: string[] };

export interface ToolkitOverview {
  tools: { translation: boolean; ocr: boolean; transcription: boolean; study_pack: boolean; speech: boolean; writing: boolean; feedback?: boolean; compare?: boolean; paper_review?: boolean };
  provider: 'local' | 'hosted';
  limits: { translation_chars: number; ocr_mb: number; transcription_mb: number };
  jobs: ToolkitJob[];
}

export interface TranslationResult { text: string; model: string | null; provider: 'local' | 'hosted'; fallback_reason: string | null }

interface Envelope<T> { status: string; message?: string; data: T }

const upload = (path: string, file: File, extra: Record<string, string> = {}) => {
  const form = new FormData();
  form.append('file', file);
  Object.entries(extra).forEach(([k, v]) => form.append(k, v));
  return apiClient<Envelope<ToolkitJob>>(path, { method: 'POST', body: form });
};

export const aiToolkitService = {
  overview: () => apiClient<Envelope<ToolkitOverview>>('/ai-toolkit').then((r) => r.data),
  translate: (text: string, source: ToolkitLanguage, target: ToolkitLanguage) =>
    apiClient<Envelope<TranslationResult>>('/ai-toolkit/translate', { method: 'POST', body: JSON.stringify({ text, source, target }) }).then((r) => r.data),
  ocr: (file: File) => upload('/ai-toolkit/ocr', file),
  transcribe: (file: File, language?: ToolkitLanguage) => upload('/ai-toolkit/transcribe', file, language ? { language } : {}),
  job: (id: number) => apiClient<Envelope<ToolkitJobDetail>>(`/ai-toolkit/jobs/${id}`).then((r) => r.data),
  remove: (id: number) => apiClient<{ message: string }>(`/ai-toolkit/jobs/${id}`, { method: 'DELETE' }),
  downloadUrl: (id: number) => `${API_BASE_URL}/ai-toolkit/jobs/${id}/download`,

  studyPack: (input: { text?: string; source_job_id?: number; title?: string; num_questions?: number }) =>
    apiClient<Envelope<ToolkitJob>>('/ai-toolkit/study-pack', { method: 'POST', body: JSON.stringify(input) }),
  questionQuality: (questions: string[], suggestRewrites = true) =>
    apiClient<Envelope<QualityReport>>('/ai-toolkit/question-quality', { method: 'POST', body: JSON.stringify({ questions, suggest_rewrites: suggestRewrites }) }).then((r) => r.data),
  simplify: (text: string, level: 'school' | 'undergraduate' | 'esl') =>
    apiClient<Envelope<SimplifyResult>>('/ai-toolkit/simplify', { method: 'POST', body: JSON.stringify({ text, level }) }).then((r) => r.data),
  bloomRewrite: (question: string, target_level: BloomLevel) =>
    apiClient<Envelope<BloomResult>>('/ai-toolkit/bloom-rewrite', { method: 'POST', body: JSON.stringify({ question, target_level }) }).then((r) => r.data),
  feedbackDraft: (input: FeedbackDraftInput) =>
    apiClient<Envelope<FeedbackDraft>>('/ai-toolkit/feedback-draft', { method: 'POST', body: JSON.stringify(input) }).then((r) => r.data),
  compareAnswers: (answers: { label?: string; text: string }[], question?: string, threshold?: number) =>
    apiClient<Envelope<CompareResult>>('/ai-toolkit/compare-answers', { method: 'POST', body: JSON.stringify({ answers, question: question || null, threshold }) }).then((r) => r.data),
  paperReview: (input: PaperReviewInput) =>
    apiClient<Envelope<PaperReview>>('/ai-toolkit/paper-review', { method: 'POST', body: JSON.stringify(input) }).then((r) => r.data),
  attach: (jobId: number, body: { submission_id: number; question_id: number; text: string; replace?: boolean }) =>
    apiClient<Envelope<{ answer_id: number; submission_id: number; assessment_id: number }>>(`/ai-toolkit/jobs/${jobId}/attach`, { method: 'POST', body: JSON.stringify(body) }),
  saveToQuestionBank: (jobId: number, courseId: number, questions: { question: string; bloom_level: string | null }[]) =>
    apiClient<Envelope<{ ids: number[] }>>(`/ai-toolkit/jobs/${jobId}/question-bank`, { method: 'POST', body: JSON.stringify({ course_id: courseId, questions }) }),

  /** Text to speech: returns a playable object URL for a WAV (caller revokes it). */
  speak: async (text: string, language: ToolkitLanguage): Promise<string> => {
    const xsrf = getCookie('XSRF-TOKEN');
    if (!xsrf) await getCsrfCookie();
    const res = await fetch(`${API_BASE_URL}/ai-toolkit/speak`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', Accept: 'audio/wav, application/json', ...(getCookie('XSRF-TOKEN') ? { 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN')! } : {}) },
      body: JSON.stringify({ text, language }),
    });
    if (!res.ok) {
      let message = 'The text could not be read aloud.';
      try { message = ((await res.json()) as { message?: string }).message || message; } catch { /* not JSON */ }
      throw new Error(message);
    }
    return URL.createObjectURL(await res.blob());
  },
};
