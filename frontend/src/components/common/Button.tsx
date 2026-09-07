import React from 'react';
import { cn } from '@/utils/cn';
import { Loader2 } from 'lucide-react';

export type ButtonVariant = 'primary' | 'secondary' | 'outline' | 'danger' | 'ghost';
export type ButtonSize = 'sm' | 'md' | 'lg';

export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  isLoading?: boolean;
  leftIcon?: React.ReactNode;
  rightIcon?: React.ReactNode;
}

export const Button: React.FC<ButtonProps> = ({
  children,
  className,
  variant = 'primary',
  size = 'md',
  isLoading = false,
  leftIcon,
  rightIcon,
  disabled,
  ...props
}) => {
  const baseStyles = 'inline-flex items-center justify-center font-medium transition-all duration-150 rounded-lg select-none disabled:opacity-50 disabled:cursor-not-allowed disabled:pointer-events-none focus-visible:outline-2 focus-visible:outline-offset-2';

  const variants: Record<ButtonVariant, string> = {
    primary: 'bg-[#111111] text-white hover:bg-[#262626] active:bg-black focus-visible:outline-[#111111] shadow-subtle',
    secondary: 'bg-[#E5E5E5] text-[#111111] hover:bg-[#D4D4D4] active:bg-[#CCCCCC] focus-visible:outline-[#737373]',
    outline: 'border border-[#E5E5E5] bg-white text-[#111111] hover:bg-[#F7F7F5] hover:border-[#CCCCCC] active:bg-[#EAEAEA] focus-visible:outline-[#111111]',
    danger: 'bg-[#DC2626] text-white hover:bg-[#B91C1C] active:bg-[#991B1B] focus-visible:outline-[#DC2626] shadow-subtle',
    ghost: 'text-[#111111] hover:bg-[#F7F7F5] active:bg-[#EAEAEA] focus-visible:outline-[#111111]',
  };

  const sizes: Record<ButtonSize, string> = {
    sm: 'text-xs px-3 py-1.5 gap-1.5',
    md: 'text-sm px-4 py-2 gap-2',
    lg: 'text-base px-5 py-2.5 gap-2.5',
  };

  return (
    <button
      className={cn(baseStyles, variants[variant], sizes[size], className)}
      disabled={disabled || isLoading}
      {...props}
    >
      {isLoading ? (
        <Loader2 className="w-4 h-4 animate-spin shrink-0" aria-hidden="true" />
      ) : (
        leftIcon && <span className="shrink-0">{leftIcon}</span>
      )}
      <span>{children}</span>
      {!isLoading && rightIcon && <span className="shrink-0">{rightIcon}</span>}
    </button>
  );
};

