import React from 'react';
import { HelpCircle } from 'lucide-react';
import { cn } from '@/utils/cn';

interface WhyButtonProps extends Omit<React.ButtonHTMLAttributes<HTMLButtonElement>, 'children'> {
  label?: string;
  /** Accessible description of the result being explained. */
  describes: string;
}

/** Compact "Why?" trigger placed next to any AI result. */
export const WhyButton: React.FC<WhyButtonProps> = ({ label = 'Why?', describes, className, ...props }) => (
  <button
    type="button"
    aria-label={`${label} Explain ${describes}`}
    title={`Explain ${describes}`}
    data-testid="why-button"
    className={cn(
      'inline-flex items-center gap-1 rounded-md border border-sage-200 bg-white px-1.5 py-0.5 text-[10px] font-semibold text-sage-700 hover:border-sage-400 hover:text-sage-800',
      'dark:bg-[#2C2C2E] dark:border-[#3A3A3C] dark:text-sage-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage-700',
      className
    )}
    {...props}
  >
    <HelpCircle className="w-3 h-3" aria-hidden="true" />
    {label}
  </button>
);
