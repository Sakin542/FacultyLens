import React, { useEffect, useState } from 'react';
import { AlertTriangle, CheckCircle2, Info } from 'lucide-react';
import { examService } from '@/services/examService';
import type { ItemAnalysis } from '@/types/exam';
import { cn } from '@/utils/cn';

const FLAG_LABEL: Record<string, string> = {
  too_easy: 'Too easy',
  too_hard: 'Too hard',
  poor_discrimination: 'Poor discrimination',
  negative_discrimination: 'Negative discrimination',
  no_variance: 'Everyone scored the same',
};

const BAND_TEXT: Record<string, string> = { excellent: 'text-[#166534]', good: 'text-[#166534]', marginal: 'text-[#92400E]', poor: 'text-[#991B1B]' };

/**
 * Difficulty (p) and discrimination (D) per question. A horizontal strip shows p (0 → 1) with the
 * upper-group and lower-group marks; the gap between them is D. Values are always printed, never color alone.
 */
export const ItemAnalysisView: React.FC<{ assessmentId: string }> = ({ assessmentId }) => {
  const [data, setData] = useState<ItemAnalysis | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    examService.itemAnalysis(assessmentId).then(setData).catch((e) => setError(e instanceof Error ? e.message : 'Could not load item analysis.'));
  }, [assessmentId]);

  if (error) return <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] p-3 text-sm text-[#991B1B]">{error}</p>;
  if (!data) return <p className="py-8 text-center text-sm text-sage-500">Calculating…</p>;
  if (!data.sufficient) {
    return (
      <div className="rounded-xl border border-sage-200 bg-white p-6 text-sm text-sage-600" data-testid="item-analysis-insufficient">
        <p className="font-medium text-sage-800">Not enough graded work yet</p>
        <p className="mt-1">{data.message} Grade more submissions and come back.{data.submissions_excluded_ungraded > 0 && ` ${data.submissions_excluded_ungraded} submission(s) are still being graded.`}</p>
      </div>
    );
  }

  const s = data.summary!;
  return (
    <div className="space-y-4" data-testid="item-analysis">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {[
          ['Submissions analysed', `${data.submissions_analyzed}`, data.submissions_excluded_ungraded ? `${data.submissions_excluded_ungraded} still ungraded (excluded)` : 'All graded'],
          ['Class average', `${s.mean_percent ?? '—'}%`, `${s.mean_total} of ${s.max_total} marks · SD ${s.std_dev_total}`],
          ["Reliability (Cronbach's α)", s.cronbach_alpha === null ? '—' : s.cronbach_alpha.toFixed(2), s.reliability_band ? `${s.reliability_band} (≥ 0.70 is acceptable)` : 'Needs at least 2 questions'],
          ['Questions to review', `${s.flagged_items} / ${s.questions}`, `mean p ${s.mean_difficulty_index} · mean D ${s.mean_discrimination_index}`],
        ].map(([label, value, hint]) => (
          <div key={label} className="rounded-xl border border-sage-200 bg-white p-4 shadow-subtle">
            <p className="text-[11px] uppercase tracking-[0.14em] text-sage-500">{label}</p>
            <p className="mt-1.5 text-2xl font-semibold tabular-nums text-sage-800">{value}</p>
            <p className="mt-0.5 text-xs capitalize text-sage-500">{hint}</p>
          </div>
        ))}
      </div>

      <p className="flex items-start gap-1.5 text-xs text-sage-500"><Info className="mt-0.5 w-3.5 h-3.5 shrink-0" aria-hidden="true" />
        Difficulty p is the share of marks students earned (higher = easier). Discrimination D compares the top 27% and bottom 27% of students by total score: 0.40 or more is excellent, below 0.20 means the question does not separate stronger from weaker students, and a negative value usually signals a key or wording problem.</p>

      <ul className="space-y-3">
        {data.items.map((it) => (
          <li key={it.question_id} className="rounded-xl border border-sage-200 bg-white p-4" data-testid={`item-${it.question_id}`}>
            <div className="flex flex-wrap items-start justify-between gap-2">
              <p className="min-w-0 flex-1 text-sm text-sage-800"><span className="font-semibold">Q{it.question_number}.</span> {it.question_excerpt}</p>
              <span className="text-xs text-sage-500">{it.mean_marks} / {it.max_marks} avg{it.cognitive_level ? ` · ${it.cognitive_level}` : ''}</span>
            </div>

            <div className="mt-3 grid gap-4 sm:grid-cols-[1fr,auto]">
              <div>
                <div className="relative h-6" aria-hidden="true">
                  <div className="absolute inset-x-0 top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-sage-100" />
                  <div className="absolute top-1/2 h-1.5 -translate-y-1/2 rounded-full bg-sage-300" style={{ left: `${Math.min(it.lower_p, it.upper_p) * 100}%`, width: `${Math.abs(it.upper_p - it.lower_p) * 100}%` }} />
                  <span className="absolute top-1/2 h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-sage-500" style={{ left: `${it.lower_p * 100}%` }} title="Lower group" />
                  <span className="absolute top-1/2 h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white bg-sage-800" style={{ left: `${it.upper_p * 100}%` }} title="Upper group" />
                  <span className="absolute top-0 h-6 w-0.5 -translate-x-1/2 bg-sage-700" style={{ left: `${it.difficulty_index * 100}%` }} title="Whole class" />
                </div>
                <div className="flex justify-between text-[10px] text-sage-400"><span>0 (no marks)</span><span>1 (full marks)</span></div>
                <p className="mt-1 text-xs text-sage-600">Lower group {it.lower_p} · <span className="font-medium">class {it.difficulty_index}</span> · upper group {it.upper_p}</p>
              </div>
              <dl className="grid grid-cols-3 gap-3 text-center text-xs sm:w-72">
                <div><dt className="text-sage-500">Difficulty p</dt><dd className="text-base font-semibold tabular-nums text-sage-800">{it.difficulty_index.toFixed(2)}</dd><dd className="capitalize text-sage-500">{it.difficulty_band}</dd></div>
                <div><dt className="text-sage-500">Discrimination D</dt><dd className={cn('text-base font-semibold tabular-nums', BAND_TEXT[it.discrimination_band])}>{it.discrimination_index.toFixed(2)}</dd><dd className="capitalize text-sage-500">{it.discrimination_band}</dd></div>
                <div><dt className="text-sage-500">Item–rest r</dt><dd className="text-base font-semibold tabular-nums text-sage-800">{it.item_rest_correlation === null ? '—' : it.item_rest_correlation.toFixed(2)}</dd></div>
              </dl>
            </div>

            <div className={cn('mt-3 flex items-start gap-1.5 rounded-lg px-3 py-2 text-xs', it.flags.length ? 'bg-[#FFFBEB] text-[#92400E]' : 'bg-[#F0FDF4] text-[#166534]')}>
              {it.flags.length ? <AlertTriangle className="mt-0.5 w-3.5 h-3.5 shrink-0" aria-hidden="true" /> : <CheckCircle2 className="mt-0.5 w-3.5 h-3.5 shrink-0" aria-hidden="true" />}
              <span>{it.flags.length > 0 && <strong>{it.flags.map((f) => FLAG_LABEL[f] ?? f).join(' · ')}: </strong>}{it.recommendation}</span>
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
};
