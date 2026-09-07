import React from 'react';
import { cn } from '@/utils/cn';

export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label?: string;
  helperText?: string;
  error?: string;
  leftIcon?: React.ReactNode;
  rightIcon?: React.ReactNode;
}

export const Input = React.forwardRef<HTMLInputElement, InputProps>(
  ({ label, helperText, error, leftIcon, rightIcon, className, id, required, disabled, ...props }, ref) => {
    const inputId = id || (label ? label.toLowerCase().replace(/\s+/g, '-') : undefined);

    return (
      <div className="w-full space-y-1.5 text-left">
        {label && (
          <label
            htmlFor={inputId}
            className="block text-xs font-medium uppercase tracking-wider text-[#262626]"
          >
            {label}
            {required && <span className="text-[#DC2626] ml-1" aria-hidden="true">*</span>}
          </label>
        )}
        <div className="relative flex items-center">
          {leftIcon && (
            <div className="absolute left-3 flex items-center pointer-events-none text-[#737373]">
              {leftIcon}
            </div>
          )}
          <input
            id={inputId}
            ref={ref}
            disabled={disabled}
            aria-invalid={Boolean(error)}
            aria-describedby={error ? `${inputId}-error` : helperText ? `${inputId}-helper` : undefined}
            className={cn(
              'w-full rounded-lg border bg-white px-3.5 py-2 text-sm text-[#111111] transition-all duration-150',
              'placeholder:text-[#A3A3A3]',
              'focus:outline-none focus:ring-2 focus:ring-[#111111] focus:border-transparent',
              'disabled:bg-[#F7F7F5] disabled:text-[#A3A3A3] disabled:cursor-not-allowed',
              error
                ? 'border-[#DC2626] focus:ring-[#DC2626]'
                : 'border-[#E5E5E5] hover:border-[#CCCCCC]',
              leftIcon ? 'pl-10' : '',
              rightIcon ? 'pr-10' : '',
              className
            )}
            {...props}
          />
          {rightIcon && (
            <div className="absolute right-3 flex items-center text-[#737373]">
              {rightIcon}
            </div>
          )}
        </div>
        {error && (
          <p id={`${inputId}-error`} className="text-xs text-[#DC2626] mt-1 font-medium" role="alert">
            {error}
          </p>
        )}
        {!error && helperText && (
          <p id={`${inputId}-helper`} className="text-xs text-[#737373] mt-1">
            {helperText}
          </p>
        )}
      </div>
    );
  }
);

Input.displayName = 'Input';

