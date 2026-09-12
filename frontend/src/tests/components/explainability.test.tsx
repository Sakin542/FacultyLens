import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { ExplanationPanel } from '@/components/explainability/ExplanationPanel';
import { ExplainabilityBadge } from '@/components/explainability/ExplainabilityBadge';
import { ConfidenceIndicator } from '@/components/explainability/ConfidenceIndicator';
import { SourceCitation } from '@/components/explainability/SourceCitation';
import type { AiExplanation } from '@/types/explainability';
import { ApiError } from '@/services/api';

vi.mock('@/services/explainabilityService', () => ({
  explainabilityService: {
    getExplanation: vi.fn(),
    review: vi.fn(),
    override: vi.fn(),
    recordEvent: vi.fn(),
    getReviews: vi.fn(),
    clearCache: vi.fn(),
  },
}));

import { explainabilityService } from '@/services/explainabilityService';
const svc = explainabilityService as unknown as Record<string, ReturnType<typeof vi.fn>>;

const bloomExplanation = (overrides: Partial<AiExplanation> = {}): AiExplanation => ({
  result_type: 'bloom',
  result_id: 7,
  result: { label: 'ANALYZE', score: null, display: 'Analyze' },
  explanation: {
    summary: 'FacultyLens classified this question at the ANALYZE level because it uses Analyze-level directive wording ("Compare", "analyze").',
    details: [
      { label: 'Faculty value', value: 'Understand' },
      { label: 'Weights', value: [{ dimension: 'Topic Coverage', configured_weight: 20, applied_weight: 0, status: 'EXCLUDED' }] },
    ],
  },
  evidence: [
    { type: 'question_text', label: 'Question wording: leading directive verb', text: '"Compare"' },
    { type: 'question_text', label: 'Question wording: Analyze-level verb', text: '"analyze"' },
  ],
  evidence_status: 'available',
  method: { type: 'RULE_BASED', label: 'FacultyLens deterministic rules', description: 'Rule tables applied to the question wording.', components: ['Leading directive verb'] },
  model: { name: 'facultylens-question-analyzer', version: '1.0.0', prompt_version: null, embedding_model: null, rule_version: 'step10-rules-1.0.0' },
  confidence: { available: false, value: null, note: 'Confidence is not available for this result.' },
  limitations: ['Bloom classification can involve expert judgment.'],
  related: { links: [{ label: 'View question', type: 'question', id: 7 }], analysis_report_id: 3, analysis_version: 2, is_current: true },
  evaluation: { status: 'EVALUATED', label: 'Evaluated', task: 'BLOOM_CLASSIFICATION', headline_metric: 'macro_f1', headline_value: 0.83, example_count: 60 },
  review: {
    overridable: true,
    can_review: true,
    actions: ['ACCEPTED', 'REJECTED', 'REVIEWED', 'OVERRIDE'],
    override_options: ['REMEMBER', 'UNDERSTAND', 'APPLY', 'ANALYZE', 'EVALUATE', 'CREATE'],
    override_reasons: [
      { code: 'AI_CLASSIFICATION_INCORRECT', label: 'AI classification incorrect' },
      { code: 'ACADEMIC_JUDGMENT', label: 'Academic judgment' },
    ],
    latest: null,
    history_count: 0,
    faculty_value: 'Understand',
    faculty_field: 'cognitive_level',
  },
  version: { explanation_version: '1.0.0', generated_at: '2026-09-13T00:00:00Z' },
  disclaimer: 'AI assists. Faculty decides.',
  ...overrides,
});

describe('ExplanationPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('shows a loading state, then the result, why, evidence and limitations (level 1)', async () => {
    svc.getExplanation.mockResolvedValue(bloomExplanation());
    render(<ExplanationPanel type="bloom" id={7} title="Bloom level" />);

    expect(screen.getByTestId('explanation-loading')).toBeInTheDocument();
    await waitFor(() => expect(screen.getByTestId('explanation-result')).toHaveTextContent('Analyze'));
    expect(screen.getByTestId('explanation-why')).toHaveTextContent('FacultyLens classified this question at the ANALYZE level');
    expect(screen.getAllByTestId('evidence-item')).toHaveLength(2);
    expect(screen.getByText('"Compare"')).toBeInTheDocument();
    expect(screen.getByTestId('limitations-panel')).toHaveTextContent('Bloom classification can involve expert judgment.');
    expect(screen.getByTestId('confidence-value')).toHaveTextContent('Not available');
    expect(svc.getExplanation).toHaveBeenCalledWith('bloom', 7, { force: false });
    // Level 2/3 are collapsed by default
    expect(screen.queryByTestId('method-info')).not.toBeInTheDocument();
  });

  it('expands technical details (method/model/evaluation) and detailed evidence, logging an evidence-viewed event', async () => {
    svc.getExplanation.mockResolvedValue(bloomExplanation());
    render(<ExplanationPanel type="bloom" id={7} />);
    await waitFor(() => screen.getByTestId('explanation-result'));

    const technical = screen.getByRole('button', { name: /technical details/i });
    expect(technical).toHaveAttribute('aria-expanded', 'false');
    fireEvent.click(technical);
    expect(technical).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByTestId('method-type')).toHaveTextContent('RULE_BASED');
    expect(screen.getByTestId('model-info')).toHaveTextContent('facultylens-question-analyzer');
    expect(screen.getByTestId('model-info')).toHaveTextContent('step10-rules-1.0.0');
    expect(screen.getByTestId('evaluation-status')).toHaveTextContent('Evaluated');
    expect(screen.getByTestId('evaluation-status')).toHaveTextContent('macro_f1 = 0.830');
    // structured detail rendered as a table
    expect(screen.getByTestId('explanation-details')).toHaveTextContent('EXCLUDED');

    fireEvent.click(screen.getByRole('button', { name: /detailed evidence/i }));
    expect(svc.recordEvent).toHaveBeenCalledWith('bloom', 7, 'AI_EVIDENCE_VIEWED', { level: 3 });
    fireEvent.keyDown(technical, { key: 'Enter' });
  });

  it('renders the empty-evidence state honestly', async () => {
    svc.getExplanation.mockResolvedValue(bloomExplanation({ evidence: [], evidence_status: 'unavailable', evidence_note: 'Cue-word evidence could not be loaded because the AI service is unavailable.' }));
    render(<ExplanationPanel type="bloom" id={7} />);
    await waitFor(() => screen.getByTestId('explanation-result'));
    expect(screen.getByTestId('evidence-empty')).toBeInTheDocument();
    expect(screen.getByTestId('evidence-status-note')).toHaveTextContent('unavailable');
    expect(screen.getByTestId('evidence-status-note')).toHaveTextContent('AI service is unavailable');
  });

  it('shows an error state with retry', async () => {
    svc.getExplanation.mockRejectedValueOnce(new ApiError(403, 'Unauthorized access to this AI result.'));
    svc.getExplanation.mockResolvedValueOnce(bloomExplanation());
    render(<ExplanationPanel type="bloom" id={7} />);
    await waitFor(() => expect(screen.getByTestId('explanation-error')).toHaveTextContent('Unauthorized access to this AI result.'));
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => screen.getByTestId('explanation-result'));
    expect(svc.getExplanation).toHaveBeenLastCalledWith('bloom', 7, { force: true });
  });

  it('records a review decision and refreshes', async () => {
    svc.getExplanation.mockResolvedValueOnce(bloomExplanation());
    svc.review.mockResolvedValue({ status: 'success', message: 'Your decision has been recorded.', data: { review: { id: 1, action: 'ACCEPTED', user: { id: 1 }, created_at: null } } });
    svc.getExplanation.mockResolvedValueOnce(bloomExplanation({ review: { ...bloomExplanation().review, latest: { id: 1, action: 'ACCEPTED', user: { id: 1 }, created_at: '2026-09-13T00:00:00Z' }, history_count: 1 } }));
    const onChanged = vi.fn();
    render(<ExplanationPanel type="bloom" id={7} onChanged={onChanged} />);
    await waitFor(() => screen.getByTestId('explanation-result'));

    fireEvent.click(screen.getByRole('button', { name: 'Accept' }));
    fireEvent.change(screen.getByLabelText(/Record decision: ACCEPTED/), { target: { value: 'Looks right.' } });
    fireEvent.click(screen.getByTestId('review-submit'));

    await waitFor(() => expect(svc.review).toHaveBeenCalledWith('bloom', 7, 'ACCEPTED', 'Looks right.'));
    await waitFor(() => expect(screen.getByTestId('review-latest')).toHaveTextContent('ACCEPTED'));
    expect(screen.getByTestId('explanation-notice')).toHaveTextContent('Your decision has been recorded.');
    expect(onChanged).toHaveBeenCalled();
  });

  it('opens the override dialog, requires a value, and submits value + reason', async () => {
    svc.getExplanation.mockResolvedValue(bloomExplanation());
    svc.override.mockResolvedValue({ status: 'success', message: 'Your override has been applied.', data: { review: { id: 2, action: 'OVERRIDDEN', user: { id: 1 }, created_at: null }, applied: { label: 'EVALUATE' } } });
    render(<ExplanationPanel type="bloom" id={7} />);
    await waitFor(() => screen.getByTestId('explanation-result'));

    fireEvent.click(screen.getByTestId('override-button'));
    const dialog = screen.getByTestId('override-dialog');
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    expect(dialog).toHaveAttribute('role', 'dialog');
    expect(within(dialog).getByText(/Analyze/)).toBeInTheDocument();

    fireEvent.click(screen.getByTestId('override-submit'));
    expect(screen.getByRole('alert')).toHaveTextContent('Select the value');
    expect(svc.override).not.toHaveBeenCalled();

    fireEvent.change(screen.getByTestId('override-value'), { target: { value: 'EVALUATE' } });
    fireEvent.change(screen.getByTestId('override-reason'), { target: { value: 'ACADEMIC_JUDGMENT' } });
    fireEvent.click(screen.getByTestId('override-submit'));
    await waitFor(() => expect(svc.override).toHaveBeenCalledWith('bloom', 7, { label: 'EVALUATE' }, 'ACADEMIC_JUDGMENT', ''));
    await waitFor(() => expect(screen.queryByTestId('override-dialog')).not.toBeInTheDocument());
    expect(screen.getByTestId('explanation-notice')).toHaveTextContent('Your override has been applied.');
  });

  it('hides review actions when the viewer cannot review', async () => {
    svc.getExplanation.mockResolvedValue(bloomExplanation({ review: { ...bloomExplanation().review, can_review: false, actions: [] } }));
    render(<ExplanationPanel type="bloom" id={7} />);
    await waitFor(() => screen.getByTestId('explanation-result'));
    expect(screen.getByTestId('review-unavailable')).toHaveTextContent('your role does not allow');
    expect(screen.queryByTestId('override-button')).not.toBeInTheDocument();
  });

  it('never displays "exact duplicate" for a similarity explanation and shows the score out of 1.00', async () => {
    svc.getExplanation.mockResolvedValue(
      bloomExplanation({
        result_type: 'similarity',
        result: { label: 'POTENTIAL_DUPLICATE', score: 0.88, display: 'Potential Duplicate', similarity_display: '0.88 / 1.00' },
        explanation: { summary: 'Both questions appear to address the same underlying concept: the similarity score 0.88 is at or above the potential-duplicate threshold (0.85).', details: [] },
        evidence: [{ type: 'score', label: 'Semantic similarity', score: 0.88, text: '0.88 / 1.00' }],
        limitations: ['Semantic similarity does not prove that two questions are duplicates.'],
        review: { ...bloomExplanation().review, overridable: false, actions: ['ACCEPTED', 'REJECTED'], reject_label: 'Not a duplicate' },
      })
    );
    render(<ExplanationPanel type="similarity" id={11} />);
    await waitFor(() => screen.getByTestId('explanation-result'));
    expect(screen.getByTestId('explanation-result')).toHaveTextContent('Potential Duplicate');
    expect(screen.getByText('Similarity 0.88 / 1.00')).toBeInTheDocument();
    expect(screen.getByTestId('explanation-panel').textContent?.toLowerCase()).not.toContain('exact duplicate');
    expect(screen.getByRole('button', { name: 'Not a duplicate' })).toBeInTheDocument();
    expect(screen.queryByTestId('override-button')).not.toBeInTheDocument();
  });

  it('has accessible structure: headings, close button label and keyboard-operable disclosures', async () => {
    svc.getExplanation.mockResolvedValue(bloomExplanation());
    const onClose = vi.fn();
    render(<ExplanationPanel type="bloom" id={7} title="Bloom level" onClose={onClose} />);
    await waitFor(() => screen.getByTestId('explanation-result'));
    expect(screen.getByRole('heading', { name: 'Bloom level' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'AI Result' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Close explanation' }));
    expect(onClose).toHaveBeenCalled();
    const disclosures = screen.getAllByRole('button', { expanded: false });
    expect(disclosures.length).toBeGreaterThanOrEqual(2);
    expect(screen.getByRole('list', { name: 'Evidence' })).toBeInTheDocument();
  });
});

describe('Explainability building blocks', () => {
  it('ExplainabilityBadge exposes a "Why?" affordance and triggers onExplain', () => {
    const onExplain = vi.fn();
    render(<ExplainabilityBadge label="Bloom: ANALYZE" onExplain={onExplain} tone="outline" />);
    const btn = screen.getByRole('button', { name: /Why\? Explain Bloom: ANALYZE/ });
    fireEvent.click(btn);
    expect(onExplain).toHaveBeenCalledTimes(1);
    expect(btn).toHaveTextContent('Bloom: ANALYZE');
  });

  it('ConfidenceIndicator shows a percentage only when available and never fabricates one', () => {
    const { rerender } = render(<ConfidenceIndicator confidence={{ available: true, value: 0.81, note: 'Model confidence.' }} />);
    expect(screen.getByTestId('confidence-value')).toHaveTextContent('Available: 81%');
    expect(screen.getByRole('meter')).toHaveAttribute('aria-valuenow', '81');
    rerender(<ConfidenceIndicator confidence={{ available: false, value: null, note: 'Not produced by this method.' }} />);
    expect(screen.getByTestId('confidence-value')).toHaveTextContent('Not available');
    expect(screen.queryByRole('meter')).not.toBeInTheDocument();
  });

  it('SourceCitation shows the location when known and "Source location unavailable" otherwise', () => {
    const onOpen = vi.fn();
    const { rerender } = render(<SourceCitation item={{ type: 'document_chunk', label: 'Source 1: Notes', text: 'Page 12 · Section: Graph search', document_id: 5, meta: { excerpt: 'BFS explores level by level.', relevance: 0.61 } }} onOpen={onOpen} />);
    expect(screen.getByText('Page 12 · Section: Graph search')).toBeInTheDocument();
    expect(screen.getByText('BFS explores level by level.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Open source document/ }));
    expect(onOpen).toHaveBeenCalled();
    rerender(<SourceCitation item={{ type: 'document_chunk', label: 'Source 2', text: '', document_id: null }} />);
    expect(screen.getByText('Source location unavailable')).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });
});
