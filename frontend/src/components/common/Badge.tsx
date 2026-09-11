import React from 'react';
import { cn } from '@/utils/cn';

export type BadgeVariant =
  | 'Analyzed'
  | 'Pending'
  | 'Good'
  | 'Attention'
  | 'Critical'
  | 'default'
  | 'outline'
  | 'neutral';

export interface BadgeProps extends React.HTMLAttributes<HTMLSpanElement> {
  variant?: BadgeVariant;
  size?: 'sm' | 'md';
  dot?: boolean;
}

export const Badge: React.FC<BadgeProps> = ({
  children,
  className,
  variant = 'default',
  size = 'sm',
  dot = false,
  ...props
}) => {
  const variantStyles: Record<BadgeVariant, { container: string; dot: string }> = {
    Analyzed: {
      container: 'bg-[#F0FDF4] text-[#166534] border border-[#BBF7D0]',
      dot: 'bg-[#16A34A]',
    },
    Pending: {
      container: 'bg-[#FFFBEB] text-[#92400E] border border-[#FDE68A]',
      dot: 'bg-[#D97706]',
    },
    Good: {
      container: 'bg-[#F0FDF4] text-[#166534] border border-[#BBF7D0]',
      dot: 'bg-[#16A34A]',
    },
    Attention: {
      container: 'bg-[#FFFBEB] text-[#92400E] border border-[#FDE68A]',
      dot: 'bg-[#D97706]',
    },
    Critical: {
      container: 'bg-[#FEF2F2] text-[#991B1B] border border-[#FECACA]',
      dot: 'bg-[#DC2626]',
    },
    default: {
      container: 'bg-sage-700 text-white border border-sage-700',
      dot: 'bg-white',
    },
    neutral: {
      container: 'bg-sage-100 text-sage-700 border border-sage-200',
      dot: 'bg-sage-400',
    },
    outline: {
      container: 'bg-transparent text-sage-700 border border-sage-200',
      dot: 'bg-sage-700',
    },
  };

  const sizes = {
    sm: 'text-xs px-2 py-0.5 gap-1.5 font-medium',
    md: 'text-xs px-2.5 py-1 gap-1.5 font-medium',
  };

  const selected = variantStyles[variant] || variantStyles.neutral;

  return (
    <span
      className={cn(
        'inline-flex items-center rounded-md select-none tracking-tight',
        selected.container,
        sizes[size],
        className
      )}
      {...props}
    >
      {dot && (
        <span
          className={cn('w-1.5 h-1.5 rounded-full shrink-0 animate-pulse', selected.dot)}
          aria-hidden="true"
        />
      )}
      <span>{children}</span>
    </span>
  );
};

