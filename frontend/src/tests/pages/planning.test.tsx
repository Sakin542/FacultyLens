import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { CourseQualityTrend } from '@/pages/CourseQualityTrend';
import { CourseObe } from '@/pages/CourseObe';
import { Calendar } from '@/pages/Calendar';
import { Workload } from '@/pages/Workload';
import { Department } from '@/pages/Department';
import type { OfferingMetrics } from '@/types/planning';

vi.mock('@/services/planningService', () => ({
  planningService: {
    qualityTrend: vi.fn(), obe: vi.fn(), downloadObePack: vi.fn(), downloadArchive: vi.fn(), workload: vi.fn(), department: vi.fn(),
    events: vi.fn(), createEvent: vi.fn(), updateEvent: vi.fn(), deleteEvent: vi.fn(), downloadIcs: vi.fn(),
  },
}));
vi.mock('@/services/courseService', () => ({ courseService: { getAll: vi.fn().mockResolvedValue({ data: [{ id: 1, course_code: 'CSE311' }] }) } }));

import { planningService } from '@/services/planningService';
const svc = planningService as unknown as Record<string, ReturnType<typeof vi.fn>>;

const offering = (term: string, higher: number, coverage: number): OfferingMetrics => ({
  course: { id: term === 'Fall 2025' ? 1 : 2, course_code: 'CSE311', course_name: 'Database Systems', term },
  assessments: 1, questions: 4, total_marks: 40, bloom_percent: { Remember: 50, Analyze: higher }, higher_order_percent: higher, difficulty_percent: {},
  outcomes: 2, co_coverage_percent: coverage, ai_quality_score: 70, class_average_percent: 65, co_attainment: [], co_attained_percent: 50,
  mean_discrimination: 0.3, flagged_questions: 1, assessments_with_item_analysis: 1,
});

const at = (path: string, route: string, el: React.ReactElement) =>
  render(<MemoryRouter initialEntries={[path]}><Routes><Route path={route} element={el} /></Routes></MemoryRouter>);

describe('Planning pages', () => {
  beforeEach(() => vi.clearAllMocks());

  it('compares offerings across semesters with insights', async () => {
    svc.qualityTrend.mockResolvedValue({
      course_code: 'CSE311', course_name: 'Database Systems', message: null,
      offerings: [offering('Fall 2025', 10, 50), offering('Spring 2026', 40, 100)],
      insights: [{ metric: 'higher_order_percent', direction: 'improved', text: 'Higher-order questions rose from 10% to 40%.' }],
    });
    at('/courses/2/quality', '/courses/:courseId/quality', <CourseQualityTrend />);

    expect(await screen.findByTestId('trend-insights')).toHaveTextContent('rose from 10% to 40%');
    expect(screen.getByTestId('trend-higher-order')).toHaveTextContent('40%');
    expect(screen.getByTestId('trend-higher-order')).toHaveTextContent('▲ 30%');
  });

  it('shows CO/PO attainment and downloads the OBE pack', async () => {
    svc.obe.mockResolvedValue({
      course: { id: 2, course_code: 'CSE311', course_name: 'Database Systems', term: 'Spring 2026' }, co_target: 60, po_target: 2,
      co_attainment: [{ id: 1, code: 'CO1', description: 'Write SQL', average_percent: 72, level: 3, attained: true, assessed: true }, { id: 2, code: 'CO2', description: 'Normalize', average_percent: null, level: null, attained: false, assessed: false }],
      po_attainment: [{ id: 9, code: 'PO1', title: 'Knowledge', attainment: 2.75, attained: true, contributing_cos: ['CO1'] }],
      program_outcomes: [{ id: 9, code: 'PO1', title: 'Knowledge' }], matrix: [{ code: 'CO1', levels: { PO1: 3 } }, { code: 'CO2', levels: { PO1: 0 } }],
      assessments: [{ id: 5, title: 'Final', questions: 4, mapped: 3 }],
    });
    svc.downloadObePack.mockResolvedValue(undefined);
    at('/courses/2/obe', '/courses/:courseId/obe', <CourseObe />);

    expect(await screen.findByTestId('co-attainment')).toHaveTextContent('Not assessed yet');
    expect(screen.getByTestId('co-po-matrix')).toHaveTextContent('2.75');
    fireEvent.click(screen.getByTestId('download-obe-pack'));
    await waitFor(() => expect(svc.downloadObePack).toHaveBeenCalledWith('2'));
    expect(await screen.findByText('OBE evidence pack downloaded.')).toBeInTheDocument();
  });

  it('creates a calendar event with reminders', async () => {
    svc.events.mockResolvedValue([]);
    svc.createEvent.mockResolvedValue({});
    at('/calendar?date=2026-10-20', '/calendar', <Calendar />);

    expect(await screen.findByTestId('calendar-month')).toHaveTextContent('October 2026');
    fireEvent.click(screen.getByTestId('new-event'));
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Final exam' } });
    fireEvent.click(screen.getByTestId('save-event'));

    await waitFor(() => expect(svc.createEvent).toHaveBeenCalledWith(expect.objectContaining({
      title: 'Final exam', type: 'exam', starts_at: '2026-10-20 10:00:00', reminder_offsets: [1440, 60],
    })));
  });

  it('shows the workload estimate with its formula', async () => {
    svc.workload.mockResolvedValue({
      courses: [{ id: 1, course_code: 'CSE311', course_name: 'DB', term: 'Spring 2026', credits: 3, contact_hours_per_week: 3, contact_hours_is_estimate: true, assessments: 2, draft_assessments: 1, ungraded_answers: 30, submissions_awaiting_grading: 6 }],
      totals: { active_courses: 1, contact_hours_per_week: 3, ungraded_answers: 30, submissions_awaiting_grading: 6, draft_assessments: 1, upcoming_14d: 0 },
      estimate_hours_this_week: { teaching: 3, grading: 2, exam_preparation: 3, total: 8, formula: 'contact hours + ungraded answers × 4 min' },
      upcoming: [],
    });
    at('/workload', '/workload', <Workload />);
    expect(await screen.findByTestId('workload-estimate')).toHaveTextContent('8 h');
    expect(screen.getByTestId('workload-estimate')).toHaveTextContent('ungraded answers × 4 min');
  });

  it('lists department courses with attention flags', async () => {
    svc.department.mockResolvedValue({
      department: 'Computer Science & Engineering',
      summary: { faculty: 3, courses: 1, mean_higher_order_percent: 25, mean_co_coverage_percent: 50, mean_class_average_percent: 61, courses_needing_attention: 1 },
      courses: [{ ...offering('Spring 2026', 10, 50), instructor: { id: 1, name: 'Dr. Doe' }, flags: ['Some course outcomes are not assessed'] }],
      faculty: [{ id: 1, name: 'Dr. Doe', designation: 'Lecturer', role: 'FACULTY', active_courses: 2, contact_hours_per_week: 6, ungraded_answers: 10, estimated_hours_this_week: 7 }],
    });
    at('/department', '/department', <Department />);
    expect(await screen.findByTestId('department-courses')).toHaveTextContent('Some course outcomes are not assessed');
  });
});
