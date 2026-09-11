import React, { useEffect } from 'react';
import { useLocation } from 'react-router-dom';

const reduced = () => typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** On route change: scroll to the top (or to a #hash target) without fighting in-page anchor navigation. */
export const ScrollToTop: React.FC = () => {
  const { pathname, hash } = useLocation();
  useEffect(() => {
    if (hash) {
      const id = hash.slice(1);
      const t = window.setTimeout(() => document.getElementById(id)?.scrollIntoView({ behavior: reduced() ? 'auto' : 'smooth' }), 60);
      return () => window.clearTimeout(t);
    }
    window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
  }, [pathname, hash]);
  return null;
};

/** Fades/slides page content in on every route change. */
export const PageTransition: React.FC<{ children: React.ReactNode; className?: string }> = ({ children, className }) => {
  const { pathname } = useLocation();
  return (
    <div key={pathname} className={`page-enter ${className ?? ''}`}>
      {children}
    </div>
  );
};
