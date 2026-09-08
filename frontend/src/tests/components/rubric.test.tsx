import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { RubricStatusBadge } from '@/components/rubric/RubricStatusBadge';
import { RubricPreview } from '@/components/rubric/RubricPreview';
import { RubricEditor } from '@/components/rubric/RubricEditor';
import { RubricCriteriaEditor } from '@/components/rubric/RubricCriteriaEditor';
import { RubricVersionHistory } from '@/components/rubric/RubricVersionHistory';
import { RubricGenerationLoading } from '@/components/rubric/RubricGenerationLoading';
import { RubricError, getRubricErrorMessage } from '@/components/rubric/RubricError';
import { RubricGenerator } from '@/components/rubric/RubricGenerator';
import { criteriaTotal, toEditable, validateEditable } from '@/components/rubric/rubricMath';
import { ApiError } from '@/services/api';
import { Rubric } from '@/types/rubric';
import { QuestionDetail } from '@/types';

vi.mock('@/services/rubricService', () => ({
  rubricService: {
    generate: vi.fn(),
    listForQuestion: vi.fn(),
    getById: vi.fn(),
    update: vi.fn(),
    approve: vi.fn(),
    regenerate: vi.fn(),
    delete: vi.fn(),
  },
}));

import { rubricService } from '@/services/rubricService';

const mocked = rubricService as unknown as Record<keyof typeof rubricService, ReturnType<typeof vi.fn>>;

const question: QuestionDetail = {
  id: 15,
  question_number: 5,
  question_text: 'Explain database normalization.',
  marks: 10,
  question_type: 'descriptive',
  difficulty_level: 'medium',
  cognitive_level: 'Understand',
  learning_outcome: { id: 2, code: 'CO2', description: 'Explain fundamental database concepts.' },
};

const makeRubric = (overrides: Partial<Rubric> = {}): Rubric => ({
  id: 1,
  question_id: 15,
  assessment_id: 3,
  created_by: 1,
  title: 'Rubric: Database normalization',
  total_marks: 10,
  criteria_total: 10,
  status: 'DRAFT',
  version: 1,
  generation_method: 'template_based',
  is_ai_generated: true,
  ai_model: 'facultylens-rubric-template-engine',
  ai_model_version: '1.0.0',
  general_guidance: 'Award marks based on demonstrated understanding.',
  generated_at: '2026-09-08T10:00:00Z',
  approved_at: null,
  approved_by: null,
  criteria: [
    { id: 1, criterion: 'Definition of normalization', description: 'Defines normalization', max_marks: 2, scoring_guidance: 'Full marks for a clear definition', expected_indicators: ['reduces redundancy'], sort_order: 1 },
    { id: 2, criterion: 'Explanation of 1NF', description: 'Explains 1NF', max_marks: 2, scoring_guidance: null, expected_indicators: [], sort_order: 2 },
    { id: 3, criterion: 'Explanation of 2NF', description: 'Explains 2NF', max_marks: 2, scoring_guidance: null, expected_indicators: [], sort_order: 3 },
    { id: 4, criterion: 'Explanation of 3NF', description: 'Explains 3NF', max_marks: 2, scoring_guidance: null, expected_indicators: [], sort_order: 4 },
    { id: 5, criterion: 'Appropriate examples', description: 'Gives examples', max_marks: 2, scoring_guidance: null, expected_indicators: [], sort_order: 5 },
  ],
  ...overrides,
});

describe('Rubric math helpers', () => {
  it('computes criteria totals and validates against question marks', () => {
    const editable = toEditable(makeRubric().criteria);
    expect(criteriaTotal(editable)).toBe(10);
    expect(validateEditable(editable, 10, 'Title')).toEqual([]);

    editable[0].max_marks = '4';
    expect(criteriaTotal(editable)).toBe(12);
    const errors = validateEditable(editable, 10, 'Title');
    expect(errors.some((e) => e.includes('Question marks: 10') && e.includes('Rubric marks: 12'))).toBe(true);
  });

  it('flags missing names, descriptions, negative marks and empty title', () => {
    const editable = toEditable(makeRubric().criteria);
    editable[1].criterion = '';
    editable[2].description = '';
    editable[3].max_marks = '-1';
    const errors = validateEditable(editable, 10, '');
    expect(errors).toContain('Rubric title is required.');
    expect(errors).toContain('Criterion 2 needs a name.');
    expect(errors).toContain('Criterion 3 needs a description.');
    expect(errors).toContain('Criterion 4 cannot have negative marks.');
  });
});

describe('RubricStatusBadge', () => {
  it('renders each status distinctly', () => {
    const { rerender } = render(<RubricStatusBadge status="DRAFT" />);
    expect(screen.getByText('Draft')).toBeInTheDocument();
    rerender(<RubricStatusBadge status="APPROVED" />);
    expect(screen.getByText('Approved')).toBeInTheDocument();
    rerender(<RubricStatusBadge status="ARCHIVED" />);
    expect(screen.getByText('Archived')).toBeInTheDocument();
  });
});

describe('RubricGenerationLoading & RubricError', () => {
  it('shows generation message without fake progress', () => {
    render(<RubricGenerationLoading />);
    expect(screen.getByText(/generating rubric/i)).toBeInTheDocument();
    expect(screen.queryByText(/%/)).not.toBeInTheDocument();
  });

  it('maps API status codes to friendly messages', () => {
    expect(getRubricErrorMessage(new ApiError(503, 'x'))).toMatch(/temporarily unavailable/i);
    expect(getRubricErrorMessage(new ApiError(504, 'x'))).toMatch(/took too long/i);
    expect(getRubricErrorMessage(new ApiError(403, 'x'))).toMatch(/not authorized/i);
    expect(getRubricErrorMessage(new ApiError(429, 'x'))).toMatch(/too many/i);
    expect(getRubricErrorMessage(new ApiError(502, 'FacultyLens could not produce a valid rubric'))).toMatch(/valid rubric/i);
    expect(getRubricErrorMessage(new ApiError(500, 'Traceback /srv/app'))).not.toMatch(/srv/);
  });

  it('renders retry button and calls handler', () => {
    const onRetry = vi.fn();
    render(<RubricError error={new ApiError(503, 'x')} onRetry={onRetry} />);
    fireEvent.click(screen.getByRole('button', { name: /try again/i }));
    expect(onRetry).toHaveBeenCalled();
  });
});

describe('RubricPreview', () => {
  it('renders AI-generated label, draft status, criteria and total', () => {
    render(<RubricPreview rubric={makeRubric()} questionMarks={10} onApprove={vi.fn()} onEdit={vi.fn()} onRegenerate={vi.fn()} />);
    expect(screen.getByText(/ai-generated rubric/i, { selector: 'span' })).toBeInTheDocument();
    expect(screen.getByText(/draft — faculty review required/i)).toBeInTheDocument();
    expect(screen.getAllByTestId('rubric-criterion')).toHaveLength(5);
    expect(screen.getByTestId('rubric-total')).toHaveTextContent('10 / 10');
    expect(screen.getByText(/review and adjust before use/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /approve rubric/i })).toBeEnabled();
  });

  it('disables approval and shows mismatch when totals differ', () => {
    render(<RubricPreview rubric={makeRubric({ criteria_total: 12 })} questionMarks={10} onApprove={vi.fn()} />);
    expect(screen.getByRole('alert')).toHaveTextContent(/does not match/i);
    expect(screen.getByRole('button', { name: /approve rubric/i })).toBeDisabled();
  });

  it('hides actions for archived rubrics and hides approve for approved ones', () => {
    const { rerender } = render(<RubricPreview rubric={makeRubric({ status: 'ARCHIVED' })} questionMarks={10} onApprove={vi.fn()} onEdit={vi.fn()} />);
    expect(screen.queryByRole('button', { name: /approve rubric/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /edit rubric/i })).not.toBeInTheDocument();

    rerender(<RubricPreview rubric={makeRubric({ status: 'APPROVED', approved_at: '2026-09-08T12:00:00Z' })} questionMarks={10} onApprove={vi.fn()} onEdit={vi.fn()} />);
    expect(screen.getByText(/faculty-approved on/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /approve rubric/i })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /edit rubric/i })).toBeInTheDocument();
  });

  it('invokes callbacks', () => {
    const onEdit = vi.fn();
    const onApprove = vi.fn();
    const onRegenerate = vi.fn();
    render(<RubricPreview rubric={makeRubric()} questionMarks={10} onEdit={onEdit} onApprove={onApprove} onRegenerate={onRegenerate} />);
    fireEvent.click(screen.getByRole('button', { name: /edit rubric/i }));
    fireEvent.click(screen.getByRole('button', { name: /approve rubric/i }));
    fireEvent.click(screen.getByRole('button', { name: /regenerate/i }));
    expect(onEdit).toHaveBeenCalled();
    expect(onApprove).toHaveBeenCalled();
    expect(onRegenerate).toHaveBeenCalled();
  });
});

describe('RubricCriteriaEditor', () => {
  it('adds, removes and reorders criteria', () => {
    const onChange = vi.fn();
    const criteria = toEditable(makeRubric().criteria.slice(0, 2));
    render(<RubricCriteriaEditor criteria={criteria} onChange={onChange} />);

    fireEvent.click(screen.getByRole('button', { name: /add criterion/i }));
    expect(onChange).toHaveBeenLastCalledWith(expect.arrayContaining([expect.objectContaining({ criterion: '' })]));
    expect(onChange.mock.calls.at(-1)?.[0]).toHaveLength(3);

    fireEvent.click(screen.getByRole('button', { name: /remove criterion 1/i }));
    expect(onChange.mock.calls.at(-1)?.[0]).toHaveLength(1);
    expect(onChange.mock.calls.at(-1)?.[0][0].criterion).toBe('Explanation of 1NF');

    fireEvent.click(screen.getByRole('button', { name: /move criterion 2 up/i }));
    expect(onChange.mock.calls.at(-1)?.[0][0].criterion).toBe('Explanation of 1NF');
    expect(onChange.mock.calls.at(-1)?.[0][1].criterion).toBe('Definition of normalization');
  });

  it('edits marks and adds expected indicators', () => {
    const onChange = vi.fn();
    const criteria = toEditable(makeRubric().criteria.slice(0, 1));
    render(<RubricCriteriaEditor criteria={criteria} onChange={onChange} />);

    fireEvent.change(screen.getByLabelText(/^marks/i), { target: { value: '3.5' } });
    expect(onChange.mock.calls.at(-1)?.[0][0].max_marks).toBe('3.5');

    const indicatorInput = screen.getByLabelText(/new indicator for criterion 1/i);
    fireEvent.change(indicatorInput, { target: { value: 'improves organization' } });
    fireEvent.keyDown(indicatorInput, { key: 'Enter' });
    expect(onChange.mock.calls.at(-1)?.[0][0].expected_indicators).toEqual(['reduces redundancy', 'improves organization']);
  });
});

describe('RubricEditor', () => {
  it('recalculates total, blocks save on mismatch and saves when consistent', async () => {
    const onSave = vi.fn().mockResolvedValue(undefined);
    render(<RubricEditor rubric={makeRubric()} questionMarks={10} onSave={onSave} onCancel={vi.fn()} />);

    expect(screen.getByTestId('editor-total')).toHaveTextContent('10 / 10');
    const saveBtn = screen.getByRole('button', { name: /save draft/i });
    expect(saveBtn).toBeEnabled();

    const marksInputs = screen.getAllByLabelText(/^marks/i);
    fireEvent.change(marksInputs[0], { target: { value: '4' } });
    expect(screen.getByTestId('editor-total')).toHaveTextContent('12 / 10');
    expect(screen.getByTestId('marks-mismatch')).toHaveTextContent(/question marks: 10/i);
    expect(screen.getByTestId('marks-mismatch')).toHaveTextContent(/rubric marks: 12/i);
    expect(saveBtn).toBeDisabled();

    fireEvent.change(marksInputs[4], { target: { value: '0' } });
    expect(screen.getByTestId('editor-total')).toHaveTextContent('10 / 10');
    expect(saveBtn).toBeEnabled();

    fireEvent.change(screen.getByLabelText(/rubric title/i), { target: { value: 'Adjusted rubric' } });
    fireEvent.click(saveBtn);

    await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
    const payload = onSave.mock.calls[0][0];
    expect(payload.title).toBe('Adjusted rubric');
    expect(payload.criteria).toHaveLength(5);
    expect(payload.criteria[0].max_marks).toBe(4);
    expect(payload.criteria[4].max_marks).toBe(0);
    expect(payload.criteria.map((c: { sort_order: number }) => c.sort_order)).toEqual([1, 2, 3, 4, 5]);
  });

  it('calls onCancel', () => {
    const onCancel = vi.fn();
    render(<RubricEditor rubric={makeRubric()} questionMarks={10} onSave={vi.fn()} onCancel={onCancel} />);
    fireEvent.click(screen.getByRole('button', { name: /cancel/i }));
    expect(onCancel).toHaveBeenCalled();
  });
});

describe('RubricVersionHistory', () => {
  it('renders nothing for a single version and lists multiple versions', () => {
    const onSelect = vi.fn();
    const { rerender } = render(<RubricVersionHistory rubrics={[makeRubric()]} selectedId={1} onSelect={onSelect} />);
    expect(screen.queryByTestId('rubric-version-history')).not.toBeInTheDocument();

    const v2 = makeRubric({ id: 2, version: 2, status: 'APPROVED' });
    rerender(<RubricVersionHistory rubrics={[v2, makeRubric({ status: 'ARCHIVED' })]} selectedId={2} onSelect={onSelect} />);
    expect(screen.getByText('v2')).toBeInTheDocument();
    expect(screen.getByText('v1')).toBeInTheDocument();
    fireEvent.click(screen.getByText('v1'));
    expect(onSelect).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
  });
});

describe('RubricGenerator modal', () => {
  beforeEach(() => {
    Object.values(mocked).forEach((fn) => fn.mockReset());
  });

  it('shows empty state with Generate button, then loading, then preview on success', async () => {
    mocked.listForQuestion.mockResolvedValueOnce({ status: 'success', data: [], question: { id: 15, question_number: 5, marks: 10 } });
    let resolveGenerate: (v: unknown) => void = () => {};
    mocked.generate.mockImplementationOnce(() => new Promise((res) => { resolveGenerate = res; }));
    mocked.listForQuestion.mockResolvedValueOnce({ status: 'success', data: [makeRubric()], question: { id: 15, question_number: 5, marks: 10 } });

    render(<RubricGenerator isOpen onClose={vi.fn()} question={question} />);

    expect(screen.getByText('AI Rubric Generator')).toBeInTheDocument();
    expect(screen.getByText('Explain database normalization.')).toBeInTheDocument();
    expect(screen.getByText('LO: CO2')).toBeInTheDocument();

    const generateBtn = await screen.findByRole('button', { name: /generate ai rubric/i });
    fireEvent.click(generateBtn);

    expect(await screen.findByText(/generating rubric/i)).toBeInTheDocument();
    expect(mocked.generate).toHaveBeenCalledWith(15);

    resolveGenerate({ status: 'success', data: makeRubric() });

    expect(await screen.findByTestId('rubric-preview')).toBeInTheDocument();
    expect(screen.getByText(/draft rubric generated/i)).toBeInTheDocument();
    expect(screen.getAllByTestId('rubric-criterion')).toHaveLength(5);
  });

  it('shows friendly error when AI service is unavailable', async () => {
    mocked.listForQuestion.mockResolvedValue({ status: 'success', data: [], question: { id: 15, question_number: 5, marks: 10 } });
    mocked.generate.mockRejectedValueOnce(new ApiError(503, 'AI Service is currently unavailable'));

    render(<RubricGenerator isOpen onClose={vi.fn()} question={question} />);
    fireEvent.click(await screen.findByRole('button', { name: /generate ai rubric/i }));

    expect(await screen.findByRole('alert')).toHaveTextContent(/temporarily unavailable/i);
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument();
  });

  it('loads existing versions, supports edit -> save draft -> approve -> regenerate', async () => {
    const v1 = makeRubric();
    mocked.listForQuestion.mockResolvedValue({ status: 'success', data: [v1], question: { id: 15, question_number: 5, marks: 10 } });
    mocked.update.mockResolvedValue({ status: 'success', data: { ...v1, title: 'Edited' } });
    mocked.approve.mockResolvedValue({ status: 'success', data: { ...v1, status: 'APPROVED' } });

    render(<RubricGenerator isOpen onClose={vi.fn()} question={question} />);
    expect(await screen.findByTestId('rubric-preview')).toBeInTheDocument();

    // Edit
    fireEvent.click(screen.getByRole('button', { name: /edit rubric/i }));
    const editor = await screen.findByTestId('rubric-editor');
    fireEvent.change(within(editor).getByLabelText(/rubric title/i), { target: { value: 'Edited' } });
    fireEvent.click(within(editor).getByRole('button', { name: /save draft/i }));
    await waitFor(() => expect(mocked.update).toHaveBeenCalledWith(1, expect.objectContaining({ title: 'Edited' })));
    expect(await screen.findByText(/rubric draft saved/i)).toBeInTheDocument();

    // Approve
    mocked.listForQuestion.mockResolvedValue({ status: 'success', data: [{ ...v1, status: 'APPROVED' }], question: { id: 15, question_number: 5, marks: 10 } });
    fireEvent.click(screen.getByRole('button', { name: /approve rubric/i }));
    await waitFor(() => expect(mocked.approve).toHaveBeenCalledWith(1));
    expect(await screen.findByText(/rubric approved by faculty/i)).toBeInTheDocument();
    expect(screen.getByText('Approved')).toBeInTheDocument();

    // Regenerate creates v2 while keeping v1
    const v2 = makeRubric({ id: 2, version: 2 });
    mocked.regenerate.mockResolvedValue({ status: 'success', data: v2 });
    mocked.listForQuestion.mockResolvedValue({ status: 'success', data: [v2, { ...v1, status: 'APPROVED' }], question: { id: 15, question_number: 5, marks: 10 } });
    fireEvent.click(screen.getByRole('button', { name: /regenerate/i }));
    await waitFor(() => expect(mocked.regenerate).toHaveBeenCalledWith(1));
    const history = await screen.findByTestId('rubric-version-history');
    expect(within(history).getByText('v2')).toBeInTheDocument();
    expect(within(history).getByText('v1')).toBeInTheDocument();
    expect(screen.getByText(/previous versions were kept/i)).toBeInTheDocument();
  });

  it('renders nothing when closed', () => {
    const { container } = render(<RubricGenerator isOpen={false} onClose={vi.fn()} question={question} />);
    expect(container).toBeEmptyDOMElement();
    expect(mocked.listForQuestion).not.toHaveBeenCalled();
  });
});
