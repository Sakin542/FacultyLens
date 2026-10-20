import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { CourseDesign } from '@/pages/CourseDesign';
import { Research } from '@/pages/Research';
import { MobileGrading } from '@/pages/MobileGrading';
import type { CoursePlan, GradingQueue, SyllabusContent } from '@/types/courseDesign';

vi.mock('@/services/courseDesignService', () => ({
  courseDesignService: {
    plans: vi.fn(), draftSyllabus: vi.fn(), applySyllabus: vi.fn(), lessonPlan: vi.fn(), schedule: vi.fn(), exportPlan: vi.fn(),
    summaries: vi.fn(), summary: vi.fn(), summarize: vi.fn(), deleteSummary: vi.fn(), gradingQueue: vi.fn(), saveGrade: vi.fn(),
  },
}));
vi.mock('@/services/courseService', () => ({ courseService: { getById: vi.fn().mockResolvedValue({ data: { id: 1, course_code: 'CSE311', course_name: 'Database Systems', description: 'SQL, normalization' } }) } }));
vi.mock('@/services/examService', () => ({ examService: { comments: vi.fn().mockResolvedValue([]), useComment: vi.fn(), createComment: vi.fn() } }));

import { courseDesignService } from '@/services/courseDesignService';
const svc = courseDesignService as unknown as Record<string, ReturnType<typeof vi.fn>>;

const syllabus: CoursePlan<SyllabusContent> = {
  id: 7, course_id: 1, kind: 'syllabus', inputs: {}, creator: null, applied_at: null, created_at: null,
  content: {
    topics: ['SQL'], co_po_method: 'embedding', engine: 'template', model: null, warnings: [], program_outcome_source: 'course_program',
    existing_outcomes: [{ code: 'CO1', description: 'Old', cognitive_level: 'Remember' }],
    outcomes: [
      { code: 'CO1', statement: 'Explain SQL.', cognitive_level: 'Understand', topics: ['SQL'] },
      { code: 'CO2', statement: 'Apply normalization.', cognitive_level: 'Apply', topics: ['Normalization'] },
    ],
    co_po: [{ co: 'CO1', po: 'PO1', level: 3, similarity: 0.6, justification: 'Semantic match 0.60' }, { co: 'CO2', po: 'PO3', level: 2, similarity: 0.5, justification: 'Semantic match 0.50' }],
    weekly_outline: [{ week: 1, topic: 'SQL', outcomes: ['CO1'], kind: 'teaching' }],
    assessment_plan: [{ component: 'Final examination', weight: 100, outcomes: ['CO1', 'CO2'] }],
  },
};

describe('Phase 4 pages', () => {
  beforeEach(() => vi.clearAllMocks());

  it('drafts a syllabus, lets faculty edit it, and applies only the selected parts', async () => {
    svc.plans.mockResolvedValue([]);
    svc.draftSyllabus.mockResolvedValue({ status: 'success', message: 'Syllabus draft ready.', data: syllabus });
    svc.applySyllabus.mockResolvedValue({ status: 'success', message: 'Saved: 1 new outcome(s).', data: {} });
    render(<MemoryRouter initialEntries={['/courses/1/design']}><Routes><Route path="/courses/:courseId/design" element={<CourseDesign />} /></Routes></MemoryRouter>);

    await waitFor(() => expect(screen.getByTestId('syllabus-description')).toHaveValue('SQL, normalization'));
    fireEvent.click(screen.getByTestId('draft-syllabus'));
    const outcomes = await screen.findByTestId('draft-outcomes');
    expect(outcomes).toHaveTextContent('Already exists: kept as is');

    fireEvent.change(within(outcomes).getByLabelText('CO2 statement'), { target: { value: 'Apply normalization to real schemas.' } });
    fireEvent.click(within(screen.getByTestId('draft-links')).getByLabelText('Use CO1 to PO1'));
    fireEvent.click(screen.getByTestId('apply-syllabus'));

    await waitFor(() => expect(svc.applySyllabus).toHaveBeenCalledWith('1', 7, {
      outcomes: [{ code: 'CO1', statement: 'Explain SQL.', cognitive_level: 'Understand' }, { code: 'CO2', statement: 'Apply normalization to real schemas.', cognitive_level: 'Apply' }],
      links: [{ co: 'CO2', po: 'PO3', level: 2 }],
      update_existing: false,
    }));
  });

  it('summarizes pasted text and shows citation and findings', async () => {
    svc.summaries.mockResolvedValue([]);
    svc.summarize.mockResolvedValue({ status: 'success', data: { id: 3, title: 'Attention Grading', file_name: null, word_count: 900, course: null, authors: ['Jane Smith'], year: 2025, created_at: null,
      summary: { title: 'Attention Grading', authors: ['Jane Smith'], year: 2025, doi: null, keywords: ['grading'], summary: ['We propose a model.'], key_findings: ['It reaches 91% agreement.'], methods: [], limitations: [], sections_found: ['abstract'], references: [{ index: 1, text: 'A. Brown, Keyword grading, 2015.', year: 2015, cited_in_text: 2 }], citation: { apa: 'Smith, J. (2025). Attention Grading.', ieee: 'J. Smith, "Attention Grading," 2025.' }, stats: { words: 900, sentences: 40 }, engine: 'embedding' } } });
    render(<MemoryRouter><Research /></MemoryRouter>);

    fireEvent.click(screen.getByRole('tab', { name: 'Paste text' }));
    fireEvent.change(screen.getByTestId('paper-text'), { target: { value: 'word '.repeat(120) } });
    fireEvent.click(screen.getByTestId('summarize-submit'));

    expect(await screen.findByTestId('paper-summary')).toHaveTextContent('We propose a model.');
    expect(screen.getByTestId('paper-citation')).toHaveTextContent('Smith, J. (2025). Attention Grading.');
    expect(screen.getByText('It reaches 91% agreement.')).toBeInTheDocument();
    expect(screen.getByText('cited 2×')).toBeInTheDocument();
  });

  it('grades one answer at a time with quick marks and moves on', async () => {
    const queue = (answers: GradingQueue['answers']): GradingQueue => ({
      assessment: { id: 4, title: 'Quiz', course_code: 'CSE311' },
      questions: [{ id: 10, question_number: 1, marks: 5, total: 2, ungraded: answers.length }],
      question: { id: 10, question_number: 1, question_text: 'Explain joins.', marks: 5, expected_answer: 'Combine rows', rubric: [] },
      answers,
    });
    const a = (id: number) => ({ id, student: { name: `Student ${id}`, student_identifier: `S${id}` }, answer_text: `Answer ${id}`, has_file: false, awarded_marks: null, faculty_feedback: null, ai_suggested_marks: id === 1 ? 4 : null, submission_status: 'SUBMITTED' });
    svc.gradingQueue.mockResolvedValueOnce(queue([a(1), a(2)])).mockResolvedValueOnce(queue([a(2)]));
    svc.saveGrade.mockResolvedValue({ message: 'Final marks saved.' });
    render(<MemoryRouter initialEntries={['/assessments/4/grade']}><Routes><Route path="/assessments/:assessmentId/grade" element={<MobileGrading />} /></Routes></MemoryRouter>);

    const card = await screen.findByTestId('grading-card');
    expect(card).toHaveTextContent('Answer 1');
    fireEvent.click(within(card).getByRole('button', { name: /AI suggests 4/ }));
    expect(screen.getByTestId('marks-value')).toHaveTextContent('4');
    fireEvent.click(within(card).getByRole('button', { name: 'Half a mark less' }));
    fireEvent.click(screen.getByTestId('save-next'));

    await waitFor(() => expect(svc.saveGrade).toHaveBeenCalledWith(1, 3.5, null));
    expect(await screen.findByText('Answer 2')).toBeInTheDocument();
  });
});
