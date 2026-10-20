import React, { useEffect, useState } from 'react';
import { AlertCircle, HelpCircle, Loader2, X } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { LearningOutcome, QuestionDetail } from '@/types';
import { BloomLevel, QuestionInput, QuestionType } from '@/services/assessmentQuestionService';
import { ApiError } from '@/services/api';

export const QUESTION_TYPES: { value: QuestionType; label: string }[] = [
  { value: 'descriptive', label: 'Descriptive' }, { value: 'short_answer', label: 'Short answer' }, { value: 'problem_solving', label: 'Problem solving' },
  { value: 'mcq', label: 'Multiple choice (MCQ)' }, { value: 'true_false', label: 'True / False' }, { value: 'other', label: 'Other' },
];
export const BLOOM_LEVELS: BloomLevel[] = ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'];

const fieldCls = 'w-full rounded-lg border border-sage-200 dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-sm text-sage-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-sage-600 dark:focus:ring-white';
const labelCls = 'block text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-200 mb-1.5';

interface Props {
  isOpen: boolean;
  question?: QuestionDetail | null;
  outcomes: LearningOutcome[];
  nextNumber: number;
  onClose: () => void;
  onSave: (input: QuestionInput) => Promise<void>;
}

const capital = (v?: string) => (v ? v.charAt(0).toUpperCase() + v.slice(1).toLowerCase() : '');

/** Add or edit one assessment question (text, marks, type, difficulty, Bloom level, course outcome, model answer). */
export const QuestionEditorModal: React.FC<Props> = ({ isOpen, question, outcomes, nextNumber, onClose, onSave }) => {
  const [form, setForm] = useState({ text: '', marks: '', type: 'descriptive', difficulty: 'medium', bloom: 'Understand', outcome: '', expected: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!isOpen) return;
    setErrors({}); setError(null);
    setForm({
      text: question?.question_text ?? question?.text ?? '',
      marks: question?.marks !== undefined && question?.marks !== null ? String(Number(question.marks)) : '',
      type: (question?.question_type as string) || 'descriptive',
      difficulty: String(question?.difficulty_level || 'medium').toLowerCase(),
      bloom: capital(String(question?.cognitive_level || 'Understand')),
      outcome: question?.learning_outcome_id ? String(question.learning_outcome_id) : '',
      expected: question?.expected_answer ?? '',
    });
  }, [isOpen, question]);

  if (!isOpen) return null;

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const next: Record<string, string> = {};
    if (form.text.trim().length < 3) next.text = 'Enter the question.';
    const marks = Number(form.marks);
    if (form.marks === '' || !Number.isFinite(marks) || marks < 0) next.marks = 'Enter the marks (0 or more).';
    setErrors(next);
    if (Object.keys(next).length) return;
    setSaving(true); setError(null);
    try {
      await onSave({
        question_text: form.text.trim(), marks, question_type: form.type as QuestionType, difficulty_level: form.difficulty as 'easy' | 'medium' | 'hard',
        cognitive_level: form.bloom as BloomLevel, learning_outcome_id: form.outcome ? Number(form.outcome) : null, expected_answer: form.expected.trim() || null,
      });
      onClose();
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setErrors({ text: err.errors.question_text?.[0] ?? '', marks: err.errors.marks?.[0] ?? '', outcome: err.errors.learning_outcome_id?.[0] ?? '' });
      }
      setError(err instanceof Error ? err.message : 'The question could not be saved.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs" role="dialog" aria-modal="true" aria-labelledby="question-editor-title">
      <div className="bg-white dark:bg-[#1C1C1E] rounded-2xl border border-sage-200 dark:border-[#2C2C2E] shadow-xl max-w-2xl w-full max-h-[92vh] overflow-y-auto p-6 space-y-5">
        <div className="flex items-center justify-between pb-3 border-b border-sage-200 dark:border-[#2C2C2E]">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-sage-100 dark:bg-[#2C2C2E] border border-sage-200 dark:border-[#3A3A3C] flex items-center justify-center text-sage-800 dark:text-white"><HelpCircle className="w-4 h-4" /></div>
            <h3 id="question-editor-title" className="text-lg font-bold text-sage-800 dark:text-white">
              {question ? `Edit question ${question.question_number ?? ''}` : `Add question ${nextNumber}`}
            </h3>
          </div>
          <button type="button" onClick={onClose} aria-label="Close" className="p-1 rounded-lg text-sage-500 hover:text-sage-800 hover:bg-sage-100 dark:hover:bg-[#2C2C2E]"><X className="w-5 h-5" /></button>
        </div>

        {error && <div role="alert" className="p-3 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 rounded-lg text-xs text-red-600 dark:text-red-400 flex items-center gap-2"><AlertCircle className="w-4 h-4 shrink-0" />{error}</div>}

        <form onSubmit={(e) => { void submit(e); }} className="space-y-4" noValidate>
          <div>
            <label htmlFor="qe-text" className={labelCls}>Question <span className="text-[#DC2626]">*</span></label>
            <textarea id="qe-text" rows={5} value={form.text} onChange={(e) => setForm({ ...form, text: e.target.value })} className={`${fieldCls} resize-y`} placeholder="e.g. Explain how breadth-first search finds the shortest path in an unweighted graph." />
            {errors.text && <p className="mt-1 text-xs text-[#DC2626]">{errors.text}</p>}
          </div>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div>
              <label htmlFor="qe-marks" className={labelCls}>Marks <span className="text-[#DC2626]">*</span></label>
              <input id="qe-marks" type="number" min={0} step={0.5} value={form.marks} onChange={(e) => setForm({ ...form, marks: e.target.value })} className={fieldCls} />
              {errors.marks && <p className="mt-1 text-xs text-[#DC2626]">{errors.marks}</p>}
            </div>
            <div>
              <label htmlFor="qe-type" className={labelCls}>Type</label>
              <select id="qe-type" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })} className={fieldCls}>{QUESTION_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}</select>
            </div>
            <div>
              <label htmlFor="qe-difficulty" className={labelCls}>Difficulty</label>
              <select id="qe-difficulty" value={form.difficulty} onChange={(e) => setForm({ ...form, difficulty: e.target.value })} className={fieldCls}><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select>
            </div>
            <div>
              <label htmlFor="qe-bloom" className={labelCls}>Bloom level</label>
              <select id="qe-bloom" value={form.bloom} onChange={(e) => setForm({ ...form, bloom: e.target.value })} className={fieldCls}>{BLOOM_LEVELS.map((b) => <option key={b} value={b}>{b}</option>)}</select>
            </div>
          </div>
          <div>
            <label htmlFor="qe-outcome" className={labelCls}>Course outcome</label>
            <select id="qe-outcome" value={form.outcome} onChange={(e) => setForm({ ...form, outcome: e.target.value })} className={fieldCls}>
              <option value="">Not linked</option>
              {outcomes.map((o) => <option key={o.id} value={String(o.id)}>{o.code} — {o.description.slice(0, 80)}</option>)}
            </select>
            {outcomes.length === 0 && <p className="mt-1 text-[11px] text-sage-500">This course has no outcomes yet; add them on the course page to link questions.</p>}
            {errors.outcome && <p className="mt-1 text-xs text-[#DC2626]">{errors.outcome}</p>}
          </div>
          <div>
            <label htmlFor="qe-expected" className={labelCls}>Model answer (optional)</label>
            <textarea id="qe-expected" rows={3} value={form.expected} onChange={(e) => setForm({ ...form, expected: e.target.value })} className={`${fieldCls} resize-y`} placeholder="Key points a full answer should contain. Used by rubric generation and grading assistance." />
          </div>
          <div className="flex items-center justify-end gap-3 pt-4 border-t border-sage-200 dark:border-[#2C2C2E]">
            <Button variant="outline" size="sm" type="button" onClick={onClose} disabled={saving}>Cancel</Button>
            <Button variant="primary" size="sm" type="submit" disabled={saving} leftIcon={saving ? <Loader2 className="w-4 h-4 animate-spin" /> : undefined}>
              {saving ? 'Saving…' : question ? 'Save changes' : 'Add question'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
};
