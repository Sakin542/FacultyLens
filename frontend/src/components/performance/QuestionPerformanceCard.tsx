import React, { useState } from 'react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { QuestionPerformance } from '@/types/performance';
import { PerformanceGapBadge, formatGap, formatPct } from './PerformanceGapBadge';
import { PerformanceBar } from './PerformanceBar';

const fmt = (v: number | null | undefined): string => (v === null || v === undefined ? '—' : Number.isInteger(v) ? String(v) : String(Number(v.toFixed(2))));

/** One question's detail: statistics, difficulty/cognitive context, STEP 27/28 context, review signals. */
export const QuestionPerformanceCard: React.FC<{ q: QuestionPerformance; expected: number; defaultOpen?: boolean }> = ({ q, expected, defaultOpen = false }) => {
  const [open, setOpen] = useState(defaultOpen);
  return (
    <li className="rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E]" data-testid="question-performance-card">
      <button type="button" onClick={() => setOpen((v) => !v)} className="w-full p-3 text-left space-y-2" aria-expanded={open}>
        <div className="flex items-center justify-between gap-2 flex-wrap">
          <span className="text-xs font-bold font-mono text-[#111111] dark:text-white">Q{q.question_number ?? q.question_id}</span>
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-xs font-mono text-[#262626] dark:text-[#E5E5E5]" data-testid="question-average">{formatPct(q.average_percentage)}</span>
            <span className="text-[10px] text-[#737373]">{q.response_count} / {q.submission_count} finalized</span>
            <PerformanceGapBadge status={q.performance_status} />
            {open ? <ChevronUp className="w-4 h-4 text-[#737373]" /> : <ChevronDown className="w-4 h-4 text-[#737373]" />}
          </div>
        </div>
        <PerformanceBar value={q.average_percentage} status={q.performance_status} expected={expected} />
        {q.question_text_excerpt && <p className="text-[11px] text-[#737373] truncate">{q.question_text_excerpt}</p>}
      </button>
      {open && (
        <div className="px-3 pb-3 pt-2 border-t border-[#E5E5E5] dark:border-[#2C2C2E] space-y-3 text-xs" data-testid="question-performance-details">
          <dl className="grid grid-cols-2 sm:grid-cols-4 gap-2">
            {([
              ['Maximum', fmt(q.maximum_marks)], ['Average', fmt(q.average_marks)], ['Median', fmt(q.median_marks)],
              ['Min / Max awarded', `${fmt(q.minimum_marks)} / ${fmt(q.max_awarded_marks)}`], ['Gap', formatGap(q.performance_gap)],
              ['Difficulty', q.difficulty_level ? q.difficulty_level.charAt(0).toUpperCase() + q.difficulty_level.slice(1) : '—'],
              ['Cognitive level', q.cognitive_level ?? '—'], ['Topics', q.topics.length ? q.topics.join(', ') : '—'],
            ] as Array<[string, string]>).map(([k, v]) => (
              <div key={k} className="p-2 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E]">
                <dt className="text-[10px] uppercase tracking-wider text-[#737373]">{k}</dt>
                <dd className="font-mono text-[#111111] dark:text-white">{v}</dd>
              </div>
            ))}
          </dl>
          {(q.ai_suggested_average_percentage !== null || q.rubric_alignment_average !== null) && (
            <div className="grid grid-cols-2 gap-2" data-testid="question-context">
              {q.ai_suggested_average_percentage !== null && (
                <div className="p-2 rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C]">
                  <span className="block text-[10px] uppercase tracking-wider text-[#737373]">AI suggested (supporting only)</span>
                  <span className="font-mono">{formatPct(q.ai_suggested_average_percentage)}</span>
                </div>
              )}
              {q.rubric_alignment_average !== null && (
                <div className="p-2 rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C]">
                  <span className="block text-[10px] uppercase tracking-wider text-[#737373]">Rubric alignment (avg)</span>
                  <span className="font-mono">{formatPct(q.rubric_alignment_average)}</span>
                </div>
              )}
            </div>
          )}
          {q.review_signals.length > 0 && (
            <div>
              <span className="block text-[10px] uppercase tracking-wider text-[#737373] mb-1">Review signals</span>
              <ul className="list-disc pl-4 space-y-0.5 text-[#262626] dark:text-[#E5E5E5]" data-testid="review-signals">
                {q.review_signals.map((s, i) => <li key={i}>{s}</li>)}
              </ul>
            </div>
          )}
        </div>
      )}
    </li>
  );
};
