import React, { useEffect, useState } from 'react';
import { MessageSquareText, Plus, Pencil, Trash2, Save, X, Search } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { examService } from '@/services/examService';
import { courseService } from '@/services/courseService';
import type { FeedbackComment, FeedbackTone } from '@/types/exam';
import { cn } from '@/utils/cn';

interface Draft { title: string; body: string; tone: FeedbackTone; criterion: string; course_id: string }
const EMPTY: Draft = { title: '', body: '', tone: 'neutral', criterion: '', course_id: '' };
const TONES: { value: FeedbackTone; label: string; dot: string }[] = [
  { value: 'positive', label: 'Positive', dot: 'bg-[#16A34A]' },
  { value: 'improvement', label: 'Needs improvement', dot: 'bg-[#D97706]' },
  { value: 'neutral', label: 'Neutral', dot: 'bg-sage-400' },
];
const field = 'w-full rounded-lg border border-sage-200 bg-white px-3 py-2 text-sm text-sage-800 focus:outline-none focus:ring-2 focus:ring-sage-600';

/** /feedback-bank — reusable grading comments, linked to rubric criteria and courses, inserted while grading. */
export const FeedbackBank: React.FC = () => {
  const [comments, setComments] = useState<FeedbackComment[] | null>(null);
  const [courses, setCourses] = useState<{ id: number; course_code: string; course_name: string }[]>([]);
  const [search, setSearch] = useState('');
  const [tone, setTone] = useState<FeedbackTone | ''>('');
  const [editingId, setEditingId] = useState<number | 'new' | null>(null);
  const [draft, setDraft] = useState<Draft>(EMPTY);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = () => examService.comments({ search, tone }).then(setComments).catch((e) => setError(e instanceof Error ? e.message : 'Could not load comments.'));
  useEffect(() => { const t = window.setTimeout(load, 250); return () => window.clearTimeout(t); }, [search, tone]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    courseService.getAll()
      .then((res) => setCourses(res.data.map((c) => ({ id: Number(c.id), course_code: c.course_code ?? '', course_name: c.course_name ?? '' }))))
      .catch(() => setCourses([]));
  }, []);

  const startEdit = (c?: FeedbackComment) => {
    setError(null);
    setEditingId(c ? c.id : 'new');
    setDraft(c ? { title: c.title, body: c.body, tone: c.tone, criterion: c.criterion ?? '', course_id: c.course ? String(c.course.id) : '' } : EMPTY);
  };

  const save = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!draft.title.trim() || !draft.body.trim()) { setError('Give the comment a title and text.'); return; }
    setBusy(true);
    try {
      const payload = { title: draft.title.trim(), body: draft.body.trim(), tone: draft.tone, criterion: draft.criterion.trim() || null, course_id: draft.course_id ? Number(draft.course_id) : null };
      if (editingId === 'new') await examService.createComment(payload);
      else if (editingId) await examService.updateComment(editingId, payload);
      setEditingId(null);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Could not save the comment.');
    } finally {
      setBusy(false);
    }
  };

  const form = (
    <form onSubmit={save} className="space-y-3 rounded-xl border border-sage-300 bg-white p-4" data-testid="comment-form">
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="text-xs font-medium text-sage-700">Title<input className={cn(field, 'mt-1')} maxLength={120} value={draft.title} onChange={(e) => setDraft({ ...draft, title: e.target.value })} placeholder="e.g. Missing worked example" /></label>
        <label className="text-xs font-medium text-sage-700">Rubric criterion (optional)<input className={cn(field, 'mt-1')} maxLength={255} value={draft.criterion} onChange={(e) => setDraft({ ...draft, criterion: e.target.value })} placeholder="e.g. Use of examples" /></label>
      </div>
      <label className="block text-xs font-medium text-sage-700">Comment text<textarea className={cn(field, 'mt-1')} rows={3} maxLength={3000} value={draft.body} onChange={(e) => setDraft({ ...draft, body: e.target.value })} /></label>
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="text-xs font-medium text-sage-700">Tone
          <select className={cn(field, 'mt-1')} value={draft.tone} onChange={(e) => setDraft({ ...draft, tone: e.target.value as FeedbackTone })}>{TONES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}</select>
        </label>
        <label className="text-xs font-medium text-sage-700">Course (optional)
          <select className={cn(field, 'mt-1')} value={draft.course_id} onChange={(e) => setDraft({ ...draft, course_id: e.target.value })}>
            <option value="">All my courses</option>
            {courses.map((c) => <option key={c.id} value={c.id}>{c.course_code} · {c.course_name}</option>)}
          </select>
        </label>
      </div>
      {error && <p role="alert" className="text-xs text-[#991B1B]">{error}</p>}
      <div className="flex gap-2">
        <Button type="submit" size="sm" isLoading={busy} leftIcon={<Save className="w-3.5 h-3.5" />}>Save comment</Button>
        <Button type="button" size="sm" variant="ghost" onClick={() => setEditingId(null)} leftIcon={<X className="w-3.5 h-3.5" />}>Cancel</Button>
      </div>
    </form>
  );

  return (
    <div className="space-y-5 page-enter" data-testid="feedback-bank-page">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex items-start gap-3">
          <span className="w-10 h-10 rounded-xl bg-white border border-sage-200 flex items-center justify-center text-sage-800"><MessageSquareText className="w-5 h-5" aria-hidden="true" strokeWidth={1.8} /></span>
          <div>
            <h2 className="text-xl font-semibold tracking-tight text-sage-800">Comment bank</h2>
            <p className="text-sm text-sage-500">Reusable feedback you insert while grading. Most-used comments appear first. Only you can see your bank.</p>
          </div>
        </div>
        {editingId === null && <Button size="sm" leftIcon={<Plus className="w-3.5 h-3.5" />} onClick={() => startEdit()} data-testid="new-comment">New comment</Button>}
      </div>

      {editingId === 'new' && form}

      <div className="flex flex-wrap gap-2">
        <label className="relative min-w-[220px] flex-1">
          <span className="sr-only">Search comments</span>
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-sage-400" aria-hidden="true" />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search comments" className={cn(field, 'pl-9')} />
        </label>
        <select aria-label="Filter by tone" value={tone} onChange={(e) => setTone(e.target.value as FeedbackTone | '')} className="rounded-lg border border-sage-200 bg-white px-3 py-2 text-sm text-sage-800">
          <option value="">All tones</option>{TONES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
        </select>
      </div>

      {comments === null ? <p className="text-sm text-sage-500">Loading…</p> : comments.length === 0 ? (
        <p className="rounded-xl border border-dashed border-sage-300 p-8 text-center text-sm text-sage-500">No comments yet. Create one, or click “Save as comment” under any feedback box while grading.</p>
      ) : (
        <ul className="grid gap-3 md:grid-cols-2" data-testid="comment-list">
          {comments.map((c) => editingId === c.id ? <li key={c.id} className="md:col-span-2">{form}</li> : (
            <li key={c.id} className="rounded-xl border border-sage-200 bg-white p-4">
              <div className="flex items-start justify-between gap-2">
                <p className="flex items-center gap-1.5 font-medium text-sage-800"><span className={cn('h-2 w-2 rounded-full', TONES.find((t) => t.value === c.tone)?.dot)} aria-hidden="true" />{c.title}</p>
                <span className="text-[11px] text-sage-500">{c.usage_count ? `used ${c.usage_count}×` : 'not used yet'}</span>
              </div>
              <p className="mt-1 whitespace-pre-line text-sm text-sage-700">{c.body}</p>
              <p className="mt-2 text-[11px] text-sage-500">{TONES.find((t) => t.value === c.tone)?.label}{c.criterion ? ` · ${c.criterion}` : ''} · {c.course ? c.course.course_code : 'All courses'}</p>
              <div className="mt-2 flex gap-1">
                <button type="button" onClick={() => startEdit(c)} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-sage-700 hover:bg-sage-100"><Pencil className="w-3.5 h-3.5" aria-hidden="true" />Edit</button>
                <button type="button" onClick={async () => { if (window.confirm(`Delete "${c.title}"?`)) { await examService.deleteComment(c.id); void load(); } }} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-[#991B1B] hover:bg-[#FEF2F2]"><Trash2 className="w-3.5 h-3.5" aria-hidden="true" />Delete</button>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};
