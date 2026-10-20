import React, { useEffect, useRef, useState } from 'react';
import { MessageSquarePlus, BookmarkPlus, Search } from 'lucide-react';
import { examService } from '@/services/examService';
import type { FeedbackComment } from '@/types/exam';
import { cn } from '@/utils/cn';

interface Props {
  /** Current feedback text (offered for "Save as comment"). */
  current: string;
  onInsert: (text: string) => void;
  disabled?: boolean;
}

const TONE_DOT: Record<string, string> = { positive: 'bg-[#16A34A]', improvement: 'bg-[#D97706]', neutral: 'bg-sage-400' };

/** Insert a saved comment from the personal comment bank into the feedback box, or save the current text to it. */
export const CommentBankPicker: React.FC<Props> = ({ current, onInsert, disabled }) => {
  const [open, setOpen] = useState(false);
  const [comments, setComments] = useState<FeedbackComment[] | null>(null);
  const [search, setSearch] = useState('');
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    examService.comments({ search }).then(setComments).catch(() => setComments([]));
  }, [open, search]);

  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false); };
    const esc = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', close);
    window.addEventListener('keydown', esc);
    return () => { document.removeEventListener('mousedown', close); window.removeEventListener('keydown', esc); };
  }, [open]);

  const insert = (c: FeedbackComment) => {
    onInsert(c.body);
    setOpen(false);
    void examService.useComment(c.id).catch(() => undefined);
  };

  const saveCurrent = async () => {
    const body = current.trim();
    if (!body) return;
    setSaving(true);
    try {
      await examService.createComment({ title: body.slice(0, 60), body, tone: 'neutral' });
      setSaved(true);
      window.setTimeout(() => setSaved(false), 2000);
    } finally {
      setSaving(false);
    }
  };

  return (
    <div ref={ref} className="relative flex flex-wrap items-center gap-2 text-xs" data-testid="comment-bank-picker">
      <button type="button" disabled={disabled} onClick={() => setOpen(!open)} aria-expanded={open}
        className="inline-flex items-center gap-1 rounded-md border border-sage-200 px-2 py-1 text-sage-700 hover:bg-sage-100 disabled:opacity-50">
        <MessageSquarePlus className="w-3.5 h-3.5" aria-hidden="true" />Insert saved comment
      </button>
      <button type="button" disabled={disabled || saving || !current.trim()} onClick={saveCurrent}
        className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-sage-600 hover:bg-sage-100 disabled:opacity-40">
        <BookmarkPlus className="w-3.5 h-3.5" aria-hidden="true" />{saved ? 'Saved to bank' : 'Save as comment'}
      </button>

      {open && (
        <div className="absolute left-0 top-full z-30 mt-1 w-[min(420px,90vw)] rounded-xl border border-sage-200 bg-white p-2 shadow-elevated" role="dialog" aria-label="Comment bank">
          <label className="relative block">
            <span className="sr-only">Search comments</span>
            <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-sage-400" aria-hidden="true" />
            <input autoFocus value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search your comments" className="w-full rounded-lg border border-sage-200 py-1.5 pl-8 pr-2 text-xs focus:outline-none focus:ring-2 focus:ring-sage-600" />
          </label>
          <ul className="mt-1 max-h-64 overflow-y-auto">
            {comments === null && <li className="p-2 text-sage-500">Loading…</li>}
            {comments?.length === 0 && <li className="p-2 text-sage-500">No saved comments yet. Write feedback and use “Save as comment”, or manage them under Comment Bank.</li>}
            {comments?.map((c) => (
              <li key={c.id}>
                <button type="button" onClick={() => insert(c)} className="w-full rounded-lg p-2 text-left hover:bg-sage-100">
                  <span className="flex items-center gap-1.5 font-medium text-sage-800">
                    <span className={cn('h-1.5 w-1.5 rounded-full', TONE_DOT[c.tone])} aria-hidden="true" />{c.title}
                    <span className="ml-auto text-[10px] font-normal capitalize text-sage-500">{c.tone}{c.usage_count ? ` · used ${c.usage_count}×` : ''}</span>
                  </span>
                  <span className="mt-0.5 block line-clamp-2 text-sage-600">{c.body}</span>
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
};
