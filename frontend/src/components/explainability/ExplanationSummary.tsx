import React from 'react';
import type { AiExplanation } from '@/types/explainability';

interface ExplanationSummaryProps {
  explanation: AiExplanation;
}

const statusNote: Record<string, string> = {
  unavailable: 'Evidence could not be loaded; the stored result is shown unchanged.',
  stale: 'The underlying data changed after this result was produced.',
  none: 'No evidence is stored for this result.',
  partial: 'Only part of the evidence was recorded for this result.',
};

/** Level 1 — Result + "Why this result?" (concise, evidence-based; never chain-of-thought). */
export const ExplanationSummary: React.FC<ExplanationSummaryProps> = ({ explanation }) => {
  const { result, explanation: ex, evidence_status, evidence_note } = explanation;
  const display = result.display ?? result.label ?? 'Not available';
  const note = evidence_note ?? statusNote[evidence_status];

  return (
    <section aria-labelledby="explanation-result-heading" className="space-y-3" data-testid="explanation-summary">
      <div>
        <h3 id="explanation-result-heading" className="text-[11px] font-semibold uppercase tracking-wide text-sage-500">
          AI Result
        </h3>
        <p className="mt-1 text-lg font-extrabold font-mono text-sage-800 dark:text-white" data-testid="explanation-result">
          {display}
        </p>
        {typeof result.similarity_display === 'string' && (
          <p className="text-xs text-sage-500 font-mono" title="Cosine similarity of sentence embeddings. Not a probability that the items are the same.">
            Similarity {result.similarity_display}
          </p>
        )}
      </div>

      <div>
        <h4 className="text-[11px] font-semibold uppercase tracking-wide text-sage-500">Why this result?</h4>
        <p className="mt-1 text-sm text-sage-700 dark:text-sage-200 leading-relaxed" data-testid="explanation-why">
          {ex.summary}
        </p>
      </div>

      {evidence_status !== 'available' && note && (
        <p role="status" className="text-xs rounded-lg border border-[#FDE68A] bg-[#FFFBEB] text-[#92400E] px-3 py-2" data-testid="evidence-status-note">
          <span className="font-semibold uppercase mr-1">{evidence_status}:</span>
          {note}
        </p>
      )}
    </section>
  );
};
