import React, { useCallback, useEffect, useRef, useState } from 'react';
import { aiGradingService } from '@/services/aiGradingService';
import { ApiError } from '@/services/api';
import { AIGradingResult as AIGradingResultType, FinalGradePayload, isAIGradingActive } from '@/types/grading';
import { RubricAlignment } from '@/types/rubricAlignment';
import { StudentAnswer, SubmissionQuestion } from '@/types/submission';
import { AIGradingButton } from './AIGradingButton';
import { AIGradingLoading } from './AIGradingLoading';
import { AIGradingError, getGradingErrorMessage } from './AIGradingError';
import { AIGradingResult } from './AIGradingResult';
import { FinalGradeForm } from './FinalGradeForm';

interface AIGradingPanelProps {
  question: SubmissionQuestion;
  answer: StudentAnswer;
  readOnly?: boolean;
  pollIntervalMs?: number;
  /** Called after the faculty grade changes so the parent can refresh the submission. */
  onGradeSaved?: () => Promise<void> | void;
  /** STEP 28: current alignment analysis (server state) shown as a separate signal. */
  alignment?: RubricAlignment | null;
}

const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/jpg'];

/**
 * STEP 27: request -> poll -> result -> faculty review, for one answer.
 * Polls Laravel (never FastAPI) while a run is queued/processing.
 */
export const AIGradingPanel: React.FC<AIGradingPanelProps> = ({
  question,
  answer,
  readOnly = false,
  pollIntervalMs = 3000,
  onGradeSaved,
  alignment,
}) => {
  const [result, setResult] = useState<AIGradingResultType | null>(answer.ai_grading ?? null);
  const [requestError, setRequestError] = useState<Error | null>(null);
  const [isRequesting, setIsRequesting] = useState(false);
  const [isRegenerating, setIsRegenerating] = useState(false);
  const [showManual, setShowManual] = useState(false);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const mountedRef = useRef(true);

  useEffect(() => { setResult(answer.ai_grading ?? null); }, [answer.ai_grading, answer.id]);

  const stopPolling = useCallback(() => {
    if (timerRef.current !== null) {
      clearInterval(timerRef.current);
      timerRef.current = null;
    }
  }, []);

  const fetchResult = useCallback(async () => {
    try {
      const res = await aiGradingService.getAIGradingResult(answer.id);
      if (!mountedRef.current) return;
      setResult(res.data);
      if (!isAIGradingActive(res.data.grading_status)) stopPolling();
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) {
        stopPolling();
        if (mountedRef.current) setResult(null);
      }
      // other errors are transient — keep polling
    }
  }, [answer.id, stopPolling]);

  const startPolling = useCallback(() => {
    if (timerRef.current !== null) return;
    timerRef.current = setInterval(fetchResult, pollIntervalMs);
  }, [fetchResult, pollIntervalMs]);

  useEffect(() => {
    mountedRef.current = true;
    return () => { mountedRef.current = false; stopPolling(); };
  }, [stopPolling]);

  useEffect(() => {
    if (result && isAIGradingActive(result.grading_status)) startPolling();
    else stopPolling();
  }, [result, startPolling, stopPolling]);

  const toError = (err: unknown): Error => (err instanceof Error ? err : new Error('AI grading assistance failed. Please try again.'));

  const request = async () => {
    try {
      setIsRequesting(true);
      setRequestError(null);
      const res = await aiGradingService.requestAIGrading(answer.id);
      setResult(res.data);
    } catch (err) {
      setRequestError(toError(err));
    } finally {
      setIsRequesting(false);
    }
  };

  const regenerate = async () => {
    if (!result) return request();
    try {
      setIsRegenerating(true);
      setRequestError(null);
      const res = await aiGradingService.regenerateAIGrading(result.id);
      setResult(res.data);
    } catch (err) {
      setRequestError(toError(err));
    } finally {
      setIsRegenerating(false);
    }
  };

  const finalize = async (data: FinalGradePayload) => {
    const res = await aiGradingService.finalizeGrade(answer.id, data);
    if (res.data.ai_grading) setResult(res.data.ai_grading);
    setShowManual(false);
    await onGradeSaved?.();
  };

  const reject = async () => {
    if (!result) return;
    const res = await aiGradingService.rejectAIGrading(result.id);
    setResult(res.data);
  };

  const hasText = Boolean(answer.answer_text && answer.answer_text.trim());
  const isImageOnly = !hasText && (answer.answer_type === 'IMAGE' || (answer.has_file && IMAGE_TYPES.includes((answer.answer_file_type || '').toLowerCase())));
  const hasContent = hasText || answer.has_file;

  if (result && isAIGradingActive(result.grading_status)) {
    return <AIGradingLoading status={result.grading_status} />;
  }

  if (result && result.grading_status === 'FAILED') {
    return (
      <div className="space-y-3" data-testid="ai-grading-panel">
        <AIGradingError message={result.error_message} onRetry={readOnly ? undefined : request} isRetrying={isRequesting} />
        {requestError && <AIGradingError error={requestError} />}
      </div>
    );
  }

  if (result) {
    return (
      <div className="space-y-3" data-testid="ai-grading-panel">
        {requestError && <p className="text-xs text-red-600" role="alert">{getGradingErrorMessage(requestError)}</p>}
        <AIGradingResult
          result={result}
          answer={answer}
          maxMarks={question.marks}
          readOnly={readOnly}
          isRegenerating={isRegenerating}
          onRegenerate={regenerate}
          onFinalize={finalize}
          onReject={reject}
          alignment={alignment}
        />
      </div>
    );
  }

  if (readOnly) return null;

  return (
    <div className="space-y-3 pt-3 border-t border-dashed border-[#E5E5E5] dark:border-[#3A3A3C]" data-testid="ai-grading-panel">
      <AIGradingButton
        hasApprovedRubric={Boolean(question.approved_rubric)}
        hasContent={hasContent}
        isImageOnly={isImageOnly}
        isLoading={isRequesting}
        onClick={request}
      />
      {requestError && <AIGradingError error={requestError} onRetry={request} isRetrying={isRequesting} />}
      {showManual ? (
        <FinalGradeForm
          answerId={answer.id}
          maxMarks={question.marks}
          initialMarks={answer.awarded_marks}
          initialFeedback={answer.faculty_feedback}
          onSubmit={finalize}
          onCancel={() => setShowManual(false)}
          submitLabel="Save Final Marks"
        />
      ) : (
        <button
          type="button"
          onClick={() => setShowManual(true)}
          className="text-[11px] text-[#737373] hover:text-[#111111] dark:hover:text-white underline"
          data-testid="grade-manually"
        >
          Grade without AI assistance
        </button>
      )}
    </div>
  );
};
