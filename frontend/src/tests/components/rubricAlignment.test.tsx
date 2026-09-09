import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within, act } from '@testing-library/react';
import { AlignmentStatusBadge, formatAlignmentStatus } from '@/components/rubricAlignment/AlignmentStatusBadge';
import { AlignmentScore, formatPercent } from '@/components/rubricAlignment/AlignmentScore';
import { AlignmentEvidence } from '@/components/rubricAlignment/AlignmentEvidence';
import { MissingElements } from '@/components/rubricAlignment/MissingElements';
import { AlignmentLoading } from '@/components/rubricAlignment/AlignmentLoading';
import { AlignmentError, getAlignmentErrorMessage } from '@/components/rubricAlignment/AlignmentError';
import { AlignmentDisclaimer } from '@/components/rubricAlignment/AlignmentDisclaimer';
import { CriterionAlignmentList } from '@/components/rubricAlignment/CriterionAlignmentList';
import { RubricAlignmentSummary } from '@/components/rubricAlignment/RubricAlignmentSummary';
import { RubricAlignmentCard } from '@/components/rubricAlignment/RubricAlignmentCard';
import { RubricAlignmentPanel } from '@/components/rubricAlignment/RubricAlignmentPanel';
import { SuggestedMarksCard } from '@/components/grading/SuggestedMarksCard';
import { ApiError } from '@/services/api';
import { AIGradingResult } from '@/types/grading';
import { RubricAlignment, gradingAlignmentGap } from '@/types/rubricAlignment';
import { StudentAnswer, SubmissionQuestion } from '@/types/submission';

vi.mock('@/services/rubricAlignmentService', () => ({
  rubricAlignmentService: {
    requestAlignment: vi.fn(),
    getAlignment: vi.fn(),
    getAlignmentHistory: vi.fn(),
    regenerateAlignment: vi.fn(),
    markReviewed: vi.fn(),
  },
}));

import { rubricAlignmentService } from '@/services/rubricAlignmentService';

const svc = rubricAlignmentService as unknown as Record<string, ReturnType<typeof vi.fn>>;

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
  answer_text: 'Normalization organizes data to reduce redundancy. First normal form requires atomic values.',
  is_faculty_edited: false,
  has_file: false,
  awarded_marks: null,
  faculty_feedback: null,
  answer_status: 'NOT_REVIEWED',
  ai_grading: null,
  rubric_alignment: null,
  ...o,
});

const alignment = (o: Partial<RubricAlignment> = {}): RubricAlignment => ({
  id: 1,
  student_answer_id: 101,
  student_submission_id: 1,
  question_id: 5,
  rubric_id: 20,
  rubric_version: 1,
  overall_alignment_score: 55,
  unweighted_alignment_score: 55,
  alignment_status: 'PARTIAL',
  analysis_status: 'COMPLETED',
  is_current: true,
  is_stale: false,
  stale_reasons: [],
  counts: { strong: 2, partial: 1, weak: 1, not_aligned: 1 },
  summary: 'The answer addresses several rubric criteria but leaves important requirements insufficiently addressed.',
  strengths: ["Addresses 'Definition' with clear evidence.", "Addresses '1NF' with clear evidence."],
  missing_elements: ['2NF: No evidence found for: partial dependency.', '3NF: No evidence found for: transitive dependency.'],
  error_message: null,
  model_name: 'facultylens-rubric-alignment-engine',
  model_version: '1.0.0',
  analysis_method: 'semantic_and_rubric_alignment',
  thresholds: { strong: 0.75, partial: 0.55, weak: 0.35 },
  generated_at: '2026-09-09T10:00:00Z',
  criterion_alignments: [
    { id: 1, rubric_criterion_id: 1, criterion: 'Definition', max_marks: 2, alignment_score: 1, similarity: 0.91, alignment_status: 'STRONG', evidence: ['Normalization organizes data to reduce redundancy.'], missing_elements: [], explanation: "The answer directly addresses 'Definition' and provides relevant evidence. Faculty review is recommended to verify correctness." },
    { id: 2, rubric_criterion_id: 2, criterion: '1NF', max_marks: 2, alignment_score: 1, similarity: 0.88, alignment_status: 'STRONG', evidence: ['First normal form requires atomic values.'], missing_elements: [], explanation: 'Directly addressed.' },
    { id: 3, rubric_criterion_id: 3, criterion: '2NF', max_marks: 2, alignment_score: 0.5, similarity: 0.6, alignment_status: 'PARTIAL', evidence: ['First normal form requires atomic values.'], missing_elements: ['Only limited evidence found for: partial dependency.'], explanation: 'Partially addressed.' },
    { id: 4, rubric_criterion_id: 4, criterion: '3NF', max_marks: 2, alignment_score: 0.25, similarity: 0.4, alignment_status: 'WEAK', evidence: [], missing_elements: ['No evidence found for: transitive dependency.'], explanation: 'Limited evidence.' },
    { id: 5, rubric_criterion_id: 5, criterion: 'Example', max_marks: 2, alignment_score: 0, similarity: 0.1, alignment_status: 'NOT_ALIGNED', evidence: [], missing_elements: ['No evidence found for: example table.'], explanation: 'No meaningful evidence.' },
  ],
  ...o,
});

const grading = (o: Partial<AIGradingResult> = {}): AIGradingResult => ({
  id: 9,
  student_answer_id: 101,
  student_submission_id: 1,
  question_id: 5,
  rubric_id: 20,
  rubric_version: 1,
  suggested_marks: 9,
  maximum_marks: 10,
  overall_feedback: 'Good.',
  strengths: [],
  missing_elements: [],
  evaluation_summary: 'Summary.',
  grading_status: 'COMPLETED',
  is_current: true,
  is_stale: false,
  stale_reasons: [],
  faculty_decision: null,
  error_message: null,
  generated_at: '2026-09-09T10:00:00Z',
  criterion_results: [],
  ...o,
});

beforeEach(() => { Object.values(svc).forEach((fn) => fn.mockReset()); });
afterEach(() => { vi.useRealTimers(); });

describe('Alignment helpers and badges', () => {
  it('formats statuses, percentages and computes the grading/alignment gap', () => {
    expect(formatAlignmentStatus('STRONG')).toBe('Strong');
    expect(formatAlignmentStatus('NOT_ALIGNED')).toBe('Not Aligned');
    expect(formatAlignmentStatus(null)).toBe('—');
    expect(formatPercent(55)).toBe('55%');
    expect(formatPercent(64.29)).toBe('64.3%');
    expect(formatPercent(null)).toBe('—');
    expect(gradingAlignmentGap(9, 10, 35)).toBe(55);
    expect(gradingAlignmentGap(7.5, 10, 68)).toBe(7);
    expect(gradingAlignmentGap(null, 10, 68)).toBeNull();
    expect(gradingAlignmentGap(7, 0, 68)).toBeNull();
  });

  it('renders badges with subtle semantic variants', () => {
    render(<div><AlignmentStatusBadge status="STRONG" /><AlignmentStatusBadge status="NOT_ALIGNED" showSymbol /></div>);
    const badges = screen.getAllByTestId('alignment-status');
    expect(badges[0]).toHaveTextContent('Strong');
    expect(badges[1]).toHaveTextContent('✕ Not Aligned');
  });

  it('maps API errors to safe messages', () => {
    expect(getAlignmentErrorMessage(new ApiError(403, 'x'))).toMatch(/not authorized/i);
    expect(getAlignmentErrorMessage(new ApiError(503, 'x'))).toMatch(/temporarily unavailable/i);
    expect(getAlignmentErrorMessage(new ApiError(504, 'x'))).toMatch(/too long/i);
    expect(getAlignmentErrorMessage(new ApiError(422, 'No approved rubric'))).toBe('No approved rubric');
    expect(getAlignmentErrorMessage(new ApiError(409, 'exists'))).toBe('exists');
    expect(getAlignmentErrorMessage(new ApiError(502, ''))).toMatch(/could not validate/i);
    expect(getAlignmentErrorMessage(new ApiError(429, 'x'))).toMatch(/too many/i);
  });

  it('renders score, evidence, missing, loading, disclaimer and error', () => {
    render(
      <div>
        <AlignmentScore score={68} status="PARTIAL" unweightedScore={60} />
        <AlignmentEvidence evidence={['First normal form requires atomic values.']} />
        <MissingElements items={['No evidence found for: transitive dependency.']} />
        <AlignmentLoading status="PROCESSING" />
        <AlignmentDisclaimer />
        <AlignmentError error={new ApiError(503, 'x')} onRetry={vi.fn()} />
      </div>,
    );
    expect(screen.getByTestId('alignment-percent')).toHaveTextContent('68%');
    expect(screen.getByTestId('alignment-overall-status')).toHaveTextContent('Partial');
    expect(screen.getByTestId('alignment-score')).toHaveTextContent(/unweighted 60%/);
    expect(screen.getByTestId('alignment-evidence')).toHaveTextContent('First normal form requires atomic values.');
    expect(screen.getByTestId('missing-elements')).toHaveTextContent('transitive dependency');
    expect(screen.getByTestId('alignment-loading')).toHaveTextContent(/Comparing the answer/);
    expect(screen.getByTestId('alignment-loading').textContent).not.toMatch(/%/);
    expect(screen.getByTestId('alignment-disclaimer')).toHaveTextContent(/Faculty review remains necessary/);
    expect(screen.getByTestId('alignment-error')).toHaveTextContent(/Marks and feedback are unaffected/);
  });
});

describe('CriterionAlignmentList and RubricAlignmentSummary', () => {
  it('lists criteria with status and weight, expanding to evidence, missing and explanation', () => {
    render(<CriterionAlignmentList items={alignment().criterion_alignments} />);
    const rows = screen.getAllByTestId('criterion-alignment');
    expect(rows).toHaveLength(5);
    expect(within(rows[0]).getByTestId('alignment-status')).toHaveTextContent('Strong');
    expect(within(rows[0]).getByTestId('criterion-weight')).toHaveTextContent('2 / 2 alignment weight');
    expect(within(rows[2]).getByTestId('criterion-weight')).toHaveTextContent('1 / 2 alignment weight');
    expect(within(rows[3]).getByTestId('criterion-weight')).toHaveTextContent('0.5 / 2 alignment weight');
    expect(within(rows[4]).getByTestId('alignment-status')).toHaveTextContent('Not Aligned');

    fireEvent.click(within(rows[0]).getByRole('button'));
    const details = screen.getByTestId('criterion-alignment-details');
    expect(details).toHaveTextContent(/verify correctness/);
    expect(details).toHaveTextContent('Normalization organizes data to reduce redundancy.');
    expect(details).toHaveTextContent('Alignment weight is not a mark.');

    fireEvent.click(within(rows[4]).getByRole('button'));
    expect(screen.getAllByTestId('criterion-alignment-details')[1]).toHaveTextContent('No evidence found for: example table.');
  });

  it('shows counts from the result and no comparison without AI grading', () => {
    render(<RubricAlignmentSummary alignment={alignment()} />);
    const counts = screen.getByTestId('alignment-counts');
    expect(counts).toHaveTextContent('2Strong');
    expect(counts).toHaveTextContent('1Partial');
    expect(counts).toHaveTextContent('1Weak');
    expect(counts).toHaveTextContent('1Not aligned');
    expect(screen.getByTestId('alignment-percent')).toHaveTextContent('55%');
    expect(screen.queryByTestId('signal-comparison')).not.toBeInTheDocument();
  });

  it('shows both signals and a review notice when they diverge substantially', () => {
    const { rerender } = render(<RubricAlignmentSummary alignment={alignment({ overall_alignment_score: 35, alignment_status: 'WEAK' })} aiGrading={grading({ suggested_marks: 9 })} />);
    expect(screen.getByTestId('signal-comparison')).toHaveTextContent('9 / 10');
    expect(screen.getByTestId('signal-comparison')).toHaveTextContent('35%');
    expect(screen.getByTestId('inconsistency-notice')).toHaveTextContent(/substantially different signals/);
    expect(screen.getByTestId('inconsistency-notice')).toHaveTextContent(/Faculty review is recommended/);

    rerender(<RubricAlignmentSummary alignment={alignment({ overall_alignment_score: 68 })} aiGrading={grading({ suggested_marks: 7.5 })} />);
    expect(screen.queryByTestId('inconsistency-notice')).not.toBeInTheDocument();
    expect(screen.getByTestId('signal-comparison')).toHaveTextContent(/not a grade/);

    rerender(<RubricAlignmentSummary alignment={alignment()} aiGrading={grading({ grading_status: 'PENDING', suggested_marks: null })} />);
    expect(screen.queryByTestId('signal-comparison')).not.toBeInTheDocument();
  });
});

describe('RubricAlignmentCard', () => {
  it('renders summary, breakdown, strengths, missing, disclaimer and controls', () => {
    const onRegenerate = vi.fn();
    const onMarkReviewed = vi.fn();
    render(<RubricAlignmentCard alignment={alignment()} onRegenerate={onRegenerate} onMarkReviewed={onMarkReviewed} />);
    expect(screen.getByTestId('rubric-alignment-card')).toHaveTextContent('Answer ↔ Rubric Alignment');
    expect(screen.getByTestId('alignment-percent')).toHaveTextContent('55%');
    expect(screen.getAllByTestId('criterion-alignment')).toHaveLength(5);
    expect(screen.getByTestId('alignment-strengths')).toHaveTextContent("Addresses 'Definition'");
    expect(screen.getByTestId('missing-elements')).toHaveTextContent('transitive dependency');
    expect(screen.getByTestId('alignment-disclaimer')).toBeInTheDocument();
    expect(screen.getByText(/not correctness/i)).toBeInTheDocument();
    expect(screen.queryByTestId('alignment-stale-warning')).not.toBeInTheDocument();
    expect(screen.queryByText(/final marks|correct answer|grade =/i)).not.toBeInTheDocument();

    fireEvent.click(screen.getByTestId('regenerate-alignment'));
    fireEvent.click(screen.getByTestId('mark-alignment-reviewed'));
    expect(onRegenerate).toHaveBeenCalled();
    expect(onMarkReviewed).toHaveBeenCalled();
  });

  it('warns when stale and hides review button once reviewed', () => {
    const { rerender } = render(<RubricAlignmentCard alignment={alignment({ is_stale: true, stale_reasons: ['The rubric was modified after this analysis.'] })} onRegenerate={vi.fn()} onMarkReviewed={vi.fn()} />);
    expect(screen.getByTestId('alignment-stale-warning')).toHaveTextContent(/rubric was modified/);

    rerender(<RubricAlignmentCard alignment={alignment({ analysis_status: 'REVIEWED' })} onRegenerate={vi.fn()} onMarkReviewed={vi.fn()} />);
    expect(screen.getByTestId('alignment-reviewed')).toBeInTheDocument();
    expect(screen.queryByTestId('mark-alignment-reviewed')).not.toBeInTheDocument();
  });

  it('hides controls when read-only', () => {
    render(<RubricAlignmentCard alignment={alignment()} readOnly onRegenerate={vi.fn()} onMarkReviewed={vi.fn()} />);
    expect(screen.queryByTestId('regenerate-alignment')).not.toBeInTheDocument();
    expect(screen.queryByTestId('mark-alignment-reviewed')).not.toBeInTheDocument();
  });
});

describe('RubricAlignmentPanel', () => {
  it('shows the analyze button with rubric info and requests, polls, then renders the result', async () => {
    vi.useFakeTimers();
    svc.requestAlignment.mockResolvedValue({ status: 'processing', data: alignment({ analysis_status: 'PENDING', overall_alignment_score: null, alignment_status: null, criterion_alignments: [] }) });
    svc.getAlignment
      .mockResolvedValueOnce({ status: 'success', data: alignment({ analysis_status: 'PROCESSING', overall_alignment_score: null, alignment_status: null, criterion_alignments: [] }) })
      .mockResolvedValueOnce({ status: 'success', data: alignment() });

    render(<RubricAlignmentPanel question={question()} answer={answer()} pollIntervalMs={1000} />);
    expect(screen.getByTestId('alignment-rubric-info')).toHaveTextContent('Approved rubric v1 · 10 marks');
    fireEvent.click(screen.getByTestId('analyze-alignment-button'));
    await act(async () => { await Promise.resolve(); });
    expect(svc.requestAlignment).toHaveBeenCalledWith(101);
    expect(screen.getByTestId('alignment-loading')).toHaveTextContent('Queued');

    await act(async () => { vi.advanceTimersByTime(1000); await Promise.resolve(); });
    expect(screen.getByTestId('alignment-loading')).toHaveTextContent('Processing');

    await act(async () => { vi.advanceTimersByTime(1000); await Promise.resolve(); });
    expect(screen.getByTestId('rubric-alignment-card')).toBeInTheDocument();
    expect(screen.getByTestId('alignment-percent')).toHaveTextContent('55%');

    await act(async () => { vi.advanceTimersByTime(5000); await Promise.resolve(); });
    expect(svc.getAlignment).toHaveBeenCalledTimes(2);
  });

  it('is blocked without an approved rubric and for image-only answers', () => {
    const { rerender } = render(<RubricAlignmentPanel question={question({ approved_rubric: null })} answer={answer()} />);
    expect(screen.getByTestId('analyze-alignment-button')).toBeDisabled();
    expect(screen.getByTestId('alignment-blocked')).toHaveTextContent(/approved rubric is required/);

    rerender(<RubricAlignmentPanel question={question()} answer={answer({ answer_text: null, answer_type: 'IMAGE', has_file: true, answer_file_type: 'image/png' })} />);
    expect(screen.getByTestId('alignment-blocked')).toHaveTextContent(/not currently supported/);
  });

  it('shows request errors including authorization and never a fake score', async () => {
    svc.requestAlignment.mockRejectedValue(new ApiError(403, 'Forbidden'));
    render(<RubricAlignmentPanel question={question()} answer={answer()} />);
    fireEvent.click(screen.getByTestId('analyze-alignment-button'));
    expect(await screen.findByTestId('alignment-error')).toHaveTextContent(/not authorized/i);
    expect(screen.queryByTestId('alignment-percent')).not.toBeInTheDocument();
  });

  it('shows a failed run and allows retry', async () => {
    svc.requestAlignment.mockResolvedValue({ status: 'processing', data: alignment({ analysis_status: 'PENDING', criterion_alignments: [] }) });
    render(<RubricAlignmentPanel question={question()} answer={answer({ rubric_alignment: alignment({ analysis_status: 'FAILED', overall_alignment_score: null, criterion_alignments: [], error_message: 'Rubric alignment analysis is temporarily unavailable. Please try again later.' }) })} />);
    expect(screen.getByTestId('alignment-error')).toHaveTextContent(/temporarily unavailable/);
    fireEvent.click(screen.getByRole('button', { name: /try again/i }));
    await waitFor(() => expect(svc.requestAlignment).toHaveBeenCalledWith(101));
  });

  it('renders an existing result, marks reviewed, and regenerates', async () => {
    svc.markReviewed.mockResolvedValue({ status: 'success', data: alignment({ analysis_status: 'REVIEWED' }) });
    svc.regenerateAlignment.mockResolvedValue({ status: 'processing', data: alignment({ id: 2, analysis_status: 'PENDING', criterion_alignments: [] }) });
    render(<RubricAlignmentPanel question={question()} answer={answer({ rubric_alignment: alignment(), ai_grading: grading({ suggested_marks: 9 }) })} aiGrading={grading({ suggested_marks: 9 })} />);
    expect(screen.getByTestId('rubric-alignment-card')).toBeInTheDocument();
    expect(screen.getByTestId('inconsistency-notice')).toBeInTheDocument();

    fireEvent.click(screen.getByTestId('mark-alignment-reviewed'));
    await waitFor(() => expect(svc.markReviewed).toHaveBeenCalledWith(1));
    expect(await screen.findByTestId('alignment-reviewed')).toBeInTheDocument();

    fireEvent.click(screen.getByTestId('regenerate-alignment'));
    await waitFor(() => expect(svc.regenerateAlignment).toHaveBeenCalledWith(1));
    expect(await screen.findByTestId('alignment-loading')).toBeInTheDocument();
  });

  it('renders nothing for read-only answers without a result', () => {
    const { container } = render(<RubricAlignmentPanel question={question()} answer={answer()} readOnly />);
    expect(container).toBeEmptyDOMElement();
  });
});

describe('STEP 27 SuggestedMarksCard with alignment signal', () => {
  it('shows alignment as a third separate tile without altering marks', () => {
    render(<SuggestedMarksCard result={grading({ suggested_marks: 7.5 })} facultyMarks={8} alignment={alignment({ overall_alignment_score: 68 })} />);
    expect(screen.getByTestId('suggested-marks')).toHaveTextContent('7.5 / 10');
    expect(screen.getByTestId('faculty-final-marks')).toHaveTextContent('8 / 10');
    expect(screen.getByTestId('alignment-signal')).toHaveTextContent('68%');
    expect(screen.getByTestId('alignment-signal')).toHaveTextContent(/not a grade/);
  });

  it('omits the alignment tile when no completed alignment exists', () => {
    render(<SuggestedMarksCard result={grading()} facultyMarks={null} alignment={alignment({ analysis_status: 'PENDING', overall_alignment_score: null })} />);
    expect(screen.queryByTestId('alignment-signal')).not.toBeInTheDocument();
  });
});
