import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { SubmissionStatusBadge, GradingStatusBadge, AnswerStatusBadge } from '@/components/submissions/SubmissionStatusBadge';
import { SubmissionTable } from '@/components/submissions/SubmissionTable';
import { SubmissionFilters } from '@/components/submissions/SubmissionFilters';
import { SubmissionEmptyState } from '@/components/submissions/SubmissionEmptyState';
import { SubmissionCard } from '@/components/submissions/SubmissionCard';
import { StudentAnswerCard } from '@/components/submissions/StudentAnswerCard';
import { StudentAnswerList } from '@/components/submissions/StudentAnswerList';
import { AnswerUpload, validateAnswerFile } from '@/components/submissions/AnswerUpload';
import { StudentSubmissions } from '@/pages/StudentSubmissions';
import { SubmissionDetails } from '@/pages/SubmissionDetails';
import { ApiError } from '@/services/api';
import { StudentSubmission, StudentSubmissionDetail, SubmissionQuestion } from '@/types/submission';

vi.mock('@/services/studentSubmissionService', () => ({
  studentSubmissionService: {
    getSubmissions: vi.fn(),
    getSummary: vi.fn(),
    getSubmission: vi.fn(),
    createSubmission: vi.fn(),
    updateSubmissionStatus: vi.fn(),
    deleteSubmission: vi.fn(),
    addAnswer: vi.fn(),
    uploadAnswer: vi.fn(),
    updateAnswer: vi.fn(),
    deleteAnswer: vi.fn(),
    downloadAnswerFile: vi.fn(),
    importCsv: vi.fn(),
  },
  studentService: { getAll: vi.fn(), create: vi.fn(), update: vi.fn(), delete: vi.fn() },
}));
vi.mock('@/services/assessmentService', () => ({
  assessmentService: { getById: vi.fn() },
}));

import { studentSubmissionService, studentService } from '@/services/studentSubmissionService';
import { assessmentService } from '@/services/assessmentService';

const svc = studentSubmissionService as unknown as Record<string, ReturnType<typeof vi.fn>>;
const stuSvc = studentService as unknown as Record<string, ReturnType<typeof vi.fn>>;
const assessSvc = assessmentService as unknown as Record<string, ReturnType<typeof vi.fn>>;

const submission = (o: Partial<StudentSubmission> = {}): StudentSubmission => ({
  id: 1,
  assessment_id: 7,
  student_id: 3,
  submission_identifier: 'MID-001',
  submitted_at: '2026-09-08T10:30:00Z',
  status: 'SUBMITTED',
  grading_status: 'NOT_STARTED',
  total_marks: 30,
  awarded_marks: null,
  answers_count: 2,
  reviewed_answers_count: 0,
  student: { id: 3, student_identifier: 'STU001', name: 'Student One', section: 'A' },
  ...o,
});

const question = (o: Partial<SubmissionQuestion> = {}): SubmissionQuestion => ({
  id: 11,
  question_number: 1,
  question_text: 'Explain database normalization.',
  question_type: 'descriptive',
  marks: 10,
  approved_rubric: null,
  answer: null,
  ...o,
});

const detail = (o: Partial<StudentSubmissionDetail> = {}): StudentSubmissionDetail => ({
  id: 1,
  assessment_id: 7,
  student_id: 3,
  submission_identifier: 'MID-001',
  submitted_at: '2026-09-08T10:30:00Z',
  status: 'SUBMITTED',
  grading_status: 'NOT_STARTED',
  total_marks: 30,
  awarded_marks: null,
  allowed_transitions: ['UNDER_REVIEW', 'DRAFT'],
  student: { id: 3, student_identifier: 'STU001', name: 'Student One', section: 'A' },
  assessment: { id: 7, title: 'Midterm Examination', type: 'midterm', total_marks: 30, course: { id: 1, course_code: 'CSE101', course_name: 'Database Systems' } },
  questions: [
    question({ id: 11, question_number: 1, marks: 5, question_text: 'Define a primary key.', answer: { id: 100, student_submission_id: 1, question_id: 11, answer_type: 'TEXT', answer_text: 'A unique identifier for a row.', is_faculty_edited: false, has_file: false, awarded_marks: null, answer_status: 'NOT_REVIEWED' } }),
    question({ id: 12, question_number: 2, marks: 10, approved_rubric: { id: 5, title: 'R', status: 'APPROVED', version: 2 } }),
    question({ id: 13, question_number: 3, marks: 15, question_text: 'Design an ER diagram.' }),
  ],
  answers_count: 1,
  ...o,
});

const renderAt = (path: string, ui: React.ReactElement, routePath: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path={routePath} element={ui} />
        <Route path="*" element={<div>other page</div>} />
      </Routes>
    </MemoryRouter>
  );

describe('Submission status badges', () => {
  it('render human labels', () => {
    render(<><SubmissionStatusBadge status="UNDER_REVIEW" /><GradingStatusBadge status="NOT_STARTED" /><AnswerStatusBadge status="REVIEWED" /></>);
    expect(screen.getByText('Under Review')).toBeInTheDocument();
    expect(screen.getByText('Not Started')).toBeInTheDocument();
    expect(screen.getByText('Reviewed')).toBeInTheDocument();
  });
});

describe('SubmissionTable', () => {
  it('renders rows with student, status, grading and marks; paginates', () => {
    const onPage = vi.fn();
    render(
      <MemoryRouter>
        <SubmissionTable submissions={[submission(), submission({ id: 2, awarded_marks: 21, status: 'GRADED', grading_status: 'FACULTY_REVIEWED', student: { id: 4, student_identifier: 'STU002', name: 'Student Two' } })]} meta={{ current_page: 1, last_page: 3, per_page: 20, total: 48 }} onPageChange={onPage} />
      </MemoryRouter>
    );
    expect(screen.getAllByTestId('submission-row')).toHaveLength(2);
    const table = screen.getByTestId('submission-table');
    expect(within(table).getAllByText('Student One')[0]).toBeInTheDocument();
    expect(within(table).getAllByText('MID-001')[0]).toBeInTheDocument();
    expect(within(table).getAllByText('Not graded / 30')[0]).toBeInTheDocument();
    expect(within(table).getAllByText('21 / 30')[0]).toBeInTheDocument();
    expect(screen.getByTestId('submission-pagination')).toHaveTextContent('Page 1 of 3 (48 total)');
    expect(screen.getByRole('button', { name: /previous/i })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: /next/i }));
    expect(onPage).toHaveBeenCalledWith(2);
    expect(screen.getAllByRole('link', { name: /view/i })[0]).toHaveAttribute('href', '/submissions/1');
  });

  it('renders mobile cards and hides pagination for a single page', () => {
    render(<MemoryRouter><SubmissionTable submissions={[submission()]} meta={{ current_page: 1, last_page: 1, per_page: 20, total: 1 }} onPageChange={vi.fn()} /></MemoryRouter>);
    expect(screen.getAllByTestId('submission-card')).toHaveLength(1);
    expect(screen.queryByTestId('submission-pagination')).not.toBeInTheDocument();
  });
});

describe('SubmissionFilters & EmptyState', () => {
  it('emits filter changes and clear', () => {
    const onChange = vi.fn();
    const onClear = vi.fn();
    render(<SubmissionFilters filters={{ status: 'SUBMITTED', search: '' }} onChange={onChange} onClear={onClear} />);
    fireEvent.change(screen.getByLabelText(/filter by status/i), { target: { value: 'GRADED' } });
    expect(onChange).toHaveBeenCalledWith({ status: 'GRADED', page: 1 });
    fireEvent.change(screen.getByLabelText(/filter by grading status/i), { target: { value: 'IN_PROGRESS' } });
    expect(onChange).toHaveBeenCalledWith({ grading_status: 'IN_PROGRESS', page: 1 });
    fireEvent.change(screen.getByLabelText(/search submissions/i), { target: { value: 'STU0' } });
    expect(onChange).toHaveBeenCalledWith({ search: 'STU0', page: 1 });
    fireEvent.click(screen.getByRole('button', { name: /clear/i }));
    expect(onClear).toHaveBeenCalled();
  });

  it('shows contextual empty states', () => {
    const onAdd = vi.fn();
    const { rerender } = render(<SubmissionEmptyState onAdd={onAdd} />);
    expect(screen.getByText(/no student submissions yet/i)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /add submission/i }));
    expect(onAdd).toHaveBeenCalled();
    rerender(<SubmissionEmptyState hasFilters onClearFilters={vi.fn()} />);
    expect(screen.getByText(/no submissions match/i)).toBeInTheDocument();
  });
});

describe('SubmissionCard (assessment page summary)', () => {
  it('shows real counts from stats and links to submissions', () => {
    render(
      <MemoryRouter>
        <SubmissionCard assessmentId={7} stats={{ total_submissions: 48, by_status: { DRAFT: 0, SUBMITTED: 48, UNDER_REVIEW: 0, GRADED: 0, RETURNED: 0 }, by_grading_status: { NOT_STARTED: 40, IN_PROGRESS: 8, AI_ASSISTED: 0, FACULTY_REVIEWED: 0, FINALIZED: 0 }, total_answers: 48, answers_by_status: { NOT_REVIEWED: 31, UNDER_REVIEW: 10, REVIEWED: 7 }, questions_count: 3 }} onImport={vi.fn()} />
      </MemoryRouter>
    );
    expect(screen.getByText('48')).toBeInTheDocument();
    expect(screen.getByText('31')).toBeInTheDocument();
    expect(screen.getByText('10')).toBeInTheDocument();
    expect(screen.getByText('7')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /view submissions/i })).toHaveAttribute('href', '/assessments/7/submissions');
    expect(screen.getByRole('button', { name: /import answers/i })).toBeInTheDocument();
  });

  it('shows loading skeleton and error', () => {
    const { rerender } = render(<MemoryRouter><SubmissionCard assessmentId={7} stats={null} isLoading /></MemoryRouter>);
    expect(screen.queryByText('48')).not.toBeInTheDocument();
    rerender(<MemoryRouter><SubmissionCard assessmentId={7} stats={null} error="Could not load" /></MemoryRouter>);
    expect(screen.getByRole('alert')).toHaveTextContent('Could not load');
  });
});

describe('AnswerUpload', () => {
  it('validates file type and size', () => {
    expect(validateAnswerFile(new File(['x'], 'a.pdf'))).toBeNull();
    expect(validateAnswerFile(new File(['x'], 'a.exe'))).toMatch(/unsupported/i);
    const big = new File([new Uint8Array(11 * 1024 * 1024)], 'big.pdf');
    expect(validateAnswerFile(big)).toMatch(/10MB/);
  });

  it('reports errors and accepts valid files', () => {
    const onChange = vi.fn();
    const onError = vi.fn();
    render(<AnswerUpload file={null} onChange={onChange} onError={onError} />);
    const input = screen.getByTestId('answer-file-input') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(['x'], 'bad.exe')] } });
    expect(onError).toHaveBeenCalledWith(expect.stringMatching(/unsupported/i));
    fireEvent.change(input, { target: { files: [new File(['x'], 'ok.png')] } });
    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ name: 'ok.png' }));
  });
});

describe('StudentAnswerCard', () => {
  it('shows "Not graded", rubric availability and no AI score', () => {
    const q = detail().questions[0];
    render(<StudentAnswerCard question={{ ...q, approved_rubric: { id: 5, title: 'R', status: 'APPROVED', version: 1 } }} onUpdate={vi.fn()} />);
    expect(screen.getByTestId('answer-text')).toHaveTextContent('A unique identifier for a row.');
    expect(screen.getByTestId('answer-marks')).toHaveTextContent('Not graded');
    expect(screen.getByTestId('rubric-available')).toHaveTextContent(/approved/i);
    expect(screen.queryByText(/ai score|ai suggested|confidence/i)).not.toBeInTheDocument();
  });

  it('shows Add Answer for unanswered questions and submits text answer', async () => {
    const onAdd = vi.fn().mockResolvedValue(undefined);
    render(<StudentAnswerCard question={question()} onAdd={onAdd} />);
    expect(screen.getByTestId('no-answer')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /add answer/i }));
    fireEvent.change(screen.getByLabelText(/^student answer/i), { target: { value: 'Normalization organizes data.' } });
    fireEvent.click(screen.getByRole('button', { name: /save answer/i }));
    await waitFor(() => expect(onAdd).toHaveBeenCalledWith(11, expect.objectContaining({ answer_text: 'Normalization organizes data.', awarded_marks: null }), null));
  });

  it('blocks empty answers and marks above the question maximum', async () => {
    const onAdd = vi.fn();
    render(<StudentAnswerCard question={question({ marks: 10 })} onAdd={onAdd} />);
    fireEvent.click(screen.getByRole('button', { name: /add answer/i }));
    fireEvent.click(screen.getByRole('button', { name: /save answer/i }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/answer text or an answer file/i);

    fireEvent.change(screen.getByLabelText(/^student answer/i), { target: { value: 'x' } });
    fireEvent.change(screen.getByLabelText(/marks \(max 10\)/i), { target: { value: '15' } });
    fireEvent.click(screen.getByRole('button', { name: /save answer/i }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/cannot exceed 10/i);
    expect(onAdd).not.toHaveBeenCalled();
  });

  it('edits an existing answer and shows faculty-edited marker with original', async () => {
    const onUpdate = vi.fn().mockResolvedValue(undefined);
    const q = detail().questions[0];
    const { rerender } = render(<StudentAnswerCard question={q} onUpdate={onUpdate} />);
    fireEvent.click(screen.getByRole('button', { name: /edit answer/i }));
    fireEvent.change(screen.getByLabelText(/^student answer/i), { target: { value: 'A unique identifier for a row (corrected).' } });
    fireEvent.change(screen.getByLabelText(/marks \(max 5\)/i), { target: { value: '4' } });
    fireEvent.change(screen.getByLabelText(/faculty feedback/i), { target: { value: 'Good.' } });
    fireEvent.click(screen.getByRole('button', { name: /save changes/i }));
    await waitFor(() => expect(onUpdate).toHaveBeenCalledWith(expect.objectContaining({ id: 100 }), expect.objectContaining({ answer_text: 'A unique identifier for a row (corrected).', awarded_marks: 4, faculty_feedback: 'Good.' }), null));

    rerender(<StudentAnswerCard question={{ ...q, answer: { ...q.answer!, answer_text: 'corrected', original_answer_text: 'original', is_faculty_edited: true, awarded_marks: 4 } }} onUpdate={onUpdate} />);
    expect(screen.getByTestId('faculty-edited')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /show original answer/i }));
    expect(screen.getByTestId('original-answer-text')).toHaveTextContent('original');
    expect(screen.getByTestId('answer-marks')).toHaveTextContent('4 / 5');
  });

  it('downloads and deletes file answers', async () => {
    const onDownload = vi.fn().mockResolvedValue(undefined);
    const onDelete = vi.fn().mockResolvedValue(undefined);
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const q = question({ answer: { id: 9, student_submission_id: 1, question_id: 11, answer_type: 'FILE', answer_text: null, is_faculty_edited: false, has_file: true, answer_file_name: 'scan.pdf', answer_file_size: 2048, awarded_marks: null, answer_status: 'NOT_REVIEWED' } });
    render(<StudentAnswerCard question={q} onDownload={onDownload} onDelete={onDelete} />);
    expect(screen.getByText('scan.pdf')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /download/i }));
    expect(onDownload).toHaveBeenCalledWith(expect.objectContaining({ id: 9 }));
    fireEvent.click(screen.getByRole('button', { name: /delete/i }));
    await waitFor(() => expect(onDelete).toHaveBeenCalled());
  });

  it('hides controls when read-only', () => {
    render(<StudentAnswerList questions={detail().questions} readOnly onAdd={vi.fn()} onUpdate={vi.fn()} />);
    expect(screen.queryByRole('button', { name: /edit answer/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /add answer/i })).not.toBeInTheDocument();
    expect(screen.getAllByTestId('student-answer-card')).toHaveLength(3);
  });
});

describe('StudentSubmissions page', () => {
  beforeEach(() => {
    Object.values(svc).forEach((f) => f.mockReset());
    Object.values(stuSvc).forEach((f) => f.mockReset());
    Object.values(assessSvc).forEach((f) => f.mockReset());
    assessSvc.getById.mockResolvedValue({ data: { id: 7, title: 'Midterm Examination', type: 'midterm', status: 'draft', course: { id: 1, course_code: 'CSE101', course_name: 'Database Systems' } } });
  });

  it('shows loading then a table with pagination and filters', async () => {
    svc.getSubmissions.mockResolvedValue({ status: 'success', data: [submission(), submission({ id: 2 })], meta: { current_page: 1, last_page: 2, per_page: 20, total: 25 } });
    renderAt('/assessments/7/submissions', <StudentSubmissions />, '/assessments/:id/submissions');
    expect(screen.getByRole('status')).toBeInTheDocument();
    expect(await screen.findByTestId('submission-table')).toBeInTheDocument();
    expect(screen.getAllByTestId('submission-row')).toHaveLength(2);
    expect(svc.getSubmissions).toHaveBeenCalledWith('7', expect.objectContaining({ page: 1, per_page: 20 }));

    fireEvent.change(screen.getByLabelText(/filter by status/i), { target: { value: 'GRADED' } });
    await waitFor(() => expect(svc.getSubmissions).toHaveBeenLastCalledWith('7', expect.objectContaining({ status: 'GRADED', page: 1 })));

    fireEvent.click(screen.getByRole('button', { name: /next/i }));
    await waitFor(() => expect(svc.getSubmissions).toHaveBeenLastCalledWith('7', expect.objectContaining({ page: 2 })));
  });

  it('shows empty state', async () => {
    svc.getSubmissions.mockResolvedValue({ status: 'success', data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } });
    renderAt('/assessments/7/submissions', <StudentSubmissions />, '/assessments/:id/submissions');
    expect(await screen.findByText(/no student submissions yet/i)).toBeInTheDocument();
  });

  it('shows authorization error', async () => {
    svc.getSubmissions.mockRejectedValue(new ApiError(403, 'Unauthorized'));
    renderAt('/assessments/7/submissions', <StudentSubmissions />, '/assessments/:id/submissions');
    expect(await screen.findByRole('alert')).toHaveTextContent(/not authorized/i);
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument();
  });

  it('opens the create modal and creates a submission for a registered student', async () => {
    svc.getSubmissions.mockResolvedValue({ status: 'success', data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } });
    stuSvc.getAll.mockResolvedValue({ status: 'success', data: [{ id: 3, student_identifier: 'STU001', name: 'Student One' }], meta: { current_page: 1, last_page: 1, per_page: 200, total: 1 } });
    svc.createSubmission.mockResolvedValue({ status: 'success', data: submission() });
    renderAt('/assessments/7/submissions', <StudentSubmissions />, '/assessments/:id/submissions');
    await screen.findByText(/no student submissions yet/i);

    fireEvent.click(screen.getAllByRole('button', { name: /add submission/i })[0]);
    const dialog = await screen.findByRole('dialog');
    await within(dialog).findByRole('combobox', { name: /^student$/i });
    fireEvent.change(within(dialog).getByLabelText(/submission id/i), { target: { value: 'MID-001' } });
    fireEvent.click(within(dialog).getByRole('button', { name: /create submission/i }));

    await waitFor(() => expect(svc.createSubmission).toHaveBeenCalledWith('7', expect.objectContaining({ student_id: 3, submission_identifier: 'MID-001', status: 'SUBMITTED' })));
    expect(await screen.findByText(/submission created for STU001/i)).toBeInTheDocument();
  });

  it('imports a CSV and surfaces row errors', async () => {
    svc.getSubmissions.mockResolvedValue({ status: 'success', data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } });
    svc.importCsv.mockRejectedValueOnce(new ApiError(422, 'The CSV contains invalid rows.', { errors: ['Row 2: student STU999 is not registered under your account.'] }));
    renderAt('/assessments/7/submissions', <StudentSubmissions />, '/assessments/:id/submissions');
    await screen.findByText(/no student submissions yet/i);

    fireEvent.click(screen.getByRole('button', { name: /^import answers$/i }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.change(within(dialog).getByTestId('csv-input'), { target: { files: [new File(['a,b,c'], 'answers.csv')] } });
    fireEvent.click(within(dialog).getByRole('button', { name: /import answers/i }));
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(/STU999/);
  });
});

describe('SubmissionDetails page', () => {
  beforeEach(() => {
    Object.values(svc).forEach((f) => f.mockReset());
  });

  it('renders student, assessment, status, answers and allowed transitions', async () => {
    svc.getSubmission.mockResolvedValue({ status: 'success', data: detail() });
    renderAt('/submissions/1', <SubmissionDetails />, '/submissions/:id');
    expect(screen.getByRole('status')).toBeInTheDocument();
    expect(await screen.findByTestId('student-name')).toHaveTextContent('Student One');
    expect(screen.getByText(/CSE101 — Database Systems · Midterm Examination/)).toBeInTheDocument();
    expect(screen.getByTestId('submission-status-badge')).toHaveTextContent('Submitted');
    expect(screen.getByTestId('grading-status-badge')).toHaveTextContent('Not Started');
    expect(screen.getByTestId('submission-marks')).toHaveTextContent('Not graded');
    expect(screen.getAllByTestId('student-answer-card')).toHaveLength(3);
    expect(screen.getByTestId('rubric-available')).toBeInTheDocument();
    expect(screen.getByTestId('transition-UNDER_REVIEW')).toBeInTheDocument();
    expect(screen.queryByTestId('transition-GRADED')).not.toBeInTheDocument();
  });

  it('changes status through allowed transitions', async () => {
    svc.getSubmission
      .mockResolvedValueOnce({ status: 'success', data: detail() })
      .mockResolvedValueOnce({ status: 'success', data: detail({ status: 'UNDER_REVIEW', grading_status: 'IN_PROGRESS', allowed_transitions: ['GRADED', 'SUBMITTED'] }) });
    svc.updateSubmissionStatus.mockResolvedValue({ status: 'success', data: submission({ status: 'UNDER_REVIEW' }) });
    renderAt('/submissions/1', <SubmissionDetails />, '/submissions/:id');
    fireEvent.click(await screen.findByTestId('transition-UNDER_REVIEW'));
    await waitFor(() => expect(svc.updateSubmissionStatus).toHaveBeenCalledWith(1, 'UNDER_REVIEW'));
    expect(await screen.findByTestId('transition-GRADED')).toBeInTheDocument();
    expect(screen.getByText(/marked as under review/i)).toBeInTheDocument();
  });

  it('adds an answer with a file via uploadAnswer', async () => {
    svc.getSubmission.mockResolvedValue({ status: 'success', data: detail() });
    svc.uploadAnswer.mockResolvedValue({ status: 'success', data: {} });
    renderAt('/submissions/1', <SubmissionDetails />, '/submissions/:id');
    await screen.findByTestId('student-name');

    const cards = screen.getAllByTestId('student-answer-card');
    fireEvent.click(within(cards[2]).getByRole('button', { name: /add answer/i }));
    fireEvent.change(within(cards[2]).getByTestId('answer-file-input'), { target: { files: [new File(['%PDF'], 'er.pdf')] } });
    fireEvent.click(within(cards[2]).getByRole('button', { name: /save answer/i }));
    await waitFor(() => expect(svc.uploadAnswer).toHaveBeenCalledWith(1, 13, expect.objectContaining({ name: 'er.pdf' }), expect.any(Object)));
  });

  it('shows authorization and not-found errors', async () => {
    svc.getSubmission.mockRejectedValueOnce(new ApiError(403, 'x'));
    const { unmount } = renderAt('/submissions/1', <SubmissionDetails />, '/submissions/:id');
    expect(await screen.findByRole('alert')).toHaveTextContent(/not authorized/i);
    unmount();
    svc.getSubmission.mockRejectedValueOnce(new ApiError(404, 'x'));
    renderAt('/submissions/2', <SubmissionDetails />, '/submissions/:id');
    expect(await screen.findByRole('alert')).toHaveTextContent(/could not be found/i);
  });
});
