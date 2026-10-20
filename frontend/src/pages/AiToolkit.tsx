import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ArrowLeftRight, AudioLines, BookOpenCheck, ClipboardCheck, Copy, Download, FileSearch, FileText, Languages, Layers, Link2, Loader2, MessageSquareText, NotebookPen, ScanSearch, PenLine, Sparkles, Trash2, Volume2, Wand2 } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { aiToolkitService, type ToolkitJob, type ToolkitJobDetail, type ToolkitLanguage, type ToolkitOverview, type TranslationResult } from '@/services/aiToolkitService';
import { cn } from '@/utils/cn';
import { AttachDialog, BloomPanel, CompareAnswersPanel, FeedbackDrafterPanel, ListenButton, PaperReviewPanel, QuestionCheckerPanel, SimplifyPanel, SpeechPanel, StudyPackForm, StudyPackResult } from '@/components/aiToolkit/ToolPanels';

type Tab = 'translate' | 'speech' | 'notes' | 'checker' | 'simplify' | 'bloom' | 'paper' | 'feedback' | 'compare' | 'ocr' | 'transcribe';

const LANG: Record<ToolkitLanguage, string> = { en: 'English', bn: 'বাংলা (Bangla)' };

const CopyButton: React.FC<{ text: string; label: string }> = ({ text, label }) => {
  const [done, setDone] = useState(false);
  return (
    <button type="button" onClick={async () => { try { await navigator.clipboard.writeText(text); setDone(true); window.setTimeout(() => setDone(false), 1500); } catch { /* clipboard blocked */ } }}
      className="inline-flex items-center gap-1 rounded-md border border-sage-200 px-2 py-0.5 text-[11px] text-sage-700 hover:bg-sage-100" aria-label={`Copy ${label}`}>
      <Copy className="w-3 h-3" aria-hidden="true" />{done ? 'Copied' : 'Copy'}
    </button>
  );
};

const fmtSize = (b: number) => (b < 1024 * 1024 ? `${Math.max(1, Math.round(b / 1024))} KB` : `${(b / (1024 * 1024)).toFixed(1)} MB`);
const fmtTime = (s: number) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

const StatusChip: React.FC<{ status: ToolkitJob['status'] }> = ({ status }) => (
  <span className={cn('inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-[11px] font-medium',
    status === 'completed' && 'border-[#BBF7D0] bg-[#F0FDF4] text-[#166534]',
    status === 'failed' && 'border-[#FECACA] bg-[#FEF2F2] text-[#991B1B]',
    (status === 'queued' || status === 'processing') && 'border-[#FDE68A] bg-[#FFFBEB] text-[#92400E]')}>
    {(status === 'queued' || status === 'processing') && <Loader2 className="w-3 h-3 animate-spin" aria-hidden="true" />}
    {status === 'queued' ? 'Waiting' : status === 'processing' ? 'Working' : status === 'completed' ? 'Ready' : 'Failed'}
  </span>
);

// ------------------------------------------------------------------------------------------------- translate

const TranslatePanel: React.FC<{ limit: number }> = ({ limit }) => {
  const [source, setSource] = useState<ToolkitLanguage>('en');
  const [text, setText] = useState('');
  const [result, setResult] = useState<TranslationResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const target: ToolkitLanguage = source === 'en' ? 'bn' : 'en';

  const run = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      setResult(await aiToolkitService.translate(text, source, target));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Translation failed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={run} className="grid gap-4 lg:grid-cols-2" data-testid="translate-panel">
      <div className="space-y-2 rounded-xl border border-sage-200 bg-white p-4 shadow-subtle">
        <div className="flex items-center justify-between gap-2">
          <label htmlFor="tk-source-text" className="text-sm font-semibold text-sage-800">{LANG[source]}</label>
          <button type="button" onClick={() => { setSource(target); if (result) { setText(result.text); setResult(null); } }}
            className="inline-flex items-center gap-1 rounded-md border border-sage-200 px-2 py-1 text-xs text-sage-700 hover:bg-sage-100" aria-label="Swap languages">
            <ArrowLeftRight className="w-3.5 h-3.5" aria-hidden="true" />Swap
          </button>
        </div>
        <textarea id="tk-source-text" rows={10} value={text} maxLength={limit} onChange={(e) => setText(e.target.value)} lang={source}
          placeholder={source === 'en' ? 'Paste a question, feedback or a paragraph of course material…' : 'প্রশ্ন, মন্তব্য বা পাঠ্যাংশ এখানে লিখুন…'}
          className="w-full rounded-lg border border-sage-200 bg-white px-3 py-2 text-sm text-sage-800 focus:border-sage-500 focus:outline-none focus:ring-2 focus:ring-sage-300/40" />
        <div className="flex items-center justify-between text-[11px] text-sage-500">
          <span className="tabular-nums">{text.length} / {limit}</span>
          <Button type="submit" size="sm" isLoading={busy} disabled={!text.trim()} leftIcon={<Languages className="w-3.5 h-3.5" />}>Translate to {target === 'bn' ? 'Bangla' : 'English'}</Button>
        </div>
      </div>
      <div className="space-y-2 rounded-xl border border-sage-200 bg-sage-50 p-4">
        <div className="flex items-center justify-between gap-2">
          <p className="text-sm font-semibold text-sage-800">{LANG[target]}</p>
          {result && <CopyButton text={result.text} label="translation" />}
        </div>
        {error && <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] px-3 py-2 text-xs text-[#991B1B]">{error}</p>}
        {busy ? <p className="flex items-center gap-2 py-8 text-sm text-sage-500"><Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" />Translating… the first request loads the model and can take a minute.</p>
          : result ? <p className="whitespace-pre-wrap text-sm leading-relaxed text-sage-800" lang={target} data-testid="translation-output">{result.text}</p>
            : <p className="py-8 text-sm text-sage-500">The translation appears here.</p>}
        {result && (
          <p className="text-[11px] text-sage-500">
            <span className="inline-block align-middle"><ListenButton text={result.text} language={target} /></span>
          </p>
        )}
      </div>
    </form>
  );
};

// ------------------------------------------------------------------------------------------------- uploads

const UploadPanel: React.FC<{ tool: 'ocr' | 'transcribe'; maxMb: number; onQueued: () => void }> = ({ tool, maxMb, onQueued }) => {
  const [file, setFile] = useState<File | null>(null);
  const [language, setLanguage] = useState<'' | ToolkitLanguage>('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null);
  const input = useRef<HTMLInputElement>(null);
  const isOcr = tool === 'ocr';

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!file) return;
    if (file.size > maxMb * 1024 * 1024) { setMessage({ tone: 'error', text: `The file is larger than ${maxMb} MB.` }); return; }
    setBusy(true);
    setMessage(null);
    try {
      const res = isOcr ? await aiToolkitService.ocr(file) : await aiToolkitService.transcribe(file, language || undefined);
      setMessage({ tone: 'ok', text: res.message ?? 'Started.' });
      setFile(null);
      if (input.current) input.current.value = '';
      onQueued();
    } catch (err) {
      setMessage({ tone: 'error', text: err instanceof Error ? err.message : 'Upload failed.' });
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="space-y-3 rounded-xl border border-sage-200 bg-white p-4 shadow-subtle" data-testid={`${tool}-form`}>
      <p className="text-sm text-sage-600">
        {isOcr
          ? 'Upload a photo or scanned PDF of a handwritten answer script. The text comes back as a draft: check it against the script before using it for grading.'
          : 'Upload a lecture recording. You get a timestamped transcript you can copy into notes, the question generator or document chat. Long recordings take several minutes.'}
      </p>
      <label className="block text-xs font-medium text-sage-700">
        {isOcr ? `Image (JPG, PNG, WEBP) or PDF up to 10 pages · max ${maxMb} MB` : `Audio or video (MP3, WAV, M4A, OGG, WEBM, MP4) · max ${maxMb} MB, up to 90 minutes`}
        <input ref={input} type="file" accept={isOcr ? '.jpg,.jpeg,.png,.webp,.pdf' : '.mp3,.wav,.m4a,.ogg,.oga,.webm,.mp4,.aac,.flac'} onChange={(e) => setFile(e.target.files?.[0] ?? null)}
          className="mt-1 block w-full" data-testid={`${tool}-file`} />
      </label>
      {!isOcr && (
        <label className="block text-xs font-medium text-sage-700">Spoken language
          <select value={language} onChange={(e) => setLanguage(e.target.value as '' | ToolkitLanguage)} className="mt-1 block h-9 w-full rounded-lg border border-sage-200 bg-white px-2 text-sm">
            <option value="">Detect automatically</option><option value="en">English</option><option value="bn">Bangla</option>
          </select>
        </label>
      )}
      {message && <p role={message.tone === 'error' ? 'alert' : 'status'} className={cn('rounded-lg border px-3 py-2 text-xs', message.tone === 'ok' ? 'border-[#BBF7D0] bg-[#F0FDF4] text-[#166534]' : 'border-[#FECACA] bg-[#FEF2F2] text-[#991B1B]')}>{message.text}</p>}
      <Button type="submit" isLoading={busy} disabled={!file} leftIcon={isOcr ? <PenLine className="w-4 h-4" /> : <AudioLines className="w-4 h-4" />}>
        {isOcr ? 'Read handwriting' : 'Transcribe recording'}
      </Button>
    </form>
  );
};

const JobResult: React.FC<{ job: ToolkitJobDetail; onMakeNotes?: () => void; onAttach?: () => void }> = ({ job, onMakeNotes, onAttach }) => (
  <div className="space-y-3 rounded-xl border border-sage-200 bg-white p-4 shadow-subtle" data-testid="job-result">
    <div className="flex flex-wrap items-center justify-between gap-2">
      <div className="min-w-0">
        <p className="truncate text-sm font-semibold text-sage-800">{job.input_filename}</p>
        <p className="text-[11px] text-sage-500">{job.provider === 'hosted' ? 'Hosted model · ' : ''}<span className="font-mono">{job.model}</span>{job.duration_seconds ? ` · ${fmtTime(job.duration_seconds)} of audio` : ''}{job.line_count !== null ? ` · ${job.line_count} line(s)` : ''}</p>
        {job.fallback_reason && <p className="text-[11px] text-sage-500" data-testid="job-fallback">Read with the local model ({job.fallback_reason}).</p>}
      </div>
      <div className="flex flex-wrap items-center gap-1.5">
        {job.text && <CopyButton text={job.text} label="text" />}
        <a href={aiToolkitService.downloadUrl(job.id)} className="inline-flex items-center gap-1 rounded-md border border-sage-200 px-2 py-0.5 text-[11px] text-sage-700 hover:bg-sage-100"><Download className="w-3 h-3" aria-hidden="true" />.txt</a>
      </div>
    </div>
    {(onMakeNotes || onAttach) && (
      <div className="flex flex-wrap gap-2">
        {onMakeNotes && <Button size="sm" variant="outline" onClick={onMakeNotes} leftIcon={<NotebookPen className="w-3.5 h-3.5" />}>Make notes & quiz from this</Button>}
        {onAttach && <Button size="sm" variant="outline" onClick={onAttach} leftIcon={<Link2 className="w-3.5 h-3.5" />}>Attach to a student's answer</Button>}
      </div>
    )}
    {job.note && <p className="rounded-lg border border-[#FDE68A] bg-[#FFFBEB] px-3 py-2 text-xs text-[#92400E]">{job.note}</p>}
    {job.segments.length > 0 ? (
      <ol className="max-h-[28rem] space-y-1.5 overflow-y-auto text-sm text-sage-800">
        {job.segments.map((s, i) => <li key={i} className="flex gap-3"><span className="w-12 shrink-0 font-mono text-[11px] text-sage-400 tabular-nums">{fmtTime(s.start)}</span><span>{s.text}</span></li>)}
      </ol>
    ) : (
      <p className="max-h-[28rem] overflow-y-auto whitespace-pre-wrap text-sm leading-relaxed text-sage-800">{job.text || 'No text was recognised.'}</p>
    )}
  </div>
);

// ------------------------------------------------------------------------------------------------- page

/** Recent-jobs list for the queued tools (OCR, transcription, notes & quiz). */
const JobList: React.FC<{ jobs: ToolkitJob[]; onOpen: (id: number) => void; onRemove: (id: number) => void }> = ({ jobs, onOpen, onRemove }) => (
  <section className="rounded-xl border border-sage-200 bg-white p-4 shadow-subtle">
    <h3 className="text-sm font-semibold text-sage-800">Recent</h3>
    {jobs.length === 0 ? <p className="mt-1 text-xs text-sage-500">Nothing yet.</p> : (
      <ul className="mt-2 divide-y divide-sage-100" data-testid="toolkit-jobs">
        {jobs.map((j) => (
          <li key={j.id} className="flex items-center justify-between gap-2 py-2">
            <button type="button" disabled={j.status !== 'completed'} onClick={() => onOpen(j.id)} className="min-w-0 text-left disabled:cursor-default">
              <span className="flex items-center gap-1.5 truncate text-sm text-sage-800"><FileText className="w-3.5 h-3.5 shrink-0 text-sage-400" aria-hidden="true" />{j.input_filename}</span>
              <span className="block text-[11px] text-sage-500">{fmtSize(j.input_bytes)}{j.error ? ` · ${j.error}` : ''}</span>
            </button>
            <span className="flex shrink-0 items-center gap-1.5">
              <StatusChip status={j.status} />
              {j.status !== 'processing' && <button type="button" onClick={() => onRemove(j.id)} className="rounded p-1 text-sage-400 hover:bg-sage-100 hover:text-sage-700" aria-label={`Delete ${j.input_filename}`}><Trash2 className="w-3.5 h-3.5" /></button>}
            </span>
          </li>
        ))}
      </ul>
    )}
  </section>
);

/** /ai-toolkit — Hugging Face tools for teaching: language, speech, notes & quiz, question writing, OCR and transcription. */
export const AiToolkit: React.FC = () => {
  const [overview, setOverview] = useState<ToolkitOverview | null>(null);
  const [tab, setTab] = useState<Tab>('translate');
  const [open, setOpen] = useState<ToolkitJobDetail | null>(null);
  const [attaching, setAttaching] = useState<ToolkitJobDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(() => aiToolkitService.overview().then((o) => { setOverview(o); setError(null); }).catch((e) => setError(e instanceof Error ? e.message : 'Could not load the toolkit.')), []);
  useEffect(() => { void load(); }, [load]);

  // Poll while any job is still running
  const running = overview?.jobs.some((j) => j.status === 'queued' || j.status === 'processing');
  useEffect(() => {
    if (!running) return;
    const t = window.setInterval(() => { void load(); }, 5000);
    return () => window.clearInterval(t);
  }, [running, load]);

  const openJob = async (id: number) => { try { setOpen(await aiToolkitService.job(id)); } catch { /* ignore */ } };
  const removeJob = async (id: number) => { await aiToolkitService.remove(id).catch(() => undefined); if (open?.id === id) setOpen(null); void load(); };
  const notesFromTranscript = async (job: ToolkitJobDetail) => {
    try {
      const r = await aiToolkitService.studyPack({ source_job_id: job.id });
      setNotice(r.message ?? 'Building notes and quiz.');
      setTab('notes');
      setOpen(null);
      void load();
    } catch (e) { setNotice(e instanceof Error ? e.message : 'Could not start.'); }
  };

  const tools = overview?.tools;
  const tabs: { key: Tab; label: string; icon: React.ElementType; enabled: boolean }[] = [
    { key: 'translate', label: 'Translate', icon: Languages, enabled: tools?.translation ?? true },
    { key: 'speech', label: 'Read aloud', icon: Volume2, enabled: tools?.speech ?? true },
    { key: 'notes', label: 'Notes & quiz', icon: NotebookPen, enabled: tools?.study_pack ?? true },
    { key: 'checker', label: 'Question checker', icon: ClipboardCheck, enabled: tools?.writing ?? true },
    { key: 'simplify', label: 'Simplify', icon: Sparkles, enabled: tools?.writing ?? true },
    { key: 'bloom', label: 'Bloom rewriter', icon: Layers, enabled: tools?.writing ?? true },
    { key: 'paper', label: 'Paper review', icon: FileSearch, enabled: tools?.paper_review ?? true },
    { key: 'feedback', label: 'Feedback drafter', icon: MessageSquareText, enabled: tools?.feedback ?? true },
    { key: 'compare', label: 'Compare answers', icon: ScanSearch, enabled: tools?.compare ?? true },
    { key: 'ocr', label: 'Handwriting OCR', icon: PenLine, enabled: tools?.ocr ?? true },
    { key: 'transcribe', label: 'Lecture transcription', icon: AudioLines, enabled: tools?.transcription ?? true },
  ];
  const current = tabs.find((t) => t.key === tab)!;
  const toolOfTab: Partial<Record<Tab, ToolkitJob['tool']>> = { ocr: 'ocr', transcribe: 'transcription', notes: 'study_pack' };
  const jobs = (overview?.jobs ?? []).filter((j) => j.tool === toolOfTab[tab]);

  const renderTab = () => {
    if (!current.enabled) return <p className="rounded-xl border border-sage-200 bg-white p-6 text-sm text-sage-600">{current.label} is currently turned off by the administrator.</p>;
    switch (tab) {
      case 'translate': return <TranslatePanel limit={overview?.limits.translation_chars ?? 5000} />;
      case 'speech': return <SpeechPanel />;
      case 'checker': return <QuestionCheckerPanel />;
      case 'simplify': return <SimplifyPanel />;
      case 'bloom': return <BloomPanel />;
      case 'paper': return <PaperReviewPanel />;
      case 'feedback': return <FeedbackDrafterPanel />;
      case 'compare': return <CompareAnswersPanel />;
      default: return (
        <div className="grid gap-5 xl:grid-cols-[360px,1fr]">
          <aside className="space-y-4">
            {tab === 'notes'
              ? <StudyPackForm onQueued={() => { void load(); }} />
              : <UploadPanel tool={tab as 'ocr' | 'transcribe'} maxMb={tab === 'ocr' ? overview?.limits.ocr_mb ?? 20 : overview?.limits.transcription_mb ?? 100} onQueued={() => { void load(); }} />}
            <JobList jobs={jobs} onOpen={(id) => { void openJob(id); }} onRemove={(id) => { void removeJob(id); }} />
          </aside>
          <div>
            {!open || open.tool !== toolOfTab[tab]
              ? <p className="rounded-xl border border-dashed border-sage-300 bg-white/60 p-10 text-center text-sm text-sage-500">Open a finished item to see its result.</p>
              : open.tool === 'study_pack'
                ? <StudyPackResult job={open} />
                : <JobResult job={open}
                    onMakeNotes={open.tool === 'transcription' && tools?.study_pack ? () => { void notesFromTranscript(open); } : undefined}
                    onAttach={open.tool === 'ocr' ? () => setAttaching(open) : undefined} />}
          </div>
        </div>
      );
    }
  };

  return (
    <div className="space-y-5 page-enter" data-testid="ai-toolkit-page">
      <div className="flex items-start gap-3">
        <span className="w-10 h-10 rounded-xl bg-white border border-sage-200 flex items-center justify-center text-sage-800"><Wand2 className="w-5 h-5" aria-hidden="true" strokeWidth={1.8} /></span>
        <div>
          <h2 className="text-xl font-semibold tracking-tight text-sage-800">AI Toolkit</h2>
          <p className="text-sm text-sage-500">Translate, listen, turn lectures into notes and quizzes, improve questions, review whole papers, draft feedback, compare answers, read handwriting and transcribe recordings. Everything here is a draft for you to check — AI assists, you decide.</p>
        </div>
      </div>

      {error && <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] px-3 py-2 text-sm text-[#991B1B]">{error}</p>}
      {notice && (
        <p role="status" className="flex items-center justify-between gap-2 rounded-lg border border-sage-200 bg-white px-3 py-2 text-sm text-sage-700">
          <span className="flex items-center gap-1.5"><BookOpenCheck className="w-4 h-4" aria-hidden="true" />{notice}</span>
          <button type="button" className="text-xs underline" onClick={() => setNotice(null)}>Dismiss</button>
        </p>
      )}

      <div role="tablist" aria-label="AI toolkit tools" className="flex gap-1 overflow-x-auto rounded-xl border border-sage-200 bg-white p-1">
        {tabs.map((t) => (
          <button key={t.key} type="button" role="tab" aria-selected={tab === t.key} onClick={() => { setTab(t.key); setOpen(null); }}
            className={cn('inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium', tab === t.key ? 'bg-sage-700 text-white' : 'text-sage-700 hover:bg-sage-100')}>
            <t.icon className="w-4 h-4" aria-hidden="true" />{t.label}{!t.enabled && <span className="text-[10px] opacity-80">(off)</span>}
          </button>
        ))}
      </div>

      {renderTab()}
      {attaching && <AttachDialog job={attaching} onClose={() => setAttaching(null)} />}
    </div>
  );
};
