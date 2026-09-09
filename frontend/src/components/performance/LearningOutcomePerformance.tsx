import React from 'react';
import { LearningOutcomePerformance as LOPerformanceType, QuestionPerformance } from '@/types/performance';
import { PerformanceGapBadge, formatGap, formatPct } from './PerformanceGapBadge';
import { PerformanceBar } from './PerformanceBar';

interface LearningOutcomePerformanceProps {
  outcomes: LOPerformanceType[];
  questions: QuestionPerformance[];
  expected: number;
}

/** Mark-weighted LO performance with links to the questions that contribute. */
export const LearningOutcomePerformance: React.FC<LearningOutcomePerformanceProps> = ({ outcomes, questions, expected }) => {
  const numberOf = (qid: number) => questions.find((q) => q.question_id === qid)?.question_number ?? qid;
  return (
    <div className="space-y-2" data-testid="lo-performance">
      <h4 className="text-[10px] uppercase tracking-wider font-semibold text-[#737373]">Learning Outcome Performance</h4>
      {outcomes.length === 0 ? (
        <p className="text-xs text-[#737373] italic">This course has no learning outcomes yet.</p>
      ) : (
        <ul className="space-y-2.5">
          {outcomes.map((lo) => (
            <li key={lo.id} className="space-y-1" data-testid="lo-row">
              <div className="flex items-start justify-between gap-2 text-xs">
                <div className="min-w-0">
                  <span className="font-bold font-mono text-[#111111] dark:text-white">{lo.lo_code}</span>
                  {lo.lo_description && <span className="text-[#737373] ml-2">{lo.lo_description}</span>}
                  <div className="text-[10px] text-[#737373] mt-0.5">
                    {lo.question_count === 0 ? 'No questions mapped to this outcome in this assessment.' : (
                      <>Questions: {lo.question_ids.map((qid, i) => (
                        <a key={qid} href={`#question-${qid}`} className="underline hover:text-[#111111] dark:hover:text-white" data-testid="lo-question-link">Q{numberOf(qid)}{i < lo.question_ids.length - 1 ? ', ' : ''}</a>
                      ))} · {lo.response_count} responses</>
                    )}
                  </div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                  <span className="font-mono text-[#111111] dark:text-white">{formatPct(lo.average_percentage)}</span>
                  <span className="text-[10px] text-[#737373] w-14 text-right">{formatGap(lo.performance_gap)}</span>
                  <PerformanceGapBadge status={lo.performance_status} />
                </div>
              </div>
              <PerformanceBar value={lo.average_percentage} status={lo.performance_status} expected={expected} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};
