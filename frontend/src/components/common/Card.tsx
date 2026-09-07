import React from 'react';
import { cn } from '@/utils/cn';

export interface CardProps extends React.HTMLAttributes<HTMLDivElement> {
  variant?: 'default' | 'muted' | 'outline' | 'interactive';
  padding?: 'none' | 'sm' | 'md' | 'lg';
}

export const Card: React.FC<CardProps> = ({
  children,
  className,
  variant = 'default',
  padding = 'md',
  ...props
}) => {
  const variants = {
    default: 'bg-white border border-[#E5E5E5] shadow-subtle',
    muted: 'bg-[#F7F7F5] border border-[#E5E5E5]',
    outline: 'bg-transparent border border-[#E5E5E5]',
    interactive: 'bg-white border border-[#E5E5E5] shadow-subtle hover:border-[#111111] hover:shadow-card transition-all duration-150 cursor-pointer',
  };

  const paddings = {
    none: 'p-0',
    sm: 'p-4',
    md: 'p-6',
    lg: 'p-8',
  };

  return (
    <div
      className={cn('rounded-xl transition-colors', variants[variant], paddings[padding], className)}
      {...props}
    >
      {children}
    </div>
  );
};

export const CardHeader: React.FC<React.HTMLAttributes<HTMLDivElement>> = ({
  children,
  className,
  ...props
}) => (
  <div className={cn('flex flex-col space-y-1.5 pb-4 border-b border-[#E5E5E5] mb-4', className)} {...props}>
    {children}
  </div>
);

export const CardTitle: React.FC<React.HTMLAttributes<HTMLHeadingElement>> = ({
  children,
  className,
  ...props
}) => (
  <h3 className={cn('font-semibold text-lg text-[#111111] tracking-tight', className)} {...props}>
    {children}
  </h3>
);

export const CardDescription: React.FC<React.HTMLAttributes<HTMLParagraphElement>> = ({
  children,
  className,
  ...props
}) => (
  <p className={cn('text-sm text-[#737373]', className)} {...props}>
    {children}
  </p>
);

export const CardContent: React.FC<React.HTMLAttributes<HTMLDivElement>> = ({
  children,
  className,
  ...props
}) => <div className={cn('', className)} {...props}>{children}</div>;

export const CardFooter: React.FC<React.HTMLAttributes<HTMLDivElement>> = ({
  children,
  className,
  ...props
}) => (
  <div className={cn('flex items-center pt-4 border-t border-[#E5E5E5] mt-4', className)} {...props}>
    {children}
  </div>
);

