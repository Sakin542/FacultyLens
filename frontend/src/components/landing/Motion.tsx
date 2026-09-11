import React, { useEffect, useRef, useState } from 'react';

/** Landing-page motion helpers (cream / sage theme). All respect prefers-reduced-motion via CSS. */

const reducedMotion = (): boolean => typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Cursor-driven 3D tilt for showcase cards (max ~6deg); resets on leave. Pair with `.landing-showcase`. */
export const useCardTilt = (max = 6) => {
  const ref = useRef<HTMLDivElement | null>(null);
  const onMouseMove = (e: React.MouseEvent<HTMLDivElement>) => {
    const el = ref.current;
    if (!el || reducedMotion()) return;
    const r = el.getBoundingClientRect();
    const px = (e.clientX - r.left) / r.width - 0.5;
    const py = (e.clientY - r.top) / r.height - 0.5;
    el.style.transform = `rotateX(${(-py * max).toFixed(2)}deg) rotateY(${(px * max).toFixed(2)}deg)`;
  };
  const reset = () => { if (ref.current) ref.current.style.transform = ''; };
  return { ref, onMouseMove, reset };
};

/** Fades/slides children in when they scroll into view. */
export const Reveal: React.FC<{ children: React.ReactNode; className?: string; delay?: number; as?: 'div' | 'section' | 'li' | 'article' }> = ({ children, className, delay = 0, as = 'div' }) => {
  const ref = useRef<HTMLElement | null>(null);
  const [visible, setVisible] = useState(false);
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    if (typeof IntersectionObserver === 'undefined') { setVisible(true); return; }
    const io = new IntersectionObserver((entries) => {
      if (entries.some((e) => e.isIntersecting)) { setVisible(true); io.disconnect(); }
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
    io.observe(el);
    return () => io.disconnect();
  }, []);
  const Tag = as as React.ElementType;
  return <Tag ref={ref} className={`landing-reveal ${visible ? 'is-visible' : ''} ${className ?? ''}`} style={{ transitionDelay: `${delay}ms` }}>{children}</Tag>;
};

/** Cycles through words with a flip-in animation. */
export const RotatingWord: React.FC<{ words: string[]; interval?: number; className?: string; wordClassName?: string }> = ({ words, interval = 2600, className, wordClassName }) => {
  const [i, setI] = useState(0);
  useEffect(() => {
    if (reducedMotion() || words.length < 2) return;
    const t = window.setInterval(() => setI((n) => (n + 1) % words.length), interval);
    return () => window.clearInterval(t);
  }, [words, interval]);
  return (
    <span className={`relative inline-grid align-baseline ${className ?? ''}`} aria-live="polite">
      {/* invisible longest word keeps the line width stable */}
      <span className="invisible col-start-1 row-start-1 whitespace-nowrap" aria-hidden="true">{words.reduce((a, b) => (a.length >= b.length ? a : b))}</span>
      <span key={words[i]} className={`landing-word col-start-1 row-start-1 whitespace-nowrap ${wordClassName ?? ''}`}>{words[i]}</span>
    </span>
  );
};

/** Cycles through full lines; each word cascades in. All variants are stacked invisibly so the height never jumps. */
export const RotatingLine: React.FC<{ lines: string[]; interval?: number; className?: string; wordClassName?: string; stagger?: number }> = ({ lines, interval = 3800, className, wordClassName, stagger = 70 }) => {
  const [i, setI] = useState(0);
  useEffect(() => {
    if (reducedMotion() || lines.length < 2) return;
    const t = window.setInterval(() => setI((n) => (n + 1) % lines.length), interval);
    return () => window.clearInterval(t);
  }, [lines, interval]);
  return (
    <span className={`relative inline-grid w-full ${className ?? ''}`} aria-live="polite">
      {lines.map((l) => <span key={l} className="invisible col-start-1 row-start-1" aria-hidden="true">{l}</span>)}
      <span key={lines[i]} className="col-start-1 row-start-1">
        {lines[i].split(' ').map((w, k) => (
          <React.Fragment key={`${w}-${k}`}>
            {k > 0 && ' '}
            <span className={`landing-word ${wordClassName ?? ''}`} style={{ animationDelay: `${k * stagger}ms` }}>{w}</span>
          </React.Fragment>
        ))}
      </span>
    </span>
  );
};

/** Types each line, holds it, erases it and types the next. All lines are stacked invisibly so the height never jumps. */
export const TypewriterCycle: React.FC<{ lines: string[]; typeSpeed?: number; eraseSpeed?: number; hold?: number; className?: string; textClassName?: string }> = ({ lines, typeSpeed = 42, eraseSpeed = 18, hold = 2200, className, textClassName }) => {
  const still = reducedMotion();
  const [i, setI] = useState(0);
  const [n, setN] = useState(still ? lines[0].length : 0);
  const [phase, setPhase] = useState<'typing' | 'holding' | 'erasing'>('typing');
  const line = lines[i];
  useEffect(() => {
    if (still) return;
    let t = 0;
    if (phase === 'typing') {
      t = window.setTimeout(() => (n < line.length ? setN(n + 1) : setPhase('holding')), typeSpeed);
    } else if (phase === 'holding') {
      t = window.setTimeout(() => setPhase(lines.length > 1 ? 'erasing' : 'holding'), hold);
    } else {
      t = window.setTimeout(() => {
        if (n > 0) { setN(n - 1); } else { setI((i + 1) % lines.length); setPhase('typing'); }
      }, eraseSpeed);
    }
    return () => window.clearTimeout(t);
  }, [still, phase, n, i, line, lines.length, typeSpeed, eraseSpeed, hold]);
  return (
    <span className={`relative inline-grid w-full ${className ?? ''}`} aria-live="polite">
      {lines.map((l) => <span key={l} className="invisible col-start-1 row-start-1" aria-hidden="true">{l}</span>)}
      <span className="col-start-1 row-start-1">
        <span className={textClassName}>{line.slice(0, n)}</span>
        {!still && <span className="landing-caret" aria-hidden="true" />}
      </span>
    </span>
  );
};

/** Types out a sentence character by character once it is on screen. */
export const Typewriter: React.FC<{ text: string; speed?: number; className?: string }> = ({ text, speed = 28, className }) => {
  const [n, setN] = useState(reducedMotion() ? text.length : 0);
  const [started, setStarted] = useState(false);
  const ref = useRef<HTMLSpanElement | null>(null);
  useEffect(() => {
    const el = ref.current;
    if (!el || typeof IntersectionObserver === 'undefined') { setStarted(true); return; }
    const io = new IntersectionObserver((entries) => { if (entries.some((e) => e.isIntersecting)) { setStarted(true); io.disconnect(); } }, { threshold: 0.4 });
    io.observe(el);
    return () => io.disconnect();
  }, []);
  useEffect(() => {
    if (!started || n >= text.length) return;
    const t = window.setTimeout(() => setN((v) => v + 1), speed);
    return () => window.clearTimeout(t);
  }, [started, n, text, speed]);
  return <span ref={ref} className={className}>{text.slice(0, n)}{n < text.length && <span className="landing-caret" aria-hidden="true" />}</span>;
};

/** Counts from 0 to the target when scrolled into view. */
export const CountUp: React.FC<{ to: number; suffix?: string; prefix?: string; duration?: number; className?: string }> = ({ to, suffix = '', prefix = '', duration = 1400, className }) => {
  const [v, setV] = useState(reducedMotion() ? to : 0);
  const ref = useRef<HTMLSpanElement | null>(null);
  useEffect(() => {
    const el = ref.current;
    if (!el || reducedMotion()) return;
    let raf = 0;
    const run = () => {
      const start = performance.now();
      const tick = (now: number) => {
        const p = Math.min(1, (now - start) / duration);
        setV(Math.round(to * (1 - Math.pow(1 - p, 3))));
        if (p < 1) raf = requestAnimationFrame(tick);
      };
      raf = requestAnimationFrame(tick);
    };
    if (typeof IntersectionObserver === 'undefined') { run(); return; }
    const io = new IntersectionObserver((entries) => { if (entries.some((e) => e.isIntersecting)) { run(); io.disconnect(); } }, { threshold: 0.5 });
    io.observe(el);
    return () => { io.disconnect(); cancelAnimationFrame(raf); };
  }, [to, duration]);
  return <span ref={ref} className={`tabular-nums ${className ?? ''}`}>{prefix}{v}{suffix}</span>;
};

/** Progress bar that grows to its value once mounted (CSS transition). */
export const GrowBar: React.FC<{ value: number; className?: string; trackClassName?: string; delay?: number }> = ({ value, className, trackClassName, delay = 0 }) => {
  const [on, setOn] = useState(false);
  useEffect(() => { const t = window.setTimeout(() => setOn(true), 120 + delay); return () => window.clearTimeout(t); }, [delay]);
  return (
    <div className={`h-1.5 rounded-full overflow-hidden ${trackClassName ?? 'bg-sage-100'}`}>
      <div className={`landing-bar h-full rounded-full ${className ?? 'bg-sage-600'}`} style={{ width: `${value}%`, transform: on ? 'scaleX(1)' : 'scaleX(0)' }} />
    </div>
  );
};
