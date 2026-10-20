import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QuestionEditorModal } from '@/components/assessments/QuestionEditorModal';
import { PaperImportModal } from '@/components/assessments/PaperImportModal';

vi.mock('@/services/assessmentQuestionService', () => ({ assessmentQuestionService: { extractFromPaper: vi.fn(), bulkCreate: vi.fn() } }));
import { assessmentQuestionService } from '@/services/assessmentQuestionService';
const svc = assessmentQuestionService as unknown as Record<string, ReturnType<typeof vi.fn>>;

const outcomes = [{ id: 3, code: 'CO1', description: 'Apply graph traversal algorithms.' }];

describe('Question editor', () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it('requires text and marks, then saves every field', async () => {
    const onSave = vi.fn().mockResolvedValue(undefined);
    const onClose = vi.fn();
    render(<QuestionEditorModal isOpen outcomes={outcomes} nextNumber={2} onClose={onClose} onSave={onSave} />);
    expect(screen.getByText('Add question 2')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Add question' }));
    expect(await screen.findByText('Enter the question.')).toBeInTheDocument();
    expect(screen.getByText('Enter the marks (0 or more).')).toBeInTheDocument();
    expect(onSave).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText(/^Question/), { target: { value: 'Explain how BFS works.' } });
    fireEvent.change(screen.getByLabelText(/^Marks/), { target: { value: '10' } });
    fireEvent.change(screen.getByLabelText('Bloom level'), { target: { value: 'Apply' } });
    fireEvent.change(screen.getByLabelText('Course outcome'), { target: { value: '3' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add question' }));

    await waitFor(() => expect(onSave).toHaveBeenCalledWith({
      question_text: 'Explain how BFS works.', marks: 10, question_type: 'descriptive', difficulty_level: 'medium',
      cognitive_level: 'Apply', learning_outcome_id: 3, expected_answer: null,
    }));
    expect(onClose).toHaveBeenCalled();
  });

  it('pre-fills an existing question and shows server errors', async () => {
    const onSave = vi.fn().mockRejectedValue(new Error('A student was already awarded 7 marks on this question; marks cannot go below that.'));
    render(<QuestionEditorModal isOpen outcomes={outcomes} nextNumber={2}
      question={{ id: 9, question_number: 1, question_text: 'Define a graph.', marks: 5, cognitive_level: 'remember', difficulty_level: 'easy', learning_outcome_id: 3 }}
      onClose={vi.fn()} onSave={onSave} />);
    expect(screen.getByText('Edit question 1')).toBeInTheDocument();
    expect(screen.getByLabelText(/^Question/)).toHaveValue('Define a graph.');
    expect(screen.getByLabelText('Bloom level')).toHaveValue('Remember');
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('already awarded 7 marks');
  });
});

describe('Read questions from the paper', () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it('shows the questions found, lets faculty fix and untick them, and saves only the selected ones', async () => {
    svc.extractFromPaper.mockResolvedValue({
      file_name: 'midterm.txt', existing_questions: 0, warnings: ['No marks found for question 3; set them before saving.'],
      questions: [{ number: 1, text: 'Define a graph.', marks: 5 }, { number: 2, text: 'Explain BFS.', marks: 10 }, { number: 3, text: 'Answer all questions.', marks: null }],
    });
    svc.bulkCreate.mockResolvedValue({ status: 'success', message: '2 question(s) added.', data: { ids: [1, 2] } });
    const onImported = vi.fn();
    render(<PaperImportModal isOpen assessmentId={5} totalMarks={15} onClose={vi.fn()} onImported={onImported} />);

    expect(await screen.findByText(/No marks found for question 3/)).toBeInTheDocument();
    // A selected question without marks blocks saving
    expect(screen.getByRole('button', { name: 'Add 3 questions' })).toBeDisabled();
    fireEvent.click(screen.getAllByRole('checkbox')[2]); // not a question
    fireEvent.change(screen.getByLabelText('Question 2 text'), { target: { value: 'Explain how BFS works.' } });
    expect(screen.getByText(/2 selected · 15 marks of 15/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Add 2 questions' }));

    await waitFor(() => expect(svc.bulkCreate).toHaveBeenCalledWith(5, [
      { question_text: 'Define a graph.', marks: 5 }, { question_text: 'Explain how BFS works.', marks: 10 },
    ]));
    expect(onImported).toHaveBeenCalledWith('2 question(s) added.');
  });

  it('explains when the paper cannot be read', async () => {
    svc.extractFromPaper.mockRejectedValue(new Error('Upload the question paper first.'));
    render(<PaperImportModal isOpen assessmentId={5} onClose={vi.fn()} onImported={vi.fn()} />);
    expect(await screen.findByRole('alert')).toHaveTextContent('Upload the question paper first.');
  });
});
