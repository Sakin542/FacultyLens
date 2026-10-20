import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ClassSummaryCard } from '@/components/performance/ClassSummaryCard';

vi.mock('@/services/performanceService', () => ({ performanceService: { classSummary: vi.fn() } }));
import { performanceService } from '@/services/performanceService';
const classSummary = performanceService.classSummary as unknown as ReturnType<typeof vi.fn>;

describe('Class summary', () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it('writes the note on request and lists the rubric points the class missed', async () => {
    classSummary.mockResolvedValue({ status: 'success', data: {
      status: 'ready',
      stats: { answers: 8, students: 8, questions: 2, average: 61.3, expected: 70, rubric_answers: 4 },
      paragraphs: [
        '8 graded answers from 8 students across 2 questions. The class average was 61.3% against an expected 70%.',
        'What the class struggled with: On Q1, "Shortest path" was missing or barely addressed in 3 of 4 answers.',
      ],
      note: 'x',
      struggles: [{ question_id: 1, question_number: 'Q1', criterion: 'Shortest path', responses: 4, weak: 3,
        levels: { STRONG: 1, PARTIAL: 0, LIMITED: 1, NOT_ADDRESSED: 2 }, top_missing: [{ text: 'BFS finds the shortest path', count: 3 }] }],
      strengths: [], method: 'Finalized faculty marks, plus the rubric evidence.',
    } });
    render(<ClassSummaryCard assessmentId={7} />);
    expect(classSummary).not.toHaveBeenCalled(); // nothing runs until asked

    fireEvent.click(screen.getByRole('button', { name: /Write class summary/ }));
    await waitFor(() => expect(classSummary).toHaveBeenCalledWith(7));
    expect(await screen.findByText(/The class average was 61.3%/)).toBeInTheDocument();
    expect(screen.getByText('Rubric points the class missed')).toBeInTheDocument();
    expect(screen.getByText('3/4')).toBeInTheDocument();
    expect(screen.getByText('BFS finds the shortest path (3)')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Copy note/ })).toBeInTheDocument();
  });

  it('explains when there are no finalized grades, and shows errors', async () => {
    classSummary.mockResolvedValueOnce({ status: 'success', data: { status: 'no_data', message: 'No finalized grades yet.', paragraphs: [], note: '' } });
    render(<ClassSummaryCard assessmentId={7} />);
    fireEvent.click(screen.getByRole('button', { name: /Write class summary/ }));
    expect(await screen.findByText('No finalized grades yet.')).toBeInTheDocument();

    classSummary.mockRejectedValueOnce(new Error('Class performance summary is currently turned off by the administrator.'));
    fireEvent.click(screen.getByRole('button', { name: /Refresh/ }));
    expect(await screen.findByRole('alert')).toHaveTextContent('turned off');
  });
});
