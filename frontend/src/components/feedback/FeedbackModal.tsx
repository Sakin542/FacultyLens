import React, { useState, useEffect } from 'react';
import { EvidenceBasedRecommendation } from '@/types';
import { DecisionType, FeedbackReason } from '@/types/feedback';
import { feedbackService } from '@/services/feedbackService';
import { FeedbackRating } from './FeedbackRating';
import { FeedbackReasonSelect } from './FeedbackReasonSelect';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { X, CheckCircle2, XCircle, Eye, Loader2, AlertCircle } from 'lucide-react';

interface FeedbackModalProps {
  isOpen: boolean;
  recommendation: EvidenceBasedRecommendation | null;
  decision: DecisionType;
  onClose: () => void;
  onSuccess: (updatedRec: EvidenceBasedRecommendation) => void;
}

export const FeedbackModal: React.FC<FeedbackModalProps> = ({
  isOpen,
  recommendation,
  decision,
  onClose,
  onSuccess,
}) => {
  const [rating, setRating] = useState<number | null>(decision === 'ACCEPTED' ? 5 : null);
  const [reason, setReason] = useState<FeedbackReason | null>(
    decision === 'ACCEPTED' ? 'USEFUL_INSIGHT' : decision === 'DISMISSED' ? 'NOT_APPLICABLE' : null
  );
  const [comment, setComment] = useState<string>('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Reset form when modal opens or recommendation changes
  useEffect(() => {
    if (isOpen) {
      setRating(decision === 'ACCEPTED' ? 5 : decision === 'DISMISSED' ? 2 : 3);
      setReason(
        decision === 'ACCEPTED'
          ? 'USEFUL_INSIGHT'
          : decision === 'DISMISSED'
          ? 'NOT_APPLICABLE'
          : 'WILL_IMPLEMENT'
      );
      setComment(recommendation?.faculty_notes || '');
      setError(null);
    }
  }, [isOpen, recommendation, decision]);

  // Keyboard navigation: ESC to close
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && isOpen && !isSubmitting) {
        onClose();
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, isSubmitting, onClose]);

  if (!isOpen || !recommendation) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (isSubmitting) return;

    try {
      setIsSubmitting(true);
      setError(null);

      const res = await feedbackService.submitFeedback(recommendation.id, {
        decision,
        usefulness_rating: rating,
        reason,
        comment: comment.trim() ? comment.trim() : null,
        faculty_notes: comment.trim() ? comment.trim() : null,
      });

      onSuccess(res.recommendation);
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to submit feedback. Please try again.');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  const getDecisionTheme = () => {
    switch (decision) {
      case 'ACCEPTED':
        return {
          title: 'Accept Recommendation',
          icon: CheckCircle2,
          iconColor: 'text-emerald-500',
          confirmText: 'Confirm & Accept',
          btnClass: 'bg-emerald-600 hover:bg-emerald-700 text-white',
        };
      case 'DISMISSED':
        return {
          title: 'Ignore / Dismiss Recommendation',
          icon: XCircle,
          iconColor: 'text-neutral-500',
          confirmText: 'Confirm & Dismiss',
          btnClass: 'bg-sage-700 dark:bg-white text-white dark:text-sage-800',
        };
      case 'REVIEWED':
        return {
          title: 'Mark as Reviewed',
          icon: Eye,
          iconColor: 'text-blue-500',
          confirmText: 'Mark Reviewed',
          btnClass: 'bg-blue-600 hover:bg-blue-700 text-white',
        };
    }
  };

  const theme = getDecisionTheme();
  const TitleIcon = theme.icon;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in duration-150">
      <div
        className="w-full max-w-lg bg-white dark:bg-[#1C1C1E] rounded-2xl shadow-2xl border border-sage-200 dark:border-[#2C2C2E] overflow-hidden space-y-0"
        role="dialog"
        aria-modal="true"
      >
        {/* Header */}
        <div className="flex items-center justify-between p-4 border-b border-sage-200 dark:border-[#2C2C2E]">
          <div className="flex items-center gap-2">
            <TitleIcon className={`w-5 h-5 ${theme.iconColor}`} />
            <h3 className="text-sm font-bold text-sage-800 dark:text-white">
              {theme.title}
            </h3>
          </div>
          <button
            type="button"
            onClick={onClose}
            disabled={isSubmitting}
            className="p-1 rounded-lg text-sage-500 hover:text-sage-800 dark:hover:text-white transition-colors"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        {/* Content Form */}
        <form onSubmit={handleSubmit} className="p-5 space-y-4 text-xs">
          {/* Recommendation Summary Card */}
          <div className="p-3 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl border border-sage-200 dark:border-[#3A3A3C] space-y-1">
            <div className="flex items-center justify-between">
              <span className="font-semibold text-[11px] text-sage-500 uppercase font-mono">
                {recommendation.category?.replace('_', ' ')}
              </span>
              <Badge variant="neutral" className="text-[10px]">
                {recommendation.priority} Priority
              </Badge>
            </div>
            <p className="font-bold text-sage-800 dark:text-white">
              {recommendation.problem || recommendation.title}
            </p>
            <p className="text-sage-500 text-[11px]">
              {recommendation.recommendation || recommendation.description}
            </p>
          </div>

          {/* Rating Control */}
          <FeedbackRating
            value={rating}
            onChange={setRating}
            disabled={isSubmitting}
          />

          {/* Reason Control */}
          <FeedbackReasonSelect
            value={reason}
            onChange={setReason}
            decision={decision}
            disabled={isSubmitting}
          />

          {/* Comments Field */}
          <div className="space-y-1">
            <div className="flex items-center justify-between">
              <label className="font-semibold text-sage-800 dark:text-white">
                Faculty Comments (Optional):
              </label>
              <span className="text-[10px] text-sage-500 font-mono">
                {comment.length}/2000
              </span>
            </div>
            <textarea
              rows={3}
              maxLength={2000}
              disabled={isSubmitting}
              placeholder="Provide context, observations, or implementation plans..."
              value={comment}
              onChange={(e) => setComment(e.target.value)}
              className="w-full rounded-xl border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] p-2.5 text-xs text-sage-800 dark:text-white placeholder-[#8E8E93] focus:outline-none focus:ring-1 focus:ring-black dark:focus:ring-white"
            />
          </div>

          {/* Error Banner */}
          {error && (
            <div className="p-3 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 rounded-xl flex items-center gap-2 text-red-600 dark:text-red-400 text-xs">
              <AlertCircle className="w-4 h-4 shrink-0" />
              <span>{error}</span>
            </div>
          )}

          {/* Decision Support Disclaimer */}
          <p className="text-[11px] text-sage-500 italic">
            * Human decision support signal: your feedback will be archived and converted into structured improvement signals without altering assessment questions or historical analysis scores.
          </p>

          {/* Modal Footer Actions */}
          <div className="flex items-center justify-end gap-2 pt-2 border-t border-sage-200 dark:border-[#2C2C2E]">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={isSubmitting}
              onClick={onClose}
              className="text-xs"
            >
              Cancel
            </Button>

            <Button
              type="submit"
              variant="primary"
              size="sm"
              disabled={isSubmitting}
              className={`text-xs px-4 font-semibold ${theme.btnClass}`}
            >
              {isSubmitting ? (
                <>
                  <Loader2 className="w-3.5 h-3.5 mr-1.5 animate-spin" />
                  Recording Decision...
                </>
              ) : (
                theme.confirmText
              )}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
};

