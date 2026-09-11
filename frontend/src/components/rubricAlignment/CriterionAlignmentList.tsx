import React from 'react';
import { CriterionAlignment } from '@/types/rubricAlignment';
import { CriterionAlignmentCard } from './CriterionAlignmentCard';

export const CriterionAlignmentList: React.FC<{ items: CriterionAlignment[] }> = ({ items }) => {
  if (items.length === 0) {
    return <p className="text-xs text-sage-500 italic">No criterion-level alignment was returned.</p>;
  }
  return (
    <div className="space-y-2" data-testid="criterion-alignment-list">
      <span className="block text-[10px] uppercase tracking-wider font-semibold text-sage-500">Criterion Breakdown</span>
      <ul className="space-y-2">
        {items.map((c) => <CriterionAlignmentCard key={c.id ?? `${c.rubric_criterion_id}-${c.criterion}`} item={c} />)}
      </ul>
    </div>
  );
};
