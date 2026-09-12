import { apiClient } from './api';
import type {
  AiExplanation,
  AiResultType,
  ExplainabilityViewEvent,
  ExplanationResponse,
  ReviewRecord,
  ReviewResponse,
} from '@/types/explainability';

/**
 * STEP 45: AI explainability. Explanations are fetched lazily (only when faculty asks "Why?") and cached
 * in-memory per (type, id) for the page lifetime; reviews/overrides invalidate the cache entry.
 */
const cache = new Map<string, AiExplanation>();
const key = (type: AiResultType, id: number | string) => `${type}:${id}`;

export const explainabilityService = {
  getExplanation: async (type: AiResultType, id: number | string, options: { force?: boolean } = {}): Promise<AiExplanation> => {
    const k = key(type, id);
    if (!options.force && cache.has(k)) {
      return cache.get(k) as AiExplanation;
    }
    const res = await apiClient<ExplanationResponse>(`/ai-results/${type}/${id}/explanation`, { method: 'GET' });
    cache.set(k, res.data);
    return res.data;
  },

  getReviews: async (type: AiResultType, id: number | string): Promise<ReviewRecord[]> => {
    const res = await apiClient<{ status: string; data: ReviewRecord[] }>(`/ai-results/${type}/${id}/reviews`, { method: 'GET' });
    return res.data;
  },

  review: async (type: AiResultType, id: number | string, action: 'ACCEPTED' | 'REJECTED' | 'REVIEWED', comment?: string): Promise<ReviewResponse> => {
    const res = await apiClient<ReviewResponse>(`/ai-results/${type}/${id}/review`, {
      method: 'POST',
      body: JSON.stringify({ action, comment: comment || null }),
    });
    cache.delete(key(type, id));
    return res;
  },

  override: async (
    type: AiResultType,
    id: number | string,
    value: Record<string, unknown>,
    reason: string,
    comment?: string
  ): Promise<ReviewResponse> => {
    const res = await apiClient<ReviewResponse>(`/ai-results/${type}/${id}/override`, {
      method: 'POST',
      body: JSON.stringify({ value, reason, comment: comment || null }),
    });
    cache.delete(key(type, id));
    return res;
  },

  /** Fire-and-forget audit event; failures never disturb the UI. */
  recordEvent: (type: AiResultType, id: number | string, action: ExplainabilityViewEvent, meta: Record<string, unknown> = {}): void => {
    apiClient(`/ai-results/${type}/${id}/events`, { method: 'POST', body: JSON.stringify({ action, meta }) }).catch(() => undefined);
  },

  clearCache: (): void => {
    cache.clear();
  },
};
