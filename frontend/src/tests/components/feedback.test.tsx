import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { FeedbackRating } from '@/components/feedback/FeedbackRating';
import { FeedbackReasonSelect } from '@/components/feedback/FeedbackReasonSelect';

describe('Feedback UI Components', () => {
  describe('FeedbackRating Component', () => {
    it('renders 5 rating score buttons', () => {
      const handleChange = vi.fn();
      render(<FeedbackRating value={null} onChange={handleChange} />);

      for (let i = 1; i <= 5; i++) {
        expect(screen.getByText(String(i))).toBeInTheDocument();
      }
    });

    it('triggers onChange with selected star number', () => {
      const handleChange = vi.fn();
      render(<FeedbackRating value={null} onChange={handleChange} />);

      const star5 = screen.getByText('5');
      fireEvent.click(star5);
      expect(handleChange).toHaveBeenCalledWith(5);
    });

    it('displays textual rating label when value is selected', () => {
      render(<FeedbackRating value={5} onChange={() => {}} />);
      expect(screen.getByText('Very useful')).toBeInTheDocument();
    });
  });

  describe('FeedbackReasonSelect Component', () => {
    it('adapts prompt text based on decision type', () => {
      const { rerender } = render(
        <FeedbackReasonSelect
          decision="DISMISSED"
          value={null}
          onChange={() => {}}
        />
      );
      expect(screen.getByText(/why are you ignoring\/dismissing/i)).toBeInTheDocument();

      rerender(
        <FeedbackReasonSelect
          decision="ACCEPTED"
          value={null}
          onChange={() => {}}
        />
      );
      expect(screen.getByText(/primary reason for acceptance/i)).toBeInTheDocument();
    });

    it('triggers onChange when a reason option is chosen', () => {
      const handleChange = vi.fn();
      render(
        <FeedbackReasonSelect
          decision="ACCEPTED"
          value=""
          onChange={handleChange}
        />
      );

      const select = screen.getByRole('combobox');
      fireEvent.change(select, { target: { value: 'WILL_IMPLEMENT' } });
      expect(handleChange).toHaveBeenCalledWith('WILL_IMPLEMENT');
    });
  });
});
