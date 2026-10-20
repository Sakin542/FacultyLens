export interface CourseRef { id: number; course_code: string; course_name: string; semester?: string | null; academic_year?: string | null; term?: string }

export interface CoAttainment {
  id: number;
  code: string;
  description: string;
  average_percent: number | null;
  level: number | null;
  attained: boolean;
  assessed: boolean;
}

export interface PoAttainment {
  id: number;
  code: string;
  title: string;
  attainment: number | null;
  attained: boolean;
  contributing_cos: string[];
}

export interface OfferingMetrics {
  course: CourseRef & { term: string };
  assessments: number;
  questions: number;
  total_marks: number;
  bloom_percent: Record<string, number>;
  higher_order_percent: number;
  difficulty_percent: Record<string, number>;
  outcomes: number;
  co_coverage_percent: number | null;
  ai_quality_score: number | null;
  class_average_percent: number | null;
  co_attainment: CoAttainment[];
  co_attained_percent: number | null;
  mean_discrimination: number | null;
  flagged_questions: number;
  assessments_with_item_analysis: number;
}

export interface QualityTrend {
  course_code: string;
  course_name: string;
  offerings: OfferingMetrics[];
  insights: { metric: string; direction: 'improved' | 'declined' | 'steady'; text: string }[];
  message: string | null;
}

export type EventType = 'exam' | 'quiz' | 'assignment_deadline' | 'grading_deadline' | 'class' | 'meeting' | 'other';

export interface CalendarEvent {
  id: number | string;
  source: 'event' | 'assessment';
  title: string;
  type: EventType;
  starts_at: string;
  ends_at: string | null;
  all_day: boolean;
  location: string | null;
  notes: string | null;
  reminder_offsets: number[];
  course: { id: number; course_code: string; course_name: string } | null;
  assessment: { id: number; title: string } | null;
  editable: boolean;
}

export interface Workload {
  courses: {
    id: number; course_code: string; course_name: string; term: string; credits: number | null;
    contact_hours_per_week: number; contact_hours_is_estimate: boolean; assessments: number; draft_assessments: number;
    ungraded_answers: number; submissions_awaiting_grading: number;
  }[];
  totals: { active_courses: number; contact_hours_per_week: number; ungraded_answers: number; submissions_awaiting_grading: number; draft_assessments: number; upcoming_14d: number };
  estimate_hours_this_week: { teaching: number; grading: number; exam_preparation: number; total: number; formula: string };
  upcoming: CalendarEvent[];
}

export interface ObeSummary {
  course: CourseRef & { term: string };
  co_target: number;
  po_target: number;
  co_attainment: CoAttainment[];
  po_attainment: PoAttainment[];
  program_outcomes: { id: number; code: string; title: string }[];
  matrix: { code: string; levels: Record<string, number> }[];
  assessments: { id: number; title: string; questions: number; mapped: number }[];
}

export interface DepartmentOverview {
  department: string;
  summary: { faculty: number; courses: number; mean_higher_order_percent: number | null; mean_co_coverage_percent: number | null; mean_class_average_percent: number | null; courses_needing_attention: number };
  courses: (OfferingMetrics & { instructor: { id: number; name: string } | null; flags: string[] })[];
  faculty: { id: number; name: string; designation: string | null; role: string; active_courses: number; contact_hours_per_week: number; ungraded_answers: number; estimated_hours_this_week: number }[];
}
