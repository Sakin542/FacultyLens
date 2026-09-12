import React from 'react';
import type { AiExplanation } from '@/types/explainability';

interface ConfidenceIndicatorProps {
  confidence: AiExplanation['confidence'];
}

/**
 * Confidence ≠ correctness. Shows the value only when the underlying method actually produces one;
 * otherwise states "Not available" (never fabricated).
 */
export const ConfidenceIndicator: React.FC<ConfidenceIndicatorProps> = ({ confidence }) => {
  const pct = confidence.available && confidence.value !== null ? Math.round(confidence.value * 100) : null;
  return (
    <section aria-labelledby="explanation-confidence-heading" className="text-xs" data-testid="confidence-indicator">
      <h4 id="explanation-confidence-heading" className="text-[11px] font-semibold uppercase tracking-wide text-sage-500">
        Confidence
      </h4>
      <p className="mt-1 font-mono font-bold text-sage-800 dark:text-white" data-testid="confidence-value">
        {pct !== null ? `Available: ${pct}%` : 'Not available'}
      </p>
      {pct !== null && (
        <div className="mt-1 h-1.5 w-full rounded-full bg-sage-200 dark:bg-[#3A3A3C]" role="meter" aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct} aria-label="Confidence">
          <div className="h-1.5 rounded-full bg-sage-700" style={{ width: `${pct}%` }} />
        </div>
      )}
      <p className="mt-1 text-sage-500 leading-relaxed">{confidence.note}</p>
    </section>
  );
};
