import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, ArrowUp, CheckCircle2, Github, Leaf, Linkedin, Mail, ShieldCheck, Twitter } from 'lucide-react';
import { BrandMark, useScrollToSection } from './Navbar';

const PRODUCT: { id: string; label: string }[] = [
  { id: 'features', label: 'Features' },
  { id: 'how-it-works', label: 'How it works' },
  { id: 'about', label: 'From questions to outcomes' },
  { id: 'pricing', label: 'Get started' },
];

const PLATFORM: { to: string; label: string }[] = [
  { to: '/login', label: 'Sign in' },
  { to: '/register', label: 'Create faculty account' },
  { to: '/dashboard', label: 'Dashboard' },
  { to: '/assessments', label: 'Assessments' },
  { to: '/analytics', label: 'Academic analytics' },
];

const CAPABILITIES: { to: string; label: string }[] = [
  { to: '/question-bank', label: 'Question bank' },
  { to: '/question-generator', label: 'Question generator' },
  { to: '/academic-chat', label: 'RAG assistant' },
  { to: '/history', label: 'Analysis history' },
  { to: '/ai-evaluation', label: 'AI evaluation' },
];

const linkClass = 'group inline-flex items-start gap-1 text-left text-sm leading-snug text-white/65 hover:text-white transition-colors';
const Arrow: React.FC = () => <ArrowRight className="w-3 h-3 mt-1 shrink-0 -translate-x-1 opacity-0 transition-all group-hover:translate-x-0 group-hover:opacity-100" aria-hidden="true" />;

export const Footer: React.FC = () => {
  const scrollToSection = useScrollToSection();
  const [email, setEmail] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [subscribed, setSubscribed] = useState(false);

  const subscribe = (e: React.FormEvent) => {
    e.preventDefault();
    if (!/^\S+@\S+\.\S+$/.test(email.trim())) { setError('Enter a valid email address.'); return; }
    setError(null);
    setSubscribed(true);
    setEmail('');
    window.setTimeout(() => setSubscribed(false), 5000);
  };

  return (
    <footer className="relative overflow-hidden bg-sage-800 text-white/80">
      <div className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-sage-300/60 to-transparent" aria-hidden="true" />
      <Leaf className="landing-float-slow absolute -right-16 -bottom-16 w-72 h-72 text-white/[0.04] pointer-events-none" aria-hidden="true" />

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-12 pb-8 relative">
        {/* Brand row */}
        <div className="flex flex-col md:flex-row md:items-start md:justify-between gap-5 pb-10 border-b border-white/10">
          <div className="space-y-3 max-w-xl">
            <Link to="/" aria-label="FacultyLens home" className="inline-block"><BrandMark light subtitle="Academic Intelligence Platform" /></Link>
            <p className="text-sm text-white/65 leading-relaxed">
              AI-powered decision support for university faculty: analyze assessments, align them with learning outcomes, detect similar questions, grade with rubrics and keep an immutable record of every version — while every decision stays yours.
            </p>
          </div>
          <div className="flex flex-col items-start md:items-end gap-2 shrink-0">
            <span className="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/5 px-2.5 py-1 text-[11px]"><ShieldCheck className="w-3.5 h-3.5 text-sage-300" aria-hidden="true" />Explainable · Private · Auditable</span>
            <span className="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/5 px-2.5 py-1 text-[11px]"><span className="landing-pulse-dot w-1.5 h-1.5 rounded-full bg-[#86EFAC]" aria-hidden="true" />All systems operational</span>
          </div>
        </div>

        {/* Link columns (equal width) */}
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-8 lg:gap-10 py-10">
          <nav className="space-y-3" aria-label="Product">
            <h3 className="text-[11px] uppercase tracking-[0.18em] text-sage-300">Product</h3>
            <ul className="space-y-2.5">
              {PRODUCT.map((p) => <li key={p.id}><button type="button" onClick={() => scrollToSection(p.id)} className={linkClass}>{p.label}<Arrow /></button></li>)}
            </ul>
          </nav>

          <nav className="space-y-3" aria-label="Platform">
            <h3 className="text-[11px] uppercase tracking-[0.18em] text-sage-300">Platform</h3>
            <ul className="space-y-2.5">
              {PLATFORM.map((p) => <li key={p.to}><Link to={p.to} className={linkClass}>{p.label}<Arrow /></Link></li>)}
            </ul>
          </nav>

          <nav className="space-y-3" aria-label="Capabilities">
            <h3 className="text-[11px] uppercase tracking-[0.18em] text-sage-300">Capabilities</h3>
            <ul className="space-y-2.5">
              {CAPABILITIES.map((c) => <li key={c.to}><Link to={c.to} className={linkClass}>{c.label}<Arrow /></Link></li>)}
            </ul>
          </nav>

          <div className="space-y-3 sm:col-span-2 md:col-span-1">
            <h3 className="text-[11px] uppercase tracking-[0.18em] text-sage-300">Faculty dispatch</h3>
            <p className="text-xs text-white/60 leading-relaxed">Occasional notes on assessment design and AI governance. No marketing noise.</p>
            {subscribed ? (
              <p role="status" className="flex items-center gap-2 rounded-lg border border-[#86EFAC]/30 bg-[#86EFAC]/10 px-3 py-2 text-xs text-[#BBF7D0]"><CheckCircle2 className="w-4 h-4 shrink-0" aria-hidden="true" />Thanks — you’re on the list.</p>
            ) : (
              <form noValidate onSubmit={subscribe} className="space-y-2">
                <label htmlFor="footer-email" className="sr-only">Email address</label>
                <div className="flex items-center rounded-lg border border-white/15 bg-white/5 focus-within:border-sage-300 transition-colors">
                  <Mail className="w-4 h-4 ml-3 text-white/40 shrink-0" aria-hidden="true" />
                  <input id="footer-email" type="email" value={email} onChange={(e) => { setEmail(e.target.value); setError(null); }} placeholder="you@university.edu" aria-invalid={!!error} aria-describedby={error ? 'footer-email-error' : undefined}
                    className="flex-1 min-w-0 bg-transparent px-2.5 py-2 text-xs text-white placeholder:text-white/35 focus:outline-none" />
                  <button type="submit" aria-label="Subscribe" className="m-1 rounded-md bg-white text-sage-800 p-1.5 hover:bg-sage-100 transition-colors"><ArrowRight className="w-3.5 h-3.5" /></button>
                </div>
                {error && <p id="footer-email-error" role="alert" className="text-[11px] text-[#FCA5A5]">{error}</p>}
              </form>
            )}
            <div className="flex items-center gap-3 pt-1 text-white/60">
              <a href="https://www.linkedin.com" target="_blank" rel="noreferrer" aria-label="FacultyLens on LinkedIn" className="rounded-md border border-white/10 p-1.5 hover:bg-white/10 hover:text-white transition-colors"><Linkedin className="w-4 h-4" /></a>
              <a href="https://twitter.com" target="_blank" rel="noreferrer" aria-label="FacultyLens on Twitter" className="rounded-md border border-white/10 p-1.5 hover:bg-white/10 hover:text-white transition-colors"><Twitter className="w-4 h-4" /></a>
              <a href="https://github.com/Sakin542/FacultyLens" target="_blank" rel="noreferrer" aria-label="FacultyLens on GitHub" className="rounded-md border border-white/10 p-1.5 hover:bg-white/10 hover:text-white transition-colors"><Github className="w-4 h-4" /></a>
              <a href="mailto:hello@facultylens.edu" aria-label="Email FacultyLens" className="rounded-md border border-white/10 p-1.5 hover:bg-white/10 hover:text-white transition-colors"><Mail className="w-4 h-4" /></a>
            </div>
          </div>
        </div>

        {/* Bottom bar */}
        <div className="pt-6 border-t border-white/10 flex flex-col sm:flex-row items-center justify-between gap-4 text-[11px] text-white/50">
          <p>© {new Date().getFullYear()} FacultyLens. All rights reserved.</p>
          <p className="font-serif text-sm text-white/70">Smarter Assessments. Better Learning.</p>
          <div className="flex items-center gap-4">
            <span>AI assists · Faculty decides</span>
            <button type="button" onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })} aria-label="Back to top" className="group inline-flex items-center gap-1.5 rounded-full border border-white/15 px-3 py-1.5 text-white/70 hover:bg-white hover:text-sage-800 transition-colors">
              Top <ArrowUp className="w-3 h-3 transition-transform group-hover:-translate-y-0.5" aria-hidden="true" />
            </button>
          </div>
        </div>
      </div>
    </footer>
  );
};
