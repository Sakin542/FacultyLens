import React from 'react';
import { CheckCircle2, AlertTriangle } from 'lucide-react';
import type { ExamPaper } from '@/types/exam';
import { BLOOM_LEVELS, DIFFICULTY_LEVELS } from '@/types/exam';

/**
 * Marks distribution of the paper against its targets. One hue for the actual share; the target is a dark tick,
 * labelled in text, so the comparison never relies on color alone.
 */
export const CoveragePanel: React.FC<{ paper: ExamPaper }> = ({ paper }) => {
  const c = paper.coverage;
  if (!c) return null;
  const targetMarks = c.target_total_marks;

  const Row: React.FC<{ label: string; percent: number; marks: number; target?: number }> = ({ label, percent, marks, target }) => (
    <li className="text-xs">
      <div className="flex items-baseline justify-between gap-2">
        <span className="capitalize text-sage-700">{label}</span>
        <span className="tabular-nums text-sage-500">{marks} marks · {percent}%{target !== undefined ? ` (target ${target}%)` : ''}</span>
      </div>
      <div className="relative mt-1 h-2 rounded-full bg-sage-100" aria-hidden="true">
        <div className="h-2 rounded-full bg-sage-600" style={{ width: `${Math.min(100, percent)}%` }} />
        {target !== undefined && <div className="absolute -top-0.5 h-3 w-0.5 rounded bg-sage-800" style={{ left: `calc(${Math.min(100, target)}% - 1px)` }} />}
      </div>
    </li>
  );

  return (
    <section className="rounded-xl border border-sage-200 bg-white p-4 shadow-subtle" aria-label="Coverage" data-testid="paper-coverage">
      <h3 className="text-sm font-semibold text-sage-800">Coverage</h3>
      <dl className="mt-2 grid grid-cols-2 gap-2 text-xs">
        <div className="rounded-lg bg-sage-100 p-2"><dt className="text-sage-500">Questions</dt><dd className="text-lg font-semibold text-sage-800 tabular-nums">{c.question_count}{c.target_question_count ? <span className="text-xs font-normal text-sage-500"> / {c.target_question_count}</span> : null}</dd></div>
        <div className="rounded-lg bg-sage-100 p-2"><dt className="text-sage-500">Total marks</dt><dd className="text-lg font-semibold text-sage-800 tabular-nums">{c.total_marks}{targetMarks ? <span className="text-xs font-normal text-sage-500"> / {targetMarks}</span> : null}</dd></div>
      </dl>

      <p className="mt-3 text-[11px] uppercase tracking-[0.12em] text-sage-500">Bloom level</p>
      <ul className="mt-1.5 space-y-2">
        {BLOOM_LEVELS.filter((l) => (c.bloom[l]?.count ?? 0) > 0 || c.target_bloom_percent[l] !== undefined).map((l) => (
          <Row key={l} label={l} percent={c.bloom[l]?.percent ?? 0} marks={c.bloom[l]?.marks ?? 0} target={c.target_bloom_percent[l]} />
        ))}
        {c.bloom.Unclassified && <Row label="Unclassified" percent={c.bloom.Unclassified.percent} marks={c.bloom.Unclassified.marks} />}
      </ul>

      <p className="mt-3 text-[11px] uppercase tracking-[0.12em] text-sage-500">Difficulty</p>
      <ul className="mt-1.5 space-y-2">
        {DIFFICULTY_LEVELS.filter((l) => (c.difficulty[l]?.count ?? 0) > 0).map((l) => <Row key={l} label={l} percent={c.difficulty[l].percent} marks={c.difficulty[l].marks} />)}
        {c.difficulty.Unclassified && <Row label="Unclassified" percent={c.difficulty.Unclassified.percent} marks={c.difficulty.Unclassified.marks} />}
      </ul>

      {c.learning_outcomes.length > 0 && (
        <>
          <p className="mt-3 text-[11px] uppercase tracking-[0.12em] text-sage-500">Course outcomes</p>
          <ul className="mt-1.5 space-y-2">{c.learning_outcomes.map((o) => <Row key={o.code ?? 'x'} label={o.code ?? '—'} percent={o.percent} marks={o.marks} />)}</ul>
        </>
      )}

      <p className={`mt-3 flex items-center gap-1.5 text-xs ${c.answer_key_complete ? 'text-[#166534]' : 'text-[#92400E]'}`}>
        {c.answer_key_complete ? <CheckCircle2 className="w-3.5 h-3.5" aria-hidden="true" /> : <AlertTriangle className="w-3.5 h-3.5" aria-hidden="true" />}
        {c.answer_key_complete ? 'Answer key complete' : 'Answer key incomplete or needs review'}
      </p>
    </section>
  );
};
