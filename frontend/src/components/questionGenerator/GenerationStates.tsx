import React from 'react';
import { AlertTriangle, Loader2, Sparkles, ShieldAlert } from 'lucide-react';
import { ApiError } from '@/services/api';
import { cn } from '@/utils/cn';

/** STEP 33: shared state components for the question generator. */

export const GenerationLoading: React.FC<{ label?: string }> = ({ label = 'Drafting questions under your constraints…' }) => (
  <div data-testid="generation-loading" role="status" className="flex items-center gap-2 rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-4 py-3 text-sm text-[#525252] dark:text-[#A3A3A3]">
    <Loader2 className="w-4 h-4 animate-spin" />
    <span>{label}</span>
  </div>
);

export const GenerationEmptyState: React.FC<{ title?: string; description?: string; className?: string }> = ({
  title = 'No generated questions yet',
  description = 'Set your constraints — course outcome, topic, type, difficulty, Bloom level and marks — then generate reviewable drafts.',
  className,
}) => (
  <div data-testid="generation-empty-state" className={cn('flex flex-col items-center justify-center text-center py-12 px-6 rounded-xl border border-dashed border-[#E5E5E5] dark:border-[#2A2A2A]', className)}>
    <div className="w-12 h-12 rounded-full bg-[#F7F7F5] dark:bg-[#1F1F1F] border border-[#E5E5E5] dark:border-[#2A2A2A] flex items-center justify-center text-[#737373] mb-4">
      <Sparkles className="w-6 h-6" />
    </div>
    <h4 className="text-base font-semibold text-[#111111] dark:text-white mb-1">{title}</h4>
    <p className="text-sm text-[#737373] max-w-md">{description}</p>
  </div>
);

export function getGenerationErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.status === 401) return 'Your session has expired. Please sign in again.';
    if (err.status === 403) return err.message || 'You do not have access to this course or request.';
    if (err.status === 404) return 'The requested item no longer exists.';
    if (err.status === 409) return err.message || 'This request is still being processed.';
    if (err.status === 422) return err.message || 'Please check the constraints and try again.';
    if (err.status === 429) return 'Question generation is rate-limited. Please wait a moment and try again.';
    if (err.status === 503 || err.status === 502 || err.status === 504) return err.message || 'The question generator is temporarily unavailable. Please try again.';
    return err.message || 'Something went wrong.';
  }
  return err instanceof Error ? err.message : 'Something went wrong.';
}

export const GenerationError: React.FC<{ message: string; onRetry?: () => void; className?: string }> = ({ message, onRetry, className }) => (
  <div data-testid="generation-error" role="alert" className={cn('flex items-start gap-2 rounded-lg border border-red-200 dark:border-red-900/50 bg-red-50 dark:bg-red-950/30 px-3 py-2 text-sm text-red-700 dark:text-red-300', className)}>
    <AlertTriangle className="w-4 h-4 mt-0.5 flex-shrink-0" />
    <div className="flex-1">
      <p>{message}</p>
      {onRetry && <button type="button" onClick={onRetry} className="mt-1 text-xs font-medium underline underline-offset-2">Try again</button>}
    </div>
  </div>
);

export const GenerationDisclaimer: React.FC<{ text?: string; className?: string }> = ({ text, className }) => (
  <div data-testid="generation-disclaimer" className={cn('flex items-start gap-2 rounded-lg border border-amber-200 dark:border-amber-900/50 bg-amber-50 dark:bg-amber-950/20 px-3 py-2 text-xs text-amber-800 dark:text-amber-300', className)}>
    <ShieldAlert className="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
    <p>{text || 'AI-generated questions are drafts. Review the wording, factual accuracy, difficulty, cognitive demand, course-outcome alignment, marks, and academic appropriateness before using them in an assessment.'}</p>
  </div>
);
