import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  ArrowRight, BarChart3, BookOpen, Bot, CheckCircle2, ChevronDown, ClipboardList, Eye, FileText, GitBranch, GitCompare, History, Layers, Lightbulb,
  Lock, Search, Scale, ScrollText, ShieldCheck, Sparkles, Target, Users, Leaf, Quote,
} from 'lucide-react';
import { Reveal, Typewriter, TypewriterCycle, useCardTilt } from '@/components/landing/Motion';

/* ------------------------------------------------------------------ data */

const HERO_LINES = [
  'Turn assessment data into academic intelligence.',
  'See every question through an explainable lens.',
  'Connect outcomes, evidence and better learning.',
  'Make confident decisions you can stand behind.',
];

const PROCESS = [
  { icon: FileText, title: 'Understand', desc: 'Upload a question paper, syllabus or blueprint. FacultyLens reads the structure, questions, marks and metadata for you.' },
  { icon: Search, title: 'Analyze', desc: 'Every question is classified by type, difficulty and Bloom level, and mapped to the topics it actually tests.' },
  { icon: Scale, title: 'Compare', desc: 'Questions are aligned with course and program outcomes, checked against the blueprint and scanned for near-duplicates.' },
  { icon: CheckCircle2, title: 'Evaluate', desc: 'Six transparent quality dimensions roll up into one score you can defend in a curriculum meeting.' },
  { icon: Lightbulb, title: 'Recommend', desc: 'Prioritised, evidence-linked recommendations — you review, accept or dismiss every one.' },
];

const FEATURES = [
  { icon: ScrollText, title: 'Question Analysis', desc: 'Classify question type, difficulty and Bloom’s cognitive level, with the reasoning shown next to every label.' },
  { icon: Target, title: 'LO / CO / PO Alignment', desc: 'See which learning outcomes each question serves, which outcomes are over-tested and which are missing entirely.' },
  { icon: GitCompare, title: 'Similarity Detection', desc: 'Semantic embeddings surface duplicate or near-identical questions across semesters before a paper goes out.' },
  { icon: BookOpen, title: 'Topic & Concept Mapping', desc: 'Compare the topics your syllabus promises with the topics your assessment actually examines.' },
  { icon: BarChart3, title: 'Performance & Gap Analysis', desc: 'Connect student results back to questions and outcomes to locate learning gaps, not just low averages.' },
  { icon: Bot, title: 'RAG & AI Assistant', desc: 'Ask questions about your own course materials and get answers grounded in the documents you uploaded.' },
  { icon: ClipboardList, title: 'Assessment Blueprints', desc: 'Plan sections, marks, difficulty and outcome coverage first, then validate the real paper against the plan.' },
  { icon: History, title: 'Assessment Versioning', desc: 'Immutable version history with question snapshots, side-by-side diffs and restore-as-new-version.' },
  { icon: Users, title: 'Faculty Collaboration', desc: 'Invite co-instructors and reviewers with role-based access; every change is captured in an audit trail.' },
];

const AUDIENCE = [
  { icon: BookOpen, title: 'Course instructors', text: 'Check a paper before it goes out: coverage, difficulty, Bloom mix and near-duplicate questions, with the reasons shown.' },
  { icon: Target, title: 'Course coordinators', text: 'Keep every section’s assessment aligned to the same course outcomes and blueprint, semester after semester.' },
  { icon: ShieldCheck, title: 'Accreditation & OBE leads', text: 'Pull CO/PO evidence, version history and audit trails straight from the record instead of rebuilding spreadsheets.' },
  { icon: Users, title: 'Reviewers & moderators', text: 'Read, compare and comment on versions with role-based access — without being able to change a finalized paper.' },
];

const PRINCIPLES = [
  { icon: Eye, title: 'Explainable by design', text: 'Every score, label and recommendation links back to the questions and outcomes that produced it. There are no black-box verdicts — you can always see why FacultyLens said what it said, and disagree with it.' },
  { icon: ShieldCheck, title: 'Your decisions, on record', text: 'AI suggests; faculty decide. Approvals, rejections, edits and finalizations are written to an immutable audit log, so accreditation reviews and department meetings start from evidence rather than memory.' },
  { icon: Lock, title: 'Private and course-scoped', text: 'Course data never leaks across faculty. Access follows an explicit role matrix — owner, editor, reviewer, viewer — and student answers stay bound to the exact assessment version they were written for.' },
];

const LIFECYCLE = [
  { icon: ClipboardList, title: 'Blueprint', text: 'Define sections, marks, difficulty and CO/PO targets before a single question is written.' },
  { icon: Sparkles, title: 'Generate & review', text: 'Draft constrained questions from your own materials; nothing enters the paper until you approve it.' },
  { icon: Search, title: 'Analyze', text: 'Quality, alignment, similarity and topic coverage — computed on the real paper, explained per question.' },
  { icon: Layers, title: 'Grade with rubrics', text: 'AI-generated rubrics and grading suggestions speed up marking while every mark stays yours.' },
  { icon: BarChart3, title: 'Learn from results', text: 'Performance and gap analysis tie outcomes to evidence and feed the next revision.' },
  { icon: GitBranch, title: 'Version & preserve', text: 'Each finalized paper becomes a historical record; compare, restore and report on any version.' },
];

const VOICES = [
  { role: 'Course coordinator · Database Systems', text: 'The first thing I noticed was that two of my “new” midterm questions were 87% identical to last spring’s paper. The second thing was that CO4 had no questions at all.' },
  { role: 'Program accreditation lead', text: 'Outcome coverage used to live in a spreadsheet that was out of date the moment someone edited a paper. Now every version carries its own CO/PO evidence.' },
  { role: 'Lecturer · first semester using FacultyLens', text: 'I was worried the AI would rewrite my exam. It never touches it. It shows me what it sees and lets me decide — that is exactly the relationship I wanted.' },
];

const FAQ = [
  { q: 'Does FacultyLens change my questions, marks or grades automatically?', a: 'No. Every recommendation, generated question, rubric and grading suggestion is a proposal. Nothing becomes part of the assessment or a student record until a faculty member approves it, and finalized versions can never be edited in place.' },
  { q: 'How are quality scores calculated?', a: 'Six deterministic dimensions — topic coverage, learning-outcome alignment, difficulty balance, cognitive diversity, similarity and question diversity — are computed from your paper and shown individually. The overall score is a weighted roll-up of those dimensions, never an opaque number.' },
  { q: 'What happens to historical analyses when I revise an assessment?', a: 'Each analysis is attached to the exact assessment version it examined. Revising a paper creates a new version; earlier analyses, reports and student submissions stay bound to their original snapshot.' },
  { q: 'Can colleagues review a paper without being able to edit it?', a: 'Yes. Invite them as reviewers or viewers. They can read, comment and compare versions; only owners and editors can change drafts, approve or finalize.' },
  { q: 'Where does my course data go?', a: 'Documents, questions and student answers are stored in your institution’s FacultyLens deployment and scoped to the course. Audit logs never store question text or student answers.' },
];

/** Hero showcase: what FacultyLens does at each stage (no figures — behaviour only). */
const PIPELINE = [
  { icon: FileText, title: 'Upload', text: 'Question paper, syllabus or blueprint — PDF, DOCX or text.', tags: ['Questions parsed', 'Marks & sections read'] },
  { icon: Search, title: 'Analyze', text: 'Each question is classified and mapped to the topics it tests.', tags: ['Bloom level', 'Difficulty', 'Question type'] },
  { icon: Target, title: 'Align', text: 'Questions are matched to course and program outcomes and checked against the blueprint.', tags: ['CO / PO mapping', 'Coverage gaps'] },
  { icon: GitCompare, title: 'Check', text: 'Semantic similarity flags near-duplicate questions across semesters.', tags: ['Similarity scan', 'Question bank'] },
  { icon: Lightbulb, title: 'Recommend', text: 'Evidence-linked suggestions appear for you to accept, edit or dismiss.', tags: ['Explainable', 'Faculty decides'] },
  { icon: Lock, title: 'Finalize', text: 'The approved paper becomes an immutable version with its own history.', tags: ['Snapshot', 'Audit trail'] },
];

/* ------------------------------------------------------------------ small pieces */

const Eyebrow: React.FC<{ children: React.ReactNode; className?: string }> = ({ children, className }) => (
  <p className={`text-[11px] uppercase tracking-[0.18em] text-sage-500 ${className ?? ''}`}>{children}</p>
);

const Heading: React.FC<{ children: React.ReactNode; className?: string }> = ({ children, className }) => (
  <h2 className={`font-serif text-3xl sm:text-4xl lg:text-[2.75rem] leading-[1.1] tracking-tight text-sage-800 ${className ?? ''}`}>{children}</h2>
);

const Lede: React.FC<{ children: React.ReactNode; className?: string }> = ({ children, className }) => (
  <p className={`text-sm sm:text-base text-sage-500 leading-relaxed ${className ?? ''}`}>{children}</p>
);

const PrimaryButton: React.FC<{ onClick: () => void; children: React.ReactNode; className?: string }> = ({ onClick, children, className }) => (
  <button type="button" onClick={onClick} className={`group inline-flex items-center gap-2 rounded-lg bg-sage-700 hover:bg-sage-800 text-white text-sm px-5 py-2.5 transition-all hover:shadow-elevated hover:-translate-y-0.5 ${className ?? ''}`}>
    {children}<ArrowRight className="w-4 h-4 transition-transform group-hover:translate-x-0.5" />
  </button>
);

const IconCircle: React.FC<{ icon: React.ElementType; size?: 'md' | 'lg' }> = ({ icon: Icon, size = 'lg' }) => (
  <div className={`${size === 'lg' ? 'w-16 h-16' : 'w-12 h-12'} rounded-full bg-sage-100 border border-sage-200 flex items-center justify-center text-sage-800 shrink-0 transition-transform duration-500 group-hover:-translate-y-1 group-hover:bg-sage-200`}>
    <Icon className={size === 'lg' ? 'w-6 h-6' : 'w-5 h-5'} strokeWidth={1.6} aria-hidden="true" />
  </div>
);

/** Animated pipeline: stages light up in sequence while a pulse travels the connecting rail. */
const PipelineShowcase: React.FC = () => {
  const [active, setActive] = useState(0);
  const [paused, setPaused] = useState(false);
  useEffect(() => {
    if (paused || (typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches)) return;
    const t = window.setInterval(() => setActive((a) => (a + 1) % PIPELINE.length), 2400);
    return () => window.clearInterval(t);
  }, [paused]);
  const stage = PIPELINE[active];
  const { ref: cardRef, onMouseMove: tilt, reset: untilt } = useCardTilt();
  return (
    <div className="landing-showcase-wrap">
    <div ref={cardRef} className="landing-showcase rounded-2xl bg-white p-5 sm:p-6 text-sage-800" onMouseEnter={() => setPaused(true)} onMouseMove={tilt} onMouseLeave={() => { setPaused(false); untilt(); }}>
      <div className="flex items-center justify-between gap-3 pb-4 border-b border-sage-200">
        <div>
          <p className="text-[10px] uppercase tracking-[0.18em] text-sage-500">How a paper moves through FacultyLens</p>
          <p className="font-serif text-lg leading-tight mt-0.5">Upload once. Every stage explains itself.</p>
        </div>
        <span className="inline-flex items-center gap-1.5 rounded-full bg-sage-100 border border-sage-200 px-2.5 py-1 text-[10px] text-sage-700"><span className="landing-pulse-dot w-1.5 h-1.5 rounded-full bg-sage-600" />{paused ? 'Paused' : 'Live walkthrough'}</span>
      </div>

      {/* rail */}
      <ol className="relative mt-5 grid grid-cols-6 gap-1" aria-label="Pipeline stages">
        <div className="absolute left-[8%] right-[8%] top-5 h-px bg-sage-200" aria-hidden="true" />
        <div className="absolute left-[8%] top-5 h-px bg-sage-600 transition-all duration-700 ease-out" style={{ width: `${(active / (PIPELINE.length - 1)) * 84}%` }} aria-hidden="true" />
        <span className="landing-pipeline-pulse absolute top-5 -mt-1.5 w-3 h-3 rounded-full bg-sage-600 shadow-[0_0_0_6px_rgba(74,93,69,0.15)] transition-all duration-700 ease-out" style={{ left: `calc(8% + ${(active / (PIPELINE.length - 1)) * 84}% - 6px)` }} aria-hidden="true" />
        {PIPELINE.map((p, i) => {
          const done = i < active;
          const on = i === active;
          return (
            <li key={p.title} className="relative flex flex-col items-center text-center">
              <button type="button" onClick={() => setActive(i)} aria-current={on} aria-label={`Show stage ${p.title}`}
                className={`relative z-10 w-10 h-10 rounded-full border flex items-center justify-center transition-all duration-500 ${on ? 'bg-sage-700 border-sage-700 text-white scale-110 shadow-elevated' : done ? 'bg-sage-100 border-sage-300 text-sage-700' : 'bg-white border-sage-200 text-sage-400'}`}>
                <p.icon className="w-4 h-4" strokeWidth={1.8} aria-hidden="true" />
              </button>
              <span className={`mt-2 text-[10px] sm:text-[11px] transition-colors ${on ? 'text-sage-800 font-semibold' : 'text-sage-500'}`}>{p.title}</span>
            </li>
          );
        })}
      </ol>

      {/* active stage */}
      <div key={stage.title} className="landing-stage mt-5 rounded-xl border border-sage-200 bg-sage-50 p-4 min-h-[7.5rem]" aria-live="polite">
        <div className="flex items-center gap-2">
          <span className="font-serif text-2xl text-sage-300 leading-none">{String(active + 1).padStart(2, '0')}</span>
          <h3 className="text-sm font-semibold">{stage.title}</h3>
        </div>
        <p className="mt-1.5 text-sm text-sage-600 leading-relaxed">{stage.text}</p>
        <ul className="mt-3 flex flex-wrap gap-1.5">
          {stage.tags.map((t, k) => <li key={t} className="landing-word rounded-full border border-sage-200 bg-white px-2.5 py-0.5 text-[10px] text-sage-700" style={{ animationDelay: `${120 + k * 90}ms` }}>{t}</li>)}
        </ul>
      </div>

      <div className="mt-4 flex items-center justify-between text-[10px] text-sage-400">
        <span>Click a stage or hover to pause</span>
        <span>AI assists · Faculty decides</span>
      </div>
    </div>
    </div>
  );
};

/** Record types FacultyLens links together, and how each link is formed (no figures — relationships only). */
const TRACE_NODES = [
  { id: 'question', icon: FileText, label: 'Question', sub: 'text, marks, type, Bloom level', x: 50, y: 12 },
  { id: 'outcome', icon: Target, label: 'Outcome', sub: 'course & program outcomes', x: 86, y: 34 },
  { id: 'blueprint', icon: ClipboardList, label: 'Blueprint', sub: 'planned coverage & marks', x: 14, y: 34 },
  { id: 'topic', icon: BookOpen, label: 'Topic', sub: 'from the syllabus', x: 14, y: 72 },
  { id: 'answer', icon: Users, label: 'Student answer', sub: 'bound to the version answered', x: 86, y: 72 },
  { id: 'version', icon: History, label: 'Version', sub: 'immutable snapshot', x: 50, y: 92 },
  { id: 'recommendation', icon: Lightbulb, label: 'Recommendation', sub: 'evidence-linked, faculty-decided', x: 50, y: 52 },
] as const;

const TRACE_LINKS = [
  { from: 'question', to: 'outcome', label: 'is mapped to' },
  { from: 'question', to: 'blueprint', label: 'is checked against' },
  { from: 'question', to: 'topic', label: 'examines' },
  { from: 'question', to: 'answer', label: 'is answered in' },
  { from: 'outcome', to: 'recommendation', label: 'gaps raise' },
  { from: 'answer', to: 'recommendation', label: 'results inform' },
  { from: 'recommendation', to: 'version', label: 'is reviewed into' },
  { from: 'blueprint', to: 'version', label: 'is snapshotted with' },
] as const;

const TraceabilityMap: React.FC = () => {
  const [active, setActive] = useState(0);
  const [paused, setPaused] = useState(false);
  useEffect(() => {
    if (paused || (typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches)) return;
    const t = window.setInterval(() => setActive((a) => (a + 1) % TRACE_LINKS.length), 2200);
    return () => window.clearInterval(t);
  }, [paused]);
  const node = (id: string) => TRACE_NODES.find((n) => n.id === id)!;
  const link = TRACE_LINKS[active];
  const lit = new Set([link.from, link.to]);
  const { ref: cardRef, onMouseMove: tilt, reset: untilt } = useCardTilt();
  return (
    <div className="landing-showcase-wrap">
    <div ref={cardRef} className="landing-showcase rounded-2xl bg-sage-50 p-5 sm:p-6 text-sage-800" onMouseEnter={() => setPaused(true)} onMouseMove={tilt} onMouseLeave={() => { setPaused(false); untilt(); }}>
      <div className="flex items-center justify-between gap-3">
        <div>
          <p className="text-[10px] uppercase tracking-[0.18em] text-sage-500">Traceability map</p>
          <p className="font-serif text-lg leading-tight mt-0.5">Every record knows what it is connected to.</p>
        </div>
        <span className="inline-flex items-center gap-1.5 rounded-full bg-white border border-sage-200 px-2.5 py-1 text-[10px] text-sage-700"><span className="landing-pulse-dot w-1.5 h-1.5 rounded-full bg-sage-600" />{paused ? 'Paused' : 'Walking links'}</span>
      </div>

      <div className="relative mt-4 aspect-[4/3] sm:aspect-[16/10]">
        <svg viewBox="0 0 100 100" preserveAspectRatio="none" className="absolute inset-0 w-full h-full" aria-hidden="true">
          {TRACE_LINKS.map((l, i) => {
            const a = node(l.from);
            const b = node(l.to);
            const on = i === active;
            return <line key={`${l.from}-${l.to}`} x1={a.x} y1={a.y} x2={b.x} y2={b.y} stroke={on ? '#4A5D45' : '#C3CBB9'} strokeWidth={on ? 0.9 : 0.5} strokeDasharray={on ? undefined : '1.5 1.5'} vectorEffect="non-scaling-stroke" className="landing-trace-line transition-all duration-500" style={{ animationDelay: `${i * 120}ms` }} />;
          })}
          <circle r="1.3" fill="#4A5D45" className="landing-trace-dot" key={active} style={{ offsetPath: `path('M ${node(link.from).x} ${node(link.from).y} L ${node(link.to).x} ${node(link.to).y}')` }} />
        </svg>
        {TRACE_NODES.map((n) => {
          const on = lit.has(n.id);
          return (
            <button key={n.id} type="button" onClick={() => setActive(TRACE_LINKS.findIndex((l) => l.from === n.id || l.to === n.id))} aria-label={`${n.label}: ${n.sub}`}
              className={`absolute -translate-x-1/2 -translate-y-1/2 flex items-center gap-2 rounded-full border pl-1.5 pr-3 py-1 text-left transition-all duration-500 ${on ? 'bg-sage-700 border-sage-700 text-white shadow-elevated scale-105' : 'bg-white border-sage-200 text-sage-700 hover:border-sage-400'}`}
              style={{ left: `${n.x}%`, top: `${n.y}%` }}>
              <span className={`w-6 h-6 rounded-full flex items-center justify-center shrink-0 ${on ? 'bg-white/15' : 'bg-sage-100'}`}><n.icon className="w-3.5 h-3.5" strokeWidth={1.8} aria-hidden="true" /></span>
              <span className="text-[11px] font-medium whitespace-nowrap">{n.label}</span>
            </button>
          );
        })}
      </div>

      <div key={active} className="landing-stage mt-4 rounded-xl border border-sage-200 bg-white p-3.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm" aria-live="polite">
        <span className="font-semibold">{node(link.from).label}</span>
        <span className="text-sage-500 italic">{link.label}</span>
        <span className="font-semibold">{node(link.to).label}</span>
        <span className="basis-full text-[11px] text-sage-500">{node(link.from).sub} → {node(link.to).sub}</span>
      </div>
      <p className="mt-3 text-[10px] text-sage-400 text-right">Click a record or hover to pause · nothing here is a fabricated result</p>
    </div>
    </div>
  );
};

const FaqItem: React.FC<{ q: string; a: string; open: boolean; onToggle: () => void }> = ({ q, a, open, onToggle }) => (
  <li className="border-b border-sage-200">
    <button type="button" onClick={onToggle} aria-expanded={open} className="w-full flex items-center justify-between gap-4 py-4 text-left text-sm sm:text-base text-sage-800 hover:text-sage-600 transition-colors">
      <span className="font-medium">{q}</span>
      <ChevronDown className={`w-4 h-4 shrink-0 text-sage-400 transition-transform duration-300 ${open ? 'rotate-180' : ''}`} aria-hidden="true" />
    </button>
    <div className={`grid transition-all duration-300 ease-out ${open ? 'grid-rows-[1fr] opacity-100 pb-4' : 'grid-rows-[0fr] opacity-0'}`}>
      <p className="overflow-hidden text-sm text-sage-500 leading-relaxed max-w-3xl">{a}</p>
    </div>
  </li>
);

/* ------------------------------------------------------------------ page */

export const Home: React.FC = () => {
  const navigate = useNavigate();
  const [openFaq, setOpenFaq] = useState<number>(0);
  const go = (path: string) => () => navigate(path);
  const scrollTo = (id: string) => () => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });

  return (
    <div className="flex flex-col text-sage-800 overflow-x-hidden">
      {/* Hero */}
      <section className="relative overflow-hidden bg-sage-50">
        <Leaf className="landing-float absolute -left-10 top-10 w-64 h-64 text-sage-200/60 pointer-events-none" aria-hidden="true" />
        <Leaf className="landing-float-slow absolute -right-16 -bottom-10 w-72 h-72 text-sage-200/40 pointer-events-none" aria-hidden="true" />
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-24 grid grid-cols-1 lg:grid-cols-12 gap-12 items-center relative">
          <div className="lg:col-span-5 space-y-6">
            <Reveal><Eyebrow className="inline-flex items-center gap-2"><span className="landing-pulse-dot w-1.5 h-1.5 rounded-full bg-sage-500" />AI-Powered Assessment Intelligence</Eyebrow></Reveal>
            <Reveal delay={100}>
              <h1 className="font-serif text-4xl sm:text-5xl lg:text-[3.4rem] leading-[1.08] tracking-tight">
                <TypewriterCycle lines={HERO_LINES} textClassName="landing-sheen" />
              </h1>
            </Reveal>
            <Reveal delay={200}>
              <Lede className="max-w-md">
                FacultyLens helps faculty understand, evaluate and improve assessments using AI-powered analysis, learning-outcome alignment, performance insights and explainable recommendations — while every decision stays in your hands.
              </Lede>
            </Reveal>
            <Reveal delay={300}>
              <div className="flex flex-wrap gap-3 pt-1">
                <PrimaryButton onClick={go('/register')}>Start Analyzing</PrimaryButton>
                <button type="button" onClick={scrollTo('features')} className="inline-flex items-center rounded-lg border border-sage-300 bg-white hover:bg-sage-100 text-sage-800 text-sm px-5 py-2.5 transition-colors">Explore FacultyLens</button>
              </div>
            </Reveal>
            <Reveal delay={400}>
              <ul className="flex flex-wrap gap-x-5 gap-y-2 pt-2 text-xs text-sage-500">
                {['No automatic changes', 'Explainable scores', 'Immutable history'].map((t) => <li key={t} className="inline-flex items-center gap-1.5"><CheckCircle2 className="w-3.5 h-3.5 text-sage-600" aria-hidden="true" />{t}</li>)}
              </ul>
            </Reveal>
          </div>
          <Reveal delay={200} className="lg:col-span-7 lg:pl-6"><PipelineShowcase /></Reveal>
        </div>
      </section>

      {/* Who it's for */}
      <section className="bg-white border-b border-sage-200/70">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
          <Reveal><Eyebrow className="text-center md:text-left">Who it’s for</Eyebrow></Reveal>
          <div className="mt-5 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            {AUDIENCE.map((a, i) => (
              <Reveal key={a.title} delay={i * 90} className="group flex gap-4 rounded-xl border border-sage-200 bg-sage-50 p-5 hover:bg-white hover:shadow-card transition-all duration-300">
                <IconCircle icon={a.icon} size="md" />
                <div>
                  <h3 className="text-sm font-semibold">{a.title}</h3>
                  <p className="mt-1 text-xs text-sage-500 leading-relaxed">{a.text}</p>
                </div>
              </Reveal>
            ))}
          </div>
        </div>
      </section>

      {/* Process */}
      <section id="how-it-works" className="bg-white">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-20 text-center">
          <Reveal><Eyebrow>The FacultyLens Process</Eyebrow><Heading className="mt-3">One assessment. <span className="landing-ink">Complete insight.</span></Heading></Reveal>
          <Reveal delay={120}><Lede className="mt-4 max-w-2xl mx-auto">Most assessment review happens after the exam, in a spreadsheet, from memory. FacultyLens moves it to before the paper leaves your desk — and keeps a record of what was decided and why.</Lede></Reveal>
          <ol className="mt-12 flex flex-col md:flex-row md:items-start md:justify-between gap-8 md:gap-2">
            {PROCESS.map((p, i) => (
              <React.Fragment key={p.title}>
                <Reveal as="li" delay={i * 110} className="group flex-1 flex flex-col items-center text-center max-w-[13rem] mx-auto">
                  <IconCircle icon={p.icon} />
                  <h3 className="mt-4 text-sm font-semibold">{p.title}</h3>
                  <p className="mt-1 text-xs text-sage-500 leading-relaxed">{p.desc}</p>
                </Reveal>
                {i < PROCESS.length - 1 && <li aria-hidden="true" className="hidden md:flex items-center justify-center pt-6 text-sage-300"><ArrowRight className="w-4 h-4" /></li>}
              </React.Fragment>
            ))}
          </ol>
        </div>
      </section>

      {/* Features */}
      <section id="features" className="bg-sage-50 border-y border-sage-200/70">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-20 text-center">
          <Reveal><Eyebrow>AI-Powered Assessment Intelligence</Eyebrow><Heading className="mt-3">Everything you need, in one place.</Heading></Reveal>
          <Reveal delay={120}><Lede className="mt-4 max-w-2xl mx-auto">Nine connected modules share one course model, so a question analysed today is the same question that appears in tomorrow’s blueprint check, next week’s grading and next semester’s version comparison.</Lede></Reveal>
          <div className="mt-12 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 text-left">
            {FEATURES.map((f, i) => (
              <Reveal as="article" key={f.title} delay={(i % 3) * 100} className="group rounded-xl border border-sage-200 bg-white p-6 hover:shadow-elevated hover:-translate-y-1 transition-all duration-300">
                <div className="w-10 h-10 rounded-lg bg-sage-100 flex items-center justify-center transition-colors group-hover:bg-sage-700 group-hover:text-white"><f.icon className="w-5 h-5" strokeWidth={1.6} aria-hidden="true" /></div>
                <h3 className="mt-4 text-sm font-semibold">{f.title}</h3>
                <p className="mt-1.5 text-xs text-sage-500 leading-relaxed">{f.desc}</p>
              </Reveal>
            ))}
          </div>
        </div>
      </section>

      {/* Principles */}
      <section className="bg-white">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-20">
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-10">
            <Reveal className="lg:col-span-4 space-y-4">
              <Eyebrow>Why FacultyLens</Eyebrow>
              <Heading>Built for people who have to <span className="landing-ink">stand behind the result.</span></Heading>
              <Lede>An assessment tool for universities cannot be a black box. Faculty defend their papers to students, colleagues, and accreditation bodies — so every output has to be traceable, reversible and yours.</Lede>
            </Reveal>
            <div className="lg:col-span-8 grid grid-cols-1 md:grid-cols-3 gap-4">
              {PRINCIPLES.map((p, i) => (
                <Reveal key={p.title} delay={i * 120} className="rounded-xl border border-sage-200 bg-sage-50 p-6 space-y-3">
                  <IconCircle icon={p.icon} size="md" />
                  <h3 className="text-sm font-semibold">{p.title}</h3>
                  <p className="text-xs text-sage-500 leading-relaxed">{p.text}</p>
                </Reveal>
              ))}
            </div>
          </div>
        </div>
      </section>

      {/* Lifecycle */}
      <section className="bg-sage-800 text-white relative overflow-hidden">
        <Leaf className="landing-float-slow absolute -right-10 -top-10 w-80 h-80 text-white/5 pointer-events-none" aria-hidden="true" />
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-20 relative">
          <Reveal className="max-w-2xl">
            <p className="text-[11px] uppercase tracking-[0.18em] text-sage-300">The full lifecycle</p>
            <h2 className="mt-3 font-serif text-3xl sm:text-4xl lg:text-[2.75rem] leading-[1.1] tracking-tight">
              <Typewriter text="From the first blueprint to the final version, nothing is lost." />
            </h2>
            <p className="mt-4 text-sm sm:text-base text-sage-300 leading-relaxed">Assessment quality is not a single check. It is a cycle that runs every semester. FacultyLens follows the whole loop and preserves each step as evidence.</p>
          </Reveal>
          <ol className="mt-12 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {LIFECYCLE.map((l, i) => (
              <Reveal as="li" key={l.title} delay={i * 90} className="rounded-xl border border-white/10 bg-white/5 p-5 hover:bg-white/10 transition-colors">
                <div className="flex items-center gap-3">
                  <span className="font-serif text-2xl text-sage-300 w-8">{String(i + 1).padStart(2, '0')}</span>
                  <l.icon className="w-5 h-5 text-sage-200" strokeWidth={1.6} aria-hidden="true" />
                  <h3 className="text-sm font-semibold">{l.title}</h3>
                </div>
                <p className="mt-3 text-xs text-sage-300 leading-relaxed">{l.text}</p>
              </Reveal>
            ))}
          </ol>
        </div>
      </section>

      {/* Big picture */}
      <section id="about" className="bg-white border-b border-sage-200/70">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-20 grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
          <Reveal className="space-y-5">
            <Eyebrow>The Big Picture</Eyebrow>
            <Heading>From Questions to Learning Outcomes</Heading>
            <Lede className="max-w-md">FacultyLens connects your assessments to learning outcomes, identifies gaps, and gives you the insights you need to improve student success.</Lede>
            <Lede className="max-w-md">A low class average tells you something went wrong. Tracing that average back to the two questions on transactions, the outcome they were meant to test and the topic the syllabus barely covered tells you what to change.</Lede>
            <PrimaryButton onClick={go('/register')}>Learn More</PrimaryButton>
          </Reveal>
          <Reveal delay={150}><TraceabilityMap /></Reveal>
        </div>
      </section>

      {/* Voices */}
      <section className="bg-sage-50">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-20">
          <Reveal className="text-center"><Eyebrow>From the faculty room</Eyebrow><Heading className="mt-3">Written for the way faculty actually work.</Heading><Lede className="mt-4 max-w-2xl mx-auto">Illustrative scenarios drawn from the situations FacultyLens was designed around.</Lede></Reveal>
          <div className="mt-12 grid grid-cols-1 md:grid-cols-3 gap-4">
            {VOICES.map((v, i) => (
              <Reveal key={v.role} delay={i * 120} className="rounded-xl border border-sage-200 bg-white p-6 flex flex-col gap-4 hover:-translate-y-1 hover:shadow-elevated transition-all duration-300">
                <Quote className="w-6 h-6 text-sage-300" aria-hidden="true" />
                <p className="font-serif text-lg leading-snug text-sage-800">“{v.text}”</p>
                <p className="mt-auto text-xs text-sage-500">{v.role}</p>
              </Reveal>
            ))}
          </div>
        </div>
      </section>

      {/* FAQ */}
      <section className="bg-white border-t border-sage-200/70">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 sm:py-20 grid grid-cols-1 lg:grid-cols-12 gap-10">
          <Reveal className="lg:col-span-4 space-y-4">
            <Eyebrow>Questions faculty ask first</Eyebrow>
            <Heading>Straight answers.</Heading>
            <Lede>The questions we hear before anyone uploads their first paper — answered the way the system actually behaves.</Lede>
          </Reveal>
          <Reveal delay={120} className="lg:col-span-8">
            <ul className="border-t border-sage-200">
              {FAQ.map((f, i) => <FaqItem key={f.q} q={f.q} a={f.a} open={openFaq === i} onToggle={() => setOpenFaq(openFaq === i ? -1 : i)} />)}
            </ul>
          </Reveal>
        </div>
      </section>

      {/* CTA */}
      <section id="pricing" className="bg-white">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-16">
          <Reveal>
            <div className="relative overflow-hidden rounded-2xl bg-sage-100 border border-sage-200 px-6 sm:px-10 py-10 flex flex-col md:flex-row md:items-center gap-8">
              <Leaf className="landing-float absolute -left-4 -bottom-6 w-32 h-32 text-sage-300/60" aria-hidden="true" />
              <div className="hidden md:block w-24 shrink-0" />
              <div className="flex-1 space-y-2 relative">
                <Eyebrow>Built for Academic Excellence</Eyebrow>
                <h2 className="font-serif text-2xl sm:text-3xl tracking-tight">AI assists. <span className="landing-sheen">Faculty decides.</span></h2>
                <p className="text-sm text-sage-600 max-w-xl">Upload one assessment and see its quality, alignment and similarity picture in minutes — no automatic changes, no lock-in, every decision left to you.</p>
                <p className="text-xs text-sage-500 flex flex-wrap gap-x-4"><span>Explainable</span><span>·</span><span>Private</span><span>·</span><span>Auditable</span></p>
              </div>
              <PrimaryButton onClick={go('/register')} className="self-start md:self-center relative">Get Started</PrimaryButton>
            </div>
          </Reveal>
        </div>
      </section>
    </div>
  );
};
