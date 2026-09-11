import React, { useState } from 'react';
import { LayoutList, Table } from 'lucide-react';
import { QuestionPerformance } from '@/types/performance';
import { PerformanceGapBadge, formatGap, formatPct } from './PerformanceGapBadge';
import { QuestionPerformanceCard } from './QuestionPerformanceCard';

interface QuestionPerformanceTableProps {
  questions: QuestionPerformance[];
  expected: number;
}

const fmt = (v: number | null | undefined): string => (v === null || v === undefined ? '—' : Number.isInteger(v) ? String(v) : String(Number(v.toFixed(2))));

/** Question | Avg | Max | Responses | Gap | Status — toggle to card view for detail. */
export const QuestionPerformanceTable: React.FC<QuestionPerformanceTableProps> = ({ questions, expected }) => {
  const [view, setView] = useState<'table' | 'cards'>('table');
  if (questions.length === 0) {
    return <p className="text-xs text-sage-500 italic">This assessment has no questions.</p>;
  }
  return (
    <div className="space-y-2" data-testid="question-performance">
      <div className="flex items-center justify-between">
        <h4 className="text-[10px] uppercase tracking-wider font-semibold text-sage-500">Question Performance</h4>
        <div className="flex items-center gap-1">
          <button type="button" onClick={() => setView('table')} className={`p-1.5 rounded-md ${view === 'table' ? 'bg-sage-700 text-white dark:bg-white dark:text-sage-800' : 'text-sage-500'}`} aria-label="Table view"><Table className="w-3.5 h-3.5" /></button>
          <button type="button" onClick={() => setView('cards')} className={`p-1.5 rounded-md ${view === 'cards' ? 'bg-sage-700 text-white dark:bg-white dark:text-sage-800' : 'text-sage-500'}`} aria-label="Detail view" data-testid="detail-view"><LayoutList className="w-3.5 h-3.5" /></button>
        </div>
      </div>
      {view === 'table' ? (
        <div className="overflow-x-auto rounded-lg border border-sage-200 dark:border-[#3A3A3C]">
          <table className="w-full text-xs" data-testid="question-performance-table">
            <thead className="bg-sage-100 dark:bg-[#2C2C2E] text-[10px] uppercase tracking-wider text-sage-500">
              <tr>
                <th className="text-left px-3 py-2">Question</th>
                <th className="text-right px-3 py-2">Avg</th>
                <th className="text-right px-3 py-2">Max</th>
                <th className="text-right px-3 py-2">Responses</th>
                <th className="text-right px-3 py-2">Gap</th>
                <th className="text-left px-3 py-2">Difficulty</th>
                <th className="text-left px-3 py-2">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-sage-200 dark:divide-[#2C2C2E]">
              {questions.map((q) => (
                <tr key={q.id} data-testid="question-row">
                  <td className="px-3 py-2 font-mono font-semibold text-sage-800 dark:text-white">Q{q.question_number ?? q.question_id}</td>
                  <td className="px-3 py-2 text-right font-mono text-sage-800 dark:text-white">{formatPct(q.average_percentage)}</td>
                  <td className="px-3 py-2 text-right font-mono text-sage-500">{fmt(q.maximum_marks)}</td>
                  <td className="px-3 py-2 text-right font-mono text-sage-500">{q.response_count} / {q.submission_count}</td>
                  <td className="px-3 py-2 text-right font-mono text-sage-500">{formatGap(q.performance_gap)}</td>
                  <td className="px-3 py-2 text-sage-500 capitalize">{q.difficulty_level ?? '—'}</td>
                  <td className="px-3 py-2"><PerformanceGapBadge status={q.performance_status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <ul className="space-y-2">
          {questions.map((q) => <QuestionPerformanceCard key={q.id} q={q} expected={expected} />)}
        </ul>
      )}
    </div>
  );
};
