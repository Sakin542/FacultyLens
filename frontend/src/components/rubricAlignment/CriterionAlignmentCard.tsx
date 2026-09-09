import React, { useState } from 'react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import { CriterionAlignment } from '@/types/rubricAlignment';
import { AlignmentStatusBadge, alignmentSymbol } from './AlignmentStatusBadge';
import { AlignmentEvidence } from './AlignmentEvidence';
import { MissingElements } from './MissingElements';

const fmt = (v: number): string => (Number.isInteger(v) ? String(v) : String(Number(v.toFixed(2))));

/** One rubric criterion: status, alignment weight vs marks, evidence, missing elements, explanation. */
export const CriterionAlignmentCard: React.FC<{ item: CriterionAlignment; defaultOpen?: boolean }> = ({ item, defaultOpen = false }) => {
  const [open, setOpen] = useState(defaultOpen);
  const weightedMarks = Math.round(item.alignment_score * item.max_marks * 100) / 100;

  return (
    <li className="rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E]" data-testid="criterion-alignment">
      <button type="button" onClick={() => setOpen((v) => !v)} className="w-full flex items-center gap-3 p-3 text-left" aria-expanded={open}>
        <span className="text-sm w-5 text-center text-[#737373]" aria-hidden="true">{alignmentSymbol(item.alignment_status)}</span>
        <div className="flex-1 min-w-0">
          <div className="flex items-center justify-between gap-2 flex-wrap">
            <span className="text-xs font-semibold text-[#111111] dark:text-white truncate">{item.criterion}</span>
            <div className="flex items-center gap-2">
              <span className="text-[10px] font-mono text-[#737373]" data-testid="criterion-weight">
                {fmt(weightedMarks)} / {fmt(item.max_marks)} alignment weight
              </span>
              <AlignmentStatusBadge status={item.alignment_status} />
            </div>
          </div>
        </div>
        {open ? <ChevronUp className="w-4 h-4 text-[#737373] shrink-0" /> : <ChevronDown className="w-4 h-4 text-[#737373] shrink-0" />}
      </button>

      {open && (
        <div className="px-3 pb-3 pt-2 space-y-2.5 border-t border-[#E5E5E5] dark:border-[#2C2C2E]" data-testid="criterion-alignment-details">
          <p className="text-xs text-[#262626] dark:text-[#E5E5E5] leading-relaxed" data-testid="criterion-explanation">{item.explanation}</p>
          <AlignmentEvidence evidence={item.evidence} />
          <MissingElements items={item.missing_elements} />
          <p className="text-[10px] text-[#737373]">Maximum marks for this criterion: {fmt(item.max_marks)}. Alignment weight is not a mark.</p>
        </div>
      )}
    </li>
  );
};
