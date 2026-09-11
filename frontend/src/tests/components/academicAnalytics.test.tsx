import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { AcademicAnalytics } from '@/pages/AcademicAnalytics';
import { AnalyticsEmptyState, AnalyticsError, AnalyticsLoading, freshness, getAnalyticsErrorMessage } from '@/components/analytics/AnalyticsStates';
import { DifficultyDistribution, KPIGrid } from '@/components/analytics/AnalyticsSummary';
import { LearningGapSummary, ProgramOutcomeCoverage, StudentPerformanceCard } from '@/components/analytics/AnalyticsOutcomesPerformance';
import { AiEvaluationSummary, GradingSummary } from '@/components/analytics/AnalyticsAiCollab';
import { ApiError } from '@/services/api';
import { AnalyticsOverview, FilterOptions } from '@/types/analytics';

vi.mock('@/services/academicAnalyticsService', () => ({
  academicAnalyticsService: { getOverview: vi.fn(), getFilterOptions: vi.fn(), getHistoricalAnalytics: vi.fn(), compareAssessments: vi.fn(), exportAnalytics: vi.fn() },
}));
vi.mock('@/context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, name: 'Dr. A', role: 'FACULTY' }, loading: false }) }));

import { academicAnalyticsService } from '@/services/academicAnalyticsService';
const svc = academicAnalyticsService as unknown as Record<string, ReturnType<typeof vi.fn>>;
const ok = <T,>(data: T) => ({ status: 'success', message: 'ok', data });

const options: FilterOptions = {
  courses: [{ id: 1, code: 'CSE101', name: 'Database Systems', semester: 'Fall', academic_year: '2026' }, { id: 2, code: 'CSE202', name: 'Algorithms', semester: 'Fall', academic_year: '2026' }],
  assessments: [{ id: 10, course_id: 1, title: 'Midterm', type: 'midterm', date: '2026-10-01' }, { id: 11, course_id: 2, title: 'Algo Final', type: 'final', date: null }],
  semesters: ['Fall'], academic_years: ['2026'], assessment_types: ['midterm', 'final'],
};

const gapCounts = { STRONG: 1, ON_TARGET: 2, MINOR_GAP: 0, MODERATE_GAP: 1, HIGH_GAP: 0, INSUFFICIENT_DATA: 1 };
const overview: AnalyticsOverview = {
  filters: {}, scope: { course_ids: [1, 2], assessment_ids: [10, 11], student_data_course_ids: [1, 2], student_data_restricted: false },
  kpis: {
    courses: { value: 2, label: 'Courses' }, assessments: { value: 2, label: 'Assessments' }, questions: { value: 10, label: 'Questions' },
    average_quality: { value: 82.4, label: 'Avg Quality', unit: 'score', explanation: 'Average STEP 13 score', basis: '2 analyzed' },
    student_performance: { value: 74.8, label: 'Student Performance', unit: 'percent', basis: '4 finalized submissions' },
    co_coverage: { value: 33.3, label: 'CO Coverage', unit: 'percent', basis: '1/3 outcomes' }, open_gaps: { value: 1, label: 'Open Gaps' }, ai_analysis_runs: { value: 2, label: 'AI Analysis Runs' },
  },
  assessment_quality: { analyzed_assessments: 2, average_score: 82.4, counts: { EXCELLENT: 0, GOOD: 1, FAIR: 0, NEEDS_REVIEW: 0, REQUIRES_ATTENTION: 1 }, explanation: 'x',
    trend: [{ assessment_id: 10, title: 'Midterm', type: 'midterm', course_id: 1, date: '2026-10-01', date_source: 'assessment_date', score: 84, rating: 'GOOD' }, { assessment_id: 11, title: 'Algo Final', type: 'final', course_id: 2, date: null, date_source: 'analyzed_at', score: 58, rating: 'REQUIRES_ATTENTION' }] },
  difficulty: { total_questions: 10, unclassified: 0, total_deviation: 20, balance_status: 'SLIGHTLY_UNBALANCED', bands: { slight_deviation: 15, significant_deviation: 45 }, explanation: 'd',
    distribution: [{ level: 'easy', count: 3, percentage: 30, target_percentage: 30, difference: 0 }, { level: 'medium', count: 4, percentage: 40, target_percentage: 50, difference: -10 }, { level: 'hard', count: 3, percentage: 30, target_percentage: 20, difference: 10 }] },
  cognitive: { total_questions: 10, unclassified: 0, distinct_levels: 3, explanation: 'c', distribution: [{ level: 'Remember', count: 2, percentage: 20 }, { level: 'Understand', count: 3, percentage: 30 }, { level: 'Apply', count: 5, percentage: 50 }, { level: 'Analyze', count: 0, percentage: 0 }, { level: 'Evaluate', count: 0, percentage: 0 }, { level: 'Create', count: 0, percentage: 0 }] },
  learning_outcomes: { total_outcomes: 3, covered_outcomes: 1, coverage_percentage: 33.3, analyzed_assessments: 1, explanation: 'lo', weak_outcomes: [],
    outcomes: [{ learning_outcome_id: 1, code: 'CO1', description: 'Explain', course_id: 1, course_code: 'CSE101', questions: 4, strong: 3, weak: 1, not_aligned: 0, coverage_percentage: 75, status: 'COVERED' }, { learning_outcome_id: 2, code: 'CO2', description: 'Apply', course_id: 1, course_code: 'CSE101', questions: 2, strong: 0, weak: 1, not_aligned: 1, coverage_percentage: 0, status: 'NOT_ALIGNED' }, { learning_outcome_id: 3, code: 'CO3', description: 'Optimize', course_id: 1, course_code: 'CSE101', questions: 0, strong: 0, weak: 0, not_aligned: 0, coverage_percentage: null, status: 'NOT_ASSESSED' }] },
  program_outcomes: { configured: false, message: 'PO analysis is not configured for the selected course(s).', courses: [], program_outcomes: [] },
  performance: { available: true, average_percentage: 74.8, median_percentage: 76, minimum_percentage: 31, maximum_percentage: 98, submissions: 4, responses: 142, benchmark_percent: 70, gap: -4.8, status: 'ON_TARGET', explanation: 'p', assessments_without_analysis: 0,
    trend: [{ assessment_id: 10, title: 'Midterm', type: 'midterm', date: '2026-10-01', average_percentage: 74.8, responses: 142, status: 'ON_TARGET', sufficient: true }] },
  learning_gaps: { analyzed_assessments: 1, counts: gapCounts, open_gaps: 1, benchmark_percent: 70, explanation: 'g',
    top_gaps: [{ learning_outcome_id: 3, code: 'CO3', description: 'Query Optimization', assessment_id: 10, assessment_title: 'Midterm', average_percentage: 51, benchmark_percent: 70, gap: 19, responses: 12, status: 'MODERATE_GAP' }] },
  question_performance: [{ question_id: 4, assessment_id: 10, assessment_title: 'Midterm', question_number: 4, excerpt: 'Normalize the schema', topics: ['Normalization'], co: 'CO2', difficulty: 'medium', cognitive_level: 'Apply', average_percentage: 58, responses: 142, gap: 12, status: 'MODERATE_GAP' },
    { question_id: 1, assessment_id: 10, assessment_title: 'Midterm', question_number: 1, excerpt: 'Define key', topics: ['Keys'], co: 'CO1', difficulty: 'easy', cognitive_level: 'Remember', average_percentage: 91, responses: 142, gap: -21, status: 'STRONG' }],
  topic_performance: [{ topic: 'Normalization', questions: 1, question_ids: [4], responses: 142, assessments: 1, average_percentage: 58, gap: 12, status: 'MODERATE_GAP' }, { topic: 'Keys', questions: 1, question_ids: [1], responses: 142, assessments: 1, average_percentage: 91, gap: -21, status: 'STRONG' }],
  similarity: { analyzed_assessments: 2, by_status: { POTENTIAL_DUPLICATE: { matches: 4, questions: 4 }, HIGHLY_SIMILAR: { matches: 12, questions: 9 }, SOMEWHAT_SIMILAR: { matches: 23, questions: 20 }, NOT_SIMILAR: { matches: 0, questions: 0 } }, flagged_assessments: [{ assessment_id: 10, flagged_questions: 4 }], thresholds: {}, explanation: 's' },
  question_bank: { bank_questions: 12, assessment_questions: 10, bank_previously_matched: 1, bank_by_difficulty: { easy: 4, medium: 6, hard: 2 }, bank_by_cognitive: {}, bank_by_source: {}, assessment_questions_with_lo: 10, assessment_questions_without_lo: 0 },
  rubrics: { total: 3, draft: 1, approved: 2, archived: 0, average_criteria: 4.5, by_generation_method: {}, faculty_ratings: { rated: 0, average_rating: null, acceptance_rate: null, revision_rate: null, note: 'n' } },
  grading: { available: true, ai_assisted_answers: 40, faculty_accepted: 30, faculty_modified: 8, faculty_rejected: 2, compared_answers: 38, mae: 0.68, mean_signed_difference: 0.3, ai_suggestion_mean: 7.8, final_faculty_grade_mean: 7.5, exact_agreement_rate: 60, explanation: 'gr' },
  inter_grader: { available: false, message: 'Inter-grader consistency (STEP 29) is not available in this deployment.', indicator_name: 'FacultyLens Agreement Indicator' },
  ai_evaluation: { overall_status: 'PASSED', evaluated_tasks: 1, run_count: 1, explanation: 'ai', trend: [{ run_id: 7, task: 'DIFFICULTY_CLASSIFICATION', model_id: 1, prompt_version_id: null, dataset_id: 1, metric: 'macro_f1', value: 0.82, gate_status: 'PASSED', completed_at: '2026-09-11T10:00:00Z' }],
    tasks: [{ task: 'DIFFICULTY_CLASSIFICATION', evaluated: true, headline_metric: 'macro_f1', headline_value: 0.82, gate_status: 'PASSED', run_id: 7, completed_at: '2026-09-11T10:00:00Z', example_count: 40, regression: false }, { task: 'BLOOM_CLASSIFICATION', evaluated: false, headline_metric: 'macro_f1', headline_value: null, gate_status: null, run_id: null, completed_at: null, example_count: null, regression: false }] },
  recommendations: { total: 26, active: 7, accepted: 12, dismissed: 3, under_review: 4, active_by_priority: { high: 2, medium: 3, low: 2 }, feedback: { total: 15, accepted_percent: 62, dismissed_percent: 18, needs_review_percent: 20, average_usefulness: 4.1, label: 'Faculty Interaction Signal', explanation: 'fb' } },
  collaboration: { shared_courses: 1, active_collaborators: 2, pending_invitations: 0, open_discussions: 3, resolved_discussions: 5, open_reviews: 1, by_role: { EDITOR: 1, REVIEWER: 1 }, activity: { days: 30, since: '2026-08-12', series: [], totals: { comments: 4, reviews: 2, approvals: 1, question_edits: 0, rubric_reviews: 0 } } },
  assessments: [{ assessment_id: 10, title: 'Midterm', type: 'midterm', status: 'published', date: '2026-10-01', course: { id: 1, code: 'CSE101', name: 'Database Systems', semester: 'Fall', academic_year: '2026' }, questions: 10, quality_score: 84, quality_rating: 'GOOD', difficulty_balance_score: 90, cognitive_balance_score: 60, lo_alignment_score: 70, similar_questions: 4, performance_percentage: 74.8, performance_status: 'ON_TARGET', performance_responses: 142, high_gaps: 0 },
    { assessment_id: 11, title: 'Algo Final', type: 'final', status: 'draft', date: null, course: { id: 2, code: 'CSE202', name: 'Algorithms', semester: 'Fall', academic_year: '2026' }, questions: 0, quality_score: 58, quality_rating: 'REQUIRES_ATTENTION', difficulty_balance_score: null, cognitive_balance_score: null, lo_alignment_score: null, similar_questions: 0, performance_percentage: null, performance_status: null, performance_responses: null, high_gaps: null }],
  attention_areas: [{ severity: 'HIGH', type: 'LEARNING_GAP', title: 'CO3 performance is below benchmark', detail: 'Average 51% vs benchmark 70% (gap 19%)', status: 'MODERATE_GAP', link: { type: 'assessment', id: 10 } }, { severity: 'MEDIUM', type: 'SIMILARITY', title: '4 question(s) have potential-duplicate similarity', detail: 'Similarity ≥ 0.85', status: 'POTENTIAL_DUPLICATE', link: null }],
  meta: { generated_at: new Date().toISOString(), cached: false, cache_ttl_seconds: 300, benchmark_percent: 70, disclaimer: 'Analytics are evidence, trends and signals for faculty review.' },
};

const emptyOverview: AnalyticsOverview = {
  ...overview, scope: { course_ids: [], assessment_ids: [], student_data_course_ids: [], student_data_restricted: false },
  kpis: { ...overview.kpis, courses: { value: 0, label: 'Courses' }, assessments: { value: 0, label: 'Assessments' }, questions: { value: 0, label: 'Questions' }, average_quality: { value: null, label: 'Avg Quality', unit: 'score' }, student_performance: { value: null, label: 'Student Performance', unit: 'percent', basis: 'No finalized grades' }, co_coverage: { value: null, label: 'CO Coverage', unit: 'percent' }, open_gaps: { value: null, label: 'Open Gaps' }, ai_analysis_runs: { value: 0, label: 'AI Analysis Runs' } },
  assessment_quality: { ...overview.assessment_quality, analyzed_assessments: 0, average_score: null, trend: [], counts: { EXCELLENT: 0, GOOD: 0, FAIR: 0, NEEDS_REVIEW: 0, REQUIRES_ATTENTION: 0 } },
  difficulty: { ...overview.difficulty, total_questions: 0, balance_status: null, total_deviation: null }, cognitive: { ...overview.cognitive, total_questions: 0 },
  performance: { ...overview.performance, available: false, average_percentage: null, trend: [] }, learning_gaps: { ...overview.learning_gaps, analyzed_assessments: 0, top_gaps: [] },
  question_performance: [], topic_performance: [], assessments: [], attention_areas: [], ai_evaluation: { ...overview.ai_evaluation, evaluated_tasks: 0, trend: [], overall_status: 'NOT_EVALUATED' },
};

const renderPage = (route = '/analytics') => render(<MemoryRouter initialEntries={[route]}><AcademicAnalytics /></MemoryRouter>);

beforeEach(() => {
  vi.clearAllMocks();
  svc.getFilterOptions.mockResolvedValue(ok(options));
  svc.getOverview.mockResolvedValue(ok(overview));
  svc.getHistoricalAnalytics.mockResolvedValue(ok({ course_code: 'CSE101', course_name: 'Database Systems', course_ids: [1, 3], assessments: [], terms: [{ term: '2026 Spring', academic_year: '2026', semester: 'Spring', assessments: 1, average_quality: 76, analyzed_assessments: 1, average_performance: 68, performance_assessments: 1, average_lo_alignment: null }, { term: '2026 Fall', academic_year: '2026', semester: 'Fall', assessments: 1, average_quality: 84, analyzed_assessments: 1, average_performance: 74.8, performance_assessments: 1, average_lo_alignment: 70 }] }));
});

describe('AcademicAnalytics page', () => {
  it('renders KPIs, sections and attention areas from real overview data', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByTestId('kpi-grid')).toBeInTheDocument());
    expect(screen.getByTestId('kpi-average_quality')).toHaveTextContent('82.4');
    expect(screen.getByTestId('kpi-student_performance')).toHaveTextContent('74.8%');
    expect(screen.getByTestId('kpi-co_coverage')).toHaveTextContent('33.3%');
    expect(screen.getByTestId('kpi-open_gaps')).toHaveTextContent('1');
    expect(screen.getByTestId('attention-areas')).toHaveTextContent('CO3 performance is below benchmark');
    expect(screen.getByTestId('difficulty-distribution')).toHaveTextContent('Slightly unbalanced');
    expect(screen.getByTestId('difficulty-distribution')).toHaveTextContent('+10%');
    expect(screen.getByTestId('cognitive-distribution')).toHaveTextContent('3 of 6 Bloom levels');
    expect(screen.getByTestId('lo-coverage')).toHaveTextContent('1/3 outcomes covered');
    expect(screen.getByTestId('po-coverage')).toHaveTextContent('PO analysis is not configured');
    expect(screen.getByTestId('student-performance')).toHaveTextContent('76.0%');
    expect(screen.getByTestId('learning-gaps')).toHaveTextContent('Query Optimization');
    expect(screen.getByTestId('similarity-summary')).toHaveTextContent('Potential duplicate');
    expect(screen.getByTestId('grading-summary')).toHaveTextContent('0.68');
    expect(screen.getByTestId('inter-grader-summary')).toHaveTextContent('not available');
    expect(screen.getByTestId('ai-evaluation-summary')).toHaveTextContent('Not evaluated yet');
    expect(screen.getByTestId('ai-evaluation-summary')).toHaveTextContent('82.0%');
    expect(screen.getByTestId('feedback-signal')).toHaveTextContent('Faculty Interaction Signal');
    expect(screen.getByTestId('collaboration-summary')).toHaveTextContent('Unresolved discussions');
    expect(screen.getByTestId('freshness')).toHaveTextContent('Last calculated');
    expect(screen.queryByTestId('analytics-empty-state')).not.toBeInTheDocument();
    expect(screen.queryByTestId('course-history')).not.toBeInTheDocument();
  });

  it('applies filters through the URL and reloads every section', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByTestId('analytics-filters')).toBeInTheDocument());
    await waitFor(() => expect(screen.getByLabelText('Course')).toBeInTheDocument());
    fireEvent.change(screen.getByLabelText('Course'), { target: { value: '1' } });
    expect(within(screen.getByLabelText('Assessment') as HTMLSelectElement).queryByText('Algo Final')).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Assessment'), { target: { value: '10' } });
    fireEvent.change(screen.getByLabelText('Semester'), { target: { value: 'Fall' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply Filters' }));
    await waitFor(() => expect(svc.getOverview).toHaveBeenLastCalledWith(expect.objectContaining({ course_id: 1, assessment_id: 10, semester: 'Fall' }), false));
    await waitFor(() => expect(svc.getHistoricalAnalytics).toHaveBeenCalledWith(1));
    expect(await screen.findByTestId('course-history')).toHaveTextContent('2026 Spring');
    fireEvent.click(screen.getByRole('button', { name: 'Reset' }));
    await waitFor(() => expect(svc.getOverview).toHaveBeenLastCalledWith({}, false));
  });

  it('compares assessments, filters questions by topic and exports', async () => {
    svc.compareAssessments.mockResolvedValue(ok({ requested: 2, authorized: 2, note: 'Comparison is informational; no assessment is changed.',
      assessments: overview.assessments.map((a) => ({ ...a, difficulty: overview.difficulty, cognitive: overview.cognitive, learning_outcomes: overview.learning_outcomes, similarity: overview.similarity.by_status, performance: a.assessment_id === 10 ? overview.performance : { available: false }, learning_gaps: a.assessment_id === 10 ? overview.learning_gaps : null })) }));
    renderPage();
    await waitFor(() => expect(screen.getByTestId('assessment-comparison')).toBeInTheDocument());
    fireEvent.click(screen.getByLabelText('Select Midterm for comparison'));
    fireEvent.click(screen.getByLabelText('Select Algo Final for comparison'));
    fireEvent.click(screen.getByRole('button', { name: 'Compare' }));
    await waitFor(() => expect(svc.compareAssessments).toHaveBeenCalledWith([10, 11]));
    const cmp = screen.getByTestId('assessment-comparison');
    await waitFor(() => expect(cmp).toHaveTextContent('No finalized grades'));
    expect(cmp).toHaveTextContent('84 (Good)');
    expect(cmp).toHaveTextContent('no assessment is changed');

    fireEvent.click(screen.getByRole('button', { name: 'Normalization' }));
    const qp = screen.getByTestId('question-performance');
    expect(qp).toHaveTextContent('Topic: Normalization');
    expect(qp).toHaveTextContent('Q4');
    expect(qp).not.toHaveTextContent('Q1');
    fireEvent.click(screen.getByRole('button', { name: 'Clear topic' }));
    expect(screen.getByTestId('question-performance')).toHaveTextContent('Q1');

    fireEvent.click(screen.getByRole('button', { name: /CSV/ }));
    await waitFor(() => expect(svc.exportAnalytics).toHaveBeenCalledWith({}, 'csv'));
  });

  it('shows empty states and N/A instead of fake zeroes when there is no data', async () => {
    svc.getOverview.mockResolvedValue(ok(emptyOverview));
    renderPage();
    await waitFor(() => expect(screen.getByTestId('analytics-empty-state')).toBeInTheDocument());
    expect(screen.getByTestId('kpi-average_quality')).toHaveTextContent('N/A');
    expect(screen.getByTestId('kpi-student_performance')).toHaveTextContent('N/A');
    expect(screen.getByTestId('student-performance')).toHaveTextContent('No finalized grades are available yet.');
    expect(screen.getByTestId('ai-evaluation-summary')).toHaveTextContent('No AI evaluation has been completed yet.');
    expect(screen.getByTestId('attention-areas')).toHaveTextContent('No attention signals');
  });

  it('hides student-level sections for restricted roles', async () => {
    svc.getOverview.mockResolvedValue(ok({ ...overview, scope: { ...overview.scope, student_data_restricted: true }, performance: { ...overview.performance, available: false }, grading: { available: false, ai_assisted_answers: 0 } }));
    renderPage();
    await waitFor(() => expect(screen.getByTestId('student-performance')).toBeInTheDocument());
    expect(screen.getByTestId('student-performance')).toHaveTextContent('Restricted for your role');
    expect(screen.queryByTestId('question-performance')).not.toBeInTheDocument();
    expect(screen.getByTestId('grading-summary')).toHaveTextContent('Restricted for your role');
  });

  it('shows an error state with retry when the overview fails', async () => {
    svc.getOverview.mockRejectedValueOnce(new ApiError(403, '')).mockResolvedValue(ok(overview));
    renderPage();
    const alert = await screen.findByTestId('analytics-error');
    expect(alert).toHaveTextContent('You do not have access');
    fireEvent.click(within(alert).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.queryByTestId('analytics-error')).not.toBeInTheDocument());
    expect(svc.getOverview).toHaveBeenLastCalledWith({}, true);
  });
});

describe('analytics components', () => {
  it('formats helpers and error messages', () => {
    expect(getAnalyticsErrorMessage(new ApiError(429, ''))).toMatch(/Too many requests/);
    expect(getAnalyticsErrorMessage(new ApiError(500, ''))).toMatch(/temporarily unavailable/);
    expect(getAnalyticsErrorMessage(new Error('boom'))).toBe('boom');
    expect(freshness({ generated_at: new Date().toISOString(), cached: true, cache_ttl_seconds: 300, benchmark_percent: 70, disclaimer: '' })).toMatch(/just now.*up to 5 minutes old/);
  });

  it('renders difficulty distribution with targets and differences', () => {
    render(<DifficultyDistribution data={overview.difficulty} />);
    expect(screen.getByLabelText('Difficulty distribution versus target')).toBeInTheDocument();
    expect(screen.getByTestId('difficulty-distribution')).toHaveTextContent('-10%');
    expect(screen.getByTestId('difficulty-distribution')).toHaveTextContent('Total deviation from target 20%');
  });

  it('renders KPI grid with N/A for null values', () => {
    render(<KPIGrid kpis={emptyOverview.kpis} />);
    expect(screen.getByTestId('kpi-co_coverage')).toHaveTextContent('N/A');
    expect(screen.getByTestId('kpi-courses')).toHaveTextContent('0');
  });

  it('renders performance, gaps, PO and grading states', () => {
    render(<MemoryRouter><StudentPerformanceCard data={{ ...overview.performance, status: 'INSUFFICIENT_DATA' }} restricted={false} /></MemoryRouter>);
    expect(screen.getByTestId('student-performance')).toHaveTextContent('not classified as a gap');
    render(<MemoryRouter><LearningGapSummary data={overview.learning_gaps} /></MemoryRouter>);
    expect(screen.getByTestId('learning-gaps')).toHaveTextContent('Gap 19%');
    render(<ProgramOutcomeCoverage data={{ configured: true, analyzed_courses: 1, unanalyzed_courses: 0, courses: [], unmapped_questions: 2, program_outcomes: [{ program_outcome_id: 1, code: 'PO1', title: 'Engineering knowledge', courses: 1, mapped_cos: 2, mapped_questions: 5, strong_mappings: 1, weak_mappings: 1, evidence_percent: 40, student_performance_percent: null, status: 'ASSESSED' }] }} />);
    expect(screen.getByTestId('po-coverage')).toHaveTextContent('PO1');
    expect(screen.getByTestId('po-coverage')).toHaveTextContent('2 question(s) have no confirmed CO mapping');
    render(<GradingSummary data={{ available: false, ai_assisted_answers: 0 }} restricted={false} />);
    expect(screen.getByTestId('grading-summary')).toHaveTextContent('No AI-assisted grading yet');
    render(<MemoryRouter><AiEvaluationSummary data={overview.ai_evaluation} /></MemoryRouter>);
    expect(screen.getByLabelText('AI model performance across evaluation runs')).toBeInTheDocument();
  });

  it('renders loading, empty and error states', () => {
    render(<AnalyticsLoading />);
    expect(screen.getByTestId('analytics-loading')).toHaveTextContent('Loading analytics');
    render(<AnalyticsEmptyState />);
    expect(screen.getByTestId('analytics-empty-state')).toHaveTextContent('No assessment data available.');
    render(<AnalyticsError message="Unable to load this section." />);
    expect(screen.getByRole('alert')).toHaveTextContent('Unable to load this section.');
  });
});
