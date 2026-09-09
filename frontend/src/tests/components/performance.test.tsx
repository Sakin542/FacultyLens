import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within, act } from '@testing-library/react';
import { PerformanceGapBadge, formatGap, formatPct, formatPerformanceStatus } from '@/components/performance/PerformanceGapBadge';
import { PerformanceSummary } from '@/components/performance/PerformanceSummary';
import { QuestionPerformanceTable } from '@/components/performance/QuestionPerformanceTable';
import { TopicPerformance } from '@/components/performance/TopicPerformance';
import { LearningOutcomePerformance } from '@/components/performance/LearningOutcomePerformance';
import { GapAreas } from '@/components/performance/GapAreas';
import { StrongAreas } from '@/components/performance/StrongAreas';
import { PerformanceEmptyState } from '@/components/performance/PerformanceEmptyState';
import { PerformanceError, getPerformanceErrorMessage } from '@/components/performance/PerformanceError';
import { PerformanceOverview } from '@/components/performance/PerformanceOverview';
import { StudentPerformanceCard } from '@/components/performance/StudentPerformanceCard';
import { ApiError } from '@/services/api';
import { PerformanceAnalysis, QuestionPerformance, StudentPerformance } from '@/types/performance';

vi.mock('@/services/performanceService', () => ({
  performanceService: {
    getAssessmentPerformance: vi.fn(),
    analyzeAssessmentPerformance: vi.fn(),
    getQuestionPerformance: vi.fn(),
    getTopicPerformance: vi.fn(),
    getLearningOutcomePerformance: vi.fn(),
    getPerformanceHistory: vi.fn(),
    getStudentPerformance: vi.fn(),
  },
}));

import { performanceService } from '@/services/performanceService';

const svc = performanceService as unknown as Record<string, ReturnType<typeof vi.fn>>;

const q = (o: Partial<QuestionPerformance> = {}): QuestionPerformance => ({
  id: 1, question_id: 11, question_number: 1, question_text_excerpt: 'Write a SQL join.', maximum_marks: 10,
  response_count: 40, submission_count: 40, average_marks: 8.2, average_percentage: 82, median_marks: 8, minimum_marks: 5, max_awarded_marks: 10,
  performance_gap: -12, performance_status: 'STRONG', difficulty_level: 'easy', cognitive_level: 'Apply', topics: ['SQL Queries'],
  ai_suggested_average_percentage: null, rubric_alignment_average: null, review_signals: ['High observed performance on this question.'], ...o,
});

const analysis = (o: Partial<PerformanceAnalysis> = {}): PerformanceAnalysis => ({
  id: 1, assessment_id: 7, course_id: 3, status: 'COMPLETED', is_current: true, is_stale: false, stale_reasons: [],
  expected_performance_percent: 70, minimum_responses: 5, thresholds: null,
  student_count: 40, submission_count: 40, finalized_answer_count: 120, question_count: 3,
  overall_average_percentage: 68, overall_gap: 2, overall_status: 'ON_TARGET',
  summary: {
    status_counts: { STRONG: 1, MINOR_GAP: 1, HIGH_GAP: 1 },
    gap_areas: [
      { type: 'learning_outcome', label: 'LO4', description: 'Design transactions', average_percentage: 43, performance_gap: 27, performance_status: 'HIGH_GAP', reference_id: 4 },
      { type: 'topic', label: 'Normalization', description: null, average_percentage: 54, performance_gap: 16, performance_status: 'MODERATE_GAP', reference_id: null },
      { type: 'question', label: 'Q3', description: 'Analyze transaction isolation.', average_percentage: 48, performance_gap: 22, performance_status: 'HIGH_GAP', reference_id: 13 },
    ],
    strong_areas: [{ type: 'topic', label: 'SQL Query Design', description: null, average_percentage: 86, performance_gap: -16, performance_status: 'STRONG', reference_id: null }],
    los_with_gaps: 1, topics_with_gaps: 1, questions_with_gaps: 1, insufficient_data_count: 0,
  },
  error_message: null, analyzed_at: '2026-09-10T10:00:00Z',
  limitations: 'Performance analysis is based on available finalized grading data.',
  questions: [
    q(),
    q({ id: 2, question_id: 12, question_number: 2, question_text_excerpt: 'Explain normalization.', average_marks: 6.4, average_percentage: 64, performance_gap: 6, performance_status: 'MINOR_GAP', difficulty_level: 'medium', topics: ['Normalization'], review_signals: [] }),
    q({ id: 3, question_id: 13, question_number: 3, question_text_excerpt: 'Analyze transaction isolation.', maximum_marks: 15, average_marks: 7.2, average_percentage: 48, performance_gap: 22, performance_status: 'HIGH_GAP', difficulty_level: 'hard', cognitive_level: 'Analyze', topics: ['Transactions'], rubric_alignment_average: 51, ai_suggested_average_percentage: 50, review_signals: ['Students performed substantially below the configured benchmark on a hard question.'] }),
    q({ id: 4, question_id: 14, question_number: 4, question_text_excerpt: 'Bonus.', response_count: 3, average_percentage: 30, performance_gap: 40, performance_status: 'INSUFFICIENT_DATA', review_signals: ['Insufficient responses for a reliable aggregate analysis.'] }),
  ],
  topics: [
    { id: 1, topic: 'Normalization', question_count: 1, question_ids: [12], response_count: 40, total_marks: 10, average_percentage: 54, performance_gap: 16, performance_status: 'MODERATE_GAP' },
    { id: 2, topic: 'SQL Queries', question_count: 1, question_ids: [11], response_count: 40, total_marks: 10, average_percentage: 82, performance_gap: -12, performance_status: 'STRONG' },
  ],
  learning_outcomes: [
    { id: 1, learning_outcome_id: 1, lo_code: 'LO1', lo_description: 'Write SQL queries.', question_count: 2, question_ids: [11, 13], response_count: 80, total_marks: 25, average_percentage: 61.6, performance_gap: 8.4, performance_status: 'MINOR_GAP' },
    { id: 2, learning_outcome_id: 4, lo_code: 'LO4', lo_description: 'Design transactions', question_count: 0, question_ids: [], response_count: 0, total_marks: 0, average_percentage: null, performance_gap: null, performance_status: 'INSUFFICIENT_DATA' },
  ],
  ...o,
});

const studentData = (o: Partial<StudentPerformance> = {}): StudentPerformance => ({
  student: { id: 3, student_identifier: 'STU001', name: 'Student One' },
  assessment: { id: 7, title: 'Midterm' },
  submission_id: 1, submission_status: 'GRADED', grading_status: 'FACULTY_REVIEWED',
  has_finalized_grades: true, finalized_question_count: 3, question_count: 3,
  total_awarded_marks: 18, total_maximum_marks: 30, overall_percentage: 60, expected_performance_percent: 70,
  questions: [
    { question_id: 11, question_number: 1, question_text_excerpt: 'SQL', maximum_marks: 10, answered: true, awarded_marks: 8, is_finalized: true, percentage: 80, topics: ['SQL Queries'], learning_outcome_code: 'LO1' },
    { question_id: 12, question_number: 2, question_text_excerpt: 'Normalization', maximum_marks: 10, answered: true, awarded_marks: 6, is_finalized: true, percentage: 60, topics: ['Normalization'], learning_outcome_code: 'LO2' },
    { question_id: 13, question_number: 3, question_text_excerpt: 'Transactions', maximum_marks: 10, answered: true, awarded_marks: 4, is_finalized: true, percentage: 40, topics: ['Transactions'], learning_outcome_code: 'LO1' },
  ],
  areas_for_review: [{ type: 'topic', label: 'Transactions', description: null, percentage: 40, gap: 30 }, { type: 'topic', label: 'Normalization', description: null, percentage: 60, gap: 10 }],
  note: 'Individual results are shown for authorized faculty review only.',
  ...o,
});

beforeEach(() => { Object.values(svc).forEach((fn) => fn.mockReset()); });
afterEach(() => { vi.useRealTimers(); });

describe('Performance helpers and badges', () => {
  it('formats status, percent and gap without punitive language', () => {
    expect(formatPerformanceStatus('HIGH_GAP')).toBe('High Gap');
    expect(formatPerformanceStatus('INSUFFICIENT_DATA')).toBe('Insufficient Data');
    expect(formatPct(67.42)).toBe('67.4%');
    expect(formatPct(null)).toBe('—');
    expect(formatGap(12)).toBe('12 pts');
    expect(formatGap(-5)).toBe('—');
    expect(formatGap(null)).toBe('—');
    render(<PerformanceGapBadge status="MODERATE_GAP" />);
    expect(screen.getByTestId('performance-status')).toHaveTextContent('Moderate Gap');
    expect(screen.getByTestId('performance-status').textContent?.toLowerCase()).not.toMatch(/fail|weak student/);
  });

  it('maps errors to safe messages', () => {
    expect(getPerformanceErrorMessage(new ApiError(403, 'x'))).toMatch(/not authorized/i);
    expect(getPerformanceErrorMessage(new ApiError(409, 'exists'))).toBe('exists');
    expect(getPerformanceErrorMessage(new ApiError(503, 'x'))).toMatch(/temporarily unavailable/i);
    render(<PerformanceError error={new ApiError(500, 'x')} onRetry={vi.fn()} />);
    expect(screen.getByTestId('performance-error')).toHaveTextContent(/Grades are unaffected/);
  });
});

describe('Performance sections', () => {
  it('summary shows actual counts and benchmark', () => {
    render(<PerformanceSummary analysis={analysis()} />);
    const tiles = screen.getAllByTestId('performance-tile');
    expect(tiles[0]).toHaveTextContent('40');
    expect(tiles[1]).toHaveTextContent('120');
    expect(tiles[2]).toHaveTextContent('68%');
    expect(tiles[2]).toHaveTextContent('expected 70%');
    expect(tiles[3]).toHaveTextContent('2 pts');
    expect(tiles[4]).toHaveTextContent('3');
    expect(tiles[5]).toHaveTextContent('1');
    expect(screen.getByTestId('performance-status')).toHaveTextContent('On Target');
  });

  it('question table lists every question with avg, max, responses, gap and status; detail view shows signals and context', () => {
    render(<QuestionPerformanceTable questions={analysis().questions!} expected={70} />);
    const rows = screen.getAllByTestId('question-row');
    expect(rows).toHaveLength(4);
    expect(rows[0]).toHaveTextContent('Q1');
    expect(rows[0]).toHaveTextContent('82%');
    expect(rows[0]).toHaveTextContent('40 / 40');
    expect(within(rows[0]).getByTestId('performance-status')).toHaveTextContent('Strong');
    expect(rows[2]).toHaveTextContent('48%');
    expect(rows[2]).toHaveTextContent('22 pts');
    expect(within(rows[2]).getByTestId('performance-status')).toHaveTextContent('High Gap');
    expect(within(rows[3]).getByTestId('performance-status')).toHaveTextContent('Insufficient Data');

    fireEvent.click(screen.getByTestId('detail-view'));
    const cards = screen.getAllByTestId('question-performance-card');
    fireEvent.click(within(cards[2]).getByRole('button'));
    const details = screen.getByTestId('question-performance-details');
    expect(details).toHaveTextContent('Median');
    expect(details).toHaveTextContent('Hard');
    expect(details).toHaveTextContent('Analyze');
    expect(within(details).getByTestId('question-context')).toHaveTextContent('51%');
    expect(within(details).getByTestId('question-context')).toHaveTextContent(/supporting only/);
    expect(within(details).getByTestId('review-signals')).toHaveTextContent(/below the configured benchmark/);
  });

  it('topic and LO sections render bars, links and empty messages', () => {
    const a = analysis();
    render(<div><TopicPerformance topics={a.topics!} expected={70} hasQuestions /><LearningOutcomePerformance outcomes={a.learning_outcomes!} questions={a.questions!} expected={70} /></div>);
    const topics = screen.getAllByTestId('topic-row');
    expect(topics[0]).toHaveTextContent('Normalization');
    expect(topics[0]).toHaveTextContent('54%');
    expect(topics[0]).toHaveTextContent('16 pts');
    expect(screen.getAllByTestId('performance-bar').length).toBeGreaterThan(2);

    const los = screen.getAllByTestId('lo-row');
    expect(los[0]).toHaveTextContent('LO1');
    expect(los[0]).toHaveTextContent('61.6%');
    expect(within(los[0]).getAllByTestId('lo-question-link').map((l) => l.textContent?.trim())).toEqual(['Q1,', 'Q3']);
    expect(los[1]).toHaveTextContent(/No questions mapped/);
  });

  it('topic section explains missing topic classification', () => {
    render(<TopicPerformance topics={[]} expected={70} hasQuestions />);
    expect(screen.getByTestId('topic-performance')).toHaveTextContent(/without topic classification/);
  });

  it('gap and strong areas use "potential" wording and real values', () => {
    const a = analysis();
    render(<div><GapAreas areas={a.summary.gap_areas} minResponses={5} /><StrongAreas areas={a.summary.strong_areas} /></div>);
    const gaps = screen.getAllByTestId('gap-area');
    expect(gaps).toHaveLength(3);
    expect(gaps[0]).toHaveTextContent('LO4');
    expect(gaps[0]).toHaveTextContent('43%');
    expect(gaps[0]).toHaveTextContent('gap 27 pts');
    expect(screen.getByTestId('gap-areas')).toHaveTextContent(/Potential learning gap identified/);
    expect(screen.getByTestId('gap-areas').textContent).not.toMatch(/confirmed|failure|deficien/i);
    expect(screen.getAllByTestId('strong-area')[0]).toHaveTextContent('SQL Query Design');
    expect(screen.getAllByTestId('strong-area')[0]).toHaveTextContent('86%');
  });

  it('empty state explains finalized-only requirement', () => {
    const onAnalyze = vi.fn();
    const { rerender } = render(<PerformanceEmptyState meta={{ finalized_answer_count: 0, expected_performance_percent: 70, minimum_responses: 5 }} onAnalyze={onAnalyze} />);
    expect(screen.getByTestId('performance-empty')).toHaveTextContent(/AI-suggested marks are never counted/);
    rerender(<PerformanceEmptyState meta={{ finalized_answer_count: 12, expected_performance_percent: 70, minimum_responses: 5 }} onAnalyze={onAnalyze} />);
    expect(screen.getByTestId('performance-empty')).toHaveTextContent(/12 finalized answers/);
    fireEvent.click(screen.getByTestId('analyze-performance'));
    expect(onAnalyze).toHaveBeenCalled();
  });
});

describe('PerformanceOverview', () => {
  it('shows empty state, analyzes inline and renders the result', async () => {
    svc.getAssessmentPerformance.mockResolvedValueOnce({ status: 'success', data: null, meta: { finalized_answer_count: 120, expected_performance_percent: 70, minimum_responses: 5 } });
    svc.analyzeAssessmentPerformance.mockResolvedValue({ status: 'success', data: analysis() });
    svc.getAssessmentPerformance.mockResolvedValue({ status: 'success', data: analysis() });

    render(<PerformanceOverview assessmentId={7} />);
    expect(await screen.findByTestId('performance-empty')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('analyze-performance'));
    await waitFor(() => expect(svc.analyzeAssessmentPerformance).toHaveBeenCalledWith(7, false));
    expect(await screen.findByTestId('performance-summary')).toBeInTheDocument();
    expect(screen.getByTestId('question-performance-table')).toBeInTheDocument();
    expect(screen.getByTestId('topic-performance')).toBeInTheDocument();
    expect(screen.getByTestId('lo-performance')).toBeInTheDocument();
    expect(screen.getByTestId('gap-areas')).toBeInTheDocument();
    expect(screen.getByTestId('strong-areas')).toBeInTheDocument();
    expect(screen.getByTestId('performance-disclaimer')).toHaveTextContent(/finalized grading data/);
    expect(screen.getByTestId('regenerate-performance')).toBeInTheDocument();
  });

  it('polls while queued and stops when completed', async () => {
    vi.useFakeTimers();
    svc.getAssessmentPerformance
      .mockResolvedValueOnce({ status: 'success', data: analysis({ status: 'PENDING' }) })
      .mockResolvedValueOnce({ status: 'success', data: analysis({ status: 'PROCESSING' }) })
      .mockResolvedValue({ status: 'success', data: analysis() });

    render(<PerformanceOverview assessmentId={7} pollIntervalMs={1000} />);
    await act(async () => { await Promise.resolve(); });
    expect(screen.getByTestId('performance-loading')).toHaveTextContent(/queued/i);
    await act(async () => { vi.advanceTimersByTime(1000); await Promise.resolve(); });
    expect(screen.getByTestId('performance-loading')).toBeInTheDocument();
    await act(async () => { vi.advanceTimersByTime(1000); await Promise.resolve(); });
    expect(screen.getByTestId('performance-summary')).toBeInTheDocument();
    const calls = svc.getAssessmentPerformance.mock.calls.length;
    await act(async () => { vi.advanceTimersByTime(5000); await Promise.resolve(); });
    expect(svc.getAssessmentPerformance).toHaveBeenCalledTimes(calls);
  });

  it('shows stale warning and regenerates with force', async () => {
    svc.getAssessmentPerformance.mockResolvedValue({ status: 'success', data: analysis({ is_stale: true, status: 'STALE', stale_reasons: ['Finalized grades, questions or outcome mappings changed after this analysis.'] }) });
    svc.analyzeAssessmentPerformance.mockResolvedValue({ status: 'success', data: analysis() });
    render(<PerformanceOverview assessmentId={7} />);
    expect(await screen.findByTestId('performance-stale')).toHaveTextContent(/may be outdated/);
    fireEvent.click(screen.getByTestId('regenerate-performance'));
    await waitFor(() => expect(svc.analyzeAssessmentPerformance).toHaveBeenCalledWith(7, true));
  });

  it('shows API and authorization errors', async () => {
    svc.getAssessmentPerformance.mockRejectedValue(new ApiError(403, 'Forbidden'));
    render(<PerformanceOverview assessmentId={7} />);
    expect(await screen.findByTestId('performance-error')).toHaveTextContent(/not authorized/i);
  });

  it('shows failed run with retry and a no-finalized message when count is zero', async () => {
    svc.getAssessmentPerformance.mockResolvedValueOnce({ status: 'success', data: analysis({ status: 'FAILED', error_message: 'The performance analysis could not be completed. Please try again.' }) });
    const { unmount } = render(<PerformanceOverview assessmentId={7} />);
    expect(await screen.findByTestId('performance-error')).toHaveTextContent(/could not be completed/);
    unmount();

    svc.getAssessmentPerformance.mockResolvedValue({ status: 'success', data: analysis({ finalized_answer_count: 0, overall_average_percentage: null, overall_status: 'INSUFFICIENT_DATA' }) });
    render(<PerformanceOverview assessmentId={7} />);
    expect(await screen.findByTestId('no-finalized')).toBeInTheDocument();
  });
});

describe('StudentPerformanceCard', () => {
  it('shows finalized totals and areas for review without ability labels', async () => {
    svc.getStudentPerformance.mockResolvedValue({ status: 'success', data: studentData() });
    render(<StudentPerformanceCard studentId={3} assessmentId={7} />);
    expect(await screen.findByTestId('student-overall')).toHaveTextContent('60%');
    expect(screen.getByTestId('student-questions')).toHaveTextContent('8 / 10');
    const areas = screen.getByTestId('areas-for-review');
    expect(areas).toHaveTextContent('Transactions');
    expect(areas).toHaveTextContent('40%');
    expect(screen.getByTestId('student-performance-card').textContent).not.toMatch(/weak|poor|fail|deficien/i);
  });

  it('marks unfinalized grades clearly', async () => {
    svc.getStudentPerformance.mockResolvedValue({ status: 'success', data: studentData({ has_finalized_grades: false, overall_percentage: null, finalized_question_count: 0, areas_for_review: [], questions: [{ question_id: 11, question_number: 1, question_text_excerpt: 'SQL', maximum_marks: 10, answered: true, awarded_marks: 8, is_finalized: false, percentage: null, topics: [], learning_outcome_code: null }] }) });
    render(<StudentPerformanceCard studentId={3} assessmentId={7} />);
    expect(await screen.findByTestId('student-overall')).toHaveTextContent('Not finalized');
    expect(screen.getByTestId('student-questions')).toHaveTextContent('(not finalized)');
    expect(screen.getByTestId('areas-for-review')).toHaveTextContent(/once grades are finalized/);
  });

  it('shows authorization errors', async () => {
    svc.getStudentPerformance.mockRejectedValue(new ApiError(403, 'x'));
    render(<StudentPerformanceCard studentId={3} assessmentId={7} />);
    expect(await screen.findByTestId('performance-error')).toHaveTextContent(/not authorized/i);
  });
});
