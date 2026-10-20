import React, { useState } from 'react';
import { AlertCircle, CheckCircle2, Loader2, MessageSquareText, Save, X } from 'lucide-react';
import { aiToolkitService } from '@/services/aiToolkitService';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { AnswerTextEditor } from '@/components/submissions/AnswerTextEditor';
import { FinalGradePayload } from '@/types/grading';
import { GradingDisclaimer } from './GradingDisclaimer';
import { CommentBankPicker } from './CommentBankPicker';

interface FinalGradeFormProps {
  answerId: number;
  maxMarks: number;
  initialMarks?: number | null;
  initialFeedback?: string | null;
  suggestedMarks?: number | null;
  onSubmit: (data: FinalGradePayload) => Promise<void>;
  onCancel?: () => void;
  submitLabel?: string;
}

export function validateFinalMarks(raw: string, maxMarks: number): string | null {
  if (raw.trim() === '') return 'Final marks are required.';
  const n = Number(raw);
  if (!Number.isFinite(n)) return 'Final marks must be a number.';
  if (n < 0) return 'Final marks cannot be negative.';
  if (n > maxMarks) return `Final marks cannot exceed ${maxMarks} for this question.`;
  if (Math.round(n * 100) !== n * 100) return 'Final marks may have at most two decimal places.';
  return null;
}

/**
 * Faculty final marks + feedback. Validates 0 <= marks <= question marks before submit.
 * The AI suggestion is only used to pre-fill; the faculty decision is explicit.
 */
export const FinalGradeForm: React.FC<FinalGradeFormProps> = ({
  answerId,
  maxMarks,
  initialMarks,
  initialFeedback,
  suggestedMarks,
  onSubmit,
  onCancel,
  submitLabel = 'Finalize Grade',
}) => {
  const [marks, setMarks] = useState(initialMarks !== null && initialMarks !== undefined ? String(initialMarks) : '');
  const [feedback, setFeedback] = useState(initialFeedback ?? '');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [drafting, setDrafting] = useState(false);
  const [draftNote, setDraftNote] = useState<string | null>(null);

  // Rubric-based feedback draft for the student. It only fills the text box: marks stay the faculty member's decision,
  // and nothing is saved until the form is submitted.
  const draftFeedback = async () => {
    setDrafting(true);
    setDraftNote(null);
    try {
      const d = await aiToolkitService.feedbackDraft({ student_answer_id: answerId });
      setFeedback((f) => (f.trim() ? `${f.trim()}\n\n${d.feedback}` : d.feedback).slice(0, 5000));
      setDraftNote('AI draft added from the approved rubric. Edit it before saving; it does not affect the marks.');
    } catch (err) {
      setDraftNote(err instanceof Error ? err.message : 'Could not draft feedback.');
    } finally {
      setDrafting(false);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    const v = validateFinalMarks(marks, maxMarks);
    if (v) { setError(v); return; }
    const n = Number(marks);
    const decision = suggestedMarks !== null && suggestedMarks !== undefined
      ? (Math.abs(n - suggestedMarks) < 0.005 ? 'ACCEPTED' : 'MODIFIED')
      : undefined;
    try {
      setBusy(true);
      setError(null);
      await onSubmit({ final_marks: n, faculty_feedback: feedback.trim() || null, decision });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The final grade could not be saved.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-3" data-testid="final-grade-form" noValidate>
      <div className="grid grid-cols-1 sm:grid-cols-[160px_1fr] gap-3">
        <Input
          id={`final-marks-${answerId}`}
          label={`Final marks (max ${maxMarks})`}
          type="number"
          inputMode="decimal"
          step="0.5"
          min={0}
          max={maxMarks}
          value={marks}
          onChange={(e) => setMarks(e.target.value)}
          disabled={busy}
          required
          className="font-mono"
          data-testid="final-marks-input"
        />
        <AnswerTextEditor
          id={`final-feedback-${answerId}`}
          label="Faculty feedback (optional)"
          value={feedback}
          onChange={setFeedback}
          rows={2}
          maxLength={5000}
          placeholder="Your feedback for this answer…"
          disabled={busy}
        />
      </div>
      <div className="sm:pl-[172px] space-y-1.5">
        <div className="flex flex-wrap items-start gap-2">
          <CommentBankPicker current={feedback} disabled={busy}
            onInsert={(text) => setFeedback((f) => (f.trim() ? `${f.trim()}\n${text}` : text).slice(0, 5000))} />
          <button type="button" onClick={() => { void draftFeedback(); }} disabled={busy || drafting} data-testid="draft-feedback-button"
            className="inline-flex items-center gap-1 rounded-md border border-sage-200 px-2 py-1 text-xs text-sage-700 hover:bg-sage-100 disabled:opacity-60">
            {drafting ? <Loader2 className="w-3.5 h-3.5 animate-spin" aria-hidden="true" /> : <MessageSquareText className="w-3.5 h-3.5" aria-hidden="true" />}
            Draft feedback (AI)
          </button>
        </div>
        {draftNote && <p className="text-[11px] text-sage-500" role="status">{draftNote}</p>}
      </div>

      {error && (
        <div className="p-2.5 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 rounded-lg flex items-center gap-2 text-red-700 dark:text-red-300 text-xs" role="alert">
          <AlertCircle className="w-3.5 h-3.5 shrink-0" /> {error}
        </div>
      )}

      <GradingDisclaimer />

      <div className="flex items-center justify-end gap-2">
        {onCancel && (
          <Button type="button" variant="ghost" size="sm" leftIcon={<X className="w-3.5 h-3.5" />} onClick={onCancel} disabled={busy}>Cancel</Button>
        )}
        <Button type="submit" variant="primary" size="sm" leftIcon={submitLabel.startsWith('Finalize') ? <CheckCircle2 className="w-3.5 h-3.5" /> : <Save className="w-3.5 h-3.5" />} isLoading={busy} data-testid="finalize-grade-button">
          {submitLabel}
        </Button>
      </div>
    </form>
  );
};
