import { apiClient } from './api';
import { downloadFile } from './planningService';
import type { CoursePlan, GradingQueue, LessonPlanContent, PaperSummaryItem, SyllabusContent } from '@/types/courseDesign';

interface Envelope<T> { status: string; message?: string; data: T }

export const courseDesignService = {
  plans: (courseId: number | string, kind?: 'syllabus' | 'lesson_plan') =>
    apiClient<Envelope<CoursePlan[]>>(`/courses/${courseId}/plans${kind ? `?kind=${kind}` : ''}`).then((r) => r.data),

  draftSyllabus: (courseId: number | string, payload: { description?: string; weeks?: number; outcome_count?: number; topics?: string[] }) =>
    apiClient<Envelope<CoursePlan<SyllabusContent>>>(`/courses/${courseId}/syllabus-draft`, { method: 'POST', body: JSON.stringify(payload) }),

  applySyllabus: (courseId: number | string, planId: number, payload: { outcomes: { code: string; statement: string; cognitive_level: string }[]; links: { co: string; po: string; level: number }[]; update_existing: boolean }) =>
    apiClient<Envelope<{ created: number; updated: number; skipped: number; links: number }>>(`/courses/${courseId}/syllabus-draft/${planId}/apply`, { method: 'POST', body: JSON.stringify(payload) }),

  lessonPlan: (courseId: number | string, payload: { weeks?: number; sessions_per_week?: number; session_minutes?: number; topics?: string[] }) =>
    apiClient<Envelope<CoursePlan<LessonPlanContent>>>(`/courses/${courseId}/lesson-plan`, { method: 'POST', body: JSON.stringify(payload) }),

  schedule: (planId: number, startDate: string) =>
    apiClient<{ message: string }>(`/course-plans/${planId}/schedule`, { method: 'POST', body: JSON.stringify({ start_date: startDate }) }),

  exportPlan: (planId: number) => downloadFile(`/course-plans/${planId}/export`, 'course-plan.pdf'),

  summaries: () => apiClient<Envelope<PaperSummaryItem[]>>('/research/summaries').then((r) => r.data),
  summary: (id: number) => apiClient<Envelope<PaperSummaryItem>>(`/research/summaries/${id}`).then((r) => r.data),
  summarize: (input: { file?: File; text?: string; title?: string; course_id?: number | null }) => {
    if (input.file) {
      const form = new FormData();
      form.append('file', input.file);
      if (input.title) form.append('title', input.title);
      if (input.course_id) form.append('course_id', String(input.course_id));
      return apiClient<Envelope<PaperSummaryItem>>('/research/summaries', { method: 'POST', body: form });
    }
    return apiClient<Envelope<PaperSummaryItem>>('/research/summaries', { method: 'POST', body: JSON.stringify({ text: input.text, title: input.title || undefined, course_id: input.course_id || undefined }) });
  },
  deleteSummary: (id: number) => apiClient<{ message: string }>(`/research/summaries/${id}`, { method: 'DELETE' }),

  gradingQueue: (assessmentId: number | string, questionId?: number, status: 'all' | 'ungraded' | 'graded' = 'all') =>
    apiClient<Envelope<GradingQueue>>(`/assessments/${assessmentId}/grading-queue?status=${status}${questionId ? `&question_id=${questionId}` : ''}`).then((r) => r.data),

  saveGrade: (answerId: number, marks: number, feedback: string | null) =>
    apiClient<{ message: string }>(`/student-answers/${answerId}/finalize-grade`, { method: 'POST', body: JSON.stringify({ final_marks: marks, faculty_feedback: feedback }) }),
};
