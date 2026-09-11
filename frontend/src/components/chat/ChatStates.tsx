import React from 'react';
import { AlertTriangle, Loader2, MessageSquareText, ShieldAlert } from 'lucide-react';
import { ApiError } from '@/services/api';
import { cn } from '@/utils/cn';

/**
 * STEP 32: Small, dependency-free chat state components.
 */

export const ChatEmptyState: React.FC<{ title?: string; description?: string; className?: string }> = ({
  title = 'Ask about your documents',
  description = 'Questions are answered only from the indexed documents in this chat scope. Try asking about a topic, a deadline, or a definition from your syllabus or lecture notes.',
  className,
}) => (
  <div data-testid="chat-empty-state" className={cn('flex flex-col items-center justify-center text-center py-12 px-6', className)}>
    <div className="w-12 h-12 rounded-full bg-sage-100 dark:bg-[#1F1F1F] border border-sage-200 dark:border-[#2A2A2A] flex items-center justify-center text-sage-500 mb-4">
      <MessageSquareText className="w-6 h-6" />
    </div>
    <h4 className="text-base font-semibold text-sage-800 dark:text-white mb-1">{title}</h4>
    <p className="text-sm text-sage-500 max-w-md">{description}</p>
  </div>
);

export const ChatLoading: React.FC<{ label?: string }> = ({ label = 'Searching your documents…' }) => (
  <div data-testid="chat-loading" role="status" className="flex items-center gap-2 text-sm text-sage-500 px-4 py-3">
    <Loader2 className="w-4 h-4 animate-spin" />
    <span>{label}</span>
  </div>
);

export function getChatErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.status === 401) return 'Your session has expired. Please sign in again.';
    if (err.status === 403) return 'You do not have access to this chat.';
    if (err.status === 404) return 'This chat session no longer exists.';
    if (err.status === 409) return err.message || 'No indexed documents are available in this chat scope yet.';
    if (err.status === 422) return err.message || 'Please check your question and try again.';
    if (err.status === 429) return 'You are sending messages too quickly. Please wait a moment and try again.';
    if (err.status === 503 || err.status === 502) return err.message || 'The assistant is temporarily unavailable. Your question was not saved — please try again.';
    return err.message || 'Something went wrong.';
  }
  if (err instanceof Error) return err.message;
  return 'Something went wrong.';
}

export const ChatError: React.FC<{ message: string; onRetry?: () => void; className?: string }> = ({ message, onRetry, className }) => (
  <div
    data-testid="chat-error"
    role="alert"
    className={cn('flex items-start gap-2 rounded-lg border border-red-200 dark:border-red-900/50 bg-red-50 dark:bg-red-950/30 px-3 py-2 text-sm text-red-700 dark:text-red-300', className)}
  >
    <AlertTriangle className="w-4 h-4 mt-0.5 flex-shrink-0" />
    <div className="flex-1">
      <p>{message}</p>
      {onRetry && (
        <button type="button" onClick={onRetry} className="mt-1 text-xs font-medium underline underline-offset-2">
          Try again
        </button>
      )}
    </div>
  </div>
);

export const GroundingDisclaimer: React.FC<{ text?: string; className?: string }> = ({ text, className }) => (
  <div
    data-testid="grounding-disclaimer"
    className={cn('flex items-start gap-2 rounded-lg border border-amber-200 dark:border-amber-900/50 bg-amber-50 dark:bg-amber-950/20 px-3 py-2 text-xs text-amber-800 dark:text-amber-300', className)}
  >
    <ShieldAlert className="w-3.5 h-3.5 mt-0.5 flex-shrink-0" />
    <p>
      {text ||
        'AI-generated answers are based only on the retrieved document excerpts and may be incomplete or imprecise. Verify against the original documents before relying on them.'}
    </p>
  </div>
);
