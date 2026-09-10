import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { GenerationForm, GenerationConstraints, DocumentContextSelector, defaultConstraints } from '@/components/questionGenerator/GenerationForm';
import { GeneratedQuestionCard, ConstraintValidation, QuestionSimilarityWarning, QuestionAlignmentBadge, GeneratedQuestionEditor } from '@/components/questionGenerator/GeneratedQuestionCard';
import { GeneratedQuestionList, GenerationRequestSummary, QuestionGenerationHistory, AddToAssessmentModal, RegenerateQuestionModal } from '@/components/questionGenerator/GenerationPanels';
import { GenerationEmptyState, GenerationError, GenerationLoading, GenerationDisclaimer, getGenerationErrorMessage } from '@/components/questionGenerator/GenerationStates';
import { QuestionGenerator } from '@/pages/QuestionGenerator';
import { ApiError } from '@/services/api';
import { GeneratedQuestion, GenerationRequest, QuestionValidation } from '@/types/questionGeneration';

vi.mock('@/services/questionGenerationService', () => ({
  questionGenerationService: {
    createGenerationRequest: vi.fn(), getGenerationRequests: vi.fn(), getGenerationRequest: vi.fn(), getGeneratedQuestions: vi.fn(), regenerateRequest: vi.fn(),
    updateGeneratedQuestion: vi.fn(), approveGeneratedQuestion: vi.fn(), rejectGeneratedQuestion: vi.fn(), regenerateQuestion: vi.fn(), addToAssessment: vi.fn(),
  },
}));
vi.mock('@/services/courseService', () => ({ courseService: { getAll: vi.fn() } }));
vi.mock('@/services/assessmentService', () => ({ assessmentService: { getByCourse: vi.fn() } }));
vi.mock('@/services/learningOutcomeService', () => ({ learningOutcomeService: { getByCourse: vi.fn() } }));
vi.mock('@/services/coPoMappingService', () => ({ coPoMappingService: { getCourseMapping: vi.fn() } }));
vi.mock('@/services/documentService', () => ({ documentService: { getAll: vi.fn() } }));

import { questionGenerationService } from '@/services/questionGenerationService';
import { courseService } from '@/services/courseService';
import { assessmentService } from '@/services/assessmentService';
import { learningOutcomeService } from '@/services/learningOutcomeService';
import { coPoMappingService } from '@/services/coPoMappingService';
import { documentService } from '@/services/documentService';

const svc = questionGenerationService as unknown as Record<string, ReturnType<typeof vi.fn>>;
const m = (s: unknown) => s as unknown as Record<string, ReturnType<typeof vi.fn>>;

const validation: QuestionValidation = {
  detected_question_type: 'ANALYTICAL', detected_difficulty: 'MEDIUM', detected_cognitive_level: 'ANALYZE', detected_topics: ['Normalization'],
  co_alignment_score: 0.82, co_alignment_status: 'STRONG', max_similarity_score: 0.21, similarity_status: 'NOT_SIMILAR', similar_questions: [],
  constraints: { topic: true, question_type: true, difficulty: true, cognitive_level: true, co_alignment: true, similarity: true, marks: true }, warnings: [], overall_status: 'PASSED',
};
const question: GeneratedQuestion = {
  id: 11, generation_request_id: 5, sequence: 1, question_text: 'Analyze the schema and identify normalization anomalies.', original_question_text: 'Analyze the schema and identify normalization anomalies.',
  is_edited: false, question_type: 'descriptive', marks: 10, difficulty_level: 'medium', cognitive_level: 'Analyze', learning_outcome_id: 2, program_outcome_id: null, topic: 'Normalization',
  options: null, correct_option: null, expected_answer: 'Partial and transitive dependencies…', explanation: null, source_chunk_ids: [3], validation, validation_status: 'PASSED',
  review_status: 'DRAFT', review_note: null, version: 1, edited_at: null, approved_at: null, regenerated_from_id: null, official_question_id: null, added_to_assessment_at: null,
  can_add_to_assessment: false, created_at: null,
};
const dupValidation: QuestionValidation = {
  ...validation, detected_cognitive_level: 'UNDERSTAND', co_alignment_score: 0.42, co_alignment_status: 'NOT_ALIGNED', max_similarity_score: 0.88, similarity_status: 'POTENTIAL_DUPLICATE',
  similar_questions: [{ existing_id: 1, source: 'assessment', label: 'Midterm Q1', text: 'Explain 3NF conversion.', similarity_score: 0.88, status: 'POTENTIAL_DUPLICATE' }],
  constraints: { ...validation.constraints, cognitive_level: false, co_alignment: false, similarity: false },
  warnings: ['Cognitive-level mismatch: requested ANALYZE, AI-detected UNDERSTAND.', 'Potential duplicate of an existing question (similarity 0.88). Faculty review required.'], overall_status: 'FAILED',
};
const request: GenerationRequest = {
  id: 5, course_id: 1, assessment_id: 4, course: { id: 1, course_code: 'CSE101', course_name: 'Database Systems' }, assessment: { id: 4, title: 'Midterm', total_marks: 30, remaining_marks: 20 },
  learning_outcome: { id: 2, code: 'CO2', description: 'Analyze database structures' }, program_outcome: { id: 3, code: 'PO2', title: 'Problem Analysis' }, topic: 'Normalization',
  question_type: 'descriptive', difficulty_level: 'medium', cognitive_level: 'Analyze', marks: 10, number_of_questions: 3, language: 'English', document_scope: { scope_type: 'COURSE', course_id: 1 },
  blueprint: null, include_expected_answer: true, include_explanation: false, generation_status: 'COMPLETED', generation_method: 'template',
  models: { generation: 'facultylens-constrained-question-template-engine', generation_version: '1.0.0', embedding: 'MiniLM', prompt_version: '1.0.0' },
  regeneration_count: 0, max_regenerations: 3, feedback: [], warnings: ['Requested marks (30) exceed the remaining assessment allocation (20).'],
  set_summary: { total: 1, difficulty: { medium: 1 }, cognitive_level: { Analyze: 1 }, question_type: { descriptive: 1 }, validation: { PASSED: 1 }, potential_duplicates: 0, weak_alignment: 0, total_marks: 10, co_coverage: { '2': 100 } },
  blueprint_summary: null, retrieved_chunks: 2, existing_questions_count: 5, error_message: null, generated_questions_count: 1, approved_count: 0, disclaimer: 'Drafts.', completed_at: null, created_at: null, questions: [question],
};
const outcomes = [{ id: 2, code: 'CO2', description: 'Analyze database structures' }];
const cardHandlers = () => ({ onEdit: vi.fn().mockResolvedValue(undefined), onApprove: vi.fn(), onReject: vi.fn(), onRegenerate: vi.fn(), onAddToAssessment: vi.fn() });

describe('STEP 33 question generator components', () => {
  it('constraints expose every constraint control and report changes', () => {
    const onChange = vi.fn();
    render(<GenerationConstraints values={defaultConstraints} onChange={onChange} outcomes={outcomes} programOutcomes={[{ id: 3, code: 'PO2', title: 'Problem Analysis' }]} />);
    fireEvent.change(screen.getByLabelText('Topic'), { target: { value: 'Normalization' } });
    expect(onChange).toHaveBeenCalledWith({ topic: 'Normalization' });
    fireEvent.change(screen.getByLabelText('Course outcome'), { target: { value: '2' } });
    expect(onChange).toHaveBeenCalledWith({ learning_outcome_id: '2' });
    fireEvent.change(screen.getByLabelText('Program outcome'), { target: { value: '3' } });
    fireEvent.change(screen.getByLabelText('Question type'), { target: { value: 'mcq' } });
    expect(onChange).toHaveBeenCalledWith({ question_type: 'mcq' });
    fireEvent.change(screen.getByLabelText('Difficulty'), { target: { value: 'hard' } });
    fireEvent.change(screen.getByLabelText('Cognitive level'), { target: { value: 'Analyze' } });
    expect(onChange).toHaveBeenCalledWith({ cognitive_level: 'Analyze' });
    fireEvent.change(screen.getByLabelText('Marks'), { target: { value: '5' } });
    fireEvent.change(screen.getByLabelText('Number of questions'), { target: { value: '4' } });
    expect(onChange).toHaveBeenCalledWith({ number_of_questions: '4' });
  });

  it('document scope selector requires a document for DOCUMENT scope', () => {
    const onChange = vi.fn();
    render(<DocumentContextSelector value={{ scope_type: 'COURSE' }} onChange={onChange} documents={[{ id: 9, original_file_name: 'syllabus.pdf', indexing_status: 'INDEXING' }]} hasAssessment />);
    expect(screen.getByTestId('doc-scope-assessment')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('doc-scope-document'));
    expect(onChange).toHaveBeenCalledWith({ scope_type: 'DOCUMENT', document_id: undefined });
  });

  it('form validates and submits a structured constraint payload', async () => {
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    render(<GenerationForm courses={[{ id: 1, course_code: 'CSE101', course_name: 'DB' }]} assessments={[{ id: 4, title: 'Midterm', total_marks: 30 }]} outcomes={outcomes} programOutcomes={[]} documents={[]}
      courseId="1" assessmentId="4" onCourseChange={vi.fn()} onAssessmentChange={vi.fn()} onSubmit={onSubmit} />);
    fireEvent.change(screen.getByLabelText('Topic'), { target: { value: 'Normalization' } });
    fireEvent.change(screen.getByLabelText('Course outcome'), { target: { value: '2' } });
    fireEvent.change(screen.getByLabelText('Cognitive level'), { target: { value: 'Analyze' } });
    fireEvent.change(screen.getByLabelText('Marks'), { target: { value: '0' } });
    fireEvent.click(screen.getByTestId('generate-button'));
    expect(screen.getByRole('alert')).toHaveTextContent('Marks must be greater than zero');
    expect(onSubmit).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText('Marks'), { target: { value: '10' } });
    fireEvent.click(screen.getByTestId('generate-button'));
    await waitFor(() => expect(onSubmit).toHaveBeenCalled());
    expect(onSubmit.mock.calls[0][0]).toMatchObject({ course_id: '1', assessment_id: '4', topic: 'Normalization', learning_outcome_id: '2', question_type: 'descriptive', difficulty_level: 'medium', cognitive_level: 'Analyze', marks: 10, number_of_questions: 3, document_scope: { scope_type: 'COURSE' } });
  });

  it('card shows constraint validation, alignment badge and review actions', () => {
    const h = cardHandlers();
    render(<GeneratedQuestionCard question={question} outcomes={outcomes} {...h} regenerationsLeft={3} hasAssessment />);
    expect(screen.getByTestId('question-text')).toHaveTextContent('Analyze the schema');
    expect(screen.getByTestId('alignment-badge')).toHaveTextContent('0.82 — Strong');
    expect(screen.getByTestId('validation-status')).toHaveTextContent('Passed');
    expect(screen.queryByTestId('similarity-warning')).toBeNull();
    expect(screen.queryByTestId('add-to-assessment-button')).toBeNull(); // drafts cannot be added
    fireEvent.click(screen.getByTestId('approve-button'));
    expect(h.onApprove).toHaveBeenCalledWith(question);
    fireEvent.click(screen.getByTestId('reject-button'));
    expect(h.onReject).toHaveBeenCalled();
    fireEvent.click(screen.getByTestId('regenerate-button'));
    expect(h.onRegenerate).toHaveBeenCalled();
  });

  it('card surfaces duplicate + mismatch warnings without hiding the draft', () => {
    render(<GeneratedQuestionCard question={{ ...question, validation: dupValidation, validation_status: 'FAILED' }} outcomes={outcomes} {...cardHandlers()} regenerationsLeft={0} hasAssessment />);
    expect(screen.getByTestId('similarity-warning')).toHaveTextContent('Potential duplicate');
    expect(screen.getByTestId('similarity-warning')).toHaveTextContent('Midterm Q1');
    expect(screen.getByTestId('validation-status')).toHaveTextContent('Failed');
    expect(screen.getByTestId('validation-warnings').children).toHaveLength(2);
    expect(screen.getByTestId('alignment-badge')).toHaveTextContent('Not aligned');
    expect(screen.getByTestId('regenerate-button')).toBeDisabled(); // limit reached
    expect(screen.getByTestId('approve-button')).toBeInTheDocument(); // faculty decides
  });

  it('approved card offers add-to-assessment; added card shows badge and rubric link', () => {
    const h = cardHandlers();
    const { rerender } = render(<GeneratedQuestionCard question={{ ...question, review_status: 'APPROVED', can_add_to_assessment: true }} outcomes={outcomes} {...h} regenerationsLeft={3} hasAssessment />);
    fireEvent.click(screen.getByTestId('add-to-assessment-button'));
    expect(h.onAddToAssessment).toHaveBeenCalled();
    expect(screen.queryByTestId('approve-button')).toBeNull();
    const onRubric = vi.fn();
    rerender(<GeneratedQuestionCard question={{ ...question, review_status: 'APPROVED', official_question_id: 77, can_add_to_assessment: false }} outcomes={outcomes} {...h} onGenerateRubric={onRubric} regenerationsLeft={3} hasAssessment />);
    expect(screen.getByTestId('added-badge')).toBeInTheDocument();
    expect(screen.queryByTestId('edit-button')).toBeNull();
    fireEvent.click(screen.getByTestId('generate-rubric-button'));
    expect(onRubric).toHaveBeenCalled();
  });

  it('editor saves edits and edited card can reveal original text', async () => {
    const onSave = vi.fn().mockResolvedValue(undefined);
    render(<GeneratedQuestionEditor question={question} outcomes={outcomes} onSave={onSave} onCancel={vi.fn()} />);
    fireEvent.change(screen.getByLabelText('Question text'), { target: { value: 'Analyze the order schema and identify each normalization anomaly with justification.' } });
    fireEvent.change(screen.getByLabelText('Edit marks'), { target: { value: '8' } });
    fireEvent.click(screen.getByTestId('editor-save'));
    await waitFor(() => expect(onSave).toHaveBeenCalled());
    expect(onSave.mock.calls[0][0]).toMatchObject({ marks: 8, learning_outcome_id: 2, question_type: 'descriptive' });

    render(<GeneratedQuestionCard question={{ ...question, question_text: 'Edited text here for the draft.', is_edited: true, version: 2 }} outcomes={outcomes} {...cardHandlers()} regenerationsLeft={3} hasAssessment />);
    fireEvent.click(screen.getByText('Show original AI draft'));
    expect(screen.getByTestId('original-text')).toHaveTextContent('Analyze the schema and identify');
  });

  it('list hides rejected drafts by default and shows empty state', () => {
    const h = cardHandlers();
    const { rerender } = render(<GeneratedQuestionList questions={[question, { ...question, id: 12, sequence: 2, review_status: 'REJECTED' }]} outcomes={outcomes} regenerationsLeft={3} hasAssessment {...h} />);
    expect(screen.getAllByTestId(/generated-question-\d+/)).toHaveLength(1);
    fireEvent.click(screen.getByText('Show 1 rejected'));
    expect(screen.getAllByTestId(/generated-question-\d+/)).toHaveLength(2);
    rerender(<GeneratedQuestionList questions={[]} outcomes={outcomes} regenerationsLeft={3} hasAssessment {...h} />);
    expect(screen.getByTestId('generation-empty-state')).toBeInTheDocument();
  });

  it('summary, history, states and modals', async () => {
    render(<GenerationRequestSummary request={request} />);
    expect(screen.getByTestId('generation-status')).toHaveTextContent('Completed');
    expect(screen.getByTestId('request-warnings')).toHaveTextContent('exceed the remaining assessment allocation');
    expect(screen.getByTestId('set-summary')).toHaveTextContent('Potential duplicates: 0');
    expect(screen.getByText(/2 document passages/)).toBeInTheDocument();

    const onSelect = vi.fn();
    render(<QuestionGenerationHistory requests={[request]} activeId={null} onSelect={onSelect} />);
    fireEvent.click(screen.getByTestId('history-item-5'));
    expect(onSelect).toHaveBeenCalledWith(request);

    render(<><GenerationLoading /><GenerationEmptyState /><GenerationError message="Boom" onRetry={() => undefined} /><GenerationDisclaimer /></>);
    expect(screen.getByRole('alert')).toHaveTextContent('Boom');
    expect(getGenerationErrorMessage(new ApiError(429, 'x'))).toMatch(/rate-limited/);
    expect(getGenerationErrorMessage(new ApiError(422, 'Only approved questions can be added to an assessment.'))).toMatch(/Only approved/);
    expect(getGenerationErrorMessage(new ApiError(503, ''))).toMatch(/temporarily unavailable/);

    const onConfirm = vi.fn();
    render(<AddToAssessmentModal question={question} assessments={[{ id: 4, title: 'Midterm', total_marks: 30 }]} defaultAssessmentId="4" outcomeCode="CO2" onConfirm={onConfirm} onClose={vi.fn()} />);
    expect(screen.getByTestId('add-to-assessment-modal')).toHaveTextContent('official assessment question');
    fireEvent.click(screen.getByTestId('confirm-add-question'));
    expect(onConfirm).toHaveBeenCalledWith('4');

    const onRegen = vi.fn();
    render(<RegenerateQuestionModal onConfirm={onRegen} onClose={vi.fn()} regenerationsLeft={2} />);
    fireEvent.click(screen.getByLabelText('Too easy'));
    fireEvent.change(screen.getByLabelText('Feedback note'), { target: { value: 'Use an e-commerce schema' } });
    fireEvent.click(screen.getByTestId('confirm-regenerate'));
    expect(onRegen).toHaveBeenCalledWith({ feedback: ['too_easy'], feedback_note: 'Use an e-commerce schema' });
  });

  it('exposes alignment badge n/a when no CO', () => {
    render(<><QuestionAlignmentBadge validation={{ ...validation, co_alignment_status: null, co_alignment_score: null }} /><QuestionSimilarityWarning validation={validation} /><ConstraintValidation question={question} /></>);
    expect(screen.getByTestId('alignment-badge')).toHaveTextContent('n/a');
  });
});

const renderPage = (path: string) => render(
  <MemoryRouter initialEntries={[path]}>
    <Routes>
      <Route path="/question-generator" element={<QuestionGenerator />} />
      <Route path="/courses/:courseId/question-generator" element={<QuestionGenerator />} />
    </Routes>
  </MemoryRouter>
);

describe('STEP 33 QuestionGenerator page', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    m(courseService).getAll.mockResolvedValue({ data: [{ id: 1, course_code: 'CSE101', course_name: 'Database Systems', semester: 'Fall' }] });
    m(assessmentService).getByCourse.mockResolvedValue({ data: [{ id: 4, course_id: 1, title: 'Midterm', type: 'midterm', status: 'draft', total_marks: 30 }] });
    m(learningOutcomeService).getByCourse.mockResolvedValue({ data: [{ id: 2, code: 'CO2', description: 'Analyze database structures' }] });
    m(coPoMappingService).getCourseMapping.mockResolvedValue({ status: 'success', data: { program_outcomes: [{ id: 3, code: 'PO2', title: 'Problem Analysis' }] } });
    m(documentService).getAll.mockResolvedValue({ data: [] });
    svc.getGenerationRequests.mockResolvedValue({ status: 'success', data: [] });
  });

  it('generates, polls to completion, approves and adds to assessment', async () => {
    const pending: GenerationRequest = { ...request, generation_status: 'PENDING', questions: [] };
    svc.createGenerationRequest.mockResolvedValue({ status: 'success', message: 'Generation request queued.', data: pending });
    svc.getGenerationRequest.mockResolvedValueOnce({ status: 'success', data: pending }).mockResolvedValue({ status: 'success', data: request });
    svc.approveGeneratedQuestion.mockResolvedValue({ status: 'success', message: 'Question approved.', data: { ...question, review_status: 'APPROVED', can_add_to_assessment: true } });
    svc.addToAssessment.mockResolvedValue({ status: 'success', message: 'Official assessment question created.', data: { question: { id: 77, assessment_id: 4, question_number: 2 }, generated_question: { ...question, review_status: 'APPROVED', official_question_id: 77, can_add_to_assessment: false } } });

    renderPage('/courses/1/question-generator?assessment=4');
    await waitFor(() => expect(screen.getByTestId('generation-form')).toBeInTheDocument());
    await waitFor(() => expect(screen.getByLabelText('Course outcome')).toHaveTextContent('CO2'));
    fireEvent.change(screen.getByLabelText('Topic'), { target: { value: 'Normalization' } });
    fireEvent.change(screen.getByLabelText('Course outcome'), { target: { value: '2' } });
    fireEvent.click(screen.getByTestId('generate-button'));

    await waitFor(() => expect(svc.createGenerationRequest).toHaveBeenCalled());
    expect(svc.createGenerationRequest.mock.calls[0][0]).toMatchObject({ course_id: '1', assessment_id: '4', topic: 'Normalization', learning_outcome_id: '2' });
    await waitFor(() => expect(screen.getByTestId('generation-loading')).toBeInTheDocument());
    await waitFor(() => expect(screen.getByTestId('generated-question-11')).toBeInTheDocument(), { timeout: 6000 });
    expect(screen.getByTestId('generation-status')).toHaveTextContent('Completed');
    expect(screen.getByTestId('generation-disclaimer')).toBeInTheDocument();

    fireEvent.click(screen.getByTestId('approve-button'));
    await waitFor(() => expect(screen.getByTestId('add-to-assessment-button')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('add-to-assessment-button'));
    const modal = await screen.findByTestId('add-to-assessment-modal');
    fireEvent.click(within(modal).getByTestId('confirm-add-question'));
    await waitFor(() => expect(svc.addToAssessment).toHaveBeenCalledWith(11, '4'));
    await waitFor(() => expect(screen.getByTestId('generation-notice')).toHaveTextContent('Question #2 was added'));
  });

  it('shows API errors and failed generation state', async () => {
    svc.createGenerationRequest.mockRejectedValue(new ApiError(403, 'Unauthorized access to course.'));
    renderPage('/courses/1/question-generator');
    await waitFor(() => expect(screen.getByTestId('generation-form')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('generate-button'));
    await waitFor(() => expect(screen.getByTestId('generation-error')).toHaveTextContent('Unauthorized access to course.'));

    svc.getGenerationRequest.mockResolvedValue({ status: 'success', data: { ...request, generation_status: 'FAILED', error_message: 'The question generator could not produce drafts.', questions: [] } });
    renderPage('/question-generator?request=5');
    await waitFor(() => expect(screen.getByTestId('request-error')).toHaveTextContent('could not produce drafts'));
    expect(screen.getByText('Retry with feedback')).toBeInTheDocument();
  });

  it('history selection loads a request and regenerate modal sends feedback', async () => {
    svc.getGenerationRequests.mockResolvedValue({ status: 'success', data: [{ ...request, questions: undefined }] });
    svc.getGenerationRequest.mockResolvedValue({ status: 'success', data: request });
    svc.regenerateQuestion.mockResolvedValue({ status: 'success', message: 'A new draft was generated.', data: { ...question, id: 12 } });
    renderPage('/question-generator');
    await waitFor(() => expect(screen.getByTestId('history-item-5')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('history-item-5'));
    await waitFor(() => expect(screen.getByTestId('generated-question-11')).toBeInTheDocument());
    fireEvent.click(screen.getByTestId('regenerate-button'));
    const modal = await screen.findByTestId('regenerate-modal');
    fireEvent.click(within(modal).getByLabelText('Too similar to existing questions'));
    fireEvent.click(within(modal).getByTestId('confirm-regenerate'));
    await waitFor(() => expect(svc.regenerateQuestion).toHaveBeenCalledWith(11, { feedback: ['too_similar'], feedback_note: undefined }));
  });
});
