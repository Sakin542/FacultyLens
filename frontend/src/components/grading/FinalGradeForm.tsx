import React, { useState } from 'react';
import { AlertCircle, CheckCircle2, Save, X } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { AnswerTextEditor } from '@/components/submissions/AnswerTextEditor';
import { FinalGradePayload } from '@/types/grading';
import { GradingDisclaimer } from './GradingDisclaimer';

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
