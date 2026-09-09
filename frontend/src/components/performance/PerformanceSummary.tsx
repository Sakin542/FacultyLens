import React from 'react';
import { Award, Target, Users } from 'lucide-react';
import { PerformanceAnalysis } from '@/types/performance';
import { PerformanceGapBadge, formatGap, formatPct } from './PerformanceGapBadge';

/** Assessment-level summary tiles. All values come from the stored snapshot. */
export const PerformanceSummary: React.FC<{ analysis: PerformanceAnalysis }> = ({ analysis }) => {
  const s = analysis.summary;
  const tiles: Array<[string, React.ReactNode, string?]> = [
    ['Students', analysis.student_count, `${analysis.submission_count} submissions`],
    ['Finalized Answers', analysis.finalized_answer_count, `${analysis.question_count} questions`],
    ['Overall Average', formatPct(analysis.overall_average_percentage), `expected ${formatPct(analysis.expected_performance_percent)}`],
    ['Overall Gap', formatGap(analysis.overall_gap), analysis.overall_gap !== null && analysis.overall_gap < 0 ? 'above benchmark' : undefined],
    ['Potential Gap Areas', s.gap_areas.length, `${s.los_with_gaps ?? 0} LO${(s.los_with_gaps ?? 0) === 1 ? '' : 's'} · ${s.topics_with_gaps ?? 0} topic${(s.topics_with_gaps ?? 0) === 1 ? '' : 's'}`],
    ['Strong Areas', s.strong_areas.length, undefined],
  ];
  return (
    <div className="space-y-3" data-testid="performance-summary">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className="text-[10px] uppercase tracking-wider font-semibold text-[#737373] flex items-center gap-1.5"><Target className="w-3 h-3" /> Status</span>
          <PerformanceGapBadge status={analysis.overall_status} />
        </div>
        {analysis.analyzed_at && <span className="text-[10px] text-[#737373]">Analyzed {new Date(analysis.analyzed_at).toLocaleString()}</span>}
      </div>
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        {tiles.map(([label, value, sub]) => (
          <div key={label} className="p-3 rounded-xl bg-[#F7F7F5] dark:bg-[#2C2C2E] text-center" data-testid="performance-tile">
            <span className="block text-[10px] uppercase font-semibold text-[#737373]">{label}</span>
            <span className="block text-lg font-bold font-mono text-[#111111] dark:text-white mt-1">{value}</span>
            {sub && <span className="block text-[10px] text-[#737373]">{sub}</span>}
          </div>
        ))}
      </div>
      {(s.insufficient_data_count ?? 0) > 0 && (
        <p className="text-[11px] text-[#737373] flex items-center gap-1.5">
          <Users className="w-3 h-3" /> {s.insufficient_data_count} question{s.insufficient_data_count === 1 ? ' has' : 's have'} fewer than {analysis.minimum_responses} finalized responses and {s.insufficient_data_count === 1 ? 'is' : 'are'} not classified.
        </p>
      )}
      <p className="text-[11px] text-[#737373] flex items-center gap-1.5">
        <Award className="w-3 h-3" /> Percentages are mark-weighted: Σ final marks ÷ Σ maximum marks. Only finalized faculty marks are counted.
      </p>
    </div>
  );
};
