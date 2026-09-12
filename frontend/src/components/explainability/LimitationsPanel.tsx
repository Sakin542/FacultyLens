import React from 'react';
import { AlertCircle } from 'lucide-react';

interface LimitationsPanelProps {
  limitations: string[];
  disclaimer?: string;
}

/** Every major AI result carries a Limitations section so faculty know where the result could be wrong. */
export const LimitationsPanel: React.FC<LimitationsPanelProps> = ({ limitations, disclaimer }) => (
  <section aria-labelledby="explanation-limitations-heading" className="text-xs" data-testid="limitations-panel">
    <h4 id="explanation-limitations-heading" className="text-[11px] font-semibold uppercase tracking-wide text-sage-500 flex items-center gap-1.5">
      <AlertCircle className="w-3.5 h-3.5" aria-hidden="true" /> Limitations
    </h4>
    {limitations.length === 0 ? (
      <p className="mt-1 text-sage-500 italic">No specific limitations were recorded for this result.</p>
    ) : (
      <ul className="mt-1 list-disc list-inside space-y-0.5 text-sage-700 dark:text-sage-300">
        {limitations.map((l, i) => (
          <li key={i}>{l}</li>
        ))}
      </ul>
    )}
    {disclaimer && <p className="mt-2 text-[11px] text-sage-500 italic">{disclaimer}</p>}
  </section>
);
