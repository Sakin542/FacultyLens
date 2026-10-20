import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ExamPapers } from '@/pages/ExamPapers';
import { ExamPaperEditor } from '@/pages/ExamPaperEditor';
import { AssessmentIntegrity } from '@/pages/AssessmentIntegrity';
import { CommentBankPicker } from '@/components/grading/CommentBankPicker';
import type { ExamPaper, ItemAnalysis, SimilarityRun } from '@/types/exam';

vi.mock('@/services/examService', () => ({
  examService: {
    papers: vi.fn(), pool: vi.fn(), build: vi.fn(), paper: vi.fn(), updatePaper: vi.fn(), deletePaper: vi.fn(), addItem: vi.fn(),
    updateItem: vi.fn(), removeItem: vi.fn(), reorder: vi.fn(), variants: vi.fn(), createSets: vi.fn(), apply: vi.fn(), download: vi.fn(),
    itemAnalysis: vi.fn(), latestPlagiarism: vi.fn(), runPlagiarism: vi.fn(), reviewMatch: vi.fn(),
    comments: vi.fn(), createComment: vi.fn(), updateComment: vi.fn(), deleteComment: vi.fn(), useComment: vi.fn(),
  },
}));

import { examService } from '@/services/examService';
const svc = examService as unknown as Record<string, ReturnType<typeof vi.fn>>;

const paper = (over: Partial<ExamPaper> = {}): ExamPaper => ({
  id: 5, assessment: { id: 3, title: 'Final Examination' }, course: { id: 1, course_code: 'CSE311', course_name: 'Database Systems' },
  title: 'Final Examination', set_label: 'A', parent_paper_id: null, instructions: 'Answer all questions.', duration_minutes: 120,
  total_marks: 15, status: 'draft', build_mode: 'blueprint',
  coverage: { question_count: 2, total_marks: 15, target_total_marks: 20, target_question_count: 3, bloom: { Apply: { count: 1, marks: 10, percent: 66.7 }, Understand: { count: 1, marks: 5, percent: 33.3 } }, target_bloom_percent: { Apply: 50, Understand: 25, Create: 25 }, difficulty: { medium: { count: 2, marks: 15, percent: 100 } }, learning_outcomes: [{ code: 'CO1', count: 1, marks: 10, percent: 66.7 }], answer_key_complete: false },
  shortfalls: [{ slot: 'Create · hard', needed: 1, missing: 1 }],
  items: [
    { id: 11, sort_order: 1, section_title: null, source_type: 'question', source_id: 1, question_text: 'Write a SQL join over 2 tables.', expected_answer: 'SELECT ...', question_type: 'problem_solving', marks: 10, difficulty_level: 'medium', cognitive_level: 'Apply', topic: null, learning_outcome: { id: 1, code: 'CO1' }, options: null, variant_of_item_id: null, variant_meta: null, answer_needs_review: false },
    { id: 12, sort_order: 2, section_title: null, source_type: 'bank', source_id: 2, question_text: 'Explain indexing.', expected_answer: null, question_type: 'descriptive', marks: 5, difficulty_level: 'medium', cognitive_level: 'Understand', topic: null, learning_outcome: null, options: null, variant_of_item_id: null, variant_meta: null, answer_needs_review: false },
  ],
  sets: [], finalized_at: null, applied_at: null, ...over,
});

const at = (path: string, routePath: string, el: React.ReactElement) => render(
  <MemoryRouter initialEntries={[path]}><Routes><Route path={routePath} element={el} /><Route path="/exam-papers/:paperId" element={<p>editor page</p>} /></Routes></MemoryRouter>,
);

describe('Exam paper builder', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    svc.pool.mockResolvedValue({ data: [], meta: { total: 12, by_source: {}, by_bloom: {} } });
  });

  it('builds a paper from custom targets and opens the editor', async () => {
    svc.papers.mockResolvedValue([]);
    svc.build.mockResolvedValue({ status: 'success', message: 'Paper drafted with 10 question(s).', data: paper() });
    at('/assessments/3/exam-papers', '/assessments/:assessmentId/exam-papers', <ExamPapers />);

    expect(await screen.findByText(/12 question\(s\) available in the pool/)).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('build-mode-targets'));
    fireEvent.change(screen.getByTestId('target-count'), { target: { value: '8' } });
    fireEvent.click(screen.getByTestId('build-paper-submit'));

    await waitFor(() => expect(svc.build).toHaveBeenCalledWith('3', expect.objectContaining({ mode: 'targets', targets: expect.objectContaining({ question_count: 8, total_marks: 50 }) })));
    expect(await screen.findByText('editor page')).toBeInTheDocument();
  });

  it('shows coverage against targets, shortfalls and creates exam sets', async () => {
    svc.paper.mockResolvedValue(paper());
    svc.createSets.mockResolvedValue({ status: 'success', message: '2 set(s) created.', data: [], warnings: [] });
    at('/exam-papers/5', '/exam-papers/:paperId', <ExamPaperEditor />);

    expect(await screen.findByTestId('paper-coverage')).toHaveTextContent('target 50%');
    expect(screen.getByTestId('paper-shortfalls')).toHaveTextContent('Create · hard: 1 of 1 missing');
    expect(screen.getByTestId('paper-coverage')).toHaveTextContent('Answer key incomplete');

    fireEvent.change(screen.getByTestId('set-vary'), { target: { value: 'both' } });
    fireEvent.click(screen.getByTestId('create-sets'));
    await waitFor(() => expect(svc.createSets).toHaveBeenCalledWith(5, 1, 'both'));
    expect(await screen.findByText(/2 set\(s\) created\./)).toBeInTheDocument();
  });

  it('swaps in a question variant and flags its answer key', async () => {
    svc.paper.mockResolvedValue(paper());
    svc.variants.mockResolvedValue({ original: 'x', mode: 'both', engine: 'deterministic', model: null, message: null, variants: [
      { question_text: 'Write a SQL join over 3 tables.', expected_answer: 'SELECT ...', methods: ['numeric'], number_changes: [{ from: '2', to: '3' }], answer_needs_review: true, warnings: ['Recompute.'], similarity: 0.97 },
    ] });
    const updated = paper();
    updated.items[0] = { ...updated.items[0], question_text: 'Write a SQL join over 3 tables.', answer_needs_review: true };
    svc.updateItem.mockResolvedValue({ status: 'success', data: updated });
    at('/exam-papers/5', '/exam-papers/:paperId', <ExamPaperEditor />);

    fireEvent.click(await screen.findByTestId('variants-11'));
    fireEvent.click(screen.getByTestId('generate-variants'));
    const option = await screen.findByTestId('variant-option');
    expect(option).toHaveTextContent('2→3');
    fireEvent.click(within(option).getByRole('button', { name: /Use this variant/ }));

    await waitFor(() => expect(svc.updateItem).toHaveBeenCalledWith(5, 11, expect.objectContaining({ question_text: 'Write a SQL join over 3 tables.', answer_needs_review: true })));
    expect(await screen.findByText(/Recompute its answer key/)).toBeInTheDocument();
  });
});

describe('After-the-exam tools', () => {
  beforeEach(() => vi.clearAllMocks());

  const analysis: ItemAnalysis = {
    assessment: { id: 3, title: 'Quiz' }, submissions_analyzed: 10, submissions_excluded_ungraded: 1, minimum_required: 5, sufficient: true, message: null,
    items: [{ question_id: 1, question_number: 1, question_excerpt: 'Explain indexes.', max_marks: 10, cognitive_level: 'Understand', labelled_difficulty: 'easy', mean_marks: 9.5, difficulty_index: 0.95, difficulty_band: 'easy', upper_p: 0.97, lower_p: 0.93, discrimination_index: 0.04, discrimination_band: 'poor', item_rest_correlation: 0.1, flags: ['too_easy', 'poor_discrimination'], recommendation: 'Almost everyone earned full marks.' }],
    summary: { questions: 2, mean_total: 13, max_total: 20, mean_percent: 65, std_dev_total: 4.1, cronbach_alpha: 0.71, reliability_band: 'acceptable', flagged_items: 1, mean_difficulty_index: 0.65, mean_discrimination_index: 0.4 },
  };

  it('shows item statistics and flags', async () => {
    svc.itemAnalysis.mockResolvedValue(analysis);
    at('/assessments/3/integrity', '/assessments/:assessmentId/integrity', <AssessmentIntegrity />);

    const item = await screen.findByTestId('item-1');
    expect(item).toHaveTextContent('0.95');
    expect(item).toHaveTextContent('Too easy · Poor discrimination');
    expect(screen.getByTestId('item-analysis')).toHaveTextContent('0.71');
  });

  it('runs the plagiarism check and records a review', async () => {
    svc.itemAnalysis.mockResolvedValue(analysis);
    svc.latestPlagiarism.mockResolvedValue(null);
    const run: SimilarityRun = {
      id: 1, method: 'lexical', threshold: 0.6, answers_compared: 5, pairs_compared: 10, pairs_flagged: 1, summary: { per_question: [], semantic_unavailable: true, min_words: 8 },
      run_by: { id: 1, name: 'Dr. Doe' }, created_at: '2026-10-19T10:00:00Z',
      matches: [{ id: 9, question: { id: 1, question_number: 1, excerpt: 'Explain indexes' }, lexical_score: 0.82, semantic_score: null, combined_score: 0.82, shared_excerpt: 'keeps a sorted structure', review_status: 'pending', review_note: null, reviewed_at: null,
        a: { answer_id: 1, submission_id: 1, student: { id: 1, name: 'Student 1', student_identifier: 'S001' }, answer_text: 'An index keeps a sorted structure', awarded_marks: 8 },
        b: { answer_id: 2, submission_id: 2, student: { id: 2, name: 'Student 2', student_identifier: 'S002' }, answer_text: 'My view: an index keeps a sorted structure', awarded_marks: 8 } }],
    };
    svc.runPlagiarism.mockResolvedValue({ status: 'success', message: '1 pair(s) of answers need review.', data: run });
    svc.reviewMatch.mockResolvedValue({ ...run.matches[0], review_status: 'confirmed' });
    at('/assessments/3/integrity?tab=plagiarism', '/assessments/:assessmentId/integrity', <AssessmentIntegrity />);

    fireEvent.click(await screen.findByTestId('run-plagiarism'));
    const match = await screen.findByTestId('match-9');
    expect(match).toHaveTextContent('82% similar');
    expect(match).toHaveTextContent('S002');
    fireEvent.click(within(match).getByRole('button', { name: /Confirm copying/ }));
    await waitFor(() => expect(svc.reviewMatch).toHaveBeenCalledWith(9, 'confirmed'));
    expect(await within(screen.getByTestId('match-9')).findByText('Confirmed')).toBeInTheDocument();
  });

  it('inserts a saved comment into feedback and records its use', async () => {
    svc.comments.mockResolvedValue([{ id: 4, course: null, criterion: null, title: 'Good structure', body: 'Well organized answer.', tone: 'positive', usage_count: 2, last_used_at: null }]);
    svc.useComment.mockResolvedValue({});
    const onInsert = vi.fn();
    render(<CommentBankPicker current="" onInsert={onInsert} />);

    fireEvent.click(screen.getByRole('button', { name: /Insert saved comment/ }));
    fireEvent.click(await screen.findByText('Good structure'));
    expect(onInsert).toHaveBeenCalledWith('Well organized answer.');
    expect(svc.useComment).toHaveBeenCalledWith(4);
  });
});
