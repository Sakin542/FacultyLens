import React, { useState } from 'react';
import { CheckCircle2, XCircle, Eye, PencilLine } from 'lucide-react';
import { Button } from '@/components/common/Button';
import type { AiExplanation } from '@/types/explainability';

interface ReviewActionsProps {
  explanation: AiExplanation;
  isSubmitting?: boolean;
  onReview: (action: 'ACCEPTED' | 'REJECTED' | 'REVIEWED', comment?: string) => void;
  onOverride: () => void;
}

/**
 * Faculty actions for an AI result. "AI assists. Faculty decides." — none of these change the AI value;
 * only Override writes a faculty-controlled field.
 */
export const ReviewActions: React.FC<ReviewActionsProps> = ({ explanation, isSubmitting = false, onReview, onOverride }) => {
  const { review } = explanation;
  const [pending, setPending] = useState<'ACCEPTED' | 'REJECTED' | 'REVIEWED' | null>(null);
  const [comment, setComment] = useState('');
  const actions = review.actions ?? [];
  const canReview = review.can_review !== false && actions.length > 0;

  const submit = () => {
    if (!pending) return;
    onReview(pending, comment.trim() || undefined);
    setPending(null);
    setComment('');
  };

  return (
    <section aria-labelledby="explanation-review-heading" className="space-y-2 text-xs" data-testid="review-actions">
      <h4 id="explanation-review-heading" className="text-[11px] font-semibold uppercase tracking-wide text-sage-500">
        Faculty decision
      </h4>

      {review.faculty_value !== undefined && review.faculty_value !== null && (
        <p className="text-sage-700 dark:text-sage-300">
          Current faculty value: <span className="font-mono font-semibold text-sage-800 dark:text-white">{String(review.faculty_value)}</span>
        </p>
      )}
      {review.latest && (
        <p className="text-sage-500" data-testid="review-latest">
          Latest decision: <span className="font-semibold uppercase">{review.latest.action}</span>
          {review.latest.override_reason_label ? ` — ${review.latest.override_reason_label}` : ''}
          {review.latest.created_at ? ` · ${new Date(review.latest.created_at).toLocaleString()}` : ''}
        </p>
      )}
      {review.note && <p className="text-sage-500 italic">{review.note}</p>}

      {!canReview ? (
        <p className="text-sage-500 italic" data-testid="review-unavailable">
          {review.can_review === false ? 'You can inspect this result but your role does not allow recording a decision.' : 'No review action applies to this result.'}
        </p>
      ) : (
        <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label="Review actions">
          {actions.includes('ACCEPTED') && (
            <Button type="button" variant="outline" size="sm" leftIcon={<CheckCircle2 className="w-3.5 h-3.5" aria-hidden="true" />} disabled={isSubmitting} onClick={() => setPending('ACCEPTED')}>
              {review.accept_label ?? 'Accept'}
            </Button>
          )}
          {actions.includes('REJECTED') && (
            <Button type="button" variant="outline" size="sm" leftIcon={<XCircle className="w-3.5 h-3.5" aria-hidden="true" />} disabled={isSubmitting} onClick={() => setPending('REJECTED')}>
              {review.reject_label ?? 'Reject'}
            </Button>
          )}
          {actions.includes('REVIEWED') && (
            <Button type="button" variant="ghost" size="sm" leftIcon={<Eye className="w-3.5 h-3.5" aria-hidden="true" />} disabled={isSubmitting} onClick={() => setPending('REVIEWED')}>
              Mark as reviewed
            </Button>
          )}
          {actions.includes('OVERRIDE') && review.overridable && (
            <Button type="button" variant="secondary" size="sm" leftIcon={<PencilLine className="w-3.5 h-3.5" aria-hidden="true" />} disabled={isSubmitting} onClick={onOverride} data-testid="override-button">
              Override
            </Button>
          )}
        </div>
      )}

      {pending && (
        <div className="space-y-2 rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-sage-100 dark:bg-[#2C2C2E] p-2.5" data-testid="review-confirm">
          <label htmlFor="review-comment" className="block font-semibold text-sage-800 dark:text-white">
            Record decision: {pending}
            <span className="font-normal text-sage-500"> — optional comment</span>
          </label>
          <textarea id="review-comment" rows={2} value={comment} onChange={(e) => setComment(e.target.value)} maxLength={2000} className="w-full text-xs p-2 rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] text-sage-800 dark:text-white" />
          <div className="flex justify-end gap-2">
            <Button type="button" variant="ghost" size="sm" onClick={() => setPending(null)} disabled={isSubmitting}>
              Cancel
            </Button>
            <Button type="button" variant="primary" size="sm" onClick={submit} isLoading={isSubmitting} data-testid="review-submit">
              Confirm
            </Button>
          </div>
        </div>
      )}
    </section>
  );
};
