import React from 'react';
import { HelpCircle } from 'lucide-react';
import { cn } from '@/utils/cn';

export interface ExplainabilityBadgeProps extends Omit<React.ButtonHTMLAttributes<HTMLButtonElement>, 'onClick'> {
  /** Result label shown in the badge (e.g. "Bloom: ANALYZE"). */
  label: React.ReactNode;
  /** Opens the explanation (lazy load). */
  onExplain: () => void;
  tone?: 'neutral' | 'good' | 'attention' | 'critical' | 'outline';
  /** Screen-reader description of what will be explained. */
  explainLabel?: string;
}

const tones: Record<NonNullable<ExplainabilityBadgeProps['tone']>, string> = {
  neutral: 'bg-sage-100 text-sage-700 border-sage-200 hover:border-sage-400',
  good: 'bg-[#F0FDF4] text-[#166534] border-[#BBF7D0] hover:border-[#16A34A]',
  attention: 'bg-[#FFFBEB] text-[#92400E] border-[#FDE68A] hover:border-[#D97706]',
  critical: 'bg-[#FEF2F2] text-[#991B1B] border-[#FECACA] hover:border-[#DC2626]',
  outline: 'bg-transparent text-sage-700 border-sage-200 hover:border-sage-400',
};

/**
 * An AI-result badge with an inline "Why?" affordance. Label text always carries the meaning (never colour alone).
 */
export const ExplainabilityBadge: React.FC<ExplainabilityBadgeProps> = ({ label, onExplain, tone = 'neutral', explainLabel, className, ...props }) => (
  <button
    type="button"
    onClick={onExplain}
    aria-label={explainLabel ?? `Why? Explain ${typeof label === 'string' ? label : 'this AI result'}`}
    title="Why this result? View the AI explanation"
    data-testid="explainability-badge"
    className={cn(
      'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide transition-colors',
      'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sage-700',
      tones[tone],
      className
    )}
    {...props}
  >
    <span>{label}</span>
    <HelpCircle className="w-3 h-3 shrink-0" aria-hidden="true" />
    <span className="sr-only">Why?</span>
  </button>
);
