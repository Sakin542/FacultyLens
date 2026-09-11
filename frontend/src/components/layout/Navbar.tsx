import React, { useEffect, useState } from 'react';
import { Link, useNavigate, useLocation } from 'react-router-dom';
import { BarChart3, Bot, ClipboardList, Eye, GitCompare, GraduationCap, History, Layers, Menu, ScrollText, Target, X } from 'lucide-react';

const LINKS: { id: string; label: string }[] = [
  { id: 'features', label: 'Features' },
  { id: 'how-it-works', label: 'How It Works' },
  { id: 'pricing', label: 'Pricing' },
  { id: 'about', label: 'About' },
];

const CAPABILITIES: { icon: React.ElementType; label: string }[] = [
  { icon: Layers, label: 'Rubric generation' },
  { icon: BarChart3, label: 'Gap analysis' },
  { icon: History, label: 'Version history' },
  { icon: Eye, label: 'Explainable scores' },
  { icon: ScrollText, label: 'Audit trail' },
  { icon: Bot, label: 'RAG assistant' },
  { icon: GraduationCap, label: 'Bloom’s taxonomy' },
  { icon: Target, label: 'CO / PO mapping' },
  { icon: GitCompare, label: 'Similarity detection' },
  { icon: ClipboardList, label: 'Assessment blueprints' },
];

/** Scrolls to a landing-page section, navigating home first when needed. */
export const useScrollToSection = (): ((sectionId: string) => void) => {
  const navigate = useNavigate();
  const location = useLocation();
  return (sectionId: string) => {
    const scroll = () => document.getElementById(sectionId)?.scrollIntoView({ behavior: 'smooth' });
    if (location.pathname === '/') {
      scroll();
    } else {
      navigate('/');
      setTimeout(scroll, 150);
    }
  };
};

/** Slim scrolling capability strip above the nav (pauses on hover). */
const CapabilityTicker: React.FC = () => (
  <div className="bg-sage-800 text-sage-100 overflow-hidden" aria-hidden="true">
    <div className="landing-marquee flex w-max items-center gap-3 py-1.5 pl-3">
      {[...CAPABILITIES, ...CAPABILITIES].map((c, i) => (
        <span key={`${c.label}-${i}`} className="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/5 px-3 py-0.5 text-[10px] tracking-wide whitespace-nowrap">
          <c.icon className="w-3 h-3 text-sage-300" strokeWidth={1.8} />{c.label}
        </span>
      ))}
    </div>
  </div>
);

/** Animated "lens" mark: rotating dashed ring, orbiting dot, pulsing glow around a graduation cap. */
export const LensLogo: React.FC<{ light?: boolean; size?: number; className?: string }> = ({ light, size = 32, className }) => {
  const ink = light ? '#FFFFFF' : '#2F3E2E';
  const accent = light ? '#C3CBB9' : '#8C9A82';
  return (
    <span className={`relative inline-flex items-center justify-center shrink-0 transition-transform duration-500 group-hover:rotate-6 ${className ?? ''}`} style={{ width: size, height: size }} aria-hidden="true">
      <svg viewBox="0 0 48 48" width={size} height={size} fill="none" className="overflow-visible">
        <circle className="logo-glow" cx="24" cy="24" r="16" fill={accent} opacity="0.35" />
        <circle className="logo-ring" cx="24" cy="24" r="21" stroke={accent} strokeWidth="1.5" strokeDasharray="6 5" strokeLinecap="round" />
        <circle cx="24" cy="24" r="15.5" stroke={ink} strokeWidth="1.5" fill={light ? 'rgba(255,255,255,0.06)' : '#FFFFFF'} />
        <g className="logo-orbit">
          <circle cx="24" cy="3" r="2.2" fill={ink} />
        </g>
        <g className="logo-cap" stroke={ink} strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
          <path d="M14 22.5 24 18l10 4.5-10 4.5-10-4.5Z" />
          <path d="M18.5 24.5v4.2c0 1.6 2.6 3 5.5 3s5.5-1.4 5.5-3v-4.2" />
          <path d="M34 22.5v6" />
        </g>
      </svg>
    </span>
  );
};

/** Landing wordmark (cream/sage theme). */
export const BrandMark: React.FC<{ light?: boolean; subtitle?: string; className?: string }> = ({ light, subtitle, className }) => (
  <span className={`group inline-flex items-center gap-2.5 select-none ${className ?? ''}`}>
    <LensLogo light={light} />
    <span className="flex flex-col leading-none">
      <span className={`font-serif text-xl tracking-tight transition-colors ${light ? 'text-white' : 'text-sage-800 group-hover:text-sage-600'}`}>FacultyLens</span>
      {subtitle && <span className={`text-[9px] tracking-wide mt-0.5 ${light ? 'text-white/60' : 'text-sage-400'}`}>{subtitle}</span>}
    </span>
  </span>
);

export const Navbar: React.FC = () => {
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);
  const navigate = useNavigate();
  const scrollToSection = useScrollToSection();

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  const handleNavClick = (sectionId: string) => {
    setMobileMenuOpen(false);
    scrollToSection(sectionId);
  };

  return (
    <header className={`sticky top-0 z-50 bg-sage-50/90 backdrop-blur-md border-b transition-[box-shadow,border-color] duration-300 ${scrolled ? 'border-sage-200 shadow-subtle' : 'border-sage-200/70'}`}>
      <CapabilityTicker />
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-16">
          <Link to="/" aria-label="FacultyLens home"><BrandMark /></Link>

          <nav className="hidden md:flex items-center gap-8 text-sm text-sage-700">
            {LINKS.map((l) => (
              <button key={l.id} type="button" onClick={() => handleNavClick(l.id)} className="hover:text-sage-800 transition-colors">{l.label}</button>
            ))}
          </nav>

          <div className="hidden md:flex items-center gap-3">
            <button type="button" onClick={() => navigate('/login')} className="text-sm text-sage-800 border border-sage-300 rounded-lg px-4 py-1.5 bg-white hover:bg-sage-100 transition-colors">Sign In</button>
          </div>

          <button type="button" className="md:hidden p-2 rounded-lg text-sage-800 hover:bg-sage-200" onClick={() => setMobileMenuOpen(!mobileMenuOpen)} aria-label="Toggle Navigation Menu">
            {mobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
          </button>
        </div>
      </div>

      {mobileMenuOpen && (
        <div className="md:hidden border-b border-sage-200 bg-sage-50 px-4 pt-2 pb-6 space-y-1">
          {LINKS.map((l) => (
            <button key={l.id} type="button" className="block w-full text-left py-2 text-sm text-sage-800" onClick={() => handleNavClick(l.id)}>{l.label}</button>
          ))}
          <div className="pt-4 border-t border-sage-200 flex flex-col gap-2">
            <button type="button" className="w-full text-sm text-sage-800 border border-sage-300 rounded-lg px-4 py-2 bg-white" onClick={() => { setMobileMenuOpen(false); navigate('/login'); }}>Sign In</button>
            <button type="button" className="w-full text-sm text-white rounded-lg px-4 py-2 bg-sage-700 hover:bg-sage-800" onClick={() => { setMobileMenuOpen(false); navigate('/register'); }}>Start Analyzing</button>
          </div>
        </div>
      )}
    </header>
  );
};
