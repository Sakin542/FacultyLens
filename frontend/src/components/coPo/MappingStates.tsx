import React from 'react';
import { AlertCircle, Grid3X3, Info, Loader2, RotateCcw } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { ApiError } from '@/services/api';
import { CO_PO_DISCLAIMER } from '@/types/coPo';

export const MappingDisclaimer: React.FC<{ className?: string }> = ({ className = '' }) => (
  <p className={`text-[11px] text-[#737373] italic flex items-start gap-1.5 ${className}`} data-testid="mapping-disclaimer">
    <Info className="w-3 h-3 shrink-0 mt-0.5" /> {CO_PO_DISCLAIMER}
  </p>
);

export const MappingLoading: React.FC<{ text?: string }> = ({ text = 'Loading CO/PO mapping…' }) => (
  <div className="p-6 rounded-xl bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center gap-3 text-xs text-[#737373]" role="status" data-testid="mapping-loading">
    <Loader2 className="w-4 h-4 animate-spin" /> {text}
  </div>
);

export const MappingEmptyState: React.FC<{ title: string; description: string; action?: React.ReactNode }> = ({ title, description, action }) => (
  <div className="p-6 rounded-xl border border-dashed border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] text-center space-y-2" data-testid="mapping-empty">
    <div className="w-10 h-10 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] flex items-center justify-center mx-auto"><Grid3X3 className="w-5 h-5 text-[#737373]" /></div>
    <h4 className="text-sm font-bold text-[#111111] dark:text-white">{title}</h4>
    <p className="text-xs text-[#737373] max-w-md mx-auto">{description}</p>
    {action}
  </div>
);

export function getMappingErrorMessage(error: unknown): string {
  const status = error instanceof ApiError ? error.status : null;
  const raw = error instanceof Error ? error.message : null;
  switch (status) {
    case 401: return 'Your session has expired. Please sign in again.';
    case 403: return 'You are not authorized to manage CO/PO mapping for this course.';
    case 404: return 'The course, program or mapping could not be found.';
    case 409: return raw || 'A mapping analysis for the current mappings already exists.';
    case 422: return raw || 'The mapping request was not valid.';
    case 429: return 'Too many requests. Please wait a moment and try again.';
    case 500: case 502: case 503: case 504: return 'CO/PO mapping analysis is temporarily unavailable. Please try again later.';
    default: return raw || 'The CO/PO mapping could not be loaded.';
  }
}

export const MappingError: React.FC<{ error?: unknown; message?: string | null; onRetry?: () => void; isRetrying?: boolean }> = ({ error, message, onRetry, isRetrying = false }) => (
  <div className="p-3.5 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 rounded-xl flex items-start gap-3 text-red-700 dark:text-red-300" role="alert" data-testid="mapping-error">
    <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
    <div className="flex-1 space-y-2">
      <p className="text-xs leading-relaxed">{message || getMappingErrorMessage(error)}</p>
      {onRetry && (
        <Button variant="outline" size="sm" leftIcon={<RotateCcw className={`w-3.5 h-3.5 ${isRetrying ? 'animate-spin' : ''}`} />} onClick={onRetry} disabled={isRetrying}>
          {isRetrying ? 'Retrying…' : 'Try Again'}
        </Button>
      )}
    </div>
  </div>
);
