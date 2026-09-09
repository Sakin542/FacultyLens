import React, { useState } from 'react';
import { Check, Sparkles, X } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { QuestionCoMappingReview } from '@/types/coPo';
import { MappingStatusBadge } from './MappingStatusBadge';

interface QuestionCoMappingReviewProps {
  questions: QuestionCoMappingReview[];
  canEdit: boolean;
  onConfirm: (questionId: number, learningOutcomeId: number) => Promise<void>;
  onReject: (questionId: number, learningOutcomeId: number) => Promise<void>;
}

/**
 * Faculty review of question -> CO mappings. AI suggestions (STEP 11 similarity) are clearly labelled
 * and stay "Pending Faculty Review" until confirmed; only confirmed mappings are official.
 */
export const QuestionCoMappingReviewList: React.FC<QuestionCoMappingReviewProps> = ({ questions, canEdit, onConfirm, onReject }) => {
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<'all' | 'unmapped' | 'pending'>('all');

  const run = async (key: string, fn: () => Promise<void>) => {
    try { setBusy(key); setError(null); await fn(); }
    catch (err) { setError(err instanceof Error ? err.message : 'The mapping decision could not be saved.'); }
    finally { setBusy(null); }
  };

  const visible = questions.filter((q) =>
    filter === 'all' ? true : filter === 'unmapped' ? !q.is_mapped : q.ai_suggestions.some((s) => s.status === 'PENDING'));
  const pendingCount = questions.reduce((n, q) => n + q.ai_suggestions.filter((s) => s.status === 'PENDING').length, 0);

  return (
    <div className="space-y-2" data-testid="question-co-review">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h4 className="text-[10px] uppercase tracking-wider font-semibold text-[#737373]">Question → CO Mapping Review</h4>
        <div className="flex items-center gap-1 text-[11px]">
          {(['all', 'unmapped', 'pending'] as const).map((f) => (
            <button key={f} type="button" onClick={() => setFilter(f)} className={`px-2 py-1 rounded-md ${filter === f ? 'bg-[#111111] text-white dark:bg-white dark:text-[#111111]' : 'text-[#737373]'}`} data-testid={`filter-${f}`}>
              {f === 'all' ? `All (${questions.length})` : f === 'unmapped' ? `Unmapped (${questions.filter((q) => !q.is_mapped).length})` : `AI pending (${pendingCount})`}
            </button>
          ))}
        </div>
      </div>
      {error && <p className="text-xs text-red-600" role="alert">{error}</p>}
      {visible.length === 0 ? (
        <p className="text-xs text-[#737373] italic">No questions in this view.</p>
      ) : (
        <ul className="space-y-2">
          {visible.map((q) => (
            <li key={q.question_id} className="p-3 rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] space-y-2" data-testid="question-co-row">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <p className="text-xs font-semibold text-[#111111] dark:text-white">Q{q.question_number ?? q.question_id} <span className="text-[#737373] font-normal">· {q.assessment_title} · {q.marks} marks{q.cognitive_level ? ` · ${q.cognitive_level}` : ''}</span></p>
                  <p className="text-[11px] text-[#737373] truncate">{q.question_text_excerpt}</p>
                </div>
                <div className="flex items-center gap-1 shrink-0 flex-wrap justify-end">
                  {q.confirmed.length === 0 ? (
                    <span className="text-[10px] px-2 py-0.5 rounded bg-red-50 dark:bg-red-950/30 text-red-700 dark:text-red-300" data-testid="co-missing">CO mapping missing</span>
                  ) : q.confirmed.map((c) => (
                    <span key={c.learning_outcome_id} className="text-[10px] px-2 py-0.5 rounded bg-[#F7F7F5] dark:bg-[#2C2C2E] font-mono text-[#111111] dark:text-white" data-testid="co-confirmed">{c.code} <span className="text-[#737373]">· {c.source === 'FACULTY' ? 'Faculty' : c.source.toLowerCase()}</span></span>
                  ))}
                </div>
              </div>
              {q.ai_suggestions.length > 0 && (
                <ul className="space-y-1" data-testid="ai-suggestions">
                  {q.ai_suggestions.map((s) => (
                    <li key={s.learning_outcome_id} className="flex flex-wrap items-center justify-between gap-2 text-xs p-2 rounded-md bg-[#F7F7F5] dark:bg-[#2C2C2E]">
                      <span className="flex items-center gap-2">
                        <Sparkles className="w-3 h-3 text-amber-500" />
                        <span className="text-[#737373]">AI suggested</span>
                        <span className="font-mono font-semibold text-[#111111] dark:text-white">{s.code}</span>
                        <span className="text-[10px] text-[#737373]">similarity {Number(s.similarity_score).toFixed(2)} · {s.alignment.replace(/_/g, ' ').toLowerCase()}</span>
                        <MappingStatusBadge kind="question" status={s.status} />
                      </span>
                      {canEdit && s.status !== 'CONFIRMED' && (
                        <span className="flex items-center gap-1">
                          <Button variant="primary" size="sm" leftIcon={<Check className="w-3 h-3" />} onClick={() => run(`c${q.question_id}-${s.learning_outcome_id}`, () => onConfirm(q.question_id, s.learning_outcome_id))} isLoading={busy === `c${q.question_id}-${s.learning_outcome_id}`} data-testid="confirm-suggestion">Confirm</Button>
                          {s.status !== 'REJECTED' && (
                            <Button variant="ghost" size="sm" leftIcon={<X className="w-3 h-3" />} onClick={() => run(`r${q.question_id}-${s.learning_outcome_id}`, () => onReject(q.question_id, s.learning_outcome_id))} isLoading={busy === `r${q.question_id}-${s.learning_outcome_id}`} data-testid="reject-suggestion">Reject</Button>
                          )}
                        </span>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </li>
          ))}
        </ul>
      )}
      <p className="text-[11px] text-[#737373]">AI similarity is a suggestion only. Faculty confirmation makes a mapping official; the question's own learning outcome (set in Assessment Details) is treated as faculty-confirmed.</p>
    </div>
  );
};
