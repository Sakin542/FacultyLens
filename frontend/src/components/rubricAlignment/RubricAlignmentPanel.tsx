import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Target } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { rubricAlignmentService } from '@/services/rubricAlignmentService';
import { ApiError } from '@/services/api';
import { AIGradingResult } from '@/types/grading';
import { RubricAlignment, isAlignmentActive } from '@/types/rubricAlignment';
import { StudentAnswer, SubmissionQuestion } from '@/types/submission';
import { AlignmentLoading } from './AlignmentLoading';
import { AlignmentError, getAlignmentErrorMessage } from './AlignmentError';
import { RubricAlignmentCard } from './RubricAlignmentCard';
import { AlignmentDisclaimer } from './AlignmentDisclaimer';

interface RubricAlignmentPanelProps {
  question: SubmissionQuestion;
  answer: StudentAnswer;
  /** Current STEP 27 suggestion (server state) for side-by-side comparison. */
  aiGrading?: AIGradingResult | null;
  readOnly?: boolean;
  pollIntervalMs?: number;
}

const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/jpg'];

/**
 * STEP 28: request -> poll -> result -> faculty review for one answer.
 * Polls Laravel only; never touches marks or feedback.
 */
export const RubricAlignmentPanel: React.FC<RubricAlignmentPanelProps> = ({
  question,
  answer,
  aiGrading,
  readOnly = false,
  pollIntervalMs = 3000,
}) => {
  const [alignment, setAlignment] = useState<RubricAlignment | null>(answer.rubric_alignment ?? null);
  const [requestError, setRequestError] = useState<Error | null>(null);
  const [isRequesting, setIsRequesting] = useState(false);
  const [isRegenerating, setIsRegenerating] = useState(false);
  const [isReviewing, setIsReviewing] = useState(false);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const mountedRef = useRef(true);

  useEffect(() => { setAlignment(answer.rubric_alignment ?? null); }, [answer.rubric_alignment, answer.id]);

  const stopPolling = useCallback(() => {
    if (timerRef.current !== null) {
      clearInterval(timerRef.current);
      timerRef.current = null;
    }
  }, []);

  const fetchAlignment = useCallback(async () => {
    try {
      const res = await rubricAlignmentService.getAlignment(answer.id);
      if (!mountedRef.current) return;
      setAlignment(res.data);
      if (!isAlignmentActive(res.data.analysis_status)) stopPolling();
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) {
        stopPolling();
        if (mountedRef.current) setAlignment(null);
      }
    }
  }, [answer.id, stopPolling]);

  const startPolling = useCallback(() => {
    if (timerRef.current !== null) return;
    timerRef.current = setInterval(fetchAlignment, pollIntervalMs);
  }, [fetchAlignment, pollIntervalMs]);

  useEffect(() => {
    mountedRef.current = true;
    return () => { mountedRef.current = false; stopPolling(); };
  }, [stopPolling]);

  useEffect(() => {
    if (alignment && isAlignmentActive(alignment.analysis_status)) startPolling();
    else stopPolling();
  }, [alignment, startPolling, stopPolling]);

  const toError = (err: unknown): Error => (err instanceof Error ? err : new Error('Rubric alignment analysis failed. Please try again.'));

  const request = async () => {
    try {
      setIsRequesting(true);
      setRequestError(null);
      const res = await rubricAlignmentService.requestAlignment(answer.id);
      setAlignment(res.data);
    } catch (err) {
      setRequestError(toError(err));
    } finally {
      setIsRequesting(false);
    }
  };

  const regenerate = async () => {
    if (!alignment) return request();
    try {
      setIsRegenerating(true);
      setRequestError(null);
      const res = await rubricAlignmentService.regenerateAlignment(alignment.id);
      setAlignment(res.data);
    } catch (err) {
      setRequestError(toError(err));
    } finally {
      setIsRegenerating(false);
    }
  };

  const markReviewed = async () => {
    if (!alignment) return;
    try {
      setIsReviewing(true);
      setRequestError(null);
      const res = await rubricAlignmentService.markReviewed(alignment.id);
      setAlignment(res.data);
    } catch (err) {
      setRequestError(toError(err));
    } finally {
      setIsReviewing(false);
    }
  };

  const hasText = Boolean(answer.answer_text && answer.answer_text.trim());
  const isImageOnly = !hasText && (answer.answer_type === 'IMAGE' || (answer.has_file && IMAGE_TYPES.includes((answer.answer_file_type || '').toLowerCase())));
  const hasContent = hasText || answer.has_file;

  if (alignment && isAlignmentActive(alignment.analysis_status)) {
    return <AlignmentLoading status={alignment.analysis_status} />;
  }

  if (alignment && alignment.analysis_status === 'FAILED') {
    return (
      <div className="space-y-3" data-testid="rubric-alignment-panel">
        <AlignmentError message={alignment.error_message} onRetry={readOnly ? undefined : request} isRetrying={isRequesting} />
      </div>
    );
  }

  if (alignment) {
    return (
      <div className="space-y-3" data-testid="rubric-alignment-panel">
        {requestError && <p className="text-xs text-red-600" role="alert">{getAlignmentErrorMessage(requestError)}</p>}
        <RubricAlignmentCard
          alignment={alignment}
          aiGrading={aiGrading}
          readOnly={readOnly}
          isRegenerating={isRegenerating}
          isReviewing={isReviewing}
          onRegenerate={regenerate}
          onMarkReviewed={markReviewed}
        />
      </div>
    );
  }

  if (readOnly) return null;

  let blocked: string | null = null;
  if (!question.approved_rubric) blocked = 'An approved rubric is required before rubric alignment can be analyzed.';
  else if (isImageOnly) blocked = 'Image-based alignment analysis is not currently supported. Add the answer text to continue.';
  else if (!hasContent) blocked = 'This answer has no content to analyze.';

  return (
    <div className="space-y-2 pt-3 border-t border-dashed border-sage-200 dark:border-[#3A3A3C]" data-testid="rubric-alignment-panel">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="text-[11px] text-sage-500">
          {question.approved_rubric ? (
            <span data-testid="alignment-rubric-info">Approved rubric v{question.approved_rubric.version} · {question.marks} marks</span>
          ) : (
            <span>No approved rubric</span>
          )}
        </div>
        <Button
          variant="outline"
          size="sm"
          leftIcon={<Target className="w-3.5 h-3.5" />}
          onClick={request}
          disabled={blocked !== null}
          isLoading={isRequesting}
          data-testid="analyze-alignment-button"
        >
          Analyze Rubric Alignment
        </Button>
      </div>
      {blocked && <p className="text-[11px] text-amber-700 dark:text-amber-400" data-testid="alignment-blocked">{blocked}</p>}
      {requestError && <AlignmentError error={requestError} onRetry={request} isRetrying={isRequesting} />}
      <AlignmentDisclaimer />
    </div>
  );
};
