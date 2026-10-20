import React, { useState } from 'react';
import { ArrowUp, ArrowDown, Pencil, Trash2, Shuffle, AlertTriangle, Save, X } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import type { ExamPaperItem } from '@/types/exam';

interface Props {
  item: ExamPaperItem;
  number: number;
  locked: boolean;
  isFirst: boolean;
  isLast: boolean;
  onMove: (dir: -1 | 1) => void;
  onSave: (changes: Partial<ExamPaperItem>) => Promise<void>;
  onRemove: () => void;
  onVariants: () => void;
}

const SOURCE_LABEL: Record<string, string> = { bank: 'Question bank', question: 'Course assessment', generated: 'AI draft (approved)', manual: 'Written here' };

export const PaperItemRow: React.FC<Props> = ({ item, number, locked, isFirst, isLast, onMove, onSave, onRemove, onVariants }) => {
  const [editing, setEditing] = useState(false);
  const [text, setText] = useState(item.question_text);
  const [answer, setAnswer] = useState(item.expected_answer ?? '');
  const [marks, setMarks] = useState(String(item.marks));
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true);
    try {
      await onSave({ question_text: text, expected_answer: answer || null, marks: Number(marks), answer_needs_review: false });
      setEditing(false);
    } finally {
      setBusy(false);
    }
  };

  return (
    <li className="rounded-xl border border-sage-200 bg-white p-4" data-testid={`paper-item-${item.id}`}>
      <div className="flex items-start gap-3">
        <span className="mt-0.5 w-7 shrink-0 text-sm font-semibold text-sage-800">{number}.</span>
        <div className="min-w-0 flex-1">
          {editing ? (
            <div className="space-y-2">
              <label className="block text-xs font-medium text-sage-700">Question
                <textarea className="mt-1 w-full rounded-lg border border-sage-200 p-2 text-sm text-sage-800 focus:outline-none focus:ring-2 focus:ring-sage-600" rows={3} value={text} onChange={(e) => setText(e.target.value)} />
              </label>
              <label className="block text-xs font-medium text-sage-700">Expected answer (answer key)
                <textarea className="mt-1 w-full rounded-lg border border-sage-200 p-2 text-sm text-sage-800 focus:outline-none focus:ring-2 focus:ring-sage-600" rows={2} value={answer} onChange={(e) => setAnswer(e.target.value)} />
              </label>
              <label className="block w-28 text-xs font-medium text-sage-700">Marks
                <input type="number" min={0.5} max={100} step={0.5} className="mt-1 w-full rounded-lg border border-sage-200 px-2 py-1 text-sm" value={marks} onChange={(e) => setMarks(e.target.value)} />
              </label>
              <div className="flex gap-2">
                <Button size="sm" onClick={save} isLoading={busy} leftIcon={<Save className="w-3.5 h-3.5" />}>Save</Button>
                <Button size="sm" variant="ghost" onClick={() => { setEditing(false); setText(item.question_text); setAnswer(item.expected_answer ?? ''); setMarks(String(item.marks)); }} leftIcon={<X className="w-3.5 h-3.5" />}>Cancel</Button>
              </div>
            </div>
          ) : (
            <>
              <p className="whitespace-pre-line text-sm text-sage-800">{item.question_text}</p>
              {Array.isArray(item.options) && item.options.length > 0 && (
                <ol className="mt-1 list-[lower-alpha] pl-6 text-sm text-sage-700">{item.options.map((o, i) => <li key={i}>{typeof o === 'string' ? o : JSON.stringify(o)}</li>)}</ol>
              )}
              <div className="mt-2 flex flex-wrap items-center gap-1.5">
                {item.cognitive_level && <Badge variant="neutral">{item.cognitive_level}</Badge>}
                {item.difficulty_level && <Badge variant="outline" className="capitalize">{item.difficulty_level}</Badge>}
                {item.learning_outcome && <Badge variant="outline">{item.learning_outcome.code}</Badge>}
                <span className="text-[11px] text-sage-500">{SOURCE_LABEL[item.source_type] ?? item.source_type}</span>
                {item.variant_meta?.number_changes && item.variant_meta.number_changes.length > 0 && (
                  <span className="text-[11px] text-sage-500">· variant: {item.variant_meta.number_changes.map((c) => `${c.from}→${c.to}`).join(', ')}</span>
                )}
              </div>
              {item.answer_needs_review ? (
                <p className="mt-1.5 inline-flex items-center gap-1 text-xs text-[#92400E]"><AlertTriangle className="w-3.5 h-3.5" aria-hidden="true" />Answer key needs recomputing for the changed values.</p>
              ) : !item.expected_answer ? (
                <p className="mt-1.5 text-xs text-sage-500">No expected answer yet.</p>
              ) : null}
            </>
          )}
        </div>
        <span className="shrink-0 text-sm font-semibold text-sage-800 tabular-nums">[{item.marks}]</span>
      </div>

      {!locked && !editing && (
        <div className="mt-3 flex flex-wrap items-center gap-1 border-t border-sage-100 pt-2">
          <button type="button" disabled={isFirst} onClick={() => onMove(-1)} className="rounded-md p-1.5 text-sage-600 hover:bg-sage-100 disabled:opacity-30" aria-label={`Move question ${number} up`}><ArrowUp className="w-3.5 h-3.5" /></button>
          <button type="button" disabled={isLast} onClick={() => onMove(1)} className="rounded-md p-1.5 text-sage-600 hover:bg-sage-100 disabled:opacity-30" aria-label={`Move question ${number} down`}><ArrowDown className="w-3.5 h-3.5" /></button>
          <button type="button" onClick={() => setEditing(true)} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-sage-700 hover:bg-sage-100"><Pencil className="w-3.5 h-3.5" aria-hidden="true" />Edit</button>
          <button type="button" onClick={onVariants} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-sage-700 hover:bg-sage-100" data-testid={`variants-${item.id}`}><Shuffle className="w-3.5 h-3.5" aria-hidden="true" />Variants</button>
          <button type="button" onClick={onRemove} className="ml-auto inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-[#991B1B] hover:bg-[#FEF2F2]"><Trash2 className="w-3.5 h-3.5" aria-hidden="true" />Remove</button>
        </div>
      )}
    </li>
  );
};
