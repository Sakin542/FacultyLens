import React, { useEffect, useState } from 'react';
import { AlertTriangle, FileText, Loader2, Trash2, X } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { assessmentQuestionService, PaperPreview } from '@/services/assessmentQuestionService';

interface Row { include: boolean; text: string; marks: string }

interface Props {
  isOpen: boolean;
  assessmentId: number | string;
  totalMarks?: number | null;
  onClose: () => void;
  onImported: (message: string) => void;
}

const fieldCls = 'w-full rounded-lg border border-sage-200 dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3 py-2 text-sm text-sage-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-sage-600 dark:focus:ring-white';

/**
 * Reads the uploaded question paper and shows the questions found, for review: edit text and marks, untick
 * anything that is not a question, then save. Nothing is written before "Add N questions".
 */
export const PaperImportModal: React.FC<Props> = ({ isOpen, assessmentId, totalMarks, onClose, onImported }) => {
  const [preview, setPreview] = useState<PaperPreview | null>(null);
  const [rows, setRows] = useState<Row[]>([]);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!isOpen) return;
    setPreview(null); setRows([]); setError(null); setLoading(true);
    assessmentQuestionService.extractFromPaper(assessmentId)
      .then((p) => { setPreview(p); setRows(p.questions.map((q) => ({ include: true, text: q.text, marks: q.marks === null ? '' : String(q.marks) }))); })
      .catch((e) => setError(e instanceof Error ? e.message : 'The question paper could not be read.'))
      .finally(() => setLoading(false));
  }, [isOpen, assessmentId]);

  if (!isOpen) return null;

  const chosen = rows.filter((r) => r.include);
  const missingMarks = chosen.some((r) => r.marks === '' || !Number.isFinite(Number(r.marks)) || Number(r.marks) < 0);
  const sum = chosen.reduce((s, r) => s + (Number(r.marks) || 0), 0);
  const update = (i: number, patch: Partial<Row>) => setRows((rs) => rs.map((r, j) => (j === i ? { ...r, ...patch } : r)));

  const save = async () => {
    setSaving(true); setError(null);
    try {
      const res = await assessmentQuestionService.bulkCreate(assessmentId, chosen.map((r) => ({ question_text: r.text.trim(), marks: Number(r.marks) })));
      onImported(res.message ?? `${chosen.length} question(s) added.`);
      onClose();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'The questions could not be saved.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs" role="dialog" aria-modal="true" aria-labelledby="paper-import-title">
      <div className="bg-white dark:bg-[#1C1C1E] rounded-2xl border border-sage-200 dark:border-[#2C2C2E] shadow-xl max-w-3xl w-full max-h-[92vh] flex flex-col">
        <div className="flex items-center justify-between gap-3 p-6 pb-3 border-b border-sage-200 dark:border-[#2C2C2E]">
          <div className="flex items-center gap-2 min-w-0">
            <div className="w-8 h-8 rounded-lg bg-sage-100 dark:bg-[#2C2C2E] border border-sage-200 dark:border-[#3A3A3C] flex items-center justify-center text-sage-800 dark:text-white shrink-0"><FileText className="w-4 h-4" /></div>
            <div className="min-w-0">
              <h3 id="paper-import-title" className="text-lg font-bold text-sage-800 dark:text-white">Questions found in the paper</h3>
              {preview && <p className="text-xs text-sage-500 truncate">{preview.file_name} · check each question, then add the ones you want</p>}
            </div>
          </div>
          <button type="button" onClick={onClose} aria-label="Close" className="p-1 rounded-lg text-sage-500 hover:text-sage-800 hover:bg-sage-100 dark:hover:bg-[#2C2C2E]"><X className="w-5 h-5" /></button>
        </div>

        <div className="p-6 space-y-3 overflow-y-auto">
          {loading && <p className="flex items-center gap-2 text-sm text-sage-600"><Loader2 className="w-4 h-4 animate-spin" />Reading the question paper…</p>}
          {error && <p role="alert" className="p-3 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 rounded-lg text-xs text-red-600 dark:text-red-400">{error}</p>}
          {preview?.warnings.map((w) => <p key={w} className="flex items-start gap-1.5 p-2.5 rounded-lg border border-amber-200 bg-amber-50 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-300 dark:border-amber-800"><AlertTriangle className="w-3.5 h-3.5 mt-0.5 shrink-0" />{w}</p>)}
          {preview && preview.existing_questions > 0 && rows.length > 0 && (
            <p className="text-xs text-sage-600">This assessment already has {preview.existing_questions} question(s); the new ones are added after them.</p>
          )}
          <ol className="space-y-3" data-testid="paper-import-rows">
            {rows.map((r, i) => (
              <li key={i} className={`rounded-xl border p-3 space-y-2 ${r.include ? 'border-sage-200 dark:border-[#2C2C2E]' : 'border-dashed border-sage-200 opacity-60'}`}>
                <div className="flex items-center justify-between gap-2">
                  <label className="flex items-center gap-2 text-xs font-semibold text-sage-800 dark:text-white">
                    <input type="checkbox" checked={r.include} onChange={(e) => update(i, { include: e.target.checked })} className="h-4 w-4 accent-[#1E6F5C]" />
                    Question {preview?.questions[i]?.number ?? i + 1}
                  </label>
                  <div className="flex items-center gap-2">
                    <label htmlFor={`pi-marks-${i}`} className="text-xs text-sage-600">Marks</label>
                    <input id={`pi-marks-${i}`} type="number" min={0} step={0.5} value={r.marks} disabled={!r.include} onChange={(e) => update(i, { marks: e.target.value })} className={`${fieldCls} w-20 py-1`} />
                    <button type="button" onClick={() => setRows((rs) => rs.filter((_, j) => j !== i))} aria-label={`Remove question ${i + 1}`} className="p-1.5 rounded-lg text-sage-500 hover:text-red-600 hover:bg-red-50"><Trash2 className="w-4 h-4" /></button>
                  </div>
                </div>
                <label htmlFor={`pi-text-${i}`} className="sr-only">Question {i + 1} text</label>
                <textarea id={`pi-text-${i}`} rows={Math.min(6, Math.max(2, r.text.split('\n').length))} value={r.text} disabled={!r.include} onChange={(e) => update(i, { text: e.target.value })} className={`${fieldCls} resize-y`} />
              </li>
            ))}
          </ol>
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3 p-6 pt-3 border-t border-sage-200 dark:border-[#2C2C2E]">
          <p className="text-xs text-sage-600 tabular-nums">
            {chosen.length} selected · {sum} marks{totalMarks ? ` of ${Number(totalMarks)}` : ''}{missingMarks ? ' · set marks for every selected question' : ''}
          </p>
          <div className="flex gap-3">
            <Button variant="outline" size="sm" type="button" onClick={onClose} disabled={saving}>Cancel</Button>
            <Button variant="primary" size="sm" type="button" onClick={() => { void save(); }} disabled={saving || loading || chosen.length === 0 || missingMarks}
              leftIcon={saving ? <Loader2 className="w-4 h-4 animate-spin" /> : undefined}>
              {saving ? 'Adding…' : `Add ${chosen.length} question${chosen.length === 1 ? '' : 's'}`}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
};
