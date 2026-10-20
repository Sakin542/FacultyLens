import React, { useState } from 'react';
import { Copy, MessageSquareText, RotateCcw } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { performanceService, type ClassSummary } from '@/services/performanceService';

/**
 * Plain-language note on how the class did and what it struggled with, built from finalized marks and the rubric
 * evidence recorded by AI grading assistance. Computed on request; it names no student and changes no grade.
 */
export const ClassSummaryCard: React.FC<{ assessmentId: number | string }> = ({ assessmentId }) => {
  const [summary, setSummary] = useState<ClassSummary | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const generate = async () => {
    setBusy(true);
    setError(null);
    try {
      setSummary((await performanceService.classSummary(assessmentId)).data);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not build the class summary.');
    } finally {
      setBusy(false);
    }
  };

  const copy = async () => {
    if (!summary?.note) return;
    try { await navigator.clipboard.writeText(summary.note); setCopied(true); window.setTimeout(() => setCopied(false), 1500); } catch { /* blocked */ }
  };

  return (
    <section className="rounded-xl border border-sage-200 dark:border-sage-800 bg-sage-50/60 dark:bg-sage-900/30 p-4 space-y-3" data-testid="class-summary">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h4 className="flex items-center gap-1.5 text-sm font-semibold text-sage-800 dark:text-sage-100"><MessageSquareText className="w-4 h-4" aria-hidden="true" />Class summary</h4>
          <p className="text-xs text-sage-500">A plain-language note on what the class struggled with, from finalized marks and rubric evidence.</p>
        </div>
        <div className="flex gap-2">
          {summary?.status === 'ready' && (
            <button type="button" onClick={() => { void copy(); }} className="inline-flex items-center gap-1 rounded-md border border-sage-200 px-2 py-1 text-xs text-sage-700 hover:bg-sage-100">
              <Copy className="w-3.5 h-3.5" aria-hidden="true" />{copied ? 'Copied' : 'Copy note'}
            </button>
          )}
          <Button size="sm" variant={summary ? 'ghost' : 'primary'} isLoading={busy} onClick={() => { void generate(); }}
            leftIcon={summary ? <RotateCcw className="w-3.5 h-3.5" /> : <MessageSquareText className="w-3.5 h-3.5" />}>
            {summary ? 'Refresh' : 'Write class summary'}
          </Button>
        </div>
      </div>

      {error && <p role="alert" className="text-xs text-red-700 dark:text-red-300">{error}</p>}
      {summary?.status === 'no_data' && <p className="text-xs text-sage-600">{summary.message}</p>}
      {summary?.status === 'ready' && (
        <>
          <div className="space-y-2 text-sm leading-relaxed text-sage-800 dark:text-sage-100" data-testid="class-summary-note">
            {summary.paragraphs.map((p) => <p key={p}>{p}</p>)}
          </div>
          {(summary.struggles ?? []).length > 0 && (
            <details className="rounded-lg border border-sage-200 dark:border-sage-800 bg-white dark:bg-sage-950 px-3 py-2 text-xs">
              <summary className="cursor-pointer font-medium text-sage-800 dark:text-sage-100">Rubric points the class missed</summary>
              <table className="mt-2 w-full text-left">
                <thead className="text-sage-500"><tr><th className="py-1 font-medium">Question · criterion</th><th className="py-1 font-medium">Missing / weak</th><th className="py-1 font-medium">Most often left out</th></tr></thead>
                <tbody className="divide-y divide-sage-100 dark:divide-sage-800">
                  {summary.struggles!.map((s) => (
                    <tr key={`${s.question_id}-${s.criterion}`}>
                      <td className="py-1.5 pr-2 text-sage-800 dark:text-sage-100">{s.question_number} · {s.criterion}</td>
                      <td className="py-1.5 pr-2 font-mono">{s.weak}/{s.responses}</td>
                      <td className="py-1.5 text-sage-600 dark:text-sage-300">{s.top_missing.map((m) => `${m.text} (${m.count})`).join('; ') || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </details>
          )}
          {summary.method && <p className="text-[11px] text-sage-500">{summary.method}</p>}
        </>
      )}
    </section>
  );
};
