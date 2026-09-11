import React from 'react';
import { CheckCircle2, Leaf } from 'lucide-react';
import { Reveal } from '@/components/landing/Motion';

/** Centered auth card on the cream background, matching the landing theme. */
export const AuthShell: React.FC<{ eyebrow: string; title: string; subtitle: string; children: React.ReactNode; wide?: boolean; footer?: React.ReactNode }> = ({ eyebrow, title, subtitle, children, wide, footer }) => (
  <main className="relative overflow-hidden min-h-[calc(100vh-10rem)] flex items-center justify-center px-4 sm:px-6 lg:px-12 py-10 bg-sage-50">
    <Leaf className="landing-float absolute -left-16 top-10 w-72 h-72 text-sage-200/50 pointer-events-none" aria-hidden="true" />
    <Leaf className="landing-float-slow absolute -right-20 -bottom-24 w-80 h-80 text-sage-200/40 pointer-events-none" aria-hidden="true" />
    <Reveal className={`relative w-full ${wide ? 'max-w-2xl' : 'max-w-md'}`}>
      <div className="landing-showcase rounded-2xl bg-white p-7 sm:p-10 space-y-6">
        <header className="space-y-1.5">
          <p className="text-[11px] uppercase tracking-[0.18em] text-sage-500">{eyebrow}</p>
          <h1 className="font-serif text-3xl sm:text-[2.1rem] leading-tight tracking-tight text-sage-800">{title}</h1>
          <p className="text-sm text-sage-500">{subtitle}</p>
        </header>
        {children}
        {footer && <div className="pt-4 border-t border-sage-200 text-center text-xs text-sage-500">{footer}</div>}
      </div>
    </Reveal>
  </main>
);

export const authInputClass = 'border-sage-200 hover:border-sage-300 focus:ring-sage-600 rounded-lg';
export const authButtonClass = 'w-full justify-center bg-sage-700 hover:bg-sage-800 active:bg-sage-800 focus-visible:outline-sage-700 text-sm font-medium';
export const authLinkClass = 'font-semibold text-sage-800 underline-offset-2 hover:underline';

export const AuthAlert: React.FC<{ title: string; message: string }> = ({ title, message }) => (
  <div role="alert" className="p-3.5 rounded-xl bg-[#FEF2F2] border border-[#FECACA] text-xs text-[#991B1B] landing-stage">
    <span className="font-semibold block">{title}</span>
    <span className="text-[11px] leading-relaxed block mt-0.5">{message}</span>
  </div>
);

export const AuthSuccess: React.FC<{ message: string }> = ({ message }) => (
  <div role="status" className="p-4 rounded-xl bg-sage-100 border border-sage-200 flex items-center gap-3 text-sm text-sage-800 landing-stage">
    <CheckCircle2 className="w-5 h-5 shrink-0 text-sage-600" aria-hidden="true" />
    <span>{message}</span>
  </div>
);
