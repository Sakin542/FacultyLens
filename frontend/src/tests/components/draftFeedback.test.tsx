import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { FinalGradeForm } from '@/components/grading/FinalGradeForm';

vi.mock('@/services/aiToolkitService', () => ({ aiToolkitService: { feedbackDraft: vi.fn() } }));
vi.mock('@/services/api', async (orig) => ({ ...(await orig<typeof import('@/services/api')>()), apiClient: vi.fn().mockResolvedValue({ data: [] }) }));

import { aiToolkitService } from '@/services/aiToolkitService';
const feedbackDraft = aiToolkitService.feedbackDraft as unknown as ReturnType<typeof vi.fn>;

const draft = (feedback: string) => ({ feedback, template_feedback: feedback, tone: 'encouraging', improvements: [], next_step: '', criteria: [], method: 'rubric_template', model: null, note: '' });

describe('Draft feedback in the grading form', () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it('fills the feedback box for this answer and never touches the marks', async () => {
    feedbackDraft.mockResolvedValue(draft('Good start: cover shortest paths next.'));
    const onSubmit = vi.fn().mockResolvedValue(undefined);
    render(<FinalGradeForm answerId={101} maxMarks={10} initialMarks={6} initialFeedback="Seen." onSubmit={onSubmit} />);

    fireEvent.click(screen.getByTestId('draft-feedback-button'));
    await waitFor(() => expect(feedbackDraft).toHaveBeenCalledWith({ student_answer_id: 101 }));
    // Appended below what the teacher already wrote
    expect(await screen.findByDisplayValue(/Seen\.\s+Good start: cover shortest paths next\./)).toBeInTheDocument();
    expect(screen.getByText(/does not affect the marks/)).toBeInTheDocument();
    expect(screen.getByTestId('final-marks-input')).toHaveValue(6);
    expect(onSubmit).not.toHaveBeenCalled(); // nothing is saved until the teacher submits
  });

  it('shows why a draft is not possible (e.g. no approved rubric)', async () => {
    feedbackDraft.mockRejectedValue(new Error('This question has no approved rubric yet.'));
    render(<FinalGradeForm answerId={5} maxMarks={10} onSubmit={vi.fn()} />);
    fireEvent.click(screen.getByTestId('draft-feedback-button'));
    expect(await screen.findByText('This question has no approved rubric yet.')).toBeInTheDocument();
  });
});
