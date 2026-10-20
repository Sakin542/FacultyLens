import React, { useEffect, useRef, useState } from 'react';
import { FileSearch, Upload, Copy, Trash2, Quote, Lightbulb, FlaskConical, AlertTriangle, BookMarked } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { courseDesignService } from '@/services/courseDesignService';
import type { PaperSummaryItem } from '@/types/courseDesign';
import { cn } from '@/utils/cn';

const CopyButton: React.FC<{ text: string; label: string }> = ({ text, label }) => {
  const [done, setDone] = useState(false);
  return (
    <button type="button" onClick={async () => { try { await navigator.clipboard.writeText(text); setDone(true); window.setTimeout(() => setDone(false), 1500); } catch { /* clipboard blocked */ } }}
      className="inline-flex items-center gap-1 rounded-md border border-sage-200 px-2 py-0.5 text-[11px] text-sage-700 hover:bg-sage-100" aria-label={`Copy ${label}`}>
      <Copy className="w-3 h-3" aria-hidden="true" />{done ? 'Copied' : 'Copy'}
    </button>
  );
};

const Section: React.FC<{ title: string; icon: React.ElementType; items: string[]; empty: string }> = ({ title, icon: Icon, items, empty }) => (
  <section className="rounded-xl border border-sage-200 bg-white p-4">
    <h4 className="flex items-center gap-1.5 text-sm font-semibold text-sage-800"><Icon className="w-4 h-4 text-sage-500" aria-hidden="true" />{title}</h4>
    {items.length === 0 ? <p className="mt-1 text-xs text-sage-500">{empty}</p> : <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-sage-700">{items.map((s) => <li key={s}>{s}</li>)}</ul>}
  </section>
);

/** /research — summarize research papers: summary, findings, methods, limitations, citation and key references. */
export const Research: React.FC = () => {
  const [items, setItems] = useState<PaperSummaryItem[]>([]);
  const [active, setActive] = useState<PaperSummaryItem | null>(null);
  const [mode, setMode] = useState<'file' | 'text'>('file');
  const [file, setFile] = useState<File | null>(null);
  const [text, setText] = useState('');
  const [title, setTitle] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const load = () => courseDesignService.summaries().then(setItems).catch(() => setItems([]));
  useEffect(() => { void load(); }, []);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (mode === 'file' && !file) { setError('Choose a PDF, Word or text file.'); return; }
    if (mode === 'text' && text.trim().split(/\s+/).length < 80) { setError('Paste at least a few paragraphs (80+ words).'); return; }
    setBusy(true);
    setError(null);
    try {
      const res = await courseDesignService.summarize(mode === 'file' ? { file: file!, title } : { text, title });
      setActive(res.data);
      setFile(null);
      setText('');
      setTitle('');
      if (fileRef.current) fileRef.current.value = '';
      void load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The paper could not be summarized.');
    } finally {
      setBusy(false);
    }
  };

  const s = active?.summary;

  return (
    <div className="space-y-5 page-enter" data-testid="research-page">
      <div className="flex items-start gap-3">
        <span className="w-10 h-10 rounded-xl bg-white border border-sage-200 flex items-center justify-center text-sage-800"><FileSearch className="w-5 h-5" aria-hidden="true" strokeWidth={1.8} /></span>
        <div>
          <h2 className="text-xl font-semibold tracking-tight text-sage-800">Research papers</h2>
          <p className="text-sm text-sage-500">Upload a paper to get a summary, its key findings, methods and limitations, a ready-made citation and the references it relies on most. Every line is quoted from the paper; the file itself is not stored.</p>
        </div>
      </div>

      <div className="grid gap-5 xl:grid-cols-[340px,1fr]">
        <aside className="space-y-4">
          <form onSubmit={submit} className="space-y-3 rounded-xl border border-sage-200 bg-white p-4 shadow-subtle" data-testid="summarize-form">
            <div role="tablist" className="grid grid-cols-2 rounded-lg border border-sage-200 p-0.5 text-xs">
              {(['file', 'text'] as const).map((m) => <button key={m} type="button" role="tab" aria-selected={mode === m} onClick={() => setMode(m)} className={cn('rounded-md px-2 py-1.5 font-medium', mode === m ? 'bg-sage-700 text-white' : 'text-sage-700')}>{m === 'file' ? 'Upload file' : 'Paste text'}</button>)}
            </div>
            {mode === 'file' ? (
              <label className="block text-xs font-medium text-sage-700">PDF, DOCX or TXT (max 20 MB)
                <input ref={fileRef} type="file" accept=".pdf,.docx,.txt" onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="mt-1 block w-full text-xs text-sage-700 file:mr-2 file:rounded-md file:border-0 file:bg-sage-100 file:px-3 file:py-1.5 file:text-sage-800" data-testid="paper-file" />
              </label>
            ) : (
              <label className="block text-xs font-medium text-sage-700">Paper text<textarea rows={6} value={text} onChange={(e) => setText(e.target.value)} className="mt-1 w-full rounded-lg border border-sage-200 p-2 text-xs" placeholder="Paste the abstract and body…" data-testid="paper-text" /></label>
            )}
            <label className="block text-xs font-medium text-sage-700">Title (optional)<input value={title} onChange={(e) => setTitle(e.target.value)} className="mt-1 w-full rounded-lg border border-sage-200 px-3 py-2 text-sm" placeholder="Detected automatically" /></label>
            {error && <p role="alert" className="text-xs text-[#991B1B]">{error}</p>}
            <Button type="submit" isLoading={busy} leftIcon={<Upload className="w-4 h-4" />} className="w-full justify-center" data-testid="summarize-submit">{busy ? 'Reading the paper…' : 'Summarize'}</Button>
          </form>

          <section className="rounded-xl border border-sage-200 bg-white shadow-subtle" aria-label="Previous summaries">
            <h3 className="border-b border-sage-200 px-4 py-2.5 text-sm font-semibold text-sage-800">Your summaries</h3>
            {items.length === 0 ? <p className="p-4 text-xs text-sage-500">None yet.</p> : (
              <ul className="max-h-[420px] divide-y divide-sage-100 overflow-y-auto" data-testid="summary-list">
                {items.map((i) => (
                  <li key={i.id}>
                    <button type="button" onClick={() => courseDesignService.summary(i.id).then(setActive)} className={cn('w-full px-4 py-2.5 text-left hover:bg-sage-50', active?.id === i.id && 'bg-sage-100')}>
                      <span className="block truncate text-sm text-sage-800">{i.title ?? i.file_name ?? 'Untitled'}</span>
                      <span className="text-[11px] text-sage-500">{i.authors.slice(0, 2).join(', ')}{i.authors.length > 2 ? ' et al.' : ''}{i.year ? ` · ${i.year}` : ''} · {i.word_count.toLocaleString()} words</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </aside>

        <div className="space-y-4" data-testid="summary-view">
          {!s ? (
            <p className="rounded-xl border border-dashed border-sage-300 p-10 text-center text-sm text-sage-500">Upload a paper or pick a previous summary.</p>
          ) : (
            <>
              <section className="rounded-xl border border-sage-200 bg-white p-5 shadow-subtle">
                <div className="flex items-start justify-between gap-3">
                  <div>
                    <h3 className="text-lg font-semibold text-sage-800">{s.title ?? active?.file_name}</h3>
                    <p className="text-sm text-sage-500">{s.authors.join(', ') || 'Authors not detected'}{s.year ? ` · ${s.year}` : ''}{s.doi ? ` · doi:${s.doi}` : ''}</p>
                    {s.keywords.length > 0 && <p className="mt-1 flex flex-wrap gap-1">{s.keywords.map((k) => <span key={k} className="rounded-md bg-sage-100 px-1.5 py-0.5 text-[11px] text-sage-700">{k}</span>)}</p>}
                  </div>
                  <button type="button" onClick={async () => { if (active && window.confirm('Delete this summary?')) { await courseDesignService.deleteSummary(active.id); setActive(null); void load(); } }} className="rounded-md p-1.5 text-[#991B1B] hover:bg-[#FEF2F2]" aria-label="Delete summary"><Trash2 className="w-4 h-4" /></button>
                </div>
                <h4 className="mt-4 text-sm font-semibold text-sage-800">Summary</h4>
                <div className="mt-1 space-y-2 text-sm leading-relaxed text-sage-700" data-testid="paper-summary">{s.summary.map((line) => <p key={line}>{line}</p>)}</div>
                <p className="mt-3 text-[11px] text-sage-500">{s.stats.sentences} sentences analysed ({s.engine === 'embedding' ? 'semantic centrality' : 'word-frequency salience'}) · sections found: {s.sections_found.join(', ') || 'none'}</p>
              </section>

              <div className="grid gap-4 lg:grid-cols-3">
                <Section title="Key findings" icon={Lightbulb} items={s.key_findings} empty="No result statements detected." />
                <Section title="Methods" icon={FlaskConical} items={s.methods} empty="No method statements detected." />
                <Section title="Limitations" icon={AlertTriangle} items={s.limitations} empty="The paper does not state limitations explicitly." />
              </div>

              {(s.citation.apa || s.citation.ieee) && (
                <section className="rounded-xl border border-sage-200 bg-white p-4" data-testid="paper-citation">
                  <h4 className="flex items-center gap-1.5 text-sm font-semibold text-sage-800"><Quote className="w-4 h-4 text-sage-500" aria-hidden="true" />Cite this paper</h4>
                  {[['APA', s.citation.apa], ['IEEE', s.citation.ieee]].map(([label, value]) => value && (
                    <div key={label} className="mt-2 flex items-start justify-between gap-2 rounded-lg bg-sage-50 p-2.5">
                      <p className="text-sm text-sage-700"><span className="mr-1 text-[11px] font-semibold text-sage-500">{label}</span>{value}</p>
                      <CopyButton text={value} label={`${label} citation`} />
                    </div>
                  ))}
                  <p className="mt-1 text-[11px] text-sage-500">Built from the detected title, authors and year; check them against the published version.</p>
                </section>
              )}

              {s.references.length > 0 && (
                <section className="rounded-xl border border-sage-200 bg-white p-4">
                  <h4 className="flex items-center gap-1.5 text-sm font-semibold text-sage-800"><BookMarked className="w-4 h-4 text-sage-500" aria-hidden="true" />Suggested further reading</h4>
                  <p className="text-[11px] text-sage-500">References from this paper, most-cited in the text first.</p>
                  <ol className="mt-2 space-y-2 text-sm">
                    {s.references.map((r) => (
                      <li key={r.index} className="flex items-start justify-between gap-2">
                        <span className="text-sage-700">{r.text}</span>
                        <span className="flex shrink-0 items-center gap-1.5">{r.cited_in_text > 0 && <span className="text-[11px] text-sage-500">cited {r.cited_in_text}×</span>}<CopyButton text={r.text} label="reference" /></span>
                      </li>
                    ))}
                  </ol>
                </section>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  );
};
