import React, { useEffect, useRef, useState } from 'react';
import { X } from 'lucide-react';
import { Button } from '@/components/common/Button';
import type { AiExplanation, OverrideOption } from '@/types/explainability';

interface OverrideDialogProps {
  explanation: AiExplanation;
  isOpen: boolean;
  isSubmitting?: boolean;
  onClose: () => void;
  onSubmit: (value: Record<string, unknown>, reason: string, comment: string) => void;
}

const normalizeOptions = (options: Array<OverrideOption | string>): OverrideOption[] =>
  options.map((o) => (typeof o === 'string' ? { value: o, label: o.replace(/_/g, ' ') } : o));

/**
 * Faculty override of a faculty-controlled field (never the AI value). Captures an override reason
 * (STEP 20 improvement-signal architecture); the signal never retrains the model automatically.
 */
export const OverrideDialog: React.FC<OverrideDialogProps> = ({ explanation, isOpen, isSubmitting = false, onClose, onSubmit }) => {
  const options = normalizeOptions(explanation.review.override_options);
  const reasons = explanation.review.override_reasons;
  const [value, setValue] = useState<string>('');
  const [reason, setReason] = useState<string>(reasons[0]?.code ?? 'OTHER');
  const [comment, setComment] = useState('');
  const [error, setError] = useState<string | null>(null);
  const firstFieldRef = useRef<HTMLSelectElement>(null);

  useEffect(() => {
    if (isOpen) {
      setValue('');
      setComment('');
      setError(null);
      setReason(reasons[0]?.code ?? 'OTHER');
      setTimeout(() => firstFieldRef.current?.focus(), 0);
    }
  }, [isOpen, reasons]);

  useEffect(() => {
    if (!isOpen) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const field = explanation.review.override_field ?? 'label';
  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!value) {
      setError('Select the value you want to record as the faculty decision.');
      return;
    }
    const payload: Record<string, unknown> = field === 'learning_outcome_id' ? { learning_outcome_id: Number(value) } : { label: value };
    onSubmit(payload, reason, comment);
  };

  return (
    <div className="fixed inset-0 z-[110] flex items-center justify-center bg-sage-800/40 backdrop-blur-[2px] p-4" role="presentation" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="override-dialog-title"
        aria-describedby="override-dialog-desc"
        className="w-full max-w-md rounded-2xl bg-white dark:bg-[#1C1C1E] border border-sage-200 dark:border-[#2C2C2E] shadow-xl p-5 space-y-4"
        onClick={(e) => e.stopPropagation()}
        data-testid="override-dialog"
      >
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 id="override-dialog-title" className="text-sm font-bold text-sage-800 dark:text-white">
              Override AI result
            </h2>
            <p id="override-dialog-desc" className="text-xs text-sage-500 mt-0.5">
              AI result: <span className="font-mono font-semibold">{explanation.result.display ?? explanation.result.label}</span>. Your value replaces the
              faculty-controlled field; the AI result is kept for reference.
            </p>
          </div>
          <button type="button" onClick={onClose} aria-label="Close" className="text-sage-500 hover:text-sage-800 rounded focus-visible:outline focus-visible:outline-2 focus-visible:outline-sage-700">
            <X className="w-4 h-4" aria-hidden="true" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-3" noValidate>
          <div>
            <label htmlFor="override-value" className="block text-xs font-semibold text-sage-800 dark:text-white mb-1">
              Faculty value <span aria-hidden="true">*</span>
            </label>
            <select
              id="override-value"
              ref={firstFieldRef}
              value={value}
              onChange={(e) => setValue(e.target.value)}
              aria-invalid={error ? 'true' : undefined}
              aria-describedby={error ? 'override-error' : undefined}
              className="w-full text-xs p-2 rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] text-sage-800 dark:text-white"
              data-testid="override-value"
            >
              <option value="">Select…</option>
              {options.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label htmlFor="override-reason" className="block text-xs font-semibold text-sage-800 dark:text-white mb-1">
              Override reason <span aria-hidden="true">*</span>
            </label>
            <select id="override-reason" value={reason} onChange={(e) => setReason(e.target.value)} className="w-full text-xs p-2 rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] text-sage-800 dark:text-white" data-testid="override-reason">
              {reasons.map((r) => (
                <option key={r.code} value={r.code}>
                  {r.label}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label htmlFor="override-comment" className="block text-xs font-semibold text-sage-800 dark:text-white mb-1">
              Comment (optional)
            </label>
            <textarea id="override-comment" rows={2} value={comment} onChange={(e) => setComment(e.target.value)} maxLength={2000} className="w-full text-xs p-2 rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] text-sage-800 dark:text-white" />
          </div>

          {error && (
            <p id="override-error" role="alert" className="text-xs text-[#991B1B]">
              {error}
            </p>
          )}

          <p className="text-[11px] text-sage-500 italic">This feedback informs FacultyLens improvement signals; it never retrains the model automatically.</p>

          <div className="flex items-center justify-end gap-2 pt-1">
            <Button type="button" variant="ghost" size="sm" onClick={onClose} disabled={isSubmitting}>
              Cancel
            </Button>
            <Button type="submit" variant="primary" size="sm" isLoading={isSubmitting} data-testid="override-submit">
              Apply override
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
};
