import React from 'react';
import { MinusCircle } from 'lucide-react';

/** Expected elements that were not (or only partially) found in the answer. */
export const MissingElements: React.FC<{ items: string[]; title?: string }> = ({ items, title = 'Missing' }) => {
  if (items.length === 0) return null;
  return (
    <div data-testid="missing-elements">
      <span className="block text-[10px] uppercase tracking-wider text-[#737373] mb-1">{title}</span>
      <ul className="space-y-1">
        {items.map((m, i) => (
          <li key={i} className="flex items-start gap-1.5 text-xs text-[#262626] dark:text-[#E5E5E5]">
            <MinusCircle className="w-3.5 h-3.5 text-amber-600 shrink-0 mt-0.5" /> {m}
          </li>
        ))}
      </ul>
    </div>
  );
};
