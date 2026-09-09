import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { MappingStatusBadge, formatCoStatus, formatPoStatus } from '@/components/coPo/MappingStatusBadge';
import { MappingLevelSelector } from '@/components/coPo/MappingLevelSelector';
import { CoPoMappingMatrix } from '@/components/coPo/CoPoMappingMatrix';
import { CoPoMappingEditor } from '@/components/coPo/CoPoMappingEditor';
import { MappingFindings, MappingValidationSummary } from '@/components/coPo/MappingFindings';
import { CoPerformanceMatrix, PoEvidenceMatrix, CoCoverageTable } from '@/components/coPo/EvidenceTables';
import { QuestionCoMappingReviewList } from '@/components/coPo/QuestionCoMappingReview';
import { CourseOutcomeList, ProgramOutcomeList } from '@/components/coPo/OutcomeLists';
import { MappingEmptyState, MappingError, MappingLoading, getMappingErrorMessage } from '@/components/coPo/MappingStates';
import { CoPoMapping } from '@/pages/CoPoMapping';
import { ApiError } from '@/services/api';
import { CoCoverage, CoPoMatrix, CoPoOverview, MappingAnalysisRun, MappingFinding, PoEvidence, QuestionCoMappingReview } from '@/types/coPo';

vi.mock('@/services/coPoMappingService', () => ({
  coPoMappingService: {
    getCourseMapping: vi.fn(), analyzeCourseMapping: vi.fn(), getMappingMatrix: vi.fn(), getMappingFindings: vi.fn(),
    getCoPerformance: vi.fn(), getPoEvidence: vi.fn(), getQuestionMappings: vi.fn(), createMapping: vi.fn(), updateMapping: vi.fn(),
    deleteMapping: vi.fn(), confirmQuestionCoMapping: vi.fn(), rejectQuestionCoMapping: vi.fn(), getPrograms: vi.fn(), createProgram: vi.fn(),
    createProgramOutcome: vi.fn(), updateProgramOutcome: vi.fn(), deleteProgramOutcome: vi.fn(),
  },
}));
vi.mock('@/services/courseService', () => ({ courseService: { update: vi.fn() } }));

import { coPoMappingService } from '@/services/coPoMappingService';

const svc = coPoMappingService as unknown as Record<string, ReturnType<typeof vi.fn>>;

const matrix = (): CoPoMatrix => ({
  program_outcomes: [{ id: 1, code: 'PO1', title: 'Engineering Knowledge' }, { id: 2, code: 'PO2', title: 'Problem Analysis' }, { id: 3, code: 'PO3', title: 'Design' }],
  rows: [
    { learning_outcome_id: 11, code: 'CO1', description: 'Outcome 1', cells: [{ program_outcome_id: 1, level: 3, mapping_id: 101 }, { program_outcome_id: 2, level: 2, mapping_id: 102 }, { program_outcome_id: 3, level: 0, mapping_id: null }] },
    { learning_outcome_id: 12, code: 'CO2', description: 'Outcome 2', cells: [{ program_outcome_id: 1, level: 0, mapping_id: null }, { program_outcome_id: 2, level: 3, mapping_id: 103 }, { program_outcome_id: 3, level: 1, mapping_id: 104 }] },
  ],
  active_mappings: 4, possible_mappings: 6, density_percent: 66.67, legend: {},
});

const coverage = (): CoCoverage[] => [
  { learning_outcome_id: 11, code: 'LO1', display_code: 'CO1', description: 'Outcome 1', cognitive_level: 'Understand', question_count: 2, question_ids: [1, 2], mapped_marks: 40, coverage_percent: 40, coverage_status: 'ASSESSED', po_mapping_count: 2, performance_percent: 84, performance_gap: -14, performance_status: 'STRONG', response_count: 40, status: 'STRONG' },
  { learning_outcome_id: 12, code: 'LO2', display_code: 'CO2', description: 'Outcome 2', cognitive_level: 'Analyze', question_count: 2, question_ids: [3, 4], mapped_marks: 35, coverage_percent: 35, coverage_status: 'ASSESSED', po_mapping_count: 2, performance_percent: 52, performance_gap: 18, performance_status: 'MODERATE_GAP', response_count: 40, status: 'REVIEW' },
  { learning_outcome_id: 13, code: 'LO4', display_code: 'CO4', description: 'Outcome 4', cognitive_level: null, question_count: 1, question_ids: [6], mapped_marks: 2, coverage_percent: 2, coverage_status: 'LOW_COVERAGE', po_mapping_count: 0, performance_percent: null, performance_gap: null, performance_status: 'INSUFFICIENT_DATA', response_count: 0, status: 'LOW_COVERAGE' },
];

const evidence = (): PoEvidence[] => [
  { program_outcome_id: 1, code: 'PO1', title: 'Engineering Knowledge', mapped_co_count: 1, mapped_cos: [{ learning_outcome_id: 11, code: 'CO1', level: 3, level_label: 'HIGH' }], co_evidence: 'HIGH', contribution_percent: 40, assessment_evidence_percent: 40, question_ids: [1, 2], student_performance_percent: 84, evidence_status: 'ASSESSED', status: 'EVIDENCE_AVAILABLE' },
  { program_outcome_id: 2, code: 'PO2', title: 'Problem Analysis', mapped_co_count: 2, mapped_cos: [{ learning_outcome_id: 11, code: 'CO1', level: 2, level_label: 'MEDIUM' }, { learning_outcome_id: 12, code: 'CO2', level: 3, level_label: 'HIGH' }], co_evidence: 'HIGH', contribution_percent: 61.67, assessment_evidence_percent: 75, question_ids: [1, 2, 3, 4], student_performance_percent: 61, evidence_status: 'ASSESSED', status: 'REVIEW' },
  { program_outcome_id: 4, code: 'PO4', title: 'Investigation', mapped_co_count: 0, mapped_cos: [], co_evidence: 'NONE', contribution_percent: 0, assessment_evidence_percent: 0, question_ids: [], student_performance_percent: null, evidence_status: 'NOT_MAPPED', status: 'NOT_MAPPED' },
];

const findings = (): MappingFinding[] => [
  { id: 1, type: 'UNMAPPED_QUESTION', severity: 'HIGH', title: '3 questions have no confirmed CO mapping.', description: 'Marks not attributed.', recommendation: 'Review each question and confirm the course outcome it provides evidence for.', category: 'learning_outcome', priority: 'high', course_outcome_id: null, program_outcome_id: null, question_id: null, evidence: {} },
  { id: 2, type: 'LOW_CO_COVERAGE', severity: 'MEDIUM', title: 'CO4 has low assessment coverage (3%).', description: 'Below threshold.', recommendation: 'Review whether the assessment sufficiently measures CO4.', category: 'learning_outcome', priority: 'medium', course_outcome_id: 13, program_outcome_id: null, question_id: null, evidence: {} },
  { id: 3, type: 'LOW_PO_EVIDENCE', severity: 'LOW', title: 'PO8 has limited evidence in this course.', description: 'x', recommendation: 'Review mapped COs.', category: 'general', priority: 'low', course_outcome_id: null, program_outcome_id: 8, question_id: null, evidence: {} },
];

const run = (o: Partial<MappingAnalysisRun> = {}): MappingAnalysisRun => ({
  id: 1, course_id: 3, program_id: 1, status: 'COMPLETED', is_current: true, is_stale: false, stale_reasons: [], mapping_version: 'abc',
  summary: { co_count: 4, po_count: 5, active_mapping_count: 8, possible_mapping_count: 20, mapping_density_percent: 40, question_count: 6, questions_mapped: 5, cos_with_evidence: 4, pos_with_evidence: 4, co_coverage_percent: 100, po_evidence_percent: 80, question_mapping_percent: 83.33, finding_counts: { HIGH: 1, MEDIUM: 1, LOW: 1, INFO: 0 }, validation_checks: [{ ok: true, label: 'All COs have assessment evidence' }, { ok: false, label: '5 / 6 questions have confirmed CO mappings' }] },
  matrix: matrix(), co_coverage: coverage(), po_evidence: evidence(), thresholds: null, error_message: null, analyzed_at: '2026-09-10T10:00:00Z', disclaimer: 'CO/PO mapping analysis provides evidence and review signals … does not constitute an accreditation decision.', findings: findings(), ...o,
});

const overview = (o: Partial<CoPoOverview> = {}): CoPoOverview => ({
  course: { id: 3, course_code: 'CSE101', course_name: 'Database Systems' },
  program: { id: 1, code: 'CSE', name: 'Computer Science & Engineering' },
  course_outcomes: [{ id: 11, code: 'LO1', display_code: 'CO1', description: 'Outcome 1', cognitive_level: 'Understand' }, { id: 12, code: 'LO2', display_code: 'CO2', description: 'Outcome 2', cognitive_level: 'Analyze' }],
  program_outcomes: [{ id: 1, program_id: 1, code: 'PO1', title: 'Engineering Knowledge', sort_order: 1, status: 'ACTIVE' }, { id: 2, program_id: 1, code: 'PO2', title: 'Problem Analysis', sort_order: 2, status: 'ACTIVE' }, { id: 3, program_id: 1, code: 'PO3', title: 'Design', sort_order: 3, status: 'ACTIVE' }],
  mappings: [{ id: 101, course_id: 3, learning_outcome_id: 11, program_outcome_id: 1, mapping_level: 3, level_label: 'HIGH' }],
  summary: { co_count: 2, po_count: 3, active_mapping_count: 1, possible_mapping_count: 6, mapping_density_percent: 16.67, question_count: 4, questions_mapped: 3 },
  thresholds: {}, current_run: null, disclaimer: 'CO/PO mapping analysis provides evidence and review signals … does not constitute an accreditation decision.', ...o,
});

const questions = (): QuestionCoMappingReview[] => [
  { question_id: 8, assessment_id: 7, assessment_title: 'Midterm', question_number: 8, question_text_excerpt: 'Analyze the normalization problems in this schema.', marks: 10, cognitive_level: 'Analyze', faculty_learning_outcome_id: null, confirmed: [], ai_suggestions: [{ learning_outcome_id: 12, code: 'CO2', similarity_score: 0.82, alignment: 'STRONG_ALIGNMENT', status: 'PENDING', mapping_source: 'AI_SUGGESTED' }], is_mapped: false },
  { question_id: 1, assessment_id: 7, assessment_title: 'Midterm', question_number: 1, question_text_excerpt: 'Define a key.', marks: 5, cognitive_level: 'Remember', faculty_learning_outcome_id: 11, confirmed: [{ learning_outcome_id: 11, code: 'CO1', source: 'FACULTY' }], ai_suggestions: [], is_mapped: true },
];

const renderPage = () => render(
  <MemoryRouter initialEntries={['/courses/3/co-po-mapping']}>
    <Routes><Route path="/courses/:courseId/co-po-mapping" element={<CoPoMapping />} /></Routes>
  </MemoryRouter>,
);

beforeEach(() => { Object.values(svc).forEach((fn) => fn.mockReset()); });

describe('CO/PO badges, selector and states', () => {
  it('formats statuses and renders badges without accreditation wording', () => {
    expect(formatCoStatus('LOW_COVERAGE')).toBe('Low Coverage');
    expect(formatPoStatus('EVIDENCE_AVAILABLE')).toBe('Evidence Available');
    render(<div><MappingStatusBadge kind="co" status="REVIEW" /><MappingStatusBadge kind="severity" status="HIGH" /><MappingStatusBadge kind="question" status="PENDING" /></div>);
    expect(screen.getByTestId('mapping-status-co')).toHaveTextContent('Review');
    expect(screen.getByTestId('mapping-status-severity')).toHaveTextContent('High');
    expect(screen.getByTestId('mapping-status-question')).toHaveTextContent('Pending Faculty Review');
    expect(document.body.textContent?.toLowerCase()).not.toMatch(/compliant|accredited/);
  });

  it('level selector emits 0-3 and reflects the selected level', () => {
    const onChange = vi.fn();
    render(<MappingLevelSelector value={2} onChange={onChange} />);
    expect(screen.getByTestId('level-2')).toHaveAttribute('aria-checked', 'true');
    fireEvent.click(screen.getByTestId('level-3'));
    expect(onChange).toHaveBeenCalledWith(3);
    fireEvent.click(screen.getByTestId('level-0'));
    expect(onChange).toHaveBeenCalledWith(0);
  });

  it('renders loading, empty and error states with safe messages', () => {
    render(<div><MappingLoading /><MappingEmptyState title="Nothing" description="Add outcomes." /><MappingError error={new ApiError(403, 'x')} onRetry={vi.fn()} /></div>);
    expect(screen.getByTestId('mapping-loading')).toBeInTheDocument();
    expect(screen.getByTestId('mapping-empty')).toHaveTextContent('Add outcomes.');
    expect(screen.getByTestId('mapping-error')).toHaveTextContent(/not authorized/i);
    expect(getMappingErrorMessage(new ApiError(422, 'PO does not belong'))).toBe('PO does not belong');
    expect(getMappingErrorMessage(new ApiError(503, 'x'))).toMatch(/temporarily unavailable/i);
  });
});

describe('Matrix and editor', () => {
  it('renders the CO x PO matrix with legend and density', () => {
    render(<CoPoMappingMatrix matrix={matrix()} />);
    const rows = screen.getAllByTestId('matrix-row');
    expect(rows).toHaveLength(2);
    expect(rows[0]).toHaveTextContent('CO1');
    const cells = within(rows[0]).getAllByTestId('matrix-cell');
    expect(cells[0]).toHaveTextContent('3');
    expect(cells[1]).toHaveTextContent('2');
    expect(cells[2]).toHaveTextContent('—');
    expect(screen.getByTestId('matrix-legend')).toHaveTextContent('4 / 6 mappings · density 66.67%');
    expect(screen.getByTestId('matrix-legend')).toHaveTextContent('3 High');
  });

  it('editor toggles edit mode and saves a level change', async () => {
    const onChangeLevel = vi.fn().mockResolvedValue(undefined);
    render(<CoPoMappingEditor matrix={matrix()} canEdit onChangeLevel={onChangeLevel} />);
    expect(screen.queryByTestId('mapping-level-selector')).not.toBeInTheDocument();
    fireEvent.click(screen.getByTestId('edit-matrix'));
    const selectors = screen.getAllByTestId('mapping-level-selector');
    expect(selectors).toHaveLength(6);
    fireEvent.click(within(selectors[2]).getByTestId('level-2')); // CO1 x PO3 was 0
    await waitFor(() => expect(onChangeLevel).toHaveBeenCalledWith(11, 3, null, 2));
    fireEvent.click(screen.getByTestId('done-editing'));
    expect(screen.queryByTestId('mapping-level-selector')).not.toBeInTheDocument();
  });

  it('empty matrix explains prerequisites', () => {
    render(<CoPoMappingMatrix matrix={{ ...matrix(), program_outcomes: [] }} />);
    expect(screen.getByTestId('matrix-empty')).toBeInTheDocument();
  });
});

describe('Findings, coverage and evidence', () => {
  it('lists findings with severity and recommendation', () => {
    render(<MappingFindings findings={findings()} />);
    const cards = screen.getAllByTestId('mapping-finding');
    expect(cards).toHaveLength(3);
    expect(cards[0]).toHaveTextContent('3 questions have no confirmed CO mapping.');
    expect(within(cards[0]).getByTestId('mapping-status-severity')).toHaveTextContent('High');
    expect(within(cards[0]).getByTestId('finding-recommendation')).toHaveTextContent(/confirm the course outcome/);
    expect(cards[1]).toHaveTextContent('CO4 has low assessment coverage (3%).');
  });

  it('validation summary renders tiles, bars and checks from data', () => {
    render(<MappingValidationSummary summary={run().summary!} />);
    const tiles = screen.getAllByTestId('mapping-tile');
    expect(tiles[0]).toHaveTextContent('4');
    expect(tiles[2]).toHaveTextContent('8 / 20');
    expect(tiles[3]).toHaveTextContent('5 / 6');
    expect(tiles[5]).toHaveTextContent('4 / 5');
    expect(screen.getByTestId('mapping-bars')).toHaveTextContent('80%');
    const checks = screen.getByTestId('validation-checks');
    expect(checks).toHaveTextContent('All COs have assessment evidence');
    expect(checks).toHaveTextContent('5 / 6 questions have confirmed CO mappings');
  });

  it('CO performance matrix and coverage table show coverage, performance, gap and status', () => {
    render(<div><CoPerformanceMatrix rows={coverage()} /><CoCoverageTable rows={coverage()} /></div>);
    const rows = screen.getAllByTestId('co-performance-row');
    expect(rows[0]).toHaveTextContent('CO1');
    expect(rows[0]).toHaveTextContent('40%');
    expect(rows[0]).toHaveTextContent('84%');
    expect(within(rows[0]).getByTestId('mapping-status-co')).toHaveTextContent('Strong');
    expect(rows[1]).toHaveTextContent('18 pts');
    expect(within(rows[1]).getByTestId('mapping-status-co')).toHaveTextContent('Review');
    expect(rows[2]).toHaveTextContent('N/A');
    expect(within(rows[2]).getByTestId('mapping-status-co')).toHaveTextContent('Low Coverage');
    expect(screen.getAllByTestId('co-coverage-row')[2]).toHaveTextContent('2%');
    expect(screen.getByTestId('co-performance-matrix')).toHaveTextContent(/not evidence that an outcome was not learned/);
  });

  it('PO evidence matrix shows CO evidence, assessment evidence, student evidence and N/A', () => {
    render(<PoEvidenceMatrix rows={evidence()} />);
    const rows = screen.getAllByTestId('po-evidence-row');
    expect(rows[0]).toHaveTextContent('PO1');
    expect(rows[0]).toHaveTextContent('HIGH');
    expect(rows[0]).toHaveTextContent('84%');
    expect(within(rows[0]).getByTestId('mapping-status-po')).toHaveTextContent('Evidence Available');
    expect(rows[1]).toHaveTextContent('CO1 (2), CO2 (3)');
    expect(within(rows[1]).getByTestId('mapping-status-po')).toHaveTextContent('Review');
    expect(rows[2]).toHaveTextContent('N/A');
    expect(within(rows[2]).getByTestId('mapping-status-po')).toHaveTextContent('Not Mapped');
    expect(screen.getByTestId('po-evidence-matrix')).toHaveTextContent(/not that a PO is achieved/);
  });
});

describe('Question CO mapping review and outcome lists', () => {
  it('shows AI suggestion as pending and lets faculty confirm or reject', async () => {
    const onConfirm = vi.fn().mockResolvedValue(undefined);
    const onReject = vi.fn().mockResolvedValue(undefined);
    render(<QuestionCoMappingReviewList questions={questions()} canEdit onConfirm={onConfirm} onReject={onReject} />);
    const rows = screen.getAllByTestId('question-co-row');
    expect(rows).toHaveLength(2);
    expect(within(rows[0]).getByTestId('co-missing')).toBeInTheDocument();
    expect(within(rows[0]).getByTestId('ai-suggestions')).toHaveTextContent('AI suggested');
    expect(within(rows[0]).getByTestId('ai-suggestions')).toHaveTextContent('CO2');
    expect(within(rows[0]).getByTestId('ai-suggestions')).toHaveTextContent('similarity 0.82');
    expect(within(rows[0]).getByTestId('mapping-status-question')).toHaveTextContent('Pending Faculty Review');
    expect(within(rows[1]).getByTestId('co-confirmed')).toHaveTextContent('CO1 · Faculty');

    fireEvent.click(within(rows[0]).getByTestId('confirm-suggestion'));
    await waitFor(() => expect(onConfirm).toHaveBeenCalledWith(8, 12));
    fireEvent.click(within(rows[0]).getByTestId('reject-suggestion'));
    await waitFor(() => expect(onReject).toHaveBeenCalledWith(8, 12));

    fireEvent.click(screen.getByTestId('filter-unmapped'));
    expect(screen.getAllByTestId('question-co-row')).toHaveLength(1);
  });

  it('outcome lists render COs as CO codes and allow adding a PO', async () => {
    const onAdd = vi.fn().mockResolvedValue(undefined);
    render(<div><CourseOutcomeList outcomes={overview().course_outcomes} /><ProgramOutcomeList outcomes={overview().program_outcomes} canEdit onAdd={onAdd} onDelete={vi.fn()} /></div>);
    expect(screen.getByTestId('course-outcome-list')).toHaveTextContent('CO1');
    expect(screen.getByTestId('course-outcome-list')).toHaveTextContent('CO2');
    expect(screen.getAllByTestId('po-row')).toHaveLength(3);
    fireEvent.click(screen.getByTestId('add-po'));
    fireEvent.change(screen.getByLabelText('Code'), { target: { value: 'PO4' } });
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Investigation' } });
    fireEvent.click(screen.getByTestId('save-po'));
    await waitFor(() => expect(onAdd).toHaveBeenCalledWith({ code: 'PO4', title: 'Investigation' }));
  });
});

describe('CoPoMapping page', () => {
  it('loads overview, analyzes, and shows findings, CO performance, PO evidence and disclaimer', async () => {
    svc.getCourseMapping.mockResolvedValueOnce({ status: 'success', data: overview() }).mockResolvedValue({ status: 'success', data: overview({ current_run: run({ findings: undefined }) }) });
    svc.getQuestionMappings.mockResolvedValue({ status: 'success', data: questions() });
    svc.getMappingFindings.mockResolvedValue({ status: 'success', data: findings(), run: run({ findings: undefined }) });
    svc.analyzeCourseMapping.mockResolvedValue({ status: 'success', data: run() });

    renderPage();
    expect(await screen.findByTestId('co-po-page')).toBeInTheDocument();
    expect(screen.getByTestId('program-name')).toHaveTextContent('CSE — Computer Science & Engineering');
    expect(screen.getByTestId('co-po-matrix')).toBeInTheDocument();
    expect(screen.getAllByTestId('mapping-empty')[0]).toHaveTextContent(/No mapping analysis yet/);

    fireEvent.click(screen.getByTestId('analyze-mapping'));
    await waitFor(() => expect(svc.analyzeCourseMapping).toHaveBeenCalledWith('3', false));
    expect(await screen.findByTestId('mapping-findings')).toBeInTheDocument();
    expect(screen.getAllByTestId('mapping-finding')).toHaveLength(3);
    expect(screen.getByTestId('co-performance-matrix')).toBeInTheDocument();
    expect(screen.getByTestId('po-evidence-matrix')).toBeInTheDocument();
    expect(screen.getByTestId('validation-checks')).toBeInTheDocument();
    expect(screen.getByTestId('mapping-disclaimer')).toHaveTextContent(/does not constitute an accreditation decision/);
  });

  it('prompts to assign a program when none is set and shows stale warning', async () => {
    svc.getCourseMapping.mockResolvedValue({ status: 'success', data: overview({ program: null, program_outcomes: [], current_run: run({ is_stale: true, status: 'STALE', stale_reasons: ['Mappings changed after this analysis.'], findings: undefined }) }) });
    svc.getQuestionMappings.mockResolvedValue({ status: 'success', data: [] });
    svc.getMappingFindings.mockResolvedValue({ status: 'success', data: [], run: run({ is_stale: true, status: 'STALE', stale_reasons: ['Mappings changed after this analysis.'], findings: undefined }) });
    svc.getPrograms.mockResolvedValue({ status: 'success', data: [{ id: 9, code: 'EEE', name: 'Electrical', status: 'ACTIVE' }] });
    renderPage();
    expect(await screen.findByTestId('program-setup')).toBeInTheDocument();
    expect(screen.getByTestId('assign-program')).toHaveTextContent('EEE — Electrical');
    expect(screen.getByTestId('program-name')).toHaveTextContent('Not assigned');
    expect(screen.getByTestId('mapping-stale')).toHaveTextContent(/may be outdated/);
    expect(svc.getPrograms).toHaveBeenCalled();
  });

  it('shows authorization errors', async () => {
    svc.getCourseMapping.mockRejectedValue(new ApiError(403, 'Forbidden'));
    svc.getQuestionMappings.mockResolvedValue({ status: 'success', data: [] });
    renderPage();
    expect(await screen.findByTestId('mapping-error')).toHaveTextContent(/not authorized/i);
  });

  it('confirms an AI suggestion from the page', async () => {
    svc.getCourseMapping.mockResolvedValue({ status: 'success', data: overview() });
    svc.getQuestionMappings.mockResolvedValue({ status: 'success', data: questions() });
    svc.confirmQuestionCoMapping.mockResolvedValue({ status: 'success', data: { status: 'CONFIRMED', mapping_source: 'FACULTY' } });
    renderPage();
    await screen.findByTestId('co-po-page');
    fireEvent.click(screen.getByTestId('confirm-suggestion'));
    await waitFor(() => expect(svc.confirmQuestionCoMapping).toHaveBeenCalledWith(8, 12));
  });
});
