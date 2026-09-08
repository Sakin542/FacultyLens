import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { OverallQualityCard } from '@/components/analysis/OverallQualityCard';
import { AIFindingsCard } from '@/components/analysis/AIFindingsCard';
import { AnalysisEmptyState } from '@/components/analysis/AnalysisEmptyState';

describe('Analysis UI Components', () => {
  describe('OverallQualityCard Component', () => {
    it('renders numeric score and rating badge', () => {
      render(
        <OverallQualityCard
          score={88.5}
          rating="GOOD"
          totalQuestions={20}
        />
      );

      // Math.round(88.5) => 89
      expect(screen.getByText('89')).toBeInTheDocument();
      expect(screen.getByText('GOOD')).toBeInTheDocument();
      expect(screen.getByText(/across 20 questions/i)).toBeInTheDocument();
    });

    it('renders placeholder when score is null or pending', () => {
      render(
        <OverallQualityCard
          score={null}
          rating={null}
          totalQuestions={0}
        />
      );

      // When score is null, OverallQualityCard displays '—' and defaults to 'REQUIRES ATTENTION'
      expect(screen.getByText('—')).toBeInTheDocument();
      expect(screen.getByText('REQUIRES ATTENTION')).toBeInTheDocument();
    });
  });

  describe('AIFindingsCard Component', () => {
    it('renders key findings correctly', () => {
      const findings = [
        'Well-balanced assessment distribution across Bloom cognitive levels.',
        'High similarity detected with Question 4 from 2025 Midterm bank.',
      ];

      render(<AIFindingsCard findings={findings} />);

      expect(screen.getByText(/well-balanced assessment/i)).toBeInTheDocument();
      expect(screen.getByText(/high similarity detected/i)).toBeInTheDocument();
    });
  });

  describe('AnalysisEmptyState Component', () => {
    it('triggers onRunAnalysis when action button is clicked', () => {
      const handleRun = vi.fn();
      render(
        <AnalysisEmptyState
          assessmentTitle="Database Midterm"
          courseCode="CSE101"
          onRunAnalysis={handleRun}
          isRunningAnalysis={false}
        />
      );

      const runBtn = screen.getByRole('button', { name: /run ai analysis/i });
      fireEvent.click(runBtn);
      expect(handleRun).toHaveBeenCalledTimes(1);
    });
  });
});
