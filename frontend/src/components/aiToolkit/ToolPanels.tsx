import React, { useEffect, useRef, useState } from 'react';
import { AlertTriangle, BookPlus, CheckCircle2, ClipboardCheck, Copy, FileSearch, Link2, Loader2, MessageSquareText, ScanSearch, Sparkles, Volume2, Wand2, X } from 'lucide-react';
import { Button } from '@/components/common/Button';
import {
  aiToolkitService, type BloomLevel, type BloomResult, type CompareResult, type FeedbackCriterionInput, type FeedbackDraft, type FeedbackTone, type PaperQuestionInput, type PaperReview, type QualityReport, type QuizItem, type SimplifyResult, type ToolkitJobDetail, type ToolkitLanguage,
} from '@/services/aiToolkitService';
import { apiClient } from '@/services/api';
import { cn } from '@/utils/cn';

// ------------------------------------------------------------------------------------------------- shared bits

const card = 'rounded-xl border border-sage-200 bg-white p-4 shadow-subtle';
const fieldCls = 'w-full rounded-lg border border-sage-200 bg-white px-3 py-2 text-sm text-sage-800 focus:border-sage-500 focus:outline-none focus:ring-2 focus:ring-sage-300/40';

export const ErrorNote: React.FC<{ text: string | null }> = ({ text }) =>
  text ? <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] px-3 py-2 text-xs text-[#991B1B]">{text}</p> : null;

const OkNote: React.FC<{ text: string | null }> = ({ text }) =>
  text ? <p role="status" className="rounded-lg border border-[#BBF7D0] bg-[#F0FDF4] px-3 py-2 text-xs text-[#166534]">{text}</p> : null;

const WarnNote: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <p className="flex items-start gap-1.5 rounded-lg border border-[#FDE68A] bg-[#FFFBEB] px-3 py-2 text-xs text-[#92400E]"><AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{children}</p>
);

const CopyText: React.FC<{ text: string; label?: string }> = ({ text, label = 'Copy' }) => {
  const [done, setDone] = useState(false);
  return (
    <button type="button" onClick={async () => { try { await navigator.clipboard.writeText(text); setDone(true); window.setTimeout(() => setDone(false), 1500); } catch { /* blocked */ } }}
      className="inline-flex items-center gap-1 rounded-md border border-sage-200 px-2 py-0.5 text-[11px] text-sage-700 hover:bg-sage-100">
      <Copy className="h-3 w-3" aria-hidden="true" />{done ? 'Copied' : label}
    </button>
  );
};

const levelLabel = (l: string | null | undefined) => (l ? l.charAt(0) + l.slice(1).toLowerCase() : '—');

// ------------------------------------------------------------------------------------------------- read aloud

/** Small "Listen" button: synthesises speech for the given text and plays it inline. */
export const ListenButton: React.FC<{ text: string; language: ToolkitLanguage; disabled?: boolean }> = ({ text, language, disabled }) => {
  const [url, setUrl] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => () => { if (url) URL.revokeObjectURL(url); }, [url]);
  useEffect(() => { setUrl(null); }, [text, language]);

  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      {url ? <audio src={url} controls autoPlay className="h-8 max-w-full" aria-label="Spoken text" />
        : (
          <button type="button" disabled={disabled || busy || !text.trim()} onClick={async () => {
            setBusy(true); setError(null);
            try { setUrl(await aiToolkitService.speak(text.slice(0, 1500), language)); } catch (e) { setError(e instanceof Error ? e.message : 'Could not read aloud.'); } finally { setBusy(false); }
          }} className="inline-flex items-center gap-1 rounded-md border border-sage-200 px-2 py-0.5 text-[11px] text-sage-700 hover:bg-sage-100 disabled:opacity-50">
            {busy ? <Loader2 className="h-3 w-3 animate-spin" aria-hidden="true" /> : <Volume2 className="h-3 w-3" aria-hidden="true" />}{busy ? 'Preparing audio…' : 'Listen'}
          </button>
        )}
      {error && <span className="text-[11px] text-[#991B1B]">{error}</span>}
    </span>
  );
};

export const SpeechPanel: React.FC = () => {
  const [text, setText] = useState('');
  const [language, setLanguage] = useState<ToolkitLanguage>('en');
  return (
    <div className={cn(card, 'space-y-3')} data-testid="speech-panel">
      <p className="text-sm text-sage-600">Hear a question, feedback or a passage read aloud — useful for accessibility and for checking a Bangla translation. Up to 1,500 characters. The first use of each language loads its voice and can take half a minute.</p>
      <div className="flex flex-wrap gap-2">
        {(['en', 'bn'] as const).map((l) => (
          <button key={l} type="button" onClick={() => setLanguage(l)} aria-pressed={language === l}
            className={cn('rounded-lg border px-3 py-1.5 text-xs font-medium', language === l ? 'border-sage-700 bg-sage-700 text-white' : 'border-sage-200 text-sage-700 hover:bg-sage-100')}>
            {l === 'en' ? 'English' : 'বাংলা (Bangla)'}
          </button>
        ))}
      </div>
      <label htmlFor="tk-speech-text" className="sr-only">Text to read aloud</label>
      <textarea id="tk-speech-text" rows={6} maxLength={1500} lang={language} value={text} onChange={(e) => setText(e.target.value)} className={fieldCls}
        placeholder={language === 'en' ? 'Type or paste English text…' : 'বাংলা লেখা এখানে লিখুন…'} />
      <div className="flex items-center justify-between gap-2 text-[11px] text-sage-500">
        <span className="tabular-nums">{text.length} / 1500</span>
        <ListenButton text={text} language={language} />
      </div>
    </div>
  );
};

// ------------------------------------------------------------------------------------------------- notes & quiz

export const StudyPackForm: React.FC<{ onQueued: () => void }> = ({ onQueued }) => {
  const [text, setText] = useState('');
  const [title, setTitle] = useState('');
  const [count, setCount] = useState(5);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true); setMsg(null);
    try {
      const r = await aiToolkitService.studyPack({ text, title: title || undefined, num_questions: count });
      setMsg({ ok: true, text: r.message ?? 'Started.' });
      setText(''); setTitle('');
      onQueued();
    } catch (err) { setMsg({ ok: false, text: err instanceof Error ? err.message : 'Could not start.' }); } finally { setBusy(false); }
  };

  return (
    <form onSubmit={submit} className={cn(card, 'space-y-3')} data-testid="study-pack-form">
      <p className="text-sm text-sage-600">Paste lecture notes, a transcript or course material. You get a summary, key points and a draft quiz. Every quiz answer is checked against your text and unsupported AI answers are removed. You can also start this from a finished transcript.</p>
      <label className="block text-xs font-medium text-sage-700">Title (optional)
        <input value={title} onChange={(e) => setTitle(e.target.value)} maxLength={160} className={cn(fieldCls, 'mt-1')} placeholder="e.g. Week 3 — Graph traversal" />
      </label>
      <label className="block text-xs font-medium text-sage-700">Material (200 – 60,000 characters)
        <textarea rows={8} value={text} onChange={(e) => setText(e.target.value)} maxLength={60000} className={cn(fieldCls, 'mt-1')} data-testid="study-pack-text" />
      </label>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <label className="text-xs font-medium text-sage-700">Quiz questions
          <select value={count} onChange={(e) => setCount(Number(e.target.value))} className="ml-2 h-8 rounded-lg border border-sage-200 bg-white px-2 text-sm">
            {[3, 5, 8, 10].map((n) => <option key={n} value={n}>{n}</option>)}
          </select>
        </label>
        <Button type="submit" isLoading={busy} disabled={text.trim().length < 200} leftIcon={<Sparkles className="h-4 w-4" />}>Make notes & quiz</Button>
      </div>
      {msg && (msg.ok ? <OkNote text={msg.text} /> : <ErrorNote text={msg.text} />)}
    </form>
  );
};

interface CourseOption { id: number; course_code: string; course_name: string }

export const StudyPackResult: React.FC<{ job: ToolkitJobDetail }> = ({ job }) => {
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [edits, setEdits] = useState<Record<number, string>>({});
  const [courses, setCourses] = useState<CourseOption[] | null>(null);
  const [courseId, setCourseId] = useState<number | ''>('');
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  useEffect(() => {
    apiClient<{ data: CourseOption[] }>('/courses').then((r) => setCourses(r.data ?? [])).catch(() => setCourses([]));
  }, []);

  const quiz: QuizItem[] = job.quiz ?? [];
  const toggle = (i: number) => setSelected((s) => { const n = new Set(s); if (n.has(i)) { n.delete(i); } else { n.add(i); } return n; });

  const save = async () => {
    if (!courseId || selected.size === 0) return;
    setBusy(true); setMsg(null);
    try {
      const items = [...selected].sort((a, b) => a - b).map((i) => ({ question: edits[i] ?? quiz[i].question, bloom_level: quiz[i].bloom_level }));
      const r = await aiToolkitService.saveToQuestionBank(job.id, Number(courseId), items);
      setMsg({ ok: true, text: r.message ?? 'Saved.' });
      setSelected(new Set());
    } catch (e) { setMsg({ ok: false, text: e instanceof Error ? e.message : 'Could not save.' }); } finally { setBusy(false); }
  };

  return (
    <div className="space-y-4" data-testid="study-pack-result">
      <section className={card}>
        <div className="flex items-center justify-between gap-2">
          <h3 className="text-sm font-semibold text-sage-800">Summary</h3>
          <span className="flex items-center gap-1.5">{job.summary && <><ListenButton text={job.summary} language="en" /><CopyText text={job.text ?? job.summary} label="Copy all" /></>}</span>
        </div>
        <p className="mt-2 text-sm leading-relaxed text-sage-800">{job.summary}</p>
        <p className="mt-1 text-[11px] text-sage-500">{job.summary_method === 'generative' ? `Written by ${job.model ?? 'the AI model'}` : 'Built from the most central sentences of your text (no generation model)'}</p>
      </section>
      <section className={card}>
        <h3 className="text-sm font-semibold text-sage-800">Key points</h3>
        <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-sage-700">{job.key_points.map((p) => <li key={p}>{p}</li>)}</ul>
      </section>
      <section className={card}>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h3 className="text-sm font-semibold text-sage-800">Draft quiz</h3>
          <span className="text-[11px] text-sage-500">
            {job.quiz_method === 'generative' ? 'AI questions' : 'Fill-in-the-blank from your text'}
            {job.quiz_rejected ? ` · ${job.quiz_rejected} AI question(s) removed: not supported by your text` : ''}
          </span>
        </div>
        <ol className="mt-3 space-y-3">
          {quiz.map((q, i) => (
            <li key={i} className={cn('rounded-lg border p-3', selected.has(i) ? 'border-sage-500 bg-sage-50' : 'border-sage-200')}>
              <div className="flex items-start gap-2">
                <input type="checkbox" checked={selected.has(i)} onChange={() => toggle(i)} aria-label={`Select question ${i + 1}`} className="mt-1 h-4 w-4 accent-[#1E6F5C]" />
                <div className="min-w-0 flex-1 space-y-1">
                  {selected.has(i)
                    ? <textarea rows={2} value={edits[i] ?? q.question} onChange={(e) => setEdits({ ...edits, [i]: e.target.value })} className={fieldCls} aria-label={`Edit question ${i + 1}`} />
                    : <p className="text-sm font-medium text-sage-800">{i + 1}. {q.question}</p>}
                  <p className="text-sm text-sage-700"><span className="text-sage-500">Answer:</span> {q.answer}</p>
                  <p className="text-[11px] text-sage-500"><span className="font-medium">From your text:</span> “{q.evidence}” · {levelLabel(q.bloom_level)}</p>
                </div>
              </div>
            </li>
          ))}
        </ol>
        <div className="mt-4 flex flex-wrap items-end gap-2 border-t border-sage-100 pt-3">
          <label className="text-xs font-medium text-sage-700">Save selected to course question bank
            <select value={courseId} onChange={(e) => setCourseId(e.target.value ? Number(e.target.value) : '')} className="mt-1 block h-9 rounded-lg border border-sage-200 bg-white px-2 text-sm">
              <option value="">{courses === null ? 'Loading courses…' : 'Choose a course'}</option>
              {(courses ?? []).map((c) => <option key={c.id} value={c.id}>{c.course_code} — {c.course_name}</option>)}
            </select>
          </label>
          <Button size="sm" isLoading={busy} disabled={!courseId || selected.size === 0} onClick={() => { void save(); }} leftIcon={<BookPlus className="h-4 w-4" />}>
            Save {selected.size || ''} question{selected.size === 1 ? '' : 's'}
          </Button>
        </div>
        <div className="mt-2">{msg && (msg.ok ? <OkNote text={msg.text} /> : <ErrorNote text={msg.text} />)}</div>
      </section>
    </div>
  );
};

// ------------------------------------------------------------------------------------------------- question checker

const SEVERITY_CLS: Record<string, string> = {
  high: 'border-[#FECACA] bg-[#FEF2F2] text-[#991B1B]', medium: 'border-[#FDE68A] bg-[#FFFBEB] text-[#92400E]', low: 'border-sage-200 bg-sage-50 text-sage-700',
};

export const QuestionCheckerPanel: React.FC = () => {
  const [input, setInput] = useState('');
  const [rewrites, setRewrites] = useState(true);
  const [report, setReport] = useState<QualityReport | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const questions = input.split('\n').map((q) => q.trim()).filter(Boolean);

  const run = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true); setError(null);
    try { setReport(await aiToolkitService.questionQuality(questions, rewrites)); } catch (err) { setError(err instanceof Error ? err.message : 'Check failed.'); } finally { setBusy(false); }
  };

  return (
    <div className="grid gap-4 xl:grid-cols-[360px,1fr]" data-testid="question-checker">
      <form onSubmit={run} className={cn(card, 'space-y-3 self-start')}>
        <p className="text-sm text-sage-600">One question per line (up to 15). Checks clarity, double negatives, vague verbs, multiple questions in one and readability, then suggests a clearer rewrite.</p>
        <label htmlFor="tk-questions" className="sr-only">Questions</label>
        <textarea id="tk-questions" rows={9} value={input} onChange={(e) => setInput(e.target.value)} className={fieldCls} placeholder={'what is bfs and dfs explain\nWhich of the following is not a sorting algorithm except quicksort?'} />
        <label className="flex items-center gap-2 text-xs text-sage-700"><input type="checkbox" checked={rewrites} onChange={(e) => setRewrites(e.target.checked)} className="h-4 w-4 accent-[#1E6F5C]" />Suggest AI rewrites</label>
        <Button type="submit" isLoading={busy} disabled={questions.length === 0 || questions.length > 15} leftIcon={<ClipboardCheck className="h-4 w-4" />}>Check {questions.length || ''} question{questions.length === 1 ? '' : 's'}</Button>
        <ErrorNote text={error} />
      </form>
      <div className="space-y-3">
        {!report ? <p className="rounded-xl border border-dashed border-sage-300 bg-white/60 p-10 text-center text-sm text-sage-500">Results appear here.</p> : report.results.map((r, i) => (
          <article key={i} className={card}>
            <div className="flex flex-wrap items-start justify-between gap-2">
              <p className="min-w-0 flex-1 text-sm font-medium text-sage-800">{r.question}</p>
              <span className={cn('rounded-md border px-2 py-0.5 text-xs font-semibold tabular-nums', r.score >= 90 ? SEVERITY_CLS.low : r.score >= 70 ? SEVERITY_CLS.medium : SEVERITY_CLS.high)}>{r.score}/100</span>
            </div>
            <p className="mt-1 text-[11px] text-sage-500">Bloom: {levelLabel(r.bloom_level)}{r.readability.grade_level !== null ? ` · reading grade ${r.readability.grade_level}` : ''}</p>
            {r.issues.length === 0 ? <p className="mt-2 flex items-center gap-1 text-xs text-[#166534]"><CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" />No problems found.</p> : (
              <ul className="mt-2 space-y-1">{r.issues.map((iss) => <li key={iss.code} className={cn('rounded-md border px-2 py-1 text-xs', SEVERITY_CLS[iss.severity])}>{iss.message}</li>)}</ul>
            )}
            {r.suggested_rewrite && (
              <div className="mt-3 rounded-lg bg-sage-50 p-3">
                <p className="text-[11px] font-medium uppercase tracking-wide text-sage-500">Suggested rewrite · {levelLabel(r.rewrite_bloom_level)}</p>
                <p className="mt-1 text-sm text-sage-800">{r.suggested_rewrite}</p>
                <div className="mt-2"><CopyText text={r.suggested_rewrite} /></div>
              </div>
            )}
          </article>
        ))}
        {report && <p className="text-[11px] text-sage-500">{report.note}{!report.rewrites_available && ' Rewrites need a generation model.'}</p>}
      </div>
    </div>
  );
};

// ------------------------------------------------------------------------------------------------- simplify

export const SimplifyPanel: React.FC = () => {
  const [text, setText] = useState('');
  const [level, setLevel] = useState<'school' | 'undergraduate' | 'esl'>('undergraduate');
  const [result, setResult] = useState<SimplifyResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const run = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true); setError(null);
    try { setResult(await aiToolkitService.simplify(text, level)); } catch (err) { setError(err instanceof Error ? err.message : 'Could not simplify.'); } finally { setBusy(false); }
  };

  return (
    <form onSubmit={run} className="grid gap-4 lg:grid-cols-2" data-testid="simplify-panel">
      <div className={cn(card, 'space-y-3')}>
        <label htmlFor="tk-simplify" className="text-sm font-semibold text-sage-800">Text to simplify</label>
        <textarea id="tk-simplify" rows={9} maxLength={3000} value={text} onChange={(e) => setText(e.target.value)} className={fieldCls} placeholder="Paste a dense paragraph from a textbook or your slides…" />
        <div className="flex flex-wrap items-center justify-between gap-2">
          <select value={level} onChange={(e) => setLevel(e.target.value as typeof level)} className="h-9 rounded-lg border border-sage-200 bg-white px-2 text-sm" aria-label="Reading level">
            <option value="school">First-year / new to the subject</option><option value="undergraduate">Undergraduate</option><option value="esl">English learners</option>
          </select>
          <Button type="submit" isLoading={busy} disabled={text.trim().length < 40} leftIcon={<Wand2 className="h-4 w-4" />}>Simplify</Button>
        </div>
        <ErrorNote text={error} />
      </div>
      <div className={cn(card, 'space-y-3 bg-sage-50')}>
        {!result ? <p className="py-10 text-center text-sm text-sage-500">The simpler version appears here.</p> : <>
          <div className="flex items-center justify-between gap-2"><p className="text-sm font-semibold text-sage-800">Simplified</p><span className="flex gap-1.5"><ListenButton text={result.text} language="en" /><CopyText text={result.text} /></span></div>
          <p className="whitespace-pre-wrap text-sm leading-relaxed text-sage-800" data-testid="simplify-output">{result.text}</p>
          <p className="text-[11px] text-sage-500">Reading grade {result.readability_before.grade_level ?? '—'} → {result.readability_after.grade_level ?? '—'} · <span className="font-mono">{result.model}</span></p>
          {!result.simpler && <WarnNote>This rewrite is not measurably easier to read than the original. Try a shorter passage or a different level.</WarnNote>}
          {result.terms.length > 0 && <p className="text-[11px] text-sage-500">Key terms to define for students: {result.terms.join(', ')}</p>}
        </>}
      </div>
    </form>
  );
};

// ------------------------------------------------------------------------------------------------- Bloom rewriter

const LEVELS: BloomLevel[] = ['REMEMBER', 'UNDERSTAND', 'APPLY', 'ANALYZE', 'EVALUATE', 'CREATE'];

export const BloomPanel: React.FC = () => {
  const [question, setQuestion] = useState('');
  const [target, setTarget] = useState<BloomLevel>('APPLY');
  const [result, setResult] = useState<BloomResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const run = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true); setError(null);
    try { setResult(await aiToolkitService.bloomRewrite(question, target)); } catch (err) { setError(err instanceof Error ? err.message : 'Could not rewrite.'); } finally { setBusy(false); }
  };

  return (
    <form onSubmit={run} className="grid gap-4 lg:grid-cols-2" data-testid="bloom-panel">
      <div className={cn(card, 'space-y-3')}>
        <label htmlFor="tk-bloom-q" className="text-sm font-semibold text-sage-800">Question</label>
        <textarea id="tk-bloom-q" rows={4} maxLength={2000} value={question} onChange={(e) => setQuestion(e.target.value)} className={fieldCls} placeholder="Define breadth-first search." />
        <fieldset>
          <legend className="mb-1 text-xs font-medium text-sage-700">Target Bloom level</legend>
          <div className="flex flex-wrap gap-1.5">
            {LEVELS.map((l) => (
              <button key={l} type="button" aria-pressed={target === l} onClick={() => setTarget(l)}
                className={cn('rounded-lg border px-2.5 py-1 text-xs font-medium', target === l ? 'border-sage-700 bg-sage-700 text-white' : 'border-sage-200 text-sage-700 hover:bg-sage-100')}>{levelLabel(l)}</button>
            ))}
          </div>
        </fieldset>
        <Button type="submit" isLoading={busy} disabled={question.trim().length < 10} leftIcon={<Sparkles className="h-4 w-4" />}>Rewrite for {levelLabel(target)}</Button>
        <ErrorNote text={error} />
      </div>
      <div className={cn(card, 'space-y-3 bg-sage-50')}>
        {!result ? <p className="py-10 text-center text-sm text-sage-500">The rewritten question appears here.</p> : <>
          <p className="text-[11px] text-sage-500">Original level: {levelLabel(result.original_level)}</p>
          <p className="text-sm font-medium text-sage-800" data-testid="bloom-output">{result.rewritten}</p>
          <div className="flex flex-wrap items-center gap-2 text-[11px]">
            <span className={cn('rounded-md border px-2 py-0.5', result.matches_target ? SEVERITY_CLS.low : SEVERITY_CLS.medium)}>Detected: {levelLabel(result.detected_level)}{result.matches_target ? ' ✓' : ` (target ${levelLabel(result.target_level)})`}</span>
            <CopyText text={result.rewritten} />
          </div>
          {!result.on_topic && <WarnNote>The rewrite drifted away from the original topic. Edit it or try again.</WarnNote>}
          {result.on_topic && !result.matches_target && <WarnNote>The detected level differs from your target. Adjust the verb or try again.</WarnNote>}
          <p className="text-[11px] text-sage-500">{result.note}</p>
        </>}
      </div>
    </form>
  );
};

// ------------------------------------------------------------------------------------------------- OCR → grading

interface AssessmentOption { id: number; title: string; course?: { course_code?: string } }
interface SubmissionOption { id: number; submission_identifier?: string | null; student?: { name?: string; student_identifier?: string } | null }
interface QuestionOption { id: number; question_number?: number; question_text?: string; text?: string }

/** Attach reviewed OCR text to one student's answer so AI grading assistance can run on a handwritten script. */
export const AttachDialog: React.FC<{ job: ToolkitJobDetail; onClose: () => void }> = ({ job, onClose }) => {
  const [assessments, setAssessments] = useState<AssessmentOption[] | null>(null);
  const [assessmentId, setAssessmentId] = useState<number | ''>('');
  const [submissions, setSubmissions] = useState<SubmissionOption[]>([]);
  const [questions, setQuestions] = useState<QuestionOption[]>([]);
  const [submissionId, setSubmissionId] = useState<number | ''>('');
  const [questionId, setQuestionId] = useState<number | ''>('');
  const [text, setText] = useState(job.text ?? '');
  const [confirmReplace, setConfirmReplace] = useState(false);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const dialog = useRef<HTMLDivElement>(null);

  useEffect(() => {
    apiClient<{ data: AssessmentOption[] }>('/assessments').then((r) => setAssessments(r.data ?? [])).catch(() => setAssessments([]));
    dialog.current?.focus();
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  useEffect(() => {
    setSubmissions([]); setQuestions([]); setSubmissionId(''); setQuestionId('');
    if (!assessmentId) return;
    apiClient<{ data: SubmissionOption[] }>(`/assessments/${assessmentId}/submissions?per_page=100`).then((r) => setSubmissions(r.data ?? [])).catch(() => setSubmissions([]));
    apiClient<{ data: { questions?: QuestionOption[] } }>(`/assessments/${assessmentId}`).then((r) => setQuestions(r.data?.questions ?? [])).catch(() => setQuestions([]));
  }, [assessmentId]);

  const attach = async (replace = false) => {
    if (!submissionId || !questionId) return;
    setBusy(true); setMsg(null);
    try {
      const r = await aiToolkitService.attach(job.id, { submission_id: Number(submissionId), question_id: Number(questionId), text, replace });
      setMsg({ ok: true, text: r.message ?? 'Attached.' });
      setConfirmReplace(false);
    } catch (e) {
      const status = (e as { status?: number }).status;
      if (status === 409) { setConfirmReplace(true); setMsg({ ok: false, text: e instanceof Error ? e.message : 'An answer already exists.' }); }
      else setMsg({ ok: false, text: e instanceof Error ? e.message : 'Could not attach.' });
    } finally { setBusy(false); }
  };

  const select = 'mt-1 block h-9 w-full rounded-lg border border-sage-200 bg-white px-2 text-sm';
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-sage-800/60 p-0 sm:items-center sm:p-4" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose(); }}>
      <div ref={dialog} tabIndex={-1} role="dialog" aria-modal="true" aria-labelledby="attach-title" className="max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-white shadow-elevated sm:max-w-2xl sm:rounded-2xl" data-testid="attach-dialog">
        <header className="flex items-center justify-between border-b border-sage-200 px-5 py-3">
          <h2 id="attach-title" className="text-base font-semibold text-sage-800">Attach to a student's answer</h2>
          <button type="button" onClick={onClose} className="rounded-lg p-1 text-sage-500 hover:bg-sage-100" aria-label="Close"><X className="h-5 w-5" /></button>
        </header>
        <div className="space-y-3 px-5 py-4">
          <WarnNote>Check the text against the original script and correct any OCR mistakes before attaching. AI grading will only see this text.</WarnNote>
          <div className="grid gap-3 sm:grid-cols-3">
            <label className="text-xs font-medium text-sage-700">Assessment
              <select className={select} value={assessmentId} onChange={(e) => setAssessmentId(e.target.value ? Number(e.target.value) : '')}>
                <option value="">{assessments === null ? 'Loading…' : 'Choose'}</option>
                {(assessments ?? []).map((a) => <option key={a.id} value={a.id}>{a.course?.course_code ? `${a.course.course_code} · ` : ''}{a.title}</option>)}
              </select>
            </label>
            <label className="text-xs font-medium text-sage-700">Student submission
              <select className={select} value={submissionId} disabled={!assessmentId} onChange={(e) => setSubmissionId(e.target.value ? Number(e.target.value) : '')}>
                <option value="">{assessmentId && submissions.length === 0 ? 'No submissions' : 'Choose'}</option>
                {submissions.map((s) => <option key={s.id} value={s.id}>{s.student?.student_identifier ?? s.submission_identifier ?? `#${s.id}`}{s.student?.name ? ` — ${s.student.name}` : ''}</option>)}
              </select>
            </label>
            <label className="text-xs font-medium text-sage-700">Question
              <select className={select} value={questionId} disabled={!assessmentId} onChange={(e) => setQuestionId(e.target.value ? Number(e.target.value) : '')}>
                <option value="">Choose</option>
                {questions.map((q) => <option key={q.id} value={q.id}>Q{q.question_number ?? ''} {(q.question_text ?? q.text ?? '').slice(0, 40)}</option>)}
              </select>
            </label>
          </div>
          <label className="block text-xs font-medium text-sage-700">Answer text (edit before attaching)
            <textarea rows={8} value={text} onChange={(e) => setText(e.target.value)} className={cn(fieldCls, 'mt-1')} maxLength={20000} data-testid="attach-text" />
          </label>
          {msg && (msg.ok ? <OkNote text={msg.text} /> : <ErrorNote text={msg.text} />)}
        </div>
        <footer className="flex flex-wrap justify-end gap-2 border-t border-sage-200 bg-sage-50 px-5 py-3">
          <Button variant="outline" onClick={onClose}>Close</Button>
          {confirmReplace
            ? <Button variant="danger" isLoading={busy} onClick={() => { void attach(true); }}>Replace existing answer text</Button>
            : <Button isLoading={busy} disabled={!submissionId || !questionId || !text.trim()} onClick={() => { void attach(false); }} leftIcon={<Link2 className="h-4 w-4" />}>Attach answer</Button>}
        </footer>
      </div>
    </div>
  );
};

// ------------------------------------------------------------------------------------------------- feedback drafter

/** "Traversal order: explores level by level; uses a queue" → { criterion, indicators }. One criterion per line. */
export const parseCriteria = (raw: string): FeedbackCriterionInput[] =>
  raw.split('\n').map((line) => line.trim()).filter(Boolean).slice(0, 12).map((line) => {
    const at = line.indexOf(':');
    if (at < 1) return { criterion: line.slice(0, 255), indicators: [] };
    return {
      criterion: line.slice(0, at).trim().slice(0, 255),
      indicators: line.slice(at + 1).split(';').map((s) => s.trim()).filter(Boolean).slice(0, 12),
    };
  });

const COVERAGE_CLS: Record<string, string> = {
  STRONG: 'bg-[#F0FDF4] text-[#166534] border-[#BBF7D0]',
  PARTIAL: 'bg-[#FFFBEB] text-[#92400E] border-[#FDE68A]',
  LIMITED: 'bg-[#FFF7ED] text-[#9A3412] border-[#FED7AA]',
  NOT_ADDRESSED: 'bg-[#FEF2F2] text-[#991B1B] border-[#FECACA]',
};

/** Per-criterion view of what the feedback is based on (shown to the teacher, not the student). */
export const FeedbackBasis: React.FC<{ draft: FeedbackDraft }> = ({ draft }) => (
  <details className="rounded-lg border border-sage-200 bg-white px-3 py-2 text-xs text-sage-700">
    <summary className="cursor-pointer font-medium text-sage-800">What this draft is based on</summary>
    <ul className="mt-2 space-y-2">
      {draft.criteria.map((c) => (
        <li key={c.criterion}>
          <span className="flex flex-wrap items-center gap-1.5">
            <span className="font-medium text-sage-800">{c.criterion}</span>
            <span className={cn('rounded-full border px-1.5 py-0.5 text-[10px]', COVERAGE_CLS[c.coverage_level] ?? 'border-sage-200')}>{c.coverage}</span>
          </span>
          {c.evidence.map((e) => <span key={e} className="mt-0.5 block italic text-sage-600">“{e}”</span>)}
          {c.missing.length > 0 && <span className="mt-0.5 block text-sage-500">Not found: {c.missing.join('; ')}</span>}
        </li>
      ))}
    </ul>
  </details>
);

export const FeedbackDrafterPanel: React.FC = () => {
  const [question, setQuestion] = useState('');
  const [criteria, setCriteria] = useState('');
  const [answer, setAnswer] = useState('');
  const [tone, setTone] = useState<FeedbackTone>('encouraging');
  const [draft, setDraft] = useState<FeedbackDraft | null>(null);
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const parsed = parseCriteria(criteria);

  const run = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true); setError(null);
    try {
      const r = await aiToolkitService.feedbackDraft({ question, answer, criteria: parsed, tone });
      setDraft(r); setText(r.feedback);
    } catch (err) { setError(err instanceof Error ? err.message : 'Could not draft feedback.'); } finally { setBusy(false); }
  };

  return (
    <form onSubmit={run} className="grid gap-4 lg:grid-cols-2" data-testid="feedback-panel">
      <div className={cn(card, 'space-y-3')}>
        <div>
          <label htmlFor="tk-fb-q" className="text-sm font-semibold text-sage-800">Question</label>
          <textarea id="tk-fb-q" rows={2} maxLength={5000} value={question} onChange={(e) => setQuestion(e.target.value)} className={cn(fieldCls, 'mt-1')} placeholder="Explain breadth-first search and why it is useful." />
        </div>
        <div>
          <label htmlFor="tk-fb-c" className="text-sm font-semibold text-sage-800">Rubric criteria</label>
          <p className="text-[11px] text-sage-500">One per line. Optionally add what a good answer shows after a colon, separated by semicolons.</p>
          <textarea id="tk-fb-c" rows={4} value={criteria} onChange={(e) => setCriteria(e.target.value)} className={cn(fieldCls, 'mt-1 font-mono text-xs')}
            placeholder={'Traversal order: explores level by level; uses a queue\nShortest path: finds the shortest path in an unweighted graph\nComplexity: time complexity O(V + E)'} />
          {parsed.length > 0 && <p className="mt-1 text-[11px] text-sage-500">{parsed.length} criteri{parsed.length === 1 ? 'on' : 'a'}</p>}
        </div>
        <div>
          <label htmlFor="tk-fb-a" className="text-sm font-semibold text-sage-800">Student answer</label>
          <textarea id="tk-fb-a" rows={6} maxLength={20000} value={answer} onChange={(e) => setAnswer(e.target.value)} className={cn(fieldCls, 'mt-1')} placeholder="Paste the answer (typed or from Handwriting OCR)…" />
        </div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <select value={tone} onChange={(e) => setTone(e.target.value as FeedbackTone)} className="h-9 rounded-lg border border-sage-200 bg-white px-2 text-sm" aria-label="Tone">
            <option value="encouraging">Encouraging</option><option value="neutral">Neutral</option><option value="concise">Concise</option>
          </select>
          <Button type="submit" isLoading={busy} disabled={question.trim().length < 5 || !parsed.length || answer.trim().split(/\s+/).length < 3} leftIcon={<MessageSquareText className="h-4 w-4" />}>Draft feedback</Button>
        </div>
        <p className="text-[11px] text-sage-500">For answers already in FacultyLens, use <strong>Draft feedback</strong> in the grading form: it uses the question&apos;s approved rubric.</p>
        <ErrorNote text={error} />
      </div>
      <div className={cn(card, 'space-y-3 bg-sage-50')}>
        {!draft ? <p className="py-10 text-center text-sm text-sage-500">The feedback draft appears here. It never includes marks.</p> : <>
          <div className="flex items-center justify-between gap-2">
            <label htmlFor="tk-fb-out" className="text-sm font-semibold text-sage-800">Feedback draft <span className="font-normal text-sage-500">(edit freely)</span></label>
            <CopyText text={text} />
          </div>
          <textarea id="tk-fb-out" rows={8} value={text} onChange={(e) => setText(e.target.value)} className={fieldCls} data-testid="feedback-output" />
          <p className="text-[11px] text-sage-500">{draft.method === 'ai_polished' ? <>Reworded by <span className="font-mono">{draft.model}</span> from the rubric check.</> : 'Built from the rubric check of this answer.'} {draft.note}</p>
          <FeedbackBasis draft={draft} />
        </>}
      </div>
    </form>
  );
};

// ------------------------------------------------------------------------------------------------- compare answers

/** Answers separated by a line containing only "---". A short first line ending in ":" is the answer's label. */
export const splitAnswers = (raw: string): { label?: string; text: string }[] =>
  raw.split(/\n\s*-{3,}\s*\n/).map((block) => block.trim()).filter(Boolean).slice(0, 40).map((block) => {
    const [first, ...rest] = block.split('\n');
    const m = first.trim().match(/^(.{1,60}):$/);
    return m && rest.join('\n').trim() ? { label: m[1].trim(), text: rest.join('\n').trim() } : { text: block };
  });

export const CompareAnswersPanel: React.FC = () => {
  const [question, setQuestion] = useState('');
  const [raw, setRaw] = useState('');
  const [threshold, setThreshold] = useState(0.6);
  const [result, setResult] = useState<CompareResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const answers = splitAnswers(raw);

  const run = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true); setError(null);
    try { setResult(await aiToolkitService.compareAnswers(answers, question, threshold)); } catch (err) { setError(err instanceof Error ? err.message : 'Could not compare.'); } finally { setBusy(false); }
  };
  const shown = result ? result.pairs.filter((p) => p.flagged || p.combined_score >= 0.3).slice(0, 15) : [];

  return (
    <form onSubmit={run} className="grid gap-4 lg:grid-cols-2" data-testid="compare-panel">
      <div className={cn(card, 'space-y-3')}>
        <div>
          <label htmlFor="tk-cmp-q" className="text-sm font-semibold text-sage-800">Question <span className="font-normal text-sage-500">(optional)</span></label>
          <input id="tk-cmp-q" maxLength={5000} value={question} onChange={(e) => setQuestion(e.target.value)} className={cn(fieldCls, 'mt-1')} placeholder="Wording copied from the question is not counted as copying" />
        </div>
        <div>
          <label htmlFor="tk-cmp-a" className="text-sm font-semibold text-sage-800">Answers</label>
          <p className="text-[11px] text-sage-500">Separate answers with a line containing only <code>---</code>. Put a name followed by a colon on the first line to label one.</p>
          <textarea id="tk-cmp-a" rows={12} value={raw} onChange={(e) => setRaw(e.target.value)} className={cn(fieldCls, 'mt-1 text-xs')}
            placeholder={'Rahim:\nAn index keeps a sorted structure such as a B+ tree…\n---\nKarim:\nIn my opinion an index keeps a sorted structure…'} />
          <p className="mt-1 text-[11px] text-sage-500">{answers.length} answer{answers.length === 1 ? '' : 's'} (2–40)</p>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <label className="flex items-center gap-2 text-xs text-sage-700">Flag at
            <input type="range" min={0.4} max={0.9} step={0.05} value={threshold} onChange={(e) => setThreshold(Number(e.target.value))} aria-label="Similarity threshold" />
            <span className="w-8 font-mono">{Math.round(threshold * 100)}%</span>
          </label>
          <Button type="submit" isLoading={busy} disabled={answers.length < 2 || answers.length > 40} leftIcon={<ScanSearch className="h-4 w-4" />}>Compare</Button>
        </div>
        <p className="text-[11px] text-sage-500">Answers already in FacultyLens are checked from an assessment&apos;s <strong>Integrity</strong> page, where flagged pairs can be reviewed and recorded.</p>
        <ErrorNote text={error} />
      </div>
      <div className={cn(card, 'space-y-3 bg-sage-50')} data-testid="compare-result">
        {!result ? <p className="py-10 text-center text-sm text-sage-500">Pairs that look unusually alike appear here.</p> : <>
          <p className="text-sm text-sage-800"><strong>{result.pairs_flagged}</strong> of {result.pairs_compared} pairs flagged · {result.answers_compared} answers · {result.method === 'lexical' ? 'wording only' : 'wording + meaning'}</p>
          {result.semantic_unavailable && <WarnNote>The meaning model was unavailable, so only shared wording was compared.</WarnNote>}
          {result.too_short.length > 0 && <WarnNote>Skipped (under {result.min_words} words): {result.too_short.join(', ')}</WarnNote>}
          {shown.length === 0 ? <OkNote text="No pair looks unusually similar." /> : (
            <ul className="space-y-2">
              {shown.map((p) => (
                <li key={`${p.a}|${p.b}`} className={cn('rounded-lg border bg-white p-3', p.flagged ? 'border-[#FECACA]' : 'border-sage-200')}>
                  <div className="flex items-center justify-between gap-2 text-sm">
                    <span className="font-medium text-sage-800">{p.a} ↔ {p.b}</span>
                    <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-medium', p.flagged ? 'bg-[#FEF2F2] text-[#991B1B]' : 'bg-sage-100 text-sage-700')}>{Math.round(p.combined_score * 100)}%{p.flagged ? ' · review' : ''}</span>
                  </div>
                  <div className="mt-1.5 h-1.5 rounded-full bg-sage-100"><div className={cn('h-1.5 rounded-full', p.flagged ? 'bg-[#DC2626]' : 'bg-sage-500')} style={{ width: `${Math.round(p.combined_score * 100)}%` }} /></div>
                  <p className="mt-1 text-[11px] text-sage-500">Wording {Math.round(p.lexical_score * 100)}%{p.semantic_score !== null ? ` · meaning ${Math.round(p.semantic_score * 100)}%` : ''}</p>
                  {p.shared_excerpt && <p className="mt-1 text-xs italic text-sage-600">Shared: “{p.shared_excerpt}”</p>}
                </li>
              ))}
            </ul>
          )}
          <p className="text-[11px] text-sage-500">{result.note}</p>
        </>}
      </div>
    </form>
  );
};

// ------------------------------------------------------------------------------------------------- paper review

/** One question per line; marks may end the line as "[5]", "(5)" or "(5 marks)". */
export const parsePaper = (raw: string): PaperQuestionInput[] =>
  raw.split('\n').map((line) => line.trim()).filter(Boolean).slice(0, 60).map((line) => {
    const m = line.match(/\s*[[(]\s*(\d+(?:\.\d+)?)\s*(?:marks?|pts?|points?)?\s*[\])]\s*$/i);
    return m ? { text: line.slice(0, m.index).trim(), marks: Number(m[1]) } : { text: line, marks: null };
  });

const BAND_BAR: Record<string, string> = { ok: 'bg-sage-600', low: 'bg-[#D97706]', high: 'bg-[#DC2626]' };
const FINDING_CLS: Record<string, string> = {
  high: 'border-[#FECACA] bg-[#FEF2F2] text-[#991B1B]',
  medium: 'border-[#FDE68A] bg-[#FFFBEB] text-[#92400E]',
  low: 'border-sage-200 bg-white text-sage-700',
};

export const PaperReviewPanel: React.FC = () => {
  const [source, setSource] = useState<'paste' | 'assessment'>('paste');
  const [raw, setRaw] = useState('');
  const [topics, setTopics] = useState('');
  const [assessments, setAssessments] = useState<{ id: number; title: string; course: string }[]>([]);
  const [assessmentId, setAssessmentId] = useState<number | ''>('');
  const [result, setResult] = useState<PaperReview | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const questions = parsePaper(raw);
  const topicList = topics.split('\n').map((t) => t.trim()).filter(Boolean).slice(0, 30);

  useEffect(() => {
    if (source !== 'assessment' || assessments.length) return;
    apiClient<{ data: { id: number; title: string; course?: { course_code?: string } }[] }>('/assessments')
      .then((r) => setAssessments((r.data ?? []).map((a) => ({ id: Number(a.id), title: a.title, course: a.course?.course_code ?? '' }))))
      .catch(() => setError('Could not load your assessments.'));
  }, [source, assessments.length]);

  const run = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true); setError(null);
    try {
      setResult(await aiToolkitService.paperReview(source === 'assessment'
        ? { assessment_id: Number(assessmentId), ...(topicList.length ? { topics: topicList } : {}) }
        : { questions, topics: topicList }));
    } catch (err) { setError(err instanceof Error ? err.message : 'Could not review the paper.'); } finally { setBusy(false); }
  };
  const ready = source === 'assessment' ? assessmentId !== '' : questions.length >= 2;

  return (
    <form onSubmit={run} className="grid gap-4 xl:grid-cols-[minmax(0,2fr),minmax(0,3fr)]" data-testid="paper-review-panel">
      <div className={cn(card, 'space-y-3')}>
        <div role="radiogroup" aria-label="Paper source" className="inline-flex rounded-lg border border-sage-200 p-0.5 text-xs">
          {(['paste', 'assessment'] as const).map((s) => (
            <button key={s} type="button" role="radio" aria-checked={source === s} onClick={() => setSource(s)}
              className={cn('rounded-md px-3 py-1', source === s ? 'bg-sage-700 text-white' : 'text-sage-700 hover:bg-sage-100')}>
              {s === 'paste' ? 'Paste questions' : 'From an assessment'}
            </button>
          ))}
        </div>
        {source === 'paste' ? (
          <div>
            <label htmlFor="tk-paper-q" className="text-sm font-semibold text-sage-800">Questions</label>
            <p className="text-[11px] text-sage-500">One per line. End a line with marks in brackets, e.g. <code>[10]</code>, to weight by marks.</p>
            <textarea id="tk-paper-q" rows={9} value={raw} onChange={(e) => setRaw(e.target.value)} className={cn(fieldCls, 'mt-1 text-xs')}
              placeholder={'1. Define a graph. [5]\n2. Explain how breadth-first search works. [10]\n3. Compare BFS and DFS. [10]'} />
            <p className="mt-1 text-[11px] text-sage-500">{questions.length} question{questions.length === 1 ? '' : 's'}</p>
          </div>
        ) : (
          <div>
            <label htmlFor="tk-paper-a" className="text-sm font-semibold text-sage-800">Assessment</label>
            <select id="tk-paper-a" value={assessmentId} onChange={(e) => setAssessmentId(e.target.value ? Number(e.target.value) : '')} className={cn(fieldCls, 'mt-1')}>
              <option value="">Choose an assessment…</option>
              {assessments.map((a) => <option key={a.id} value={a.id}>{a.course ? `${a.course} · ` : ''}{a.title}</option>)}
            </select>
            <p className="mt-1 text-[11px] text-sage-500">Uses its questions, marks and any Bloom levels you set. Leave topics empty to check coverage of the course outcomes.</p>
          </div>
        )}
        <div>
          <label htmlFor="tk-paper-t" className="text-sm font-semibold text-sage-800">Topics or outcomes to cover <span className="font-normal text-sage-500">(optional)</span></label>
          <textarea id="tk-paper-t" rows={4} value={topics} onChange={(e) => setTopics(e.target.value)} className={cn(fieldCls, 'mt-1 text-xs')} placeholder={'Graph traversal\nShortest path algorithms\nHashing'} />
        </div>
        <div className="flex justify-end">
          <Button type="submit" isLoading={busy} disabled={!ready} leftIcon={<FileSearch className="h-4 w-4" />}>Review paper</Button>
        </div>
        <ErrorNote text={error} />
      </div>

      <div className={cn(card, 'space-y-4 bg-sage-50')} data-testid="paper-review-result">
        {!result ? <p className="py-10 text-center text-sm text-sage-500">Bloom balance, topic coverage and duplicate questions appear here.</p> : <>
          <section>
            <h4 className="text-sm font-semibold text-sage-800">Findings</h4>
            {result.findings.length === 0 ? <OkNote text="No problems found: balanced across Bloom levels, no duplicates, every topic covered." /> : (
              <ul className="mt-1.5 space-y-1.5">{result.findings.map((f) => <li key={f.message} className={cn('rounded-lg border px-3 py-1.5 text-xs', FINDING_CLS[f.severity])}>{f.message}</li>)}</ul>
            )}
          </section>

          <section>
            <h4 className="text-sm font-semibold text-sage-800">Bloom balance <span className="font-normal text-sage-500">(share of {result.weighted_by})</span></h4>
            <ul className="mt-1.5 space-y-2">
              {(['lower', 'middle', 'higher'] as const).map((band) => {
                const b = result.bloom.bands[band];
                return (
                  <li key={band}>
                    <div className="flex justify-between text-xs text-sage-700"><span>{b.label}</span><span>{b.share}% <span className="text-sage-500">(target {b.target[0]}–{b.target[1]}%)</span></span></div>
                    <div className="relative mt-1 h-2 rounded-full bg-sage-100">
                      <div className="absolute inset-y-0 rounded-full bg-sage-200" style={{ left: `${b.target[0]}%`, width: `${b.target[1] - b.target[0]}%` }} aria-hidden="true" />
                      <div className={cn('relative h-2 rounded-full', BAND_BAR[b.status])} style={{ width: `${Math.min(100, b.share)}%` }} />
                    </div>
                  </li>
                );
              })}
            </ul>
          </section>

          {result.topics && (
            <section>
              <h4 className="text-sm font-semibold text-sage-800">Coverage {result.topics_source === 'course_outcomes' && <span className="font-normal text-sage-500">(course outcomes)</span>}</h4>
              <ul className="mt-1.5 space-y-1 text-xs">
                {result.topics.map((t) => (
                  <li key={t.topic} className="flex items-start justify-between gap-2">
                    <span className="flex items-start gap-1.5">{t.covered ? <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-sage-600" aria-label="Covered" /> : <X className="mt-0.5 h-3.5 w-3.5 shrink-0 text-[#DC2626]" aria-label="Not covered" />}{t.topic}</span>
                    <span className="shrink-0 text-sage-500">{t.covered ? `Q${t.questions.join(', Q')} · ${t.share}%` : 'not covered'}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}

          <section>
            <h4 className="text-sm font-semibold text-sage-800">Questions</h4>
            <ul className="mt-1.5 divide-y divide-sage-100 rounded-lg border border-sage-200 bg-white text-xs">
              {result.questions.map((q) => {
                const dup = result.duplicates.filter((d) => d.a === q.number || d.b === q.number);
                return (
                  <li key={q.number} className="px-3 py-2">
                    <div className="flex items-start justify-between gap-2">
                      <span className="text-sage-800"><span className="font-medium">Q{q.number}.</span> {q.text}</span>
                      <span className="shrink-0 text-sage-500">{levelLabel(q.bloom_level)}{q.bloom_source === 'detected' ? '*' : ''}{q.marks !== null ? ` · ${q.marks}` : ''}</span>
                    </div>
                    {dup.map((d) => <p key={`${d.a}-${d.b}`} className="mt-0.5 text-[#92400E]">{d.kind === 'duplicate' ? 'Duplicate of' : 'Overlaps with'} Q{d.a === q.number ? d.b : d.a}</p>)}
                    {q.issues.map((i) => <p key={i.code} className="mt-0.5 text-sage-500">{i.message}</p>)}
                  </li>
                );
              })}
            </ul>
            <p className="mt-1 text-[11px] text-sage-500">* Bloom level detected from the question verb. {result.note}</p>
          </section>
        </>}
      </div>
    </form>
  );
};
