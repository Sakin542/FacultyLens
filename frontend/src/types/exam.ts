export type BloomLevel = 'Remember' | 'Understand' | 'Apply' | 'Analyze' | 'Evaluate' | 'Create';
export const BLOOM_LEVELS: BloomLevel[] = ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'];
export const DIFFICULTY_LEVELS = ['easy', 'medium', 'hard'] as const;
export type Difficulty = typeof DIFFICULTY_LEVELS[number];

export interface PoolCandidate {
  key: string;
  source_type: 'bank' | 'question' | 'generated';
  source_id: number;
  question_text: string;
  expected_answer: string | null;
  question_type: string | null;
  marks: number;
  difficulty_level: Difficulty | null;
  cognitive_level: BloomLevel | null;
  topic: string | null;
  learning_outcome_id: number | null;
  options: unknown[] | null;
  origin: string;
}

export interface ExamPaperItem {
  id: number;
  sort_order: number;
  section_title: string | null;
  source_type: string;
  source_id: number | null;
  question_text: string;
  expected_answer: string | null;
  question_type: string | null;
  marks: number;
  difficulty_level: Difficulty | null;
  cognitive_level: BloomLevel | null;
  topic: string | null;
  learning_outcome: { id: number; code: string } | null;
  options: unknown[] | null;
  variant_of_item_id: number | null;
  variant_meta: { methods?: string[]; number_changes?: { from: string; to: string }[]; similarity?: number | null; engine?: string } | null;
  answer_needs_review: boolean;
}

export interface DistributionCell { count: number; marks: number; percent: number }

export interface ExamPaperCoverage {
  question_count: number;
  total_marks: number;
  target_total_marks: number | null;
  target_question_count: number | null;
  bloom: Record<string, DistributionCell>;
  target_bloom_percent: Record<string, number>;
  difficulty: Record<string, DistributionCell>;
  learning_outcomes: { code: string | null; count: number; marks: number; percent: number }[];
  answer_key_complete: boolean;
}

export interface ExamPaper {
  id: number;
  assessment: { id: number; title: string };
  course: { id: number; course_code: string; course_name: string };
  title: string;
  set_label: string;
  parent_paper_id: number | null;
  instructions: string | null;
  duration_minutes: number | null;
  total_marks: number;
  status: 'draft' | 'final';
  build_mode: string;
  coverage: ExamPaperCoverage | null;
  shortfalls: { slot: string; needed: number; missing: number }[];
  items: ExamPaperItem[];
  sets: { id: number; set_label: string; status: string }[];
  finalized_at: string | null;
  applied_at: string | null;
}

export interface ExamPaperSummary {
  id: number;
  title: string;
  status: 'draft' | 'final';
  build_mode: string;
  total_marks: number;
  items_count: number;
  shortfalls: { slot: string; needed: number; missing: number }[];
  sets: { id: number; set_label: string; status: string }[];
  creator: { id: number; name: string } | null;
  applied_at: string | null;
  created_at: string | null;
}

export interface QuestionVariant {
  question_text: string;
  expected_answer: string | null;
  methods: string[];
  number_changes: { from: string; to: string }[];
  answer_needs_review: boolean;
  warnings: string[];
  similarity: number | null;
}

export interface VariantResult {
  original: string;
  mode: string;
  variants: QuestionVariant[];
  engine: string;
  model: string | null;
  message: string | null;
}

export interface ItemAnalysisItem {
  question_id: number;
  question_number: string | number;
  question_excerpt: string;
  max_marks: number;
  cognitive_level: string | null;
  labelled_difficulty: string | null;
  mean_marks: number;
  difficulty_index: number;
  difficulty_band: 'easy' | 'moderate' | 'hard';
  upper_p: number;
  lower_p: number;
  discrimination_index: number;
  discrimination_band: 'excellent' | 'good' | 'marginal' | 'poor';
  item_rest_correlation: number | null;
  flags: string[];
  recommendation: string;
}

export interface ItemAnalysis {
  assessment: { id: number; title: string };
  submissions_analyzed: number;
  submissions_excluded_ungraded: number;
  minimum_required: number;
  sufficient: boolean;
  message: string | null;
  items: ItemAnalysisItem[];
  summary: null | {
    questions: number;
    mean_total: number;
    max_total: number;
    mean_percent: number | null;
    std_dev_total: number;
    cronbach_alpha: number | null;
    reliability_band: string | null;
    flagged_items: number;
    mean_difficulty_index: number;
    mean_discrimination_index: number;
  };
}

export interface SimilaritySide {
  answer_id: number;
  submission_id: number;
  student: { id: number; name: string; student_identifier: string } | null;
  answer_text: string;
  awarded_marks: number | null;
}

export interface SimilarityMatch {
  id: number;
  question: { id: number; question_number: string | number; excerpt: string } | null;
  lexical_score: number;
  semantic_score: number | null;
  combined_score: number;
  shared_excerpt: string | null;
  review_status: 'pending' | 'confirmed' | 'dismissed';
  review_note: string | null;
  reviewed_at: string | null;
  a: SimilaritySide;
  b: SimilaritySide;
}

export interface SimilarityRun {
  id: number;
  method: string;
  threshold: number;
  answers_compared: number;
  pairs_compared: number;
  pairs_flagged: number;
  summary: { per_question: { question_id: number; question_number: string | number; answers: number; flagged_pairs: number }[]; semantic_unavailable: boolean; min_words: number } | null;
  run_by: { id: number; name: string } | null;
  created_at: string | null;
  matches: SimilarityMatch[];
}

export type FeedbackTone = 'positive' | 'improvement' | 'neutral';

export interface FeedbackComment {
  id: number;
  course: { id: number; course_code: string } | null;
  criterion: string | null;
  title: string;
  body: string;
  tone: FeedbackTone;
  usage_count: number;
  last_used_at: string | null;
}
