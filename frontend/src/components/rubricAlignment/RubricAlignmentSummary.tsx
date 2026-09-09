import React from 'react';
import { AlertTriangle, Scale } from 'lucide-react';
import { AIGradingResult } from '@/types/grading';
import { ALIGNMENT_GRADE_GAP_THRESHOLD, RubricAlignment, gradingAlignmentGap } from '@/types/rubricAlignment';
import { AlignmentScore, formatPercent } from './AlignmentScore';

interface RubricAlignmentSummaryProps {
  alignment: RubricAlignment;
  /** Current STEP 27 suggestion (if any) so both signals can be shown side by side. */
  aiGrading?: AIGradingResult | null;
}

const fmt = (v: number): string => (Number.isInteger(v) ? String(v) : String(Number(v.toFixed(2))));

/**
 * Overall alignment score, per-status counts, and — when a STEP 27 suggestion exists — the two
 * signals side by side with a review notice if they diverge substantially. Never computes a grade.
 */
export const RubricAlignmentSummary: React.FC<RubricAlignmentSummaryProps> = ({ alignment, aiGrading }) => {
  const counts = alignment.counts;
  const gradingReady = aiGrading && aiGrading.suggested_marks !== null && aiGrading.suggested_marks !== undefined
    && ['COMPLETED', 'REVIEWED', 'FINALIZED'].includes(aiGrading.grading_status);
  const gap = gradingReady ? gradingAlignmentGap(aiGrading!.suggested_marks, aiGrading!.maximum_marks, alignment.overall_alignment_score) : null;
  const inconsistent = gap !== null && Math.abs(gap) >= ALIGNMENT_GRADE_GAP_THRESHOLD;

  return (
    <div className="space-y-3" data-testid="alignment-summary">
      <div className="grid grid-cols-1 sm:grid-cols-[auto_1fr] gap-4 items-center">
        <AlignmentScore score={alignment.overall_alignment_score} status={alignment.alignment_status} unweightedScore={alignment.unweighted_alignment_score} />
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center" data-testid="alignment-counts">
          {([
            ['Strong', counts.strong, 'text-emerald-700 dark:text-emerald-400'],
            ['Partial', counts.partial, 'text-amber-700 dark:text-amber-400'],
            ['Weak', counts.weak, 'text-[#737373]'],
            ['Not aligned', counts.not_aligned, 'text-red-700 dark:text-red-400'],
          ] as const).map(([label, value, cls]) => (
            <div key={label} className="p-2 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E]">
              <span className={`block text-lg font-bold font-mono ${cls}`}>{value}</span>
              <span className="block text-[10px] uppercase tracking-wider text-[#737373]">{label}</span>
            </div>
          ))}
        </div>
      </div>

      {alignment.summary && (
        <p className="text-xs text-[#262626] dark:text-[#E5E5E5] leading-relaxed" data-testid="alignment-summary-text">{alignment.summary}</p>
      )}

      {gradingReady && (
        <div className="p-3 rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] space-y-2" data-testid="signal-comparison">
          <span className="text-[10px] uppercase tracking-wider font-semibold text-[#737373] flex items-center gap-1.5">
            <Scale className="w-3 h-3" /> Two different signals
          </span>
          <div className="grid grid-cols-2 gap-3 text-xs">
            <div>
              <span className="block text-[10px] text-[#737373]">AI Suggested Marks</span>
              <span className="font-mono font-bold text-[#111111] dark:text-white">{fmt(aiGrading!.suggested_marks as number)} / {fmt(aiGrading!.maximum_marks)}</span>
            </div>
            <div>
              <span className="block text-[10px] text-[#737373]">Answer ↔ Rubric Alignment</span>
              <span className="font-mono font-bold text-[#111111] dark:text-white">{formatPercent(alignment.overall_alignment_score)}</span>
            </div>
          </div>
          {inconsistent ? (
            <div className="p-2.5 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 flex items-start gap-2 text-amber-800 dark:text-amber-300" role="note" data-testid="inconsistency-notice">
              <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
              <div className="text-xs space-y-0.5">
                <p className="font-semibold">Review Notice</p>
                <p>The AI grading suggestion and rubric alignment analysis show substantially different signals ({gap! > 0 ? '+' : ''}{gap} points). Faculty review is recommended.</p>
              </div>
            </div>
          ) : (
            <p className="text-[10px] text-[#737373]">Alignment is not a grade; the two signals are shown for comparison only.</p>
          )}
        </div>
      )}
    </div>
  );
};
