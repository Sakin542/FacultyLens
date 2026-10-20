import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, ChevronLeft, ChevronRight, Minus, Plus, Check, Sparkles, ListChecks } from 'lucide-react';
import { courseDesignService } from '@/services/courseDesignService';
import { CommentBankPicker } from '@/components/grading/CommentBankPicker';
import type { GradingQueue } from '@/types/courseDesign';
import { cn } from '@/utils/cn';

/**
 * /assessments/:assessmentId/grade — touch-first grading: one question across all students, one answer at a time,
 * large mark controls, rubric and comment bank at hand. Swipe-free: big Prev / Save & next buttons and arrow keys.
 * Marks go through the normal finalize-grade endpoint (same validation and audit as desktop grading).
 */
export const MobileGrading: React.FC = () => {
  const { assessmentId = '' } = useParams();
  const [queue, setQueue] = useState<GradingQueue | null>(null);
  const [questionId, setQuestionId] = useState<number | undefined>(undefined);
  const [onlyUngraded, setOnlyUngraded] = useState(true);
  const [index, setIndex] = useState(0);
  const [marks, setMarks] = useState<number | null>(null);
  const [feedback, setFeedback] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showRubric, setShowRubric] = useState(false);
  const [savedCount, setSavedCount] = useState(0);

  const load = useCallback(async (qid?: number, keepIndex = false) => {
    try {
      const data = await courseDesignService.gradingQueue(assessmentId, qid, onlyUngraded ? 'ungraded' : 'all');
      setQueue(data);
      setQuestionId(data.question?.id);
      if (!keepIndex) setIndex(0);
      setError(null);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Could not load answers.');
    }
  }, [assessmentId, onlyUngraded]);

  useEffect(() => { void load(questionId); }, [onlyUngraded]); // eslint-disable-line react-hooks/exhaustive-deps

  const answer = queue?.answers[Math.min(index, (queue?.answers.length ?? 1) - 1)];
  const max = queue?.question?.marks ?? 0;

  useEffect(() => {
    setMarks(answer?.awarded_marks ?? null);
    setFeedback(answer?.faculty_feedback ?? '');
  }, [answer?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const quick = useMemo(() => {
    const steps = max <= 5 ? max * 2 : 5;
    return Array.from({ length: steps + 1 }, (_, i) => Math.round((max * i / steps) * 2) / 2);
  }, [max]);

  const save = useCallback(async () => {
    if (!answer || marks === null) { setError('Choose the marks first.'); return; }
    setSaving(true);
    setError(null);
    try {
      await courseDesignService.saveGrade(answer.id, marks, feedback.trim() || null);
      setSavedCount((n) => n + 1);
      if (onlyUngraded) {
        await load(questionId, true); // the saved answer leaves the ungraded list; stay at the same position
      } else {
        setQueue((q) => q && { ...q, answers: q.answers.map((a) => (a.id === answer.id ? { ...a, awarded_marks: marks, faculty_feedback: feedback } : a)) });
        setIndex((i) => Math.min(i + 1, (queue?.answers.length ?? 1) - 1));
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : 'The grade could not be saved.');
    } finally {
      setSaving(false);
    }
  }, [answer, marks, feedback, onlyUngraded, load, questionId, queue?.answers.length]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.target as HTMLElement)?.tagName === 'TEXTAREA') return;
      if (e.key === 'ArrowRight') setIndex((i) => Math.min(i + 1, (queue?.answers.length ?? 1) - 1));
      if (e.key === 'ArrowLeft') setIndex((i) => Math.max(0, i - 1));
      if (e.key === 'Enter') void save();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [queue?.answers.length, save]);

  if (error && !queue) return <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] p-4 text-sm text-[#991B1B]">{error}</p>;
  if (!queue) return <p className="py-10 text-center text-sm text-sage-500">Loading answers…</p>;

  const total = queue.questions.find((q) => q.id === questionId);

  return (
    <div className="mx-auto max-w-3xl space-y-4 page-enter" data-testid="mobile-grading">
      <div className="flex items-center justify-between gap-2">
        <Link to={`/assessments/${assessmentId}`} className="inline-flex items-center gap-1.5 text-xs font-semibold text-sage-500 hover:text-sage-800"><ArrowLeft className="w-3.5 h-3.5" />{queue.assessment.course_code} · {queue.assessment.title}</Link>
        <label className="flex items-center gap-2 text-xs text-sage-700"><input type="checkbox" checked={onlyUngraded} onChange={(e) => setOnlyUngraded(e.target.checked)} className="h-4 w-4 rounded border-sage-300" />Only ungraded</label>
      </div>

      <nav aria-label="Questions" className="flex gap-2 overflow-x-auto pb-1">
        {queue.questions.map((q) => (
          <button key={q.id} type="button" onClick={() => void load(q.id)} aria-pressed={q.id === questionId}
            className={cn('shrink-0 rounded-xl border px-3 py-2 text-left', q.id === questionId ? 'border-sage-700 bg-sage-700 text-white' : 'border-sage-200 bg-white text-sage-800')}>
            <span className="block text-sm font-semibold">Q{q.question_number}</span>
            <span className={cn('block text-[11px]', q.id === questionId ? 'text-white/80' : 'text-sage-500')}>{q.ungraded === 0 ? 'All graded' : `${q.ungraded} of ${q.total} left`}</span>
          </button>
        ))}
      </nav>

      {queue.question && (
        <section className="rounded-2xl border border-sage-200 bg-white p-4">
          <p className="text-sm text-sage-800"><span className="font-semibold">Q{queue.question.question_number}.</span> {queue.question.question_text} <span className="font-semibold">[{max}]</span></p>
          {(queue.question.rubric.length > 0 || queue.question.expected_answer) && (
            <button type="button" onClick={() => setShowRubric(!showRubric)} className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-sage-700" aria-expanded={showRubric}><ListChecks className="w-3.5 h-3.5" aria-hidden="true" />{showRubric ? 'Hide' : 'Show'} rubric and expected answer</button>
          )}
          {showRubric && (
            <div className="mt-2 space-y-2 rounded-lg bg-sage-50 p-3 text-xs text-sage-700">
              {queue.question.rubric.map((c) => <p key={c.criterion}><span className="font-semibold">{c.criterion} [{c.max_marks}]</span> {c.description}</p>)}
              {queue.question.expected_answer && <p><span className="font-semibold">Expected:</span> {queue.question.expected_answer}</p>}
            </div>
          )}
        </section>
      )}

      {error && <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] px-3 py-2 text-sm text-[#991B1B]">{error}</p>}

      {!answer ? (
        <div className="rounded-2xl border border-[#BBF7D0] bg-[#F0FDF4] p-8 text-center" data-testid="grading-done">
          <Check className="mx-auto w-8 h-8 text-[#166534]" aria-hidden="true" />
          <p className="mt-2 text-sm font-medium text-[#166534]">{onlyUngraded ? 'Every answer to this question is graded.' : 'No answers for this question.'}</p>
          {savedCount > 0 && <p className="text-xs text-[#166534]">{savedCount} grade(s) saved this session.</p>}
        </div>
      ) : (
        <section className="space-y-4 rounded-2xl border border-sage-200 bg-white p-4 shadow-subtle" aria-label="Answer" data-testid="grading-card">
          <div className="flex items-center justify-between text-xs text-sage-500">
            <span><span className="font-semibold text-sage-800">{answer.student?.name ?? 'Student'}</span> <span className="font-mono">{answer.student?.student_identifier}</span></span>
            <span data-testid="grading-position">{Math.min(index, queue.answers.length - 1) + 1} / {queue.answers.length}{total ? ` · ${total.ungraded} left` : ''}</span>
          </div>
          <div className="max-h-[40vh] overflow-y-auto whitespace-pre-line rounded-xl bg-sage-50 p-3 text-base leading-relaxed text-sage-800">{answer.answer_text || (answer.has_file ? 'Answer uploaded as a file: open the submission to view it.' : 'No answer text.')}</div>

          <div>
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-sage-800">Marks</span>
              {answer.ai_suggested_marks !== null && (
                <button type="button" onClick={() => setMarks(answer.ai_suggested_marks)} className="inline-flex items-center gap-1 rounded-md border border-sage-200 px-2 py-1 text-xs text-sage-700"><Sparkles className="w-3 h-3" aria-hidden="true" />AI suggests {answer.ai_suggested_marks}</button>
              )}
            </div>
            <div className="mt-2 flex items-center gap-3">
              <button type="button" onClick={() => setMarks((m) => Math.max(0, (m ?? 0) - 0.5))} className="flex h-12 w-12 items-center justify-center rounded-xl border border-sage-200 text-sage-800 active:bg-sage-100" aria-label="Half a mark less"><Minus className="w-5 h-5" /></button>
              <output className="min-w-[96px] text-center text-3xl font-semibold tabular-nums text-sage-800" data-testid="marks-value">{marks ?? '—'}<span className="text-base text-sage-400"> / {max}</span></output>
              <button type="button" onClick={() => setMarks((m) => Math.min(max, (m ?? 0) + 0.5))} className="flex h-12 w-12 items-center justify-center rounded-xl border border-sage-200 text-sage-800 active:bg-sage-100" aria-label="Half a mark more"><Plus className="w-5 h-5" /></button>
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
              {quick.map((v) => <button key={v} type="button" onClick={() => setMarks(v)} className={cn('h-11 min-w-[44px] rounded-xl border px-3 text-sm font-medium tabular-nums', marks === v ? 'border-sage-700 bg-sage-700 text-white' : 'border-sage-200 text-sage-800')}>{v}</button>)}
            </div>
          </div>

          <div className="space-y-2">
            <label className="block text-sm font-medium text-sage-800">Feedback
              <textarea rows={3} value={feedback} onChange={(e) => setFeedback(e.target.value)} className="mt-1 w-full rounded-xl border border-sage-200 p-3 text-base" placeholder="Optional comment for the student" />
            </label>
            <CommentBankPicker current={feedback} onInsert={(t) => setFeedback((f) => (f.trim() ? `${f.trim()}\n${t}` : t))} />
          </div>

          <div className="grid grid-cols-[auto,1fr,auto] gap-2">
            <button type="button" onClick={() => setIndex((i) => Math.max(0, i - 1))} disabled={index === 0} className="flex h-12 w-12 items-center justify-center rounded-xl border border-sage-200 text-sage-800 disabled:opacity-30" aria-label="Previous answer"><ChevronLeft className="w-5 h-5" /></button>
            <button type="button" onClick={() => void save()} disabled={saving || marks === null} className="h-12 rounded-xl bg-sage-700 text-base font-semibold text-white disabled:opacity-50" data-testid="save-next">{saving ? 'Saving…' : 'Save & next'}</button>
            <button type="button" onClick={() => setIndex((i) => Math.min(i + 1, queue.answers.length - 1))} disabled={index >= queue.answers.length - 1} className="flex h-12 w-12 items-center justify-center rounded-xl border border-sage-200 text-sage-800 disabled:opacity-30" aria-label="Next answer"><ChevronRight className="w-5 h-5" /></button>
          </div>
        </section>
      )}
    </div>
  );
};
