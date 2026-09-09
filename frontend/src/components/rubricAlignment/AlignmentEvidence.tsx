import React from 'react';
import { Quote } from 'lucide-react';

/** Answer excerpts that support a criterion. Never generated text — only what the student wrote. */
export const AlignmentEvidence: React.FC<{ evidence: string[]; emptyText?: string }> = ({ evidence, emptyText = 'No supporting evidence was identified in the answer.' }) => (
  <div data-testid="alignment-evidence">
    <span className="flex items-center gap-1 text-[10px] uppercase tracking-wider text-[#737373] mb-1">
      <Quote className="w-3 h-3" /> Evidence from the answer
    </span>
    {evidence.length > 0 ? (
      <ul className="space-y-1">
        {evidence.map((e, i) => (
          <li key={i} className="pl-2 border-l-2 border-emerald-300 dark:border-emerald-700 text-xs text-[#262626] dark:text-[#E5E5E5] italic">
            “{e}”
          </li>
        ))}
      </ul>
    ) : (
      <p className="text-xs text-[#737373] italic">{emptyText}</p>
    )}
  </div>
);
