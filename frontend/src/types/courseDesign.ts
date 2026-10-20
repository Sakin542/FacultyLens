export interface DraftOutcome { code: string; statement: string; cognitive_level: string; topics: string[] }
export interface DraftLink { co: string; po: string; level: number; similarity: number; justification: string }

export interface SyllabusContent {
  topics: string[];
  outcomes: DraftOutcome[];
  co_po: DraftLink[];
  co_po_method: 'embedding' | 'lexical' | 'none';
  weekly_outline: { week: number; topic: string; outcomes: string[]; kind: string }[];
  assessment_plan: { component: string; weight: number; outcomes: string[] }[];
  engine: string;
  model: string | null;
  warnings: string[];
  program_outcome_source?: 'course_program' | 'all_programs';
  existing_outcomes?: { code: string; description: string; cognitive_level: string | null }[];
}

export interface LessonSession { title: string; kind: string; minutes: number; description: string }
export interface LessonWeek {
  week: number;
  kind: 'teaching' | 'exam' | 'review';
  topic: string;
  outcomes: string[];
  bloom_focus: string | null;
  sessions: LessonSession[];
  formative_checks: string[];
  preparation: string;
}
export interface LessonPlanContent {
  weeks: LessonWeek[];
  assessments: { week: number; type: string; title: string; outcomes: string[] }[];
  outcome_coverage: Record<string, number>;
  mapping_method: string;
  warnings: string[];
}

export interface CoursePlan<T = SyllabusContent | LessonPlanContent> {
  id: number;
  course_id: number;
  kind: 'syllabus' | 'lesson_plan';
  inputs: Record<string, unknown>;
  content: T;
  creator: { id: number; name: string } | null;
  applied_at: string | null;
  created_at: string | null;
}

export interface PaperSummaryData {
  title: string | null;
  authors: string[];
  year: number | null;
  doi: string | null;
  keywords: string[];
  summary: string[];
  key_findings: string[];
  methods: string[];
  limitations: string[];
  sections_found: string[];
  references: { index: number; text: string; year: number | null; cited_in_text: number }[];
  citation: { apa: string | null; ieee: string | null };
  stats: { words: number; sentences: number };
  engine: string;
}

export interface PaperSummaryItem {
  id: number;
  title: string | null;
  file_name: string | null;
  word_count: number;
  course: { id: number; course_code: string } | null;
  authors: string[];
  year: number | null;
  created_at: string | null;
  summary?: PaperSummaryData;
}

export interface GradingQueue {
  assessment: { id: number; title: string; course_code: string };
  questions: { id: number; question_number: string | number; marks: number; total: number; ungraded: number }[];
  question: { id: number; question_number: string | number; question_text: string; marks: number; expected_answer: string | null; rubric: { criterion: string; max_marks: number; description: string | null }[] } | null;
  answers: {
    id: number;
    student: { name: string; student_identifier: string } | null;
    answer_text: string | null;
    has_file: boolean;
    awarded_marks: number | null;
    faculty_feedback: string | null;
    ai_suggested_marks: number | null;
    submission_status: string | null;
  }[];
}
