import React from 'react';
import { TopicPerformance as TopicPerformanceType } from '@/types/performance';
import { PerformanceGapBadge, formatGap, formatPct } from './PerformanceGapBadge';
import { PerformanceBar } from './PerformanceBar';

interface TopicPerformanceProps {
  topics: TopicPerformanceType[];
  expected: number;
  hasQuestions: boolean;
}

/** Simple horizontal bars per STEP 10 topic; gap areas are highlighted subtly. */
export const TopicPerformance: React.FC<TopicPerformanceProps> = ({ topics, expected, hasQuestions }) => (
  <div className="space-y-2" data-testid="topic-performance">
    <h4 className="text-[10px] uppercase tracking-wider font-semibold text-sage-500">Topic Performance</h4>
    {topics.length === 0 ? (
      <p className="text-xs text-sage-500 italic">
        {hasQuestions ? 'Topic performance unavailable for questions without topic classification. Run the AI question analysis to classify topics.' : 'No questions available.'}
      </p>
    ) : (
      <ul className="space-y-2.5">
        {topics.map((t) => (
          <li key={t.id} className="space-y-1" data-testid="topic-row">
            <div className="flex items-center justify-between gap-2 text-xs">
              <span className="font-medium text-sage-800 dark:text-white truncate">{t.topic}</span>
              <div className="flex items-center gap-2 shrink-0">
                <span className="text-[10px] text-sage-500">{t.question_count} q · {t.response_count} responses</span>
                <span className="font-mono text-sage-800 dark:text-white">{formatPct(t.average_percentage)}</span>
                <span className="text-[10px] text-sage-500 w-14 text-right">{formatGap(t.performance_gap)}</span>
                <PerformanceGapBadge status={t.performance_status} />
              </div>
            </div>
            <PerformanceBar value={t.average_percentage} status={t.performance_status} expected={expected} />
          </li>
        ))}
      </ul>
    )}
  </div>
);
