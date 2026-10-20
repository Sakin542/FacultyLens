import React, { useEffect, useState } from 'react';
import { ScanSearch, Check, X, Info } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { examService } from '@/services/examService';
import type { SimilarityMatch, SimilarityRun } from '@/types/exam';

const pct = (n: number | null) => (n === null ? '—' : `${Math.round(n * 100)}%`);

/** Run the answer plagiarism check and review flagged pairs side by side. Signals for faculty judgement only. */
export const PlagiarismView: React.FC<{ assessmentId: string }> = ({ assessmentId }) => {
  const [run, setRun] = useState<SimilarityRun | null | undefined>(undefined);
  const [threshold, setThreshold] = useState(0.6);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<'all' | SimilarityMatch['review_status']>('all');

  useEffect(() => {
    examService.latestPlagiarism(assessmentId).then(setRun).catch((e) => { setRun(null); setError(e instanceof Error ? e.message : 'Could not load the last check.'); });
  }, [assessmentId]);

  const start = async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await examService.runPlagiarism(assessmentId, threshold);
      setRun(res.data);
      setMessage(res.message);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'The check failed.');
    } finally {
      setBusy(false);
    }
  };

  const review = async (m: SimilarityMatch, status: SimilarityMatch['review_status']) => {
    const updated = await examService.reviewMatch(m.id, status);
    setRun((r) => (r ? { ...r, matches: r.matches.map((x) => (x.id === m.id ? updated : x)) } : r));
  };

  const [visible, setVisible] = useState(15);
  const filtered = (run?.matches ?? []).filter((m) => filter === 'all' || m.review_status === filter);
  const matches = filtered.slice(0, visible);

  return (
    <div className="space-y-4" data-testid="plagiarism-view">
      <section className="flex flex-wrap items-end gap-3 rounded-xl border border-sage-200 bg-white p-4 shadow-subtle">
        <div className="min-w-[220px] flex-1">
          <p className="text-sm font-medium text-sage-800">Check answers for copying</p>
          <p className="text-xs text-sage-500">Compares every pair of answers to the same question. Text copied from the question itself is ignored, and answers under 8 words are skipped.</p>
        </div>
        <label className="text-xs text-sage-700">Sensitivity: flag pairs at {Math.round(threshold * 100)}% similar or more
          <input type="range" min={0.4} max={0.9} step={0.05} value={threshold} onChange={(e) => setThreshold(Number(e.target.value))} className="mt-1 block w-56 accent-sage-700" aria-label="Similarity threshold" />
        </label>
        <Button onClick={start} isLoading={busy} leftIcon={<ScanSearch className="w-4 h-4" />} data-testid="run-plagiarism">{run ? 'Run again' : 'Run check'}</Button>
      </section>

      {error && <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] px-3 py-2 text-sm text-[#991B1B]">{error}</p>}
      {message && <p role="status" className="rounded-lg border border-[#BBF7D0] bg-[#F0FDF4] px-3 py-2 text-sm text-[#166534]">{message}</p>}

      {run === undefined && <p className="py-6 text-center text-sm text-sage-500">Loading…</p>}
      {run === null && !error && <p className="rounded-xl border border-dashed border-sage-300 p-8 text-center text-sm text-sage-500">No check has been run for this assessment yet.</p>}

      {run && (
        <>
          <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-sage-500">
            <span>Last run {run.created_at ? new Date(run.created_at).toLocaleString() : ''}{run.run_by ? ` by ${run.run_by.name}` : ''} · {run.answers_compared} answers · {run.pairs_compared} pairs · method: {run.method === 'lexical+semantic' ? 'word overlap + meaning (AI)' : 'word overlap'}</span>
            <select aria-label="Filter by review status" value={filter} onChange={(e) => setFilter(e.target.value as typeof filter)} className="rounded-md border border-sage-200 bg-white px-2 py-1 text-xs text-sage-800">
              <option value="all">All flagged ({run.matches.length})</option>
              <option value="pending">Not reviewed</option>
              <option value="confirmed">Confirmed</option>
              <option value="dismissed">Dismissed</option>
            </select>
          </div>
          {run.summary?.semantic_unavailable && <p className="flex items-center gap-1.5 text-xs text-sage-500"><Info className="w-3.5 h-3.5" aria-hidden="true" />The AI service was unavailable, so only word overlap was used.</p>}

          {matches.length === 0 ? (
            <p className="rounded-xl border border-sage-200 bg-white p-6 text-center text-sm text-sage-500">{run.matches.length === 0 ? 'No suspiciously similar answers were found.' : 'Nothing in this filter.'}</p>
          ) : (
            <ul className="space-y-3">
              {matches.map((m) => (
                <li key={m.id} className="rounded-xl border border-sage-200 bg-white p-4" data-testid={`match-${m.id}`}>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm text-sage-800"><span className="font-semibold">Q{m.question?.question_number}.</span> <span className="text-sage-500">{m.question?.excerpt}</span></p>
                    <span className="flex items-center gap-1.5 text-xs">
                      <span className="font-semibold tabular-nums text-sage-800">{pct(m.combined_score)} similar</span>
                      <span className="text-sage-500">(words {pct(m.lexical_score)}{m.semantic_score !== null ? `, meaning ${pct(m.semantic_score)}` : ''})</span>
                      <Badge variant={m.review_status === 'confirmed' ? 'Critical' : m.review_status === 'dismissed' ? 'neutral' : 'Pending'}>{m.review_status === 'pending' ? 'Not reviewed' : m.review_status === 'confirmed' ? 'Confirmed' : 'Dismissed'}</Badge>
                    </span>
                  </div>
                  <div className="mt-3 grid gap-3 md:grid-cols-2">
                    {[m.a, m.b].map((side) => (
                      <div key={side.answer_id} className="rounded-lg bg-sage-100 p-3">
                        <p className="text-xs font-medium text-sage-800">{side.student?.name ?? 'Unknown student'} <span className="font-mono text-sage-500">{side.student?.student_identifier}</span>{side.awarded_marks !== null && <span className="text-sage-500"> · {side.awarded_marks} marks</span>}</p>
                        <p className="mt-1 max-h-40 overflow-y-auto whitespace-pre-line text-xs text-sage-700">{side.answer_text}</p>
                      </div>
                    ))}
                  </div>
                  {m.shared_excerpt && <p className="mt-2 text-xs text-sage-600"><span className="font-medium">Longest shared passage:</span> “{m.shared_excerpt}”</p>}
                  <div className="mt-3 flex flex-wrap gap-1.5">
                    <Button size="sm" variant={m.review_status === 'confirmed' ? 'danger' : 'outline'} leftIcon={<Check className="w-3.5 h-3.5" />} onClick={() => review(m, m.review_status === 'confirmed' ? 'pending' : 'confirmed')}>{m.review_status === 'confirmed' ? 'Confirmed (undo)' : 'Confirm copying'}</Button>
                    <Button size="sm" variant="ghost" leftIcon={<X className="w-3.5 h-3.5" />} onClick={() => review(m, m.review_status === 'dismissed' ? 'pending' : 'dismissed')}>{m.review_status === 'dismissed' ? 'Dismissed (undo)' : 'Dismiss'}</Button>
                  </div>
                </li>
              ))}
            </ul>
          )}
          {filtered.length > visible && (
            <div className="text-center">
              <Button variant="outline" size="sm" onClick={() => setVisible(visible + 15)}>Show more ({filtered.length - visible} remaining, most similar first)</Button>
            </div>
          )}
        </>
      )}
    </div>
  );
};
