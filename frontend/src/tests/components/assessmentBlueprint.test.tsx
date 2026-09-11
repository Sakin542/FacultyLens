import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { AssessmentBlueprintPage } from '@/pages/AssessmentBlueprint';
import { BlueprintForm, emptyInput } from '@/components/blueprint/BlueprintForm';
import { BlueprintComparisonPanel, BlueprintValidationPanel, BlueprintWarnings, BlueprintCoverageMatrix, BlueprintPreview } from '@/components/blueprint/BlueprintResults';
import { BlueprintEmptyState, BlueprintError, BlueprintLoading, getBlueprintErrorMessage } from '@/components/blueprint/BlueprintStates';
import { ApiError } from '@/services/api';
import { AssessmentBlueprint, BlueprintComparison, BlueprintCoverage, BlueprintResponse, BlueprintValidation } from '@/types/blueprint';

vi.mock('@/services/assessmentBlueprintService', () => ({
  assessmentBlueprintService: { getBlueprint: vi.fn(), createBlueprint: vi.fn(), updateBlueprint: vi.fn(), deleteBlueprint: vi.fn(), validateBlueprint: vi.fn(), finalizeBlueprint: vi.fn(), getCoverage: vi.fn(), compareWithQuestions: vi.fn(), generateQuestions: vi.fn(), validateQuestions: vi.fn() },
}));
vi.mock('@/services/assessmentService', () => ({ assessmentService: { getById: vi.fn() } }));
vi.mock('@/services/learningOutcomeService', () => ({ learningOutcomeService: { getByCourse: vi.fn() } }));
vi.mock('@/services/coPoMappingService', () => ({ coPoMappingService: { getCourseMapping: vi.fn() } }));
vi.mock('@/context/AuthContext', () => ({ useAuth: () => ({ user: { id: 1, name: 'Dr. A' }, loading: false }) }));

import { assessmentBlueprintService } from '@/services/assessmentBlueprintService';
import { assessmentService } from '@/services/assessmentService';
import { learningOutcomeService } from '@/services/learningOutcomeService';
import { coPoMappingService } from '@/services/coPoMappingService';
const svc = assessmentBlueprintService as unknown as Record<string, ReturnType<typeof vi.fn>>;
const m = (s: unknown) => s as unknown as Record<string, ReturnType<typeof vi.fn>>;
const ok = <T,>(data: T, message = 'ok') => ({ status: 'success', message, data });

const assessment = { id: 10, title: 'Midterm Examination', type: 'midterm', total_marks: 50, duration_minutes: 90, status: 'draft', course_id: 1, course: { id: 1, course_code: 'CSE101', course_name: 'Database Systems' }, questions: [{ id: 1 }, { id: 2 }] };
const outcomes = [{ id: 1, code: 'CO1', description: 'Explain relational concepts.' }, { id: 2, code: 'CO2', description: 'Apply normalization.' }, { id: 3, code: 'CO3', description: 'Optimize queries.' }];

const blueprint: AssessmentBlueprint = {
  id: 5, assessment_id: 10, version: 1, status: 'VALIDATED', is_current: true, title: 'Midterm blueprint', total_marks: 50, total_questions: 8, duration_minutes: 90, instructions: null,
  validation_status: 'VALID_WITH_WARNINGS', blueprint_completeness: 100, validated_at: '2026-09-11T10:00:00Z', finalized_at: null, created_by: 1, created_at: null, updated_at: null,
  course: { id: 1, code: 'CSE101', name: 'Database Systems', program_id: null }, assessment: { id: 10, title: 'Midterm Examination', type: 'midterm', total_marks: 50, duration_minutes: 90, status: 'draft' },
  sections: [{ id: 1, title: 'Section A', section_order: 1, question_type: 'mcq', question_count: 4, marks_per_question: 2.5, total_marks: 10 }, { id: 2, title: 'Section B', section_order: 2, question_type: 'problem_solving', question_count: 2, marks_per_question: 10, total_marks: 20 }, { id: 3, title: 'Section C', section_order: 3, question_type: 'descriptive', question_count: 2, marks_per_question: 10, total_marks: 20 }],
  constraints: {
    difficulty: [{ key: 'easy', target_percentage: 30, target_count: null }, { key: 'medium', target_percentage: 50, target_count: null }, { key: 'hard', target_percentage: 20, target_count: null }],
    cognitive: [{ key: 'Understand', target_percentage: 20, target_count: null }, { key: 'Apply', target_percentage: 40, target_count: null }, { key: 'Analyze', target_percentage: 30, target_count: null }, { key: 'Evaluate', target_percentage: 10, target_count: null }],
    learning_outcomes: [{ learning_outcome_id: 1, code: 'CO1', target_percentage: 20, target_marks: null, target_count: null }, { learning_outcome_id: 2, code: 'CO2', target_percentage: 40, target_marks: null, target_count: null }, { learning_outcome_id: 3, code: 'CO3', target_percentage: 40, target_marks: null, target_count: null }],
    program_outcomes: [], topics: [{ topic: 'Normalization', target_count: 4, target_marks: 25 }], question_types: [],
  },
  items: [{ id: 1, section_order: 2, topic: 'Normalization', learning_outcome_id: 2, program_outcome_id: null, question_type: 'problem_solving', difficulty_level: 'medium', cognitive_level: 'Analyze', question_count: 2, marks_each: 10, total_marks: 20, sort_order: 1 }],
};
const validation: BlueprintValidation = {
  status: 'VALID_WITH_WARNINGS', errors: [], warnings: [{ dimension: 'DIFFICULTY', message: '8 questions cannot exactly represent the difficulty percentages. Suggested allocation: 2 Easy / 4 Medium / 2 Hard (requires your confirmation).' }, { dimension: 'ITEM', message: 'The question plan covers 2 of 8 questions.' }],
  recommendations: [{ category: 'LEARNING_OUTCOME', title: 'CO3 is planned at 40% of marks', message: 'Consider reviewing whether this reflects the intended assessment emphasis.' }],
  completeness: { score: 100, dimensions: { basics: true, sections: true, difficulty: true, cognitive: true, learning_outcomes: true, topics: true, question_types: true, items: true }, note: 'Blueprint Completeness measures configured planning dimensions; it is not an assessment quality score.' },
  totals: { section_marks: 50, section_questions: 8, item_marks: 20, item_questions: 2, total_marks: 50, total_questions: 8 },
  time_indicator: { available: true, duration_minutes: 90, minutes_per_mark: 1.8, minutes_per_question: 11.3, band: 'TYPICAL', note: 'Planning indicator only — not an official duration recommendation.' }, validated_at: '2026-09-11T10:00:00Z',
};
const coverage: BlueprintCoverage = {
  distributions: {
    difficulty: { configured: true, rows: [{ key: 'easy', label: 'Easy', target_percentage: 30, derived_count: 2, configured: true }, { key: 'medium', label: 'Medium', target_percentage: 50, derived_count: 4, configured: true }, { key: 'hard', label: 'Hard', target_percentage: 20, derived_count: 2, configured: true }], allocation: { exact: false, allocation: { easy: 2, medium: 4, hard: 2 } } },
    cognitive: { configured: true, rows: [{ key: 'Understand', label: 'Understand', target_percentage: 20, configured: true }, { key: 'Apply', label: 'Apply', target_percentage: 40, configured: true }] },
    learning_outcomes: { configured: true, rows: [{ key: 'lo:1', label: 'CO1', target_percentage: 20, target_marks: 10, configured: true }, { key: 'lo:2', label: 'CO2', target_percentage: 40, target_marks: 20, configured: true }] },
    program_outcomes: { configured: false, available: false, rows: [], message: 'PO blueprint is not configured for this course.' }, topics: { configured: true, rows: [{ key: 'Normalization', label: 'Normalization', target_percentage: 50, target_marks: 25, configured: true }] }, question_types: { configured: false, rows: [] },
  },
  matrices: {
    co_x_difficulty: { columns: ['medium'], rows: [{ label: 'CO2', cells: { medium: 2 }, total: 2 }], column_totals: { medium: 2 }, total: 2 }, co_x_bloom: { columns: ['Analyze'], rows: [{ label: 'CO2', cells: { Analyze: 2 }, total: 2 }], column_totals: { Analyze: 2 }, total: 2 },
    topic_x_difficulty: { columns: [], rows: [], column_totals: {}, total: 0 }, topic_x_bloom: { columns: [], rows: [], column_totals: {}, total: 0 },
  },
};
const response: BlueprintResponse = { blueprint, validation, coverage, versions: [{ id: 5, version: 1, status: 'VALIDATED', is_current: true, total_marks: 50, total_questions: 8, validation_status: 'VALID_WITH_WARNINGS', blueprint_completeness: 100, finalized_at: null, created_at: null }], permissions: { edit: true, generate: true } };
const comparison: BlueprintComparison = {
  blueprint_id: 5, version: 1, blueprint_status: 'VALIDATED', tolerance_percent: 5, actual: { question_count: 8, total_marks: 50, has_questions: true },
  structure: [{ dimension: 'question_count', label: 'Questions', target: 8, actual: 8, difference: 0, status: 'MATCH' }, { dimension: 'total_marks', label: 'Total marks', target: 50, actual: 50, difference: 0, status: 'MATCH' }],
  dimensions: {
    difficulty: { configured: true, basis: 'count', rows: [{ key: 'easy', label: 'Easy', target_percentage: 30, actual_percentage: 25, actual_raw: 2, difference: -5, status: 'CLOSE' }, { key: 'medium', label: 'Medium', target_percentage: 50, actual_percentage: 50, actual_raw: 4, difference: 0, status: 'MATCH' }, { key: 'hard', label: 'Hard', target_percentage: 20, actual_percentage: 25, actual_raw: 2, difference: 5, status: 'CLOSE' }] },
    cognitive: { configured: true, basis: 'count', rows: [] }, learning_outcomes: { configured: true, basis: 'marks', rows: [{ key: 'lo:2', label: 'CO2', target_percentage: 40, actual_percentage: 50, actual_raw: 25, difference: 10, status: 'MISMATCH' }] },
    program_outcomes: { configured: false, rows: [], message: 'PO blueprint is not configured for this course.' }, topics: { configured: false, rows: [] }, question_types: { configured: false, rows: [] },
  },
  summary: { difficulty: 'CLOSE', cognitive: 'NOT_CONFIGURED', learning_outcomes: 'MISMATCH', program_outcomes: 'NOT_CONFIGURED', topics: 'NOT_CONFIGURED', question_types: 'NOT_CONFIGURED' }, compliance_percent: 75,
  note: 'Comparison uses the actual question metadata of this assessment. Nothing is changed automatically; review deviations before finalizing the assessment.', compared_at: '2026-09-11T10:05:00Z',
};

const renderPage = () => render(<MemoryRouter initialEntries={['/assessments/10/blueprint']}><Routes><Route path="/assessments/:assessmentId/blueprint" element={<AssessmentBlueprintPage />} /></Routes></MemoryRouter>);

beforeEach(() => {
  vi.clearAllMocks();
  m(assessmentService).getById.mockResolvedValue(ok(assessment));
  m(learningOutcomeService).getByCourse.mockResolvedValue(ok(outcomes));
  m(coPoMappingService).getCourseMapping.mockResolvedValue(ok({ program: null, program_outcomes: [] }));
  svc.getBlueprint.mockResolvedValue(ok(response));
});

describe('AssessmentBlueprint page', () => {
  it('shows the empty state and creates a blueprint from the form', async () => {
    svc.getBlueprint.mockResolvedValueOnce(ok({ blueprint: null, validation: null, coverage: null, versions: [], permissions: { edit: true, generate: true } }));
    svc.createBlueprint.mockResolvedValue(ok(response, 'Blueprint created.'));
    renderPage();
    await waitFor(() => expect(screen.getByTestId('blueprint-empty-state')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: 'Create blueprint' }));
    await screen.findByTestId('blueprint-form');
    const form = () => screen.getByTestId('blueprint-form');
    expect(screen.getByTestId('po-not-configured')).toHaveTextContent('PO blueprint is not configured');
    fireEvent.change(within(form()).getByLabelText(/Total marks/), { target: { value: '50' } });
    fireEvent.change(within(form()).getByLabelText(/Number of questions/), { target: { value: '8' } });
    fireEvent.click(within(form()).getByRole('button', { name: 'Add section' }));
    fireEvent.change(within(form()).getByLabelText('Questions'), { target: { value: '8' } });
    fireEvent.change(within(form()).getByLabelText('Marks each'), { target: { value: '5' } });
    expect(within(form()).getByTestId('blueprint-section-editor')).toHaveTextContent('Section marks 40 / 50 required (difference -10)');
    fireEvent.change(within(form()).getByLabelText('Marks each'), { target: { value: '6.25' } });
    expect(within(form()).getByTestId('blueprint-section-editor')).toHaveTextContent('Section marks 50 / 50 required ✓');
    // Difficulty defaults to the STEP 13 target; change Hard to 40 → total 110%
    fireEvent.change(within(form()).getByLabelText('Hard'), { target: { value: '40' } });
    expect(within(form()).getByTestId('blueprint-difficulty')).toHaveTextContent('Total 120% — must equal 100%');
    fireEvent.change(within(form()).getByLabelText('Hard'), { target: { value: '20' } });
    // CO targets
    await waitFor(() => expect(within(form()).getByLabelText('CO1 target percentage')).toBeInTheDocument());
    fireEvent.change(within(form()).getByLabelText('CO1 target percentage'), { target: { value: '20' } });
    fireEvent.change(within(form()).getByLabelText('CO2 target percentage'), { target: { value: '40' } });
    fireEvent.change(within(form()).getByLabelText('CO3 target percentage'), { target: { value: '40' } });
    expect(within(form()).getByTestId('blueprint-outcomes')).toHaveTextContent('CO total 100% ✓');
    // Bloom + topic + plan row
    fireEvent.change(within(form()).getByLabelText('Apply'), { target: { value: '100' } });
    fireEvent.click(within(form()).getByRole('button', { name: 'Add topic' }));
    fireEvent.change(within(form()).getByLabelText('Topic'), { target: { value: 'Normalization' } });
    fireEvent.click(within(form()).getByRole('button', { name: 'Add row' }));
    expect(within(form()).getByTestId('plan-row-0')).toBeInTheDocument();
    fireEvent.click(within(form()).getByRole('button', { name: 'Remove row 1' }));
    expect(within(form()).queryByTestId('plan-row-0')).not.toBeInTheDocument();
    fireEvent.click(within(form()).getByRole('button', { name: 'Save & validate' }));
    await waitFor(() => expect(svc.createBlueprint).toHaveBeenCalledWith('10', expect.objectContaining({ total_marks: 50, total_questions: 8, sections: [expect.objectContaining({ question_count: 8, marks_per_question: 6.25 })],
      constraints: expect.objectContaining({ learning_outcomes: expect.arrayContaining([expect.objectContaining({ learning_outcome_id: 1, target_percentage: 20 })]), topics: [expect.objectContaining({ topic: 'Normalization' })] }) })));
    expect(await screen.findByTestId('blueprint-summary')).toHaveTextContent('Valid with warnings');
  });

  it('renders validation, warnings, matrix, preview and actions for an existing blueprint', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByTestId('blueprint-summary')).toBeInTheDocument());
    expect(screen.getByTestId('blueprint-header')).toHaveTextContent('Validated · v1');
    expect(screen.getByTestId('blueprint-validation')).toHaveTextContent('No blocking errors');
    expect(screen.getByTestId('blueprint-validation')).toHaveTextContent('1.8 min/mark · TYPICAL');
    expect(screen.getByTestId('blueprint-warnings')).toHaveTextContent('Suggested allocation: 2 Easy / 4 Medium / 2 Hard');
    expect(screen.getByTestId('blueprint-recommendations')).toHaveTextContent('CO3 is planned at 40% of marks');
    expect(screen.getByTestId('blueprint-coverage-matrix')).toHaveTextContent('CO × Difficulty');
    expect(screen.getByTestId('blueprint-preview')).toHaveTextContent('4 × 2.5 = 10');
    expect(screen.getByTestId('blueprint-preview')).toHaveTextContent('Exact allocation impossible');
    expect(screen.getByRole('button', { name: /Finalize/ })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Generate Questions from Blueprint' })).toBeDisabled();
  });

  it('validates, finalizes with confirmation, generates and compares', async () => {
    const finalized = { ...response, blueprint: { ...blueprint, status: 'FINALIZED' as const, finalized_at: '2026-09-11T11:00:00Z' } };
    svc.validateBlueprint.mockResolvedValue(ok(response, 'Blueprint validated.'));
    svc.finalizeBlueprint.mockResolvedValue(ok(finalized, 'Blueprint finalized.'));
    svc.compareWithQuestions.mockResolvedValue(ok(comparison));
    svc.generateQuestions.mockResolvedValue(ok({ blueprint_id: 5, requests: [{ id: 77, topic: 'Normalization', learning_outcome_id: 2, question_type: 'problem_solving', number_of_questions: 2, generation_status: 'PENDING', warnings: [] }], note: 'Generated questions remain drafts until faculty review and approve them.' }));
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    renderPage();
    await waitFor(() => expect(screen.getByTestId('blueprint-actions')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: 'Validate' }));
    await waitFor(() => expect(svc.validateBlueprint).toHaveBeenCalledWith(5));
    fireEvent.click(screen.getByRole('button', { name: /Finalize/ }));
    expect(svc.finalizeBlueprint).not.toHaveBeenCalled(); // cancelled confirmation
    confirm.mockReturnValue(true);
    fireEvent.click(screen.getByRole('button', { name: /Finalize/ }));
    await waitFor(() => expect(svc.finalizeBlueprint).toHaveBeenCalledWith(5));
    await waitFor(() => expect(screen.getByTestId('blueprint-header')).toHaveTextContent('Finalized · v1'));
    expect(screen.getByRole('button', { name: 'Create new version' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Compare with questions' }));
    await waitFor(() => expect(svc.compareWithQuestions).toHaveBeenCalledWith(5, true));
    const cmp = await screen.findByTestId('blueprint-comparison');
    expect(cmp).toHaveTextContent('Compliance 75%');
    expect(cmp).toHaveTextContent('Mismatch');
    expect(cmp).toHaveTextContent('+10%');
    expect(cmp).toHaveTextContent('Nothing is changed automatically');
    fireEvent.click(screen.getByRole('button', { name: 'Generate Questions from Blueprint' }));
    await waitFor(() => expect(svc.generateQuestions).toHaveBeenCalledWith(5));
    confirm.mockRestore();
  });

  it('shows error state with retry and hides edit actions without permission', async () => {
    svc.getBlueprint.mockRejectedValueOnce(new ApiError(403, '')).mockResolvedValue(ok({ ...response, permissions: { edit: false, generate: false } }));
    renderPage();
    const alert = await screen.findByTestId('blueprint-error');
    expect(alert).toHaveTextContent('You do not have access');
    fireEvent.click(within(alert).getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByTestId('blueprint-summary')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Validate' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Finalize/ })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Compare with questions' })).toBeInTheDocument();
  });
});

describe('blueprint components', () => {
  it('maps errors and renders states', () => {
    expect(getBlueprintErrorMessage(new ApiError(409, 'already finalized'))).toBe('already finalized');
    expect(getBlueprintErrorMessage(new ApiError(422, ''))).toMatch(/correct the highlighted/);
    render(<BlueprintLoading />);
    expect(screen.getByTestId('blueprint-loading')).toBeInTheDocument();
    render(<BlueprintError message="Boom" />);
    expect(screen.getByRole('alert')).toHaveTextContent('Boom');
    render(<BlueprintEmptyState canEdit={false} onCreate={() => undefined} />);
    expect(screen.queryByRole('button', { name: 'Create blueprint' })).not.toBeInTheDocument();
  });

  it('renders invalid validation errors and empty warnings', () => {
    render(<BlueprintValidationPanel validation={{ ...validation, status: 'INVALID', errors: [{ dimension: 'MARKS', message: 'Allocated section marks: 45. Required marks: 50. Difference: -5.' }] }} />);
    expect(screen.getByLabelText('Blueprint errors')).toHaveTextContent('Difference: -5');
    render(<BlueprintWarnings warnings={[]} />);
    expect(screen.getByTestId('blueprint-warnings')).toHaveTextContent('No warnings.');
    render(<BlueprintCoverageMatrix coverage={{ ...coverage, matrices: { ...coverage.matrices, co_x_difficulty: { columns: [], rows: [], column_totals: {}, total: 0 }, co_x_bloom: { columns: [], rows: [], column_totals: {}, total: 0 } } }} />);
    expect(screen.getByTestId('blueprint-coverage-matrix')).toHaveTextContent('Add question plan rows');
  });

  it('renders comparison with no questions and preview without coverage', () => {
    render(<MemoryRouter><BlueprintComparisonPanel comparison={{ ...comparison, compliance_percent: null, actual: { question_count: 0, total_marks: 0, has_questions: false } }} assessmentId={10} /></MemoryRouter>);
    expect(screen.getByTestId('blueprint-comparison')).toHaveTextContent('No questions yet');
    expect(screen.getByTestId('blueprint-comparison')).toHaveTextContent('has no questions yet');
    render(<BlueprintPreview blueprint={blueprint} coverage={null} />);
    expect(screen.getByTestId('blueprint-preview')).toHaveTextContent('CSE101 — Database Systems');
  });

  it('form blocks submission with client-side problems', async () => {
    const onSave = vi.fn();
    render(<BlueprintForm initial={emptyInput(null)} assessment={null} outcomes={[]} programOutcomes={null} onSave={onSave} />);
    fireEvent.click(screen.getByRole('button', { name: 'Save & validate' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Total marks must be greater than zero.');
    expect(onSave).not.toHaveBeenCalled();
  });
});
