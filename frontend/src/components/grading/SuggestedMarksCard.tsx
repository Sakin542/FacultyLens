import React from 'react';
import { Sparkles } from 'lucide-react';
import { AIGradingResult } from '@/types/grading';
import { GradingStatusBadge } from './GradingStatusBadge';

interface SuggestedMarksCardProps {
  result: AIGradingResult;
  facultyMarks?: number | null;
}

export const formatMarks = (value: number | null | undefined): string => {
  if (value === null || value === undefined) return '—';
  return Number.isInteger(value) ? String(value) : String(Number(value.toFixed(2)));
};

/**
 * Headline suggested marks. Faculty final marks (when present) are shown alongside, never merged.
 */
export const SuggestedMarksCard: React.FC<SuggestedMarksCardProps> = ({ result, facultyMarks }) => {
  const hasFaculty = facultyMarks !== null && facultyMarks !== undefined;
  const diff = hasFaculty && result.suggested_marks !== null ? Number((facultyMarks - result.suggested_marks).toFixed(2)) : null;

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3" data-testid="suggested-marks-card">
      <div className="p-4 rounded-xl bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C]">
        <div className="flex items-center justify-between gap-2">
          <span className="text-[10px] uppercase tracking-wider font-semibold text-[#737373] flex items-center gap-1.5">
            <Sparkles className="w-3 h-3 text-amber-500" /> AI Suggested Marks
          </span>
          <GradingStatusBadge status={result.grading_status} />
        </div>
        <p className="mt-2 text-2xl font-bold font-mono text-[#111111] dark:text-white" data-testid="suggested-marks">
          {formatMarks(result.suggested_marks)} <span className="text-sm text-[#737373] font-normal">/ {formatMarks(result.maximum_marks)}</span>
        </p>
        {result.generated_at && (
          <p className="text-[10px] text-[#737373] mt-1">
            Generated {new Date(result.generated_at).toLocaleString()}
            {result.rubric_version ? ` · Rubric v${result.rubric_version}` : ''}
          </p>
        )}
      </div>
      <div className="p-4 rounded-xl bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#3A3A3C]">
        <span className="text-[10px] uppercase tracking-wider font-semibold text-[#737373]">Faculty Final Marks</span>
        <p className="mt-2 text-2xl font-bold font-mono text-[#111111] dark:text-white" data-testid="faculty-final-marks">
          {hasFaculty ? formatMarks(facultyMarks) : 'Not set'}
          {hasFaculty && <span className="text-sm text-[#737373] font-normal"> / {formatMarks(result.maximum_marks)}</span>}
        </p>
        {diff !== null && (
          <p className="text-[10px] text-[#737373] mt-1" data-testid="marks-difference">
            Difference from AI suggestion: {diff > 0 ? '+' : ''}{formatMarks(diff)}
          </p>
        )}
        {!hasFaculty && <p className="text-[10px] text-[#737373] mt-1">Awaiting faculty review.</p>}
      </div>
    </div>
  );
};
