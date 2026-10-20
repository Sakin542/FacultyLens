import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Download, Layers, Lock, Unlock, ClipboardCheck, Trash2, AlertTriangle, Plus } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { examService } from '@/services/examService';
import type { ExamPaper, ExamPaperItem } from '@/types/exam';
import { CoveragePanel } from '@/components/exam/CoveragePanel';
import { PaperItemRow } from '@/components/exam/PaperItemRow';
import { QuestionPoolPanel } from '@/components/exam/QuestionPoolPanel';
import { VariantsDialog } from '@/components/exam/VariantsDialog';
import { cn } from '@/utils/cn';

/** /exam-papers/:paperId — edit a paper (or one of its sets), create sets, export, finalize, apply. */
export const ExamPaperEditor: React.FC = () => {
  const { paperId = '' } = useParams();
  const navigate = useNavigate();
  const location = useLocation();
  const [paper, setPaper] = useState<ExamPaper | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: 'success' | 'warn' | 'error'; text: string } | null>(
    (location.state as { notice?: string } | null)?.notice ? { tone: 'success', text: (location.state as { notice: string }).notice } : null,
  );
  const [variantItem, setVariantItem] = useState<ExamPaperItem | null>(null);
  const [setCount, setSetCount] = useState(1);
  const [vary, setVary] = useState<'shuffle' | 'numbers' | 'wording' | 'both'>('numbers');
  const [busy, setBusy] = useState<string | null>(null);
  const [manualText, setManualText] = useState('');
  const [manualMarks, setManualMarks] = useState('5');

  const load = useCallback(() => {
    examService.paper(paperId).then((p) => { setPaper(p); setError(null); }).catch((e) => setError(e instanceof Error ? e.message : 'Could not load the paper.'));
  }, [paperId]);
  useEffect(() => { load(); }, [load]);

  const run = async (key: string, fn: () => Promise<{ message?: string; data?: ExamPaper } | void>, okText?: string) => {
    setBusy(key);
    setNotice(null);
    try {
      const res = await fn();
      if (res && 'data' in res && res.data && !Array.isArray(res.data)) setPaper(res.data);
      const msg = (res && 'message' in res && res.message) || okText;
      if (msg) setNotice({ tone: 'success', text: msg });
    } catch (e) {
      setNotice({ tone: 'error', text: e instanceof Error ? e.message : 'Action failed.' });
    } finally {
      setBusy(null);
    }
  };

  const usedTexts = useMemo(() => new Set(paper?.items.map((i) => i.question_text) ?? []), [paper]);

  if (error) return <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] p-4 text-sm text-[#991B1B]">{error}</p>;
  if (!paper) return <p className="py-10 text-center text-sm text-sage-500">Loading paper…</p>;

  const locked = paper.status === 'final';
  const baseId = paper.parent_paper_id ?? paper.id;
  const setLinks = paper.parent_paper_id ? null : paper.sets;

  const move = (index: number, dir: -1 | 1) => {
    const ids = paper.items.map((i) => i.id);
    const target = index + dir;
    [ids[index], ids[target]] = [ids[target], ids[index]];
    void run('order', () => examService.reorder(paper.id, ids));
  };

  return (
    <div className="space-y-5 page-enter" data-testid="exam-paper-editor">
      <Link to={`/assessments/${paper.assessment.id}/exam-papers`} className="inline-flex items-center gap-1.5 text-xs font-semibold text-sage-500 hover:text-sage-800"><ArrowLeft className="w-3.5 h-3.5" />All papers for {paper.assessment.title}</Link>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-xs font-mono text-sage-500">{paper.course.course_code} · {paper.course.course_name}</p>
          <h2 className="text-xl font-semibold tracking-tight text-sage-800">{paper.title} <span className="ml-1 rounded border border-sage-300 px-1.5 py-0.5 align-middle text-sm">Set {paper.set_label}</span></h2>
          <p className="text-sm text-sage-500">{paper.items.length} question(s) · {paper.total_marks} marks{paper.duration_minutes ? ` · ${paper.duration_minutes} minutes` : ''}</p>
        </div>
        <div className="flex flex-wrap items-center gap-1.5">
          <Badge variant={locked ? 'Good' : 'neutral'}>{locked ? 'Final' : 'Draft'}</Badge>
          {paper.applied_at && <Badge variant="outline">Added to assessment</Badge>}
        </div>
      </div>

      <nav aria-label="Exam sets" className="flex flex-wrap items-center gap-1.5 text-sm">
        <Layers className="w-4 h-4 text-sage-500" aria-hidden="true" />
        <button type="button" onClick={() => navigate(`/exam-papers/${baseId}`)} className={cn('rounded-md border px-2.5 py-1', paper.set_label === 'A' ? 'border-sage-700 bg-sage-700 text-white' : 'border-sage-200 text-sage-700 hover:bg-sage-100')}>Set A</button>
        {(setLinks ?? []).map((s) => (
          <button key={s.id} type="button" onClick={() => navigate(`/exam-papers/${s.id}`)} className="rounded-md border border-sage-200 px-2.5 py-1 text-sage-700 hover:bg-sage-100">Set {s.set_label}</button>
        ))}
        {paper.parent_paper_id && <span className="text-xs text-sage-500">This is a derived set; other sets are listed on set A.</span>}
      </nav>

      {notice && (
        <p role={notice.tone === 'error' ? 'alert' : 'status'} className={cn('rounded-lg border px-3 py-2 text-sm',
          notice.tone === 'success' ? 'border-[#BBF7D0] bg-[#F0FDF4] text-[#166534]' : notice.tone === 'warn' ? 'border-[#FDE68A] bg-[#FFFBEB] text-[#92400E]' : 'border-[#FECACA] bg-[#FEF2F2] text-[#991B1B]')}>{notice.text}</p>
      )}

      {paper.shortfalls.length > 0 && (
        <div className="rounded-lg border border-[#FDE68A] bg-[#FFFBEB] p-3 text-sm text-[#92400E]" data-testid="paper-shortfalls">
          <p className="flex items-center gap-1.5 font-medium"><AlertTriangle className="w-4 h-4" aria-hidden="true" />Some targets could not be filled from the question pool</p>
          <ul className="mt-1 list-disc pl-6 text-xs">{paper.shortfalls.map((s, i) => <li key={i}>{s.slot}: {s.missing} of {s.needed} missing</li>)}</ul>
          <p className="mt-1 text-xs">Add matching questions to the course question bank, approve generated drafts, or write them below.</p>
        </div>
      )}

      {/* Actions */}
      <section className="flex flex-wrap items-end gap-3 rounded-xl border border-sage-200 bg-white p-4 shadow-subtle" aria-label="Paper actions">
        <div className="flex flex-wrap gap-1.5">
          <Button size="sm" variant="outline" leftIcon={<Download className="w-3.5 h-3.5" />} isLoading={busy === 'pdf'} onClick={() => run('pdf', () => examService.download(paper.id, 'pdf', false), 'Paper downloaded.')} data-testid="export-pdf">PDF</Button>
          <Button size="sm" variant="outline" leftIcon={<Download className="w-3.5 h-3.5" />} isLoading={busy === 'pdfkey'} onClick={() => run('pdfkey', () => examService.download(paper.id, 'pdf', true), 'Paper with answer key downloaded.')}>PDF + answer key</Button>
          <Button size="sm" variant="outline" leftIcon={<Download className="w-3.5 h-3.5" />} isLoading={busy === 'docx'} onClick={() => run('docx', () => examService.download(paper.id, 'docx', true), 'Word file downloaded.')}>Word + key</Button>
        </div>

        {!paper.parent_paper_id && (
          <div className="flex flex-wrap items-end gap-1.5 border-l border-sage-200 pl-3">
            <label className="text-[11px] text-sage-600">New sets
              <select value={setCount} onChange={(e) => setSetCount(Number(e.target.value))} className="mt-0.5 block rounded-md border border-sage-200 px-2 py-1 text-xs">{[1, 2, 3, 4].map((n) => <option key={n} value={n}>{n}</option>)}</select>
            </label>
            <label className="text-[11px] text-sage-600">Each set
              <select value={vary} onChange={(e) => setVary(e.target.value as typeof vary)} className="mt-0.5 block rounded-md border border-sage-200 px-2 py-1 text-xs" data-testid="set-vary">
                <option value="shuffle">Shuffled order only</option>
                <option value="numbers">Shuffled + new numbers</option>
                <option value="wording">Shuffled + reworded</option>
                <option value="both">Shuffled + numbers + wording</option>
              </select>
            </label>
            <Button size="sm" leftIcon={<Layers className="w-3.5 h-3.5" />} isLoading={busy === 'sets'} disabled={paper.items.length === 0} data-testid="create-sets"
              onClick={() => run('sets', async () => {
                const res = await examService.createSets(paper.id, setCount, vary);
                load();
                if (res.warnings.length) setNotice({ tone: 'warn', text: `${res.message} ${res.warnings.length} note(s): ${res.warnings.slice(0, 2).join(' ')}` });
                else setNotice({ tone: 'success', text: `${res.message} Answer keys flagged for review where values changed.` });
              })}>Create sets</Button>
          </div>
        )}

        <div className="ml-auto flex flex-wrap gap-1.5">
          {locked ? (
            <Button size="sm" variant="outline" leftIcon={<Unlock className="w-3.5 h-3.5" />} isLoading={busy === 'draft'} onClick={() => run('draft', () => examService.updatePaper(paper.id, { status: 'draft' }))}>Reopen as draft</Button>
          ) : (
            <Button size="sm" variant="outline" leftIcon={<Lock className="w-3.5 h-3.5" />} isLoading={busy === 'final'} disabled={paper.items.length === 0} onClick={() => run('final', () => examService.updatePaper(paper.id, { status: 'final' }))} data-testid="finalize-paper">Finalize</Button>
          )}
          <Button size="sm" variant="outline" leftIcon={<ClipboardCheck className="w-3.5 h-3.5" />} isLoading={busy === 'apply'} disabled={!!paper.applied_at || paper.items.length === 0}
            onClick={() => { if (window.confirm(`Add these ${paper.items.length} question(s) to "${paper.assessment.title}"?`)) void run('apply', () => examService.apply(paper.id)); }}>Add to assessment</Button>
          <Button size="sm" variant="danger" leftIcon={<Trash2 className="w-3.5 h-3.5" />}
            onClick={async () => { if (window.confirm('Delete this paper and its sets?')) { await examService.deletePaper(paper.id); navigate(`/assessments/${paper.assessment.id}/exam-papers`); } }}>Delete</Button>
        </div>
      </section>

      <div className="grid gap-5 xl:grid-cols-[1fr,320px]">
        <div className="space-y-3">
          {paper.instructions && <p className="rounded-lg bg-sage-100 px-3 py-2 text-xs italic text-sage-700">{paper.instructions}</p>}
          {paper.items.length === 0 && <p className="rounded-xl border border-dashed border-sage-300 p-8 text-center text-sm text-sage-500">No questions yet. Add some from the pool or write one below.</p>}
          <ol className="space-y-3">
            {paper.items.map((item, i) => (
              <React.Fragment key={item.id}>
                {item.section_title && item.section_title !== paper.items[i - 1]?.section_title && <li className="pt-2 text-sm font-semibold text-sage-800">{item.section_title}</li>}
                <PaperItemRow item={item} number={i + 1} locked={locked} isFirst={i === 0} isLast={i === paper.items.length - 1}
                  onMove={(d) => move(i, d)}
                  onSave={async (changes) => { const res = await examService.updateItem(paper.id, item.id, changes); setPaper(res.data); }}
                  onRemove={() => run('remove', () => examService.removeItem(paper.id, item.id))}
                  onVariants={() => setVariantItem(item)} />
              </React.Fragment>
            ))}
          </ol>

          {!locked && (
            <form className="flex flex-wrap items-end gap-2 rounded-xl border border-sage-200 bg-white p-3" onSubmit={(e) => {
              e.preventDefault();
              if (!manualText.trim()) return;
              void run('manual', async () => { const r = await examService.addItem(paper.id, { question_text: manualText.trim(), marks: Number(manualMarks) }); setManualText(''); return r; });
            }}>
              <label className="min-w-[240px] flex-1 text-xs font-medium text-sage-700">Write a question
                <input value={manualText} onChange={(e) => setManualText(e.target.value)} className="mt-1 w-full rounded-lg border border-sage-200 px-3 py-2 text-sm" placeholder="Type a new question" />
              </label>
              <label className="w-20 text-xs font-medium text-sage-700">Marks<input type="number" min={0.5} step={0.5} value={manualMarks} onChange={(e) => setManualMarks(e.target.value)} className="mt-1 w-full rounded-lg border border-sage-200 px-2 py-2 text-sm" /></label>
              <Button type="submit" size="sm" leftIcon={<Plus className="w-3.5 h-3.5" />} isLoading={busy === 'manual'}>Add</Button>
            </form>
          )}
        </div>

        <aside className="space-y-4">
          <CoveragePanel paper={paper} />
          {!locked && <QuestionPoolPanel assessmentId={paper.assessment.id} usedTexts={usedTexts} onAdd={async (key) => { await run('add', () => examService.addItem(paper.id, { candidate_key: key })); }} />}
        </aside>
      </div>

      {variantItem && (
        <VariantsDialog paperId={paper.id} item={variantItem} onClose={() => setVariantItem(null)}
          onUse={async (v, engine) => {
            const res = await examService.updateItem(paper.id, variantItem.id, {
              question_text: v.question_text,
              expected_answer: v.expected_answer,
              answer_needs_review: v.answer_needs_review,
              variant_meta: { methods: v.methods, number_changes: v.number_changes, similarity: v.similarity, engine },
            });
            setPaper(res.data);
            setNotice({ tone: v.answer_needs_review ? 'warn' : 'success', text: v.answer_needs_review ? 'Variant applied. Recompute its answer key before printing.' : 'Variant applied.' });
          }} />
      )}
    </div>
  );
};
