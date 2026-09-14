import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import {
  AI_ORIGIN_LABELS,
  AI_SAFETY_STATE_LABELS,
  AiOriginBadge,
  AiSafetyState,
  AiSuggestionNotice,
  chatEvidenceState,
  formatConfidence,
} from '@/components/common/AiSafety';
import { ChatMessage } from '@/components/chat/ChatWindow';
import { SuggestedMarksCard } from '@/components/grading/SuggestedMarksCard';
import { ChatMessage as ChatMessageType } from '@/types/chat';
import { AIGradingResult } from '@/types/grading';

const assistant: ChatMessageType = {
  id: 2, role: 'ASSISTANT', content: 'Normalization reduces redundancy. [S1]', grounded: true, generation_method: 'extractive',
  generation_model: 'engine', embedding_model: 'MiniLM', prompt_version: '1.0.0', retrieved_count: 2, used_count: 1,
  disclaimer: 'd', created_at: null, evidence_status: 'SUFFICIENT',
  sources: [{ id: 1, document_id: 10, chunk_id: 100, document_name: 'syllabus.pdf', document_type: 'syllabus', similarity_score: 0.8, page_number: 3, section_title: null, excerpt: 'x', source_order: 1 }],
};

const gradingResult = (o: Partial<AIGradingResult> = {}): AIGradingResult => ({
  id: 1, student_answer_id: 101, rubric_id: 20, rubric_version: 1, suggested_marks: 7.5, maximum_marks: 10,
  grading_status: 'COMPLETED', is_current: true, is_stale: false, stale_reasons: [], faculty_decision: null,
  overall_feedback: 'ok', strengths: [], missing_elements: [], evaluation_summary: 's', criterion_results: [],
  model_name: 'engine', model_version: '1', generation_method: 'embedding_rubric_alignment', error_message: null,
  generated_at: null, reviewed_at: null, created_at: null, updated_at: null, ...o,
} as AIGradingResult);

describe('STEP 46 AI safety UI vocabulary', () => {
  it('renders the three origin badges with distinct labels', () => {
    render(<><AiOriginBadge origin="AI_SUGGESTED" /><AiOriginBadge origin="FACULTY_APPROVED" /><AiOriginBadge origin="FACULTY_FINAL" /></>);
    expect(screen.getByTestId('ai-origin-ai_suggested')).toHaveTextContent(AI_ORIGIN_LABELS.AI_SUGGESTED);
    expect(screen.getByTestId('ai-origin-faculty_approved')).toHaveTextContent('Faculty Approved');
    expect(screen.getByTestId('ai-origin-faculty_final')).toHaveTextContent('Faculty Final');
    expect(screen.getByTestId('ai-origin-ai_suggested')).toHaveAttribute('title', expect.stringMatching(/faculty must review/i));
  });

  it('renders every safety state with its canonical label', () => {
    render(<>{(Object.keys(AI_SAFETY_STATE_LABELS) as Array<keyof typeof AI_SAFETY_STATE_LABELS>).map((k) => <AiSafetyState key={k} state={k} />)}</>);
    expect(screen.getByText('Insufficient evidence')).toBeInTheDocument();
    expect(screen.getByText('Conflicting evidence')).toBeInTheDocument();
    expect(screen.getByText('Source unavailable')).toBeInTheDocument();
    expect(screen.getByText('AI analysis unavailable')).toBeInTheDocument();
    expect(screen.getByText('Faculty review recommended')).toBeInTheDocument();
  });

  it('shows the verify-before-use notice', () => {
    render(<AiSuggestionNotice />);
    expect(screen.getByTestId('ai-suggestion-notice')).toHaveTextContent('AI-generated suggestion — verify before use.');
  });

  it('maps backend evidence status onto UI states without inventing grounding', () => {
    expect(chatEvidenceState('CONFLICTING', true)).toBe('CONFLICTING_EVIDENCE');
    expect(chatEvidenceState('SUFFICIENT', true)).toBe('GROUNDED');
    expect(chatEvidenceState('SUFFICIENT', false)).toBe('INSUFFICIENT_EVIDENCE');
    expect(chatEvidenceState('INSUFFICIENT', false)).toBe('INSUFFICIENT_EVIDENCE');
    expect(chatEvidenceState(undefined, false)).toBe('INSUFFICIENT_EVIDENCE');
    expect(chatEvidenceState(null, true)).toBe('GROUNDED');
  });

  it('never displays an unvalidated confidence as a number', () => {
    expect(formatConfidence(0.84)).toMatch(/^84%/);
    expect(formatConfidence(0.84)).toMatch(/not accuracy/);
    for (const bad of [null, undefined, 'high', 1.5, -0.1, NaN, 99]) expect(formatConfidence(bad)).toBe('Confidence unavailable');
  });
});

describe('STEP 46 chat safety states', () => {
  it('shows a conflicting-evidence panel listing both values and recommends faculty review', () => {
    render(<ChatMessage message={{ ...assistant, evidence_status: 'CONFLICTING', generation_method: 'conflicting_evidence',
      content: 'Conflicting evidence detected. Faculty review required.',
      conflicting_evidence: [{ subject: 'marks', values: [
        { chunk_id: 1, document_id: 4, document_name: 'Syllabus.pdf', value: 50, unit: 'mark' },
        { chunk_id: 2, document_id: 5, document_name: 'Handbook.pdf', value: 60, unit: 'mark' },
      ] }] }} />);
    expect(screen.getByTestId('ai-safety-conflicting_evidence')).toHaveTextContent('Conflicting evidence');
    expect(screen.getByTestId('ai-safety-faculty_review_recommended')).toBeInTheDocument();
    const panel = screen.getByTestId('conflicting-evidence');
    expect(panel).toHaveTextContent('Syllabus.pdf: marks = 50 mark');
    expect(panel).toHaveTextContent('Handbook.pdf: marks = 60 mark');
    expect(panel).toHaveTextContent(/has not chosen a value/);
  });

  it('shows the insufficient-evidence state for ungrounded answers', () => {
    render(<ChatMessage message={{ ...assistant, grounded: false, sources: [], evidence_status: 'INSUFFICIENT' }} />);
    expect(screen.getByTestId('ungrounded-badge')).toHaveTextContent('No supporting evidence found');
    expect(screen.queryByTestId('conflicting-evidence')).toBeNull();
  });

  it('keeps legacy messages (no evidence_status) working from the grounded flag', () => {
    const { evidence_status: _e, ...legacy } = assistant;
    render(<ChatMessage message={legacy as ChatMessageType} />);
    expect(screen.getByTestId('grounded-badge')).toHaveTextContent('Grounded in documents');
  });

  it('tells faculty when instruction-like text was found and ignored', () => {
    render(<ChatMessage message={{ ...assistant, injection_detected: true }} />);
    expect(screen.getByTestId('injection-notice')).toHaveTextContent(/treated as untrusted content and not followed/);
  });

  it('renders document content as text, never as markup', () => {
    render(<ChatMessage message={{ ...assistant, content: '<img src=x onerror="alert(1)"> <b>bold</b>' }} />);
    expect(screen.getByTestId('chat-message-assistant').querySelector('img')).toBeNull();
    expect(screen.getByTestId('chat-message-assistant').querySelector('b')).toBeNull();
    expect(screen.getByText(/<b>bold<\/b>/)).toBeInTheDocument();
  });
});

describe('STEP 46 grading origin distinction', () => {
  it('labels AI marks as AI Suggested and faculty marks as Faculty Final, never merging them', () => {
    render(<SuggestedMarksCard result={gradingResult()} facultyMarks={8} />);
    expect(screen.getByTestId('ai-origin-ai_suggested')).toBeInTheDocument();
    expect(screen.getByTestId('ai-origin-faculty_final')).toBeInTheDocument();
    expect(screen.getByTestId('suggested-marks')).toHaveTextContent('7.5');
    expect(screen.getByTestId('faculty-final-marks')).toHaveTextContent('8');
    expect(screen.getByTestId('ai-suggestion-notice')).toBeInTheDocument();
  });

  it('does not show a Faculty Final badge before faculty enter marks', () => {
    render(<SuggestedMarksCard result={gradingResult()} facultyMarks={null} />);
    expect(screen.queryByTestId('ai-origin-faculty_final')).toBeNull();
    expect(screen.getByTestId('faculty-final-marks')).toHaveTextContent('Not set');
  });

  it('shows AI analysis unavailable instead of a number when grading failed', () => {
    render(<SuggestedMarksCard result={gradingResult({ grading_status: 'FAILED', suggested_marks: null, error_message: 'AI grading assistance is temporarily unavailable.' })} />);
    expect(screen.getByTestId('ai-safety-ai_unavailable')).toHaveTextContent('AI analysis unavailable');
    expect(screen.getByTestId('suggested-marks')).toHaveTextContent('—');
    expect(screen.queryByTestId('ai-suggestion-notice')).toBeNull();
  });
});
