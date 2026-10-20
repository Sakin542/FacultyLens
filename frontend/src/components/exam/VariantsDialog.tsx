import React, { useEffect, useState } from 'react';
import { X, Shuffle, AlertTriangle } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { examService } from '@/services/examService';
import type { ExamPaperItem, QuestionVariant, VariantResult } from '@/types/exam';
import { cn } from '@/utils/cn';

interface Props {
  paperId: number;
  item: ExamPaperItem;
  onClose: () => void;
  onUse: (variant: QuestionVariant, engine: string) => Promise<void>;
}

/** Preview variants of one question (changed numbers and/or wording) and swap one into the paper. */
export const VariantsDialog: React.FC<Props> = ({ paperId, item, onClose, onUse }) => {
  const [mode, setMode] = useState<'numbers' | 'wording' | 'both'>('both');
  const [result, setResult] = useState<VariantResult | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [using, setUsing] = useState<number | null>(null);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  const generate = async () => {
    setLoading(true);
    setError(null);
    try { setResult(await examService.variants(paperId, item.id, mode, 3)); } catch (e) { setError(e instanceof Error ? e.message : 'Variants could not be generated.'); } finally { setLoading(false); }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-label="Question variants" data-testid="variants-dialog">
      <div className="absolute inset-0 bg-sage-800/50" onClick={onClose} aria-hidden="true" />
      <div className="relative max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white p-5 shadow-elevated">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h3 className="text-base font-semibold text-sage-800">Question variants</h3>
            <p className="text-xs text-sage-500">Different numbers or wording for another exam set, so students cannot copy answers across sets.</p>
          </div>
          <button type="button" onClick={onClose} className="rounded-md p-1 text-sage-500 hover:bg-sage-100" aria-label="Close"><X className="w-4 h-4" /></button>
        </div>

        <p className="mt-3 rounded-lg bg-sage-100 p-3 text-sm text-sage-800">{item.question_text}</p>

        <div className="mt-3 flex flex-wrap items-center gap-2">
          <div role="radiogroup" aria-label="What to change" className="inline-flex rounded-lg border border-sage-200 p-0.5">
            {(['numbers', 'wording', 'both'] as const).map((m) => (
              <button key={m} type="button" role="radio" aria-checked={mode === m} onClick={() => setMode(m)}
                className={cn('rounded-md px-3 py-1 text-xs font-medium capitalize', mode === m ? 'bg-sage-700 text-white' : 'text-sage-700 hover:bg-sage-100')}>{m === 'both' ? 'Numbers + wording' : m}</button>
            ))}
          </div>
          <Button size="sm" onClick={generate} isLoading={loading} leftIcon={<Shuffle className="w-3.5 h-3.5" />} data-testid="generate-variants">Generate</Button>
        </div>

        {error && <p role="alert" className="mt-3 rounded-lg border border-[#FECACA] bg-[#FEF2F2] px-3 py-2 text-xs text-[#991B1B]">{error}</p>}
        {result?.message && <p className="mt-3 text-xs text-sage-500">{result.message}</p>}

        <ul className="mt-3 space-y-2">
          {result?.variants.map((v, i) => (
            <li key={i} className="rounded-lg border border-sage-200 p-3" data-testid="variant-option">
              <p className="text-sm text-sage-800">{v.question_text}</p>
              {v.expected_answer && <p className="mt-1 text-xs text-sage-600"><span className="font-medium">Answer key:</span> {v.expected_answer}</p>}
              <p className="mt-1 text-[11px] text-sage-500">
                {v.methods.map((m) => m.replace(/_/g, ' ')).join(' + ') || 'unchanged'}
                {v.number_changes.length > 0 && ` · ${v.number_changes.map((c) => `${c.from}→${c.to}`).join(', ')}`}
                {v.similarity !== null && ` · meaning kept ${Math.round(v.similarity * 100)}%`}
              </p>
              {v.warnings.map((w) => <p key={w} className="mt-1 flex items-center gap-1 text-[11px] text-[#92400E]"><AlertTriangle className="w-3 h-3" aria-hidden="true" />{w}</p>)}
              <Button size="sm" variant="outline" className="mt-2" isLoading={using === i}
                onClick={async () => { setUsing(i); try { await onUse(v, result.engine); onClose(); } finally { setUsing(null); } }}>Use this variant</Button>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
};
