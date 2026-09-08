import { useState, useEffect, useRef, useCallback } from "react";
import { aiService } from "@/services/aiService";

export type AnalysisStatus = "not_analyzed" | "processing" | "completed" | "failed";

export interface AnalysisStatusResult {
  analysis_status: AnalysisStatus;
  analysis_id: number | null;
  overall_score: number | null;
  processing_error: string | null;
  analyzed_at: string | null;
  updated_at: string | null;
}

interface UseAnalysisPollingOptions {
  assessmentId: number | null | undefined;
  intervalMs?: number;
  onCompleted?: (result: AnalysisStatusResult) => void;
  onFailed?: (error: string | null) => void;
}

/**
 * Polls the lightweight analysis-status endpoint every {@link intervalMs} ms
 * while analysis_status === "processing". Stops automatically on terminal states.
 */
export function useAnalysisPolling({
  assessmentId,
  intervalMs = 3000,
  onCompleted,
  onFailed,
}: UseAnalysisPollingOptions) {
  const [status, setStatus] = useState<AnalysisStatusResult | null>(null);
  const [isPolling, setIsPolling] = useState(false);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const mountedRef = useRef(true);

  const stopPolling = useCallback(() => {
    if (timerRef.current !== null) {
      clearInterval(timerRef.current);
      timerRef.current = null;
    }
    setIsPolling(false);
  }, []);

  const fetchStatus = useCallback(async () => {
    if (!assessmentId) return;
    try {
      const result = await aiService.getAnalysisStatus(assessmentId);
      if (!mountedRef.current) return;
      setStatus(result);
      if (result.analysis_status === "completed") {
        stopPolling();
        onCompleted?.(result);
      } else if (result.analysis_status === "failed") {
        stopPolling();
        onFailed?.(result.processing_error);
      }
    } catch {
      // Network errors are transient — keep polling
    }
  }, [assessmentId, stopPolling, onCompleted, onFailed]);

  const startPolling = useCallback(() => {
    if (!assessmentId || timerRef.current !== null) return;
    setIsPolling(true);
    fetchStatus();
    timerRef.current = setInterval(fetchStatus, intervalMs);
  }, [assessmentId, fetchStatus, intervalMs]);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      stopPolling();
    };
  }, [stopPolling]);

  return { status, isPolling, startPolling, stopPolling };
}
