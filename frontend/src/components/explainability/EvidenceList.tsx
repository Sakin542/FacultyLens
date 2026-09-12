import React from 'react';
import type { ExplanationEvidence } from '@/types/explainability';
import { EvidenceItem } from './EvidenceItem';

interface EvidenceListProps {
  evidence: ExplanationEvidence[];
  onOpenSource?: (item: ExplanationEvidence) => void;
  emptyText?: string;
  limit?: number;
}

/** Evidence list with an accessible empty state. */
export const EvidenceList: React.FC<EvidenceListProps> = ({ evidence, onOpenSource, emptyText = 'No evidence is available for this result.', limit }) => {
  const items = limit ? evidence.slice(0, limit) : evidence;
  if (items.length === 0) {
    return (
      <p className="text-xs text-sage-500 italic" data-testid="evidence-empty">
        {emptyText}
      </p>
    );
  }
  return (
    <ul className="space-y-2" aria-label="Evidence" data-testid="evidence-list">
      {items.map((item, idx) => (
        <EvidenceItem key={`${item.type}-${idx}`} item={item} onOpenSource={onOpenSource} />
      ))}
      {limit && evidence.length > limit && (
        <li className="text-[11px] text-sage-500">+{evidence.length - limit} more in detailed evidence</li>
      )}
    </ul>
  );
};
