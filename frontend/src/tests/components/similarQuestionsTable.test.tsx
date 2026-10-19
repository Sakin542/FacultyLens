import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import { SimilarQuestionsTable } from '@/components/analysis/SimilarQuestionsTable';

const match = (id: number, qNum: number, score: number, status: string) => ({
  id, current_question_id: 100 + qNum, current_question_number: qNum, previous_question_id: id,
  previous_question_text: `Past question ${id}`, similarity_score: score, similarity_status: status,
});

describe('SimilarQuestionsTable', () => {
  it('shows question numbers (not database ids), strongest match first, and folds not-similar pairs', () => {
    render(<SimilarQuestionsTable similarityMatches={[
      match(1, 2, 0.23, 'NOT_SIMILAR'), match(2, 1, 0.76, 'HIGHLY_SIMILAR'), match(3, 1, 1.0, 'POTENTIAL_DUPLICATE'), match(4, 3, 0.55, 'SOMEWHAT_SIMILAR'),
    ]} />);

    const rows = screen.getAllByRole('row').slice(1);
    expect(rows).toHaveLength(3);
    expect(within(rows[0]).getByText('Q1')).toBeInTheDocument();
    expect(within(rows[0]).getByText('Potential Duplicate')).toBeInTheDocument();
    expect(within(rows[2]).getByText('Somewhat Similar')).toBeInTheDocument();
    expect(screen.queryByText('Q#101')).not.toBeInTheDocument();
    expect(screen.queryByText('Not Similar')).not.toBeInTheDocument();

    fireEvent.click(screen.getByTestId('toggle-not-similar'));
    expect(screen.getAllByRole('row').slice(1)).toHaveLength(4);
    expect(screen.getByText('Not Similar')).toBeInTheDocument();
    expect(screen.getByText('Hide 1 not-similar comparison')).toBeInTheDocument();
  });

  it('reports originality when every comparison is below the threshold', () => {
    render(<SimilarQuestionsTable similarityMatches={[match(1, 1, 0.2, 'NOT_SIMILAR')]} />);
    expect(screen.getByText(/No past question reaches the similarity threshold/)).toBeInTheDocument();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
  });
});
