import React from 'react';
import { ExternalLink } from 'lucide-react';
import type { ExplanationEvidence } from '@/types/explainability';

interface SourceCitationProps {
  item: ExplanationEvidence;
  onOpen?: (item: ExplanationEvidence) => void;
}

/**
 * Citation for a retrieved document passage. Location is shown only when it is actually known —
 * otherwise "Source location unavailable" (never an invented page number).
 */
export const SourceCitation: React.FC<SourceCitationProps> = ({ item, onOpen }) => {
  const location = item.text && item.text.trim() !== '' ? item.text : 'Source location unavailable';
  const excerpt = typeof item.meta?.excerpt === 'string' ? (item.meta.excerpt as string) : null;
  const relevance = typeof item.meta?.relevance === 'number' ? (item.meta.relevance as number) : item.score ?? null;
  const canOpen = Boolean(onOpen && item.document_id);

  return (
    <div className="space-y-1" data-testid="source-citation">
      <div className="flex flex-wrap items-center gap-2 text-[11px] text-sage-500">
        <span className="font-mono">{location}</span>
        {relevance !== null && (
          <span title="Retrieval relevance (embedding similarity). Not an answer confidence.">relevance {relevance.toFixed(2)}</span>
        )}
        {canOpen && (
          <button
            type="button"
            onClick={() => onOpen?.(item)}
            className="inline-flex items-center gap-1 text-sage-800 dark:text-white underline-offset-2 hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-sage-700 rounded"
            aria-label={`Open source document ${item.label}`}
          >
            <ExternalLink className="w-3 h-3" aria-hidden="true" /> Open source
          </button>
        )}
      </div>
      {excerpt && <blockquote className="border-l-2 border-sage-300 pl-2 text-sage-700 dark:text-sage-300 italic">{excerpt}</blockquote>}
    </div>
  );
};
