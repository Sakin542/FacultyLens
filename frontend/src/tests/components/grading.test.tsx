import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within, act } from '@testing-library/react';
import { AIGradingButton } from '@/components/grading/AIGradingButton';
import { AIGradingLoading } from '@/components/grading/AIGradingLoading';
import { AIGradingError, getGradingErrorMessage } from '@/components/grading/AIGradingError';
import { AIGradingResult } from '@/components/grading/AIGradingResult';
import { AIGradingPanel } from '@/components/grading/AIGradingPanel';
import { CriterionGradingBreakdown } from '@/components/grading/CriterionGradingBreakdown';
import { SuggestedMarksCard } from '@/components/grading/SuggestedMarksCard';
import { FacultyGradeEditor } from '@/components/grading/FacultyGradeEditor';
import { FinalGradeForm, validateFinalMarks } from '@/components/grading/FinalGradeForm';
import { GradingStatusBadge, formatAIGradingStatus } from '@/components/grading/GradingStatusBadge';
import { GradingDisclaimer } from '@/components/grading/GradingDisclaimer';
import { ApiError } from '@/services/api';
import { AIGradingResult as AIGradingResultType } from '@/types/grading';
import { StudentAnswer, SubmissionQuestion } from '@/types/submission';

vi.mock('@/services/aiGradingService', () => ({
  aiGradingService: {
    requestAIGrading: vi.fn(),
    getAIGradingResult: vi.fn(),
    getAIGradingHistory: vi.fn(),
    regenerateAIGrading: vi.fn(),
    rejectAIGrading: vi.fn(),
    finalizeGrade: vi.fn(),
    updateFinalGrade: vi.fn(),
  },
}));

import { aiGradingService } from '@/services/aiGradingService';

const svc = aiGradingService as unknown as Record<string, ReturnType<typeof vi.fn>>;

const question = (o: Partial<SubmissionQuestion> = {}): SubmissionQuestion => ({
  id: 5,
  question_number: 5,
  question_text: 'Explain database normalization.',
  question_type: 'descriptive',
  marks: 10,
  approved_rubric: { id: 20, title: 'Normalization rubric', status: 'APPROVED', version: 1 },
  answer: null,
  ...o,
});

const answer = (o: Partial<StudentAnswer> = {}): StudentAnswer => ({
  id: 101,
  student_submission_id: 1,
  question_id: 5,
  answer_type: 'TEXT',
  answer_text: 'Normalization is a process used to organize data and reduce redundancy.',
  is_faculty_edited: false,
  has_file: false,
  awarded_marks: null,
  faculty_feedback: null,
  answer_status: 'NOT_REVIEWED',
  ai_grading: null,
  ...o,
});

const result = (o: Partial<AIGradingResultType> = {}): AIGradingResultType => ({
  id: 1,
  student_answer_id: 101,
  student_submission_id: 1,
  question_id: 5,
  rubric_id: 20,
  rubric_version: 1,
  suggested_marks: 7.5,
  maximum_marks: 10,
  overall_feedback: 'The answer demonstrates a reasonable understanding but misses important details about 2NF and 3NF.',
  strengths: ['Correct definition', 'Identifies redundancy reduction'],
  missing_elements: ['2NF explanation', '3NF explanation'],
  evaluation_summary: 'Suggested 7.5 of 10 marks across 5 rubric criteria.',
  grading_status: 'COMPLETED',
  is_current: true,
  is_stale: false,
  stale_reasons: [],
  faculty_decision: null,
  error_message: null,
  model_name: 'facultylens-grading-engine',
  model_version: '1.0.0',
  generation_method: 'embedding_rubric_alignment',
  generated_at: '2026-09-09T10:00:00Z',
  criterion_results: [
    { id: 1, rubric_criterion_id: 1, criterion: 'Definition', suggested_marks: 1.5, maximum_marks: 2, evaluation: 'Generally correct definition.', evidence: ['Mentions reduction of redundancy'], missing_elements: ['Purpose of organizing data'], coverage_level: 'PARTIAL' },
    { id: 2, rubric_criterion_id: 2, criterion: '1NF', suggested_marks: 2, maximum_marks: 2, evaluation: 'Explains 1NF well.', evidence: ['atomic values'], missing_elements: [], coverage_level: 'STRONG' },
    { id: 3, rubric_criterion_id: 3, criterion: '2NF', suggested_marks: 1, maximum_marks: 2, evaluation: 'Limited coverage.', evidence: [], missing_elements: ['partial dependency'], coverage_level: 'LIMITED' },
    { id: 4, rubric_criterion_id: 4, criterion: '3NF', suggested_marks: 1, maximum_marks: 2, evaluation: 'Limited coverage.', evidence: [], missing_elements: ['transitive dependency'], coverage_level: 'LIMITED' },
    { id: 5, rubric_criterion_id: 5, criterion: 'Example', suggested_marks: 2, maximum_marks: 2, evaluation: 'Good example.', evidence: ['student table'], missing_elements: [], coverage_level: 'STRONG' },
  ],
  ...o,
});

beforeEach(() => {
  Object.values(svc).forEach((fn) => fn.mockReset());
});

afterEach(() => {
  vi.useRealTimers();
});

describe('Grading helpers and badges', () => {
  it('validates final marks against the question maximum', () => {
    expect(validateFinalMarks('', 10)).toMatch(/required/);
    expect(validateFinalMarks('abc', 10)).toMatch(/number/);
    expect(validateFinalMarks('-1', 10)).toMatch(/negative/);
    expect(validateFinalMarks('12', 10)).toMatch(/cannot exceed 10/);
    expect(validateFinalMarks('7.555', 10)).toMatch(/two decimal/);
    expect(validateFinalMarks('7.5', 10)).toBeNull();
    expect(validateFinalMarks('10', 10)).toBeNull();
    expect(validateFinalMarks('0', 10)).toBeNull();
  });

  it('maps AI grading statuses to faculty-facing labels', () => {
    expect(formatAIGradingStatus('PENDING')).toBe('Queued');
    expect(formatAIGradingStatus('COMPLETED')).toBe('AI Suggestion Ready');
    expect(formatAIGradingStatus('FINALIZED')).toBe('Faculty Final Marks');
    render(<GradingStatusBadge status="PROCESSING" />);
    expect(screen.getByTestId('ai-grading-status')).toHaveTextContent('Processing');
  });

  it('maps API errors to safe messages', () => {
    expect(getGradingErrorMessage(new ApiError(403, 'x'))).toMatch(/not authorized/i);
    expect(getGradingErrorMessage(new ApiError(503, 'x'))).toMatch(/temporarily unavailable/i);
    expect(getGradingErrorMessage(new ApiError(504, 'x'))).toMatch(/too long/i);
    expect(getGradingErrorMessage(new ApiError(429, 'x'))).toMatch(/too many/i);
    expect(getGradingErrorMessage(new ApiError(422, 'An approved rubric is required'))).toBe('An approved rubric is required');
    expect(getGradingErrorMessage(new ApiError(502, ''))).toMatch(/could not validate/i);
    expect(getGradingErrorMessage(new ApiError(409, 'exists'))).toBe('exists');
    expect(getGradingErrorMessage(new ApiError(401, 'x'))).toMatch(/session/i);
    expect(getGradingErrorMessage(new Error('boom'))).toBe('boom');
  });

  it('renders the academic-integrity disclaimer in both variants', () => {
    const { rerender } = render(<GradingDisclaimer />);
    expect(screen.getByTestId('grading-disclaimer')).toHaveTextContent(/Faculty review is required/);
    rerender(<GradingDisclaimer variant="block" />);
    expect(screen.getByTestId('grading-disclaimer')).toHaveTextContent(/decision-support feature/);
  });
});

describe('AIGradingButton', () => {
  it('is enabled with an approved rubric and content, and shows the disclaimer', () => {
    const onClick = vi.fn();
    render(<AIGradingButton hasApprovedRubric hasContent onClick={onClick} />);
    const btn = screen.getByTestId('ai-grading-button');
    expect(btn).toBeEnabled();
    expect(screen.getByTestId('grading-disclaimer')).toBeInTheDocument();
    fireEvent.click(btn);
    expect(onClick).toHaveBeenCalled();
  });

  it('is blocked without an approved rubric', () => {
    render(<AIGradingButton hasApprovedRubric={false} hasContent onClick={vi.fn()} />);
    expect(screen.getByTestId('ai-grading-button')).toBeDisabled();
    expect(screen.getByTestId('ai-grading-blocked')).toHaveTextContent(/approved rubric is required/i);
  });

  it('is blocked for image-only answers with an honest message', () => {
    render(<AIGradingButton hasApprovedRubric hasContent isImageOnly onClick={vi.fn()} />);
    expect(screen.getByTestId('ai-grading-button')).toBeDisabled();
    expect(screen.getByTestId('ai-grading-blocked')).toHaveTextContent(/Image-based AI grading is not currently supported/);
  });
});

describe('AIGradingLoading and AIGradingError', () => {
  it('shows processing state without a fake percentage', () => {
    render(<AIGradingLoading status="PROCESSING" />);
    const el = screen.getByTestId('ai-grading-loading');
    expect(el).toHaveTextContent(/Analyzing the answer against the approved rubric/);
    expect(el).toHaveTextContent(/Status:/);
    expect(el.textContent).not.toMatch(/%/);
  });

  it('shows an error with retry and reassures no grade was saved', () => {
    const onRetry = vi.fn();
    render(<AIGradingError error={new ApiError(503, 'x')} onRetry={onRetry} />);
    expect(screen.getByRole('alert')).toHaveTextContent(/temporarily unavailable/i);
    expect(screen.getByRole('alert')).toHaveTextContent(/No grade was saved/);
    fireEvent.click(screen.getByRole('button', { name: /try again/i }));
    expect(onRetry).toHaveBeenCalled();
  });
});

describe('SuggestedMarksCard and CriterionGradingBreakdown', () => {
  it('shows AI suggested marks separately from faculty final marks', () => {
    const { rerender } = render(<SuggestedMarksCard result={result()} facultyMarks={null} />);
    expect(screen.getByTestId('suggested-marks')).toHaveTextContent('7.5 / 10');
    expect(screen.getByTestId('faculty-final-marks')).toHaveTextContent('Not set');

    rerender(<SuggestedMarksCard result={result()} facultyMarks={8} />);
    expect(screen.getByTestId('suggested-marks')).toHaveTextContent('7.5 / 10');
    expect(screen.getByTestId('faculty-final-marks')).toHaveTextContent('8 / 10');
    expect(screen.getByTestId('marks-difference')).toHaveTextContent('+0.5');
  });

  it('lists each criterion with marks and expands to evidence and missing elements', () => {
    render(<CriterionGradingBreakdown criteria={result().criterion_results} />);
    const rows = screen.getAllByTestId('criterion-row');
    expect(rows).toHaveLength(5);
    expect(within(rows[0]).getByTestId('criterion-marks')).toHaveTextContent('1.5 / 2');
    expect(within(rows[1]).getByTestId('criterion-marks')).toHaveTextContent('2 / 2');
    expect(screen.queryByTestId('criterion-details')).not.toBeInTheDocument();

    fireEvent.click(within(rows[0]).getByRole('button'));
    const details = screen.getByTestId('criterion-details');
    expect(details).toHaveTextContent('Generally correct definition.');
    expect(details).toHaveTextContent('Mentions reduction of redundancy');
    expect(details).toHaveTextContent('Purpose of organizing data');
  });
});

describe('FinalGradeForm and FacultyGradeEditor', () => {
  it('rejects invalid final marks and submits valid ones with an inferred decision', async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    render(<FinalGradeForm answerId={101} maxMarks={10} initialMarks={7.5} suggestedMarks={7.5} onSubmit={onSubmit} />);

    fireEvent.change(screen.getByTestId('final-marks-input'), { target: { value: '12' } });
    fireEvent.click(screen.getByTestId('finalize-grade-button'));
    expect(await screen.findByRole('alert')).toHaveTextContent(/cannot exceed 10/);
    expect(onSubmit).not.toHaveBeenCalled();

    fireEvent.change(screen.getByTestId('final-marks-input'), { target: { value: '-2' } });
    fireEvent.click(screen.getByTestId('finalize-grade-button'));
    expect(await screen.findByRole('alert')).toHaveTextContent(/negative/);

    fireEvent.change(screen.getByTestId('final-marks-input'), { target: { value: '8' } });
    fireEvent.change(screen.getByLabelText(/faculty feedback/i), { target: { value: 'Good understanding, but provide more detail on 2NF and 3NF.' } });
    fireEvent.click(screen.getByTestId('finalize-grade-button'));
    await waitFor(() => expect(onSubmit).toHaveBeenCalledWith({
      final_marks: 8,
      faculty_feedback: 'Good understanding, but provide more detail on 2NF and 3NF.',
      decision: 'MODIFIED',
    }));
    expect(screen.getByTestId('grading-disclaimer')).toBeInTheDocument();
  });

  it('accept copies the AI suggestion into faculty marks with decision ACCEPTED', async () => {
    const onFinalize = vi.fn().mockResolvedValue(undefined);
    render(<FacultyGradeEditor answer={answer()} result={result()} maxMarks={10} onFinalize={onFinalize} onReject={vi.fn()} />);
    fireEvent.click(screen.getByTestId('accept-suggestion'));
    await waitFor(() => expect(onFinalize).toHaveBeenCalledWith({ final_marks: 7.5, faculty_feedback: null, decision: 'ACCEPTED' }));
  });

  it('reject calls onReject and hides accept/reject once finalized', async () => {
    const onReject = vi.fn().mockResolvedValue(undefined);
    const { rerender } = render(<FacultyGradeEditor answer={answer()} result={result()} maxMarks={10} onFinalize={vi.fn()} onReject={onReject} />);
    fireEvent.click(screen.getByTestId('reject-suggestion'));
    await waitFor(() => expect(onReject).toHaveBeenCalled());

    rerender(<FacultyGradeEditor answer={answer({ awarded_marks: 8 })} result={result({ grading_status: 'FINALIZED', faculty_decision: 'MODIFIED' })} maxMarks={10} onFinalize={vi.fn()} onReject={onReject} />);
    expect(screen.queryByTestId('accept-suggestion')).not.toBeInTheDocument();
    expect(screen.queryByTestId('reject-suggestion')).not.toBeInTheDocument();
    expect(screen.getByTestId('edit-final-marks')).toHaveTextContent('Edit Final Marks');
  });

  it('edit opens the final grade form pre-filled with the suggestion', () => {
    render(<FacultyGradeEditor answer={answer()} result={result()} maxMarks={10} onFinalize={vi.fn()} onReject={vi.fn()} />);
    fireEvent.click(screen.getByTestId('edit-final-marks'));
    expect(screen.getByTestId('final-marks-input')).toHaveValue(7.5);
  });

  it('renders nothing when read-only', () => {
    const { container } = render(<FacultyGradeEditor answer={answer()} result={result()} maxMarks={10} readOnly onFinalize={vi.fn()} onReject={vi.fn()} />);
    expect(container).toBeEmptyDOMElement();
  });
});

describe('AIGradingResult', () => {
  it('shows suggested marks, assessment, strengths, missing, breakdown, disclaimer and actions', () => {
    render(<AIGradingResult result={result()} answer={answer()} maxMarks={10} onRegenerate={vi.fn()} onFinalize={vi.fn()} onReject={vi.fn()} />);
    expect(screen.getByTestId('suggested-marks')).toHaveTextContent('7.5 / 10');
    expect(screen.getByTestId('ai-feedback')).toHaveTextContent(/reasonable understanding/);
    expect(screen.getByTestId('ai-strengths')).toHaveTextContent('Correct definition');
    expect(screen.getByTestId('ai-missing')).toHaveTextContent('2NF explanation');
    expect(screen.getAllByTestId('criterion-row')).toHaveLength(5);
    expect(screen.getByTestId('grading-disclaimer')).toHaveTextContent(/Faculty review and final judgment are required/);
    expect(screen.getByTestId('accept-suggestion')).toBeInTheDocument();
    expect(screen.getByTestId('edit-final-marks')).toBeInTheDocument();
    expect(screen.getByTestId('regenerate-grading')).toBeInTheDocument();
    expect(screen.queryByTestId('stale-warning')).not.toBeInTheDocument();
    expect(screen.queryByText(/confidence/i)).not.toBeInTheDocument();
  });

  it('warns when the result is stale and offers regenerate', () => {
    const onRegenerate = vi.fn();
    render(<AIGradingResult result={result({ is_stale: true, stale_reasons: ['The rubric was modified after this evaluation.'] })} answer={answer()} maxMarks={10} onRegenerate={onRegenerate} onFinalize={vi.fn()} onReject={vi.fn()} />);
    expect(screen.getByTestId('stale-warning')).toHaveTextContent(/may be outdated/);
    expect(screen.getByTestId('stale-warning')).toHaveTextContent(/rubric was modified/);
    fireEvent.click(screen.getByTestId('regenerate-grading'));
    expect(onRegenerate).toHaveBeenCalled();
  });

  it('shows the faculty decision badge once reviewed', () => {
    render(<AIGradingResult result={result({ grading_status: 'FINALIZED', faculty_decision: 'ACCEPTED' })} answer={answer({ awarded_marks: 7.5 })} maxMarks={10} onRegenerate={vi.fn()} onFinalize={vi.fn()} onReject={vi.fn()} />);
    expect(screen.getByTestId('faculty-decision')).toHaveTextContent(/accepted/i);
    expect(screen.getByTestId('faculty-final-marks')).toHaveTextContent('7.5 / 10');
  });
});

describe('AIGradingPanel', () => {
  it('requests grading, polls until complete, then shows the result', async () => {
    vi.useFakeTimers();
    svc.requestAIGrading.mockResolvedValue({ status: 'processing', data: result({ grading_status: 'PENDING', suggested_marks: null, criterion_results: [] }) });
    svc.getAIGradingResult
      .mockResolvedValueOnce({ status: 'success', data: result({ grading_status: 'PROCESSING', suggested_marks: null, criterion_results: [] }) })
      .mockResolvedValueOnce({ status: 'success', data: result() });

    render(<AIGradingPanel question={question()} answer={answer()} pollIntervalMs={1000} />);
    fireEvent.click(screen.getByTestId('ai-grading-button'));
    await act(async () => { await Promise.resolve(); });
    expect(svc.requestAIGrading).toHaveBeenCalledWith(101);
    expect(screen.getByTestId('ai-grading-loading')).toBeInTheDocument();

    await act(async () => { vi.advanceTimersByTime(1000); await Promise.resolve(); });
    expect(screen.getByTestId('ai-grading-loading')).toHaveTextContent('Processing');

    await act(async () => { vi.advanceTimersByTime(1000); await Promise.resolve(); });
    expect(screen.getByTestId('ai-grading-result')).toBeInTheDocument();
    expect(screen.getByTestId('suggested-marks')).toHaveTextContent('7.5 / 10');
    expect(svc.getAIGradingResult).toHaveBeenCalledTimes(2);

    // Polling stops after completion
    await act(async () => { vi.advanceTimersByTime(5000); await Promise.resolve(); });
    expect(svc.getAIGradingResult).toHaveBeenCalledTimes(2);
  });

  it('shows a request error (e.g. no approved rubric) and never a fake grade', async () => {
    svc.requestAIGrading.mockRejectedValue(new ApiError(422, 'An approved rubric is required before AI grading assistance can be requested.'));
    render(<AIGradingPanel question={question()} answer={answer()} />);
    fireEvent.click(screen.getByTestId('ai-grading-button'));
    expect(await screen.findByTestId('ai-grading-error')).toHaveTextContent(/approved rubric is required/i);
    expect(screen.queryByTestId('suggested-marks')).not.toBeInTheDocument();
  });

  it('shows authorization errors', async () => {
    svc.requestAIGrading.mockRejectedValue(new ApiError(403, 'Forbidden'));
    render(<AIGradingPanel question={question()} answer={answer()} />);
    fireEvent.click(screen.getByTestId('ai-grading-button'));
    expect(await screen.findByTestId('ai-grading-error')).toHaveTextContent(/not authorized/i);
  });

  it('shows a failed run with its message and allows retry', async () => {
    svc.requestAIGrading.mockResolvedValue({ status: 'processing', data: result({ grading_status: 'PENDING', suggested_marks: null, criterion_results: [] }) });
    render(<AIGradingPanel question={question()} answer={answer({ ai_grading: result({ grading_status: 'FAILED', suggested_marks: null, criterion_results: [], error_message: 'AI grading assistance is temporarily unavailable. Please try again later.' }) })} />);
    expect(screen.getByTestId('ai-grading-error')).toHaveTextContent(/temporarily unavailable/);
    fireEvent.click(screen.getByRole('button', { name: /try again/i }));
    await waitFor(() => expect(svc.requestAIGrading).toHaveBeenCalledWith(101));
  });

  it('renders an existing completed result and finalizes with accept', async () => {
    const onGradeSaved = vi.fn();
    svc.finalizeGrade.mockResolvedValue({ status: 'success', data: answer({ awarded_marks: 7.5, answer_status: 'REVIEWED', ai_grading: result({ grading_status: 'FINALIZED', faculty_decision: 'ACCEPTED' }) }) });
    render(<AIGradingPanel question={question()} answer={answer({ ai_grading: result() })} onGradeSaved={onGradeSaved} />);
    expect(screen.getByTestId('ai-grading-result')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('accept-suggestion'));
    await waitFor(() => expect(svc.finalizeGrade).toHaveBeenCalledWith(101, { final_marks: 7.5, faculty_feedback: null, decision: 'ACCEPTED' }));
    await waitFor(() => expect(onGradeSaved).toHaveBeenCalled());
    expect(await screen.findByTestId('faculty-decision')).toHaveTextContent(/accepted/i);
  });

  it('edits final marks and rejects invalid values before calling the API', async () => {
    svc.finalizeGrade.mockResolvedValue({ status: 'success', data: answer({ awarded_marks: 8, ai_grading: result({ grading_status: 'FINALIZED', faculty_decision: 'MODIFIED' }) }) });
    render(<AIGradingPanel question={question()} answer={answer({ ai_grading: result() })} />);
    fireEvent.click(screen.getByTestId('edit-final-marks'));
    fireEvent.change(screen.getByTestId('final-marks-input'), { target: { value: '11' } });
    fireEvent.click(screen.getByTestId('finalize-grade-button'));
    expect(await screen.findByRole('alert')).toHaveTextContent(/cannot exceed 10/);
    expect(svc.finalizeGrade).not.toHaveBeenCalled();

    fireEvent.change(screen.getByTestId('final-marks-input'), { target: { value: '8' } });
    fireEvent.click(screen.getByTestId('finalize-grade-button'));
    await waitFor(() => expect(svc.finalizeGrade).toHaveBeenCalledWith(101, expect.objectContaining({ final_marks: 8, decision: 'MODIFIED' })));
  });

  it('rejects the suggestion and regenerates', async () => {
    svc.rejectAIGrading.mockResolvedValue({ status: 'success', data: result({ grading_status: 'REVIEWED', faculty_decision: 'REJECTED' }) });
    svc.regenerateAIGrading.mockResolvedValue({ status: 'processing', data: result({ id: 2, grading_status: 'PENDING', suggested_marks: null, criterion_results: [] }) });
    render(<AIGradingPanel question={question()} answer={answer({ ai_grading: result() })} />);

    fireEvent.click(screen.getByTestId('reject-suggestion'));
    await waitFor(() => expect(svc.rejectAIGrading).toHaveBeenCalledWith(1));
    expect(await screen.findByTestId('faculty-decision')).toHaveTextContent(/rejected/i);

    fireEvent.click(screen.getByTestId('regenerate-grading'));
    await waitFor(() => expect(svc.regenerateAIGrading).toHaveBeenCalledWith(1));
    expect(await screen.findByTestId('ai-grading-loading')).toBeInTheDocument();
  });

  it('offers manual grading without AI and disables AI for image-only answers', async () => {
    svc.finalizeGrade.mockResolvedValue({ status: 'success', data: answer({ awarded_marks: 6 }) });
    render(<AIGradingPanel question={question()} answer={answer({ answer_text: null, answer_type: 'IMAGE', has_file: true, answer_file_type: 'image/png' })} />);
    expect(screen.getByTestId('ai-grading-button')).toBeDisabled();
    expect(screen.getByTestId('ai-grading-blocked')).toHaveTextContent(/not currently supported/);

    fireEvent.click(screen.getByTestId('grade-manually'));
    fireEvent.change(screen.getByTestId('final-marks-input'), { target: { value: '6' } });
    fireEvent.click(screen.getByTestId('finalize-grade-button'));
    await waitFor(() => expect(svc.finalizeGrade).toHaveBeenCalledWith(101, { final_marks: 6, faculty_feedback: null, decision: undefined }));
  });

  it('renders nothing for read-only answers without a result', () => {
    const { container } = render(<AIGradingPanel question={question()} answer={answer()} readOnly />);
    expect(container).toBeEmptyDOMElement();
  });
});
