import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button } from '@/components/common/Button';
import { Card } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import { ProgressBar } from '@/components/dashboard/ProgressBar';
import {
  Sparkles,
  ArrowRight,
  BookOpen,
  Target,
  CopyCheck,
  CheckCircle2,
  Lightbulb,
  UploadCloud,
  Cpu,
  Eye,
  Sliders,
  ShieldCheck,
  Award,
  BarChart3,
  SearchCheck,
  Compass,
} from 'lucide-react';

export const Home: React.FC = () => {
  const navigate = useNavigate();
  const [activeInteractiveTab, setActiveInteractiveTab] = useState<'taxonomy' | 'similarity' | 'matrix'>('taxonomy');

  const features = [
    {
      title: 'Syllabus & Curriculum Analysis',
      description: 'Automatically parse multi-week course outlines, identify core thematic pillars, and verify prerequisite topic distribution across academic semesters.',
      icon: BookOpen,
      tag: 'Curriculum',
    },
    {
      title: 'Learning Outcome (CLO/PLO) Alignment',
      description: 'Map assessment items directly against Bloom’s Revised Taxonomy levels to identify under-tested course learning objectives and prevent curriculum blindspots.',
      icon: Target,
      tag: 'Alignment',
    },
    {
      title: 'Question Similarity & Reuse Detection',
      description: 'Leverage semantic similarity embeddings to detect duplicate or near-identical questions from previous academic terms and protect examination integrity.',
      icon: CopyCheck,
      tag: 'Integrity',
    },
    {
      title: 'Assessment Quality & Rigor Audit',
      description: 'Evaluate question phrasing clarity, mark distribution parity, and overall cognitive depth to ensure balanced examination rigor.',
      icon: CheckCircle2,
      tag: 'Quality',
    },
    {
      title: 'Actionable AI Recommendations',
      description: 'Receive concrete pedagogical recommendations: proposed question rewrites, cognitive elevation tips, and targeted outcome balancing.',
      icon: Lightbulb,
      tag: 'Decisions',
    },
    {
      title: 'Accreditation & Governance Readiness',
      description: 'Generate audit-ready documentation and alignment matrices compatible with ABET, AACSB, and Washington Accord outcome-based education (OBE) criteria.',
      icon: Award,
      tag: 'Accreditation',
    },
    {
      title: 'Cognitive & Difficulty Balancer',
      description: 'Visualize student effort distribution across foundational (20%), application (60%), and higher-order synthesis (20%) question tiers.',
      icon: BarChart3,
      tag: 'Analytics',
    },
    {
      title: 'Historical Examination Repository',
      description: 'Cross-analyze semester archives to uncover long-term coverage trends, recurring problem types, and historical quality improvements.',
      icon: SearchCheck,
      tag: 'Repository',
    },
  ];

  const steps = [
    {
      step: '01',
      title: 'Upload Course Materials',
      desc: 'Submit syllabi, course learning outcomes, current question papers, and past examination archives in PDF, DOCX, or text formats.',
      icon: UploadCloud,
    },
    {
      step: '02',
      title: 'AI Multi-Dimensional Analysis',
      desc: 'Our academic intelligence engine processes taxonomies, semantic embeddings, and curriculum coverage matrices simultaneously.',
      icon: Cpu,
    },
    {
      step: '03',
      title: 'Inspect Visual Intelligence',
      desc: 'Explore comprehensive interactive dashboards detailing topic representation, Bloom distributions, and duplicate flags.',
      icon: Eye,
    },
    {
      step: '04',
      title: 'Make Informed Faculty Decisions',
      desc: 'Review AI suggestions, refine problem statements, adjust weighting, and finalize high-rigor assessments with full academic freedom.',
      icon: Sliders,
    },
  ];

  return (
    <div className="flex flex-col">
      {/* Hero Section */}
      <section className="relative overflow-hidden py-16 sm:py-28 border-b border-[#E5E5E5] bg-gradient-to-b from-[#FFFFFF] to-[#F7F7F5]">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-8 items-center">
            {/* Left Column: Heading & Narrative */}
            <div className="lg:col-span-7 space-y-6 text-left">
              <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full border border-[#E5E5E5] bg-white text-xs font-semibold tracking-wide text-[#262626] shadow-subtle">
                <Sparkles className="w-3.5 h-3.5 text-[#111111] animate-pulse" />
                <span>Next-Gen Academic Decision Support System</span>
              </div>

              <h1 className="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-[#111111] tracking-tighter leading-[1.12]">
                See Academic Work <br className="hidden sm:inline" />
                Through a <span className="underline decoration-[#737373] decoration-4 underline-offset-8">Smarter Lens</span>
              </h1>

              <p className="text-base sm:text-lg text-[#737373] max-w-2xl font-normal leading-relaxed">
                FacultyLens is an AI-powered academic decision support platform built specifically for university educators. Transform hours of manual syllabus cross-referencing, learning outcome mapping, and question validation into instant, transparent pedagogical insights.
              </p>

              <div className="flex flex-wrap items-center gap-3.5 pt-2">
                <Button
                  size="lg"
                  variant="primary"
                  rightIcon={<ArrowRight className="w-4 h-4" />}
                  onClick={() => navigate('/login')}
                  className="font-bold tracking-wide"
                >
                  Get Started
                </Button>
                <a href="#interactive-demo">
                  <Button size="lg" variant="outline" className="font-semibold">
                    Explore Interactive Live Box
                  </Button>
                </a>
              </div>

              <div className="pt-6 border-t border-[#E5E5E5] flex flex-wrap items-center gap-6 text-xs font-semibold text-[#737373]">
                <div className="flex items-center gap-2">
                  <CheckCircle2 className="w-4 h-4 text-[#16A34A]" />
                  <span>Accreditation Ready (OBE / ABET)</span>
                </div>
                <div className="flex items-center gap-2">
                  <CheckCircle2 className="w-4 h-4 text-[#16A34A]" />
                  <span>Bloom Taxonomy Classification</span>
                </div>
                <div className="flex items-center gap-2">
                  <CheckCircle2 className="w-4 h-4 text-[#16A34A]" />
                  <span>Human-in-the-Loop Governance</span>
                </div>
              </div>
            </div>

            {/* Right Column: AI Analysis Preview Card */}
            <div className="lg:col-span-5">
              <div className="relative mx-auto max-w-md">
                <div className="absolute -inset-1.5 bg-gradient-to-r from-[#111111]/10 via-[#737373]/10 to-[#111111]/10 rounded-2xl blur-md -z-10" />

                <Card className="bg-white border-[#111111]/15 shadow-elevated p-6 sm:p-7 space-y-5">
                  <div className="flex items-center justify-between pb-3.5 border-b border-[#E5E5E5]">
                    <div>
                      <span className="text-[10px] font-mono uppercase tracking-widest text-[#737373] font-bold">Assessment Preview</span>
                      <h2 className="text-base font-extrabold text-[#111111] tracking-tight">Database Systems Midterm</h2>
                    </div>
                    <Badge variant="Analyzed" dot>Analyzed</Badge>
                  </div>

                  {/* Quality Metrics */}
                  <div className="space-y-4">
                    <div>
                      <div className="flex justify-between items-baseline mb-1.5">
                        <span className="text-xs font-bold text-[#111111] uppercase tracking-wider">Overall Quality</span>
                        <span className="text-2xl font-extrabold text-[#111111] font-mono">86%</span>
                      </div>
                      <ProgressBar value={86} size="md" statusColor />
                    </div>

                    <div className="grid grid-cols-2 gap-3 pt-2">
                      <div className="p-3.5 bg-[#F7F7F5] rounded-xl border border-[#E5E5E5]">
                        <span className="text-[11px] text-[#737373] font-medium block">Topic Coverage</span>
                        <span className="text-lg font-extrabold text-[#111111] font-mono">89%</span>
                        <div className="w-full bg-[#E5E5E5] h-1.5 rounded-full mt-2 overflow-hidden">
                          <div className="bg-[#16A34A] h-full w-[89%] rounded-full" />
                        </div>
                      </div>

                      <div className="p-3.5 bg-[#F7F7F5] rounded-xl border border-[#E5E5E5]">
                        <span className="text-[11px] text-[#737373] font-medium block">LO Alignment</span>
                        <span className="text-lg font-extrabold text-[#111111] font-mono">84%</span>
                        <div className="w-full bg-[#E5E5E5] h-1.5 rounded-full mt-2 overflow-hidden">
                          <div className="bg-[#16A34A] h-full w-[84%] rounded-full" />
                        </div>
                      </div>
                    </div>

                    {/* Flagged alert preview */}
                    <div className="flex items-center justify-between p-3.5 rounded-xl bg-[#FEF2F2] border border-[#FECACA] text-xs text-[#991B1B]">
                      <div className="flex items-center gap-2">
                        <CopyCheck className="w-4 h-4 shrink-0 text-[#DC2626]" />
                        <span className="font-bold">Similar Questions</span>
                      </div>
                      <span className="font-extrabold font-mono px-2 py-0.5 bg-white rounded-md text-[#991B1B] border border-[#FECACA]">
                        2 detected
                      </span>
                    </div>
                  </div>

                  <div className="pt-2 flex items-center justify-between text-[11px] text-[#737373] border-t border-[#E5E5E5] font-medium">
                    <span className="flex items-center gap-1.5">
                      <Sparkles className="w-3.5 h-3.5 text-[#111111]" /> Simulated AI Output
                    </span>
                    <span className="font-mono">Spring 2026</span>
                  </div>
                </Card>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ✦ Interactive Animated Academic Intelligence Box Section */}
      <section id="interactive-demo" className="py-20 sm:py-28 bg-[#111111] text-white relative overflow-hidden border-b border-[#262626]">
        {/* Ambient background grid & glow */}
        <div className="absolute inset-0 bg-[radial-gradient(#333333_1px,transparent_1px)] [background-size:24px_24px] opacity-30 pointer-events-none" />

        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 space-y-12">
          <div className="text-center max-w-3xl mx-auto space-y-3">
            <span className="font-mono text-xs uppercase font-bold tracking-widest text-[#16A34A] px-3 py-1 rounded-full bg-[#16A34A]/10 border border-[#16A34A]/20">
              Interactive Decision Simulator
            </span>
            <h2 className="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-white tracking-tight">
              Real-Time Academic Lens Showcase
            </h2>
            <p className="text-sm sm:text-base text-[#A3A3A3] leading-relaxed">
              Explore how FacultyLens deconstructs complex examination papers across multiple pedagogical dimensions in seconds.
            </p>
          </div>

          {/* Interactive Showcase Box Container */}
          <div className="max-w-4xl mx-auto bg-[#181818] rounded-2xl border border-[#2E2E2E] shadow-2xl p-6 sm:p-8 space-y-6">
            {/* Simulation Tab Switcher */}
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[#2E2E2E] pb-4">
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => setActiveInteractiveTab('taxonomy')}
                  className={`px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all ${
                    activeInteractiveTab === 'taxonomy'
                      ? 'bg-white text-[#111111] shadow-md'
                      : 'text-[#A3A3A3] hover:text-white hover:bg-[#262626]'
                  }`}
                >
                  Bloom Cognitive Taxonomy
                </button>
                <button
                  type="button"
                  onClick={() => setActiveInteractiveTab('similarity')}
                  className={`px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all ${
                    activeInteractiveTab === 'similarity'
                      ? 'bg-white text-[#111111] shadow-md'
                      : 'text-[#A3A3A3] hover:text-white hover:bg-[#262626]'
                  }`}
                >
                  Semantic Duplicate Scan
                </button>
                <button
                  type="button"
                  onClick={() => setActiveInteractiveTab('matrix')}
                  className={`px-3.5 py-1.5 rounded-lg text-xs font-bold transition-all ${
                    activeInteractiveTab === 'matrix'
                      ? 'bg-white text-[#111111] shadow-md'
                      : 'text-[#A3A3A3] hover:text-white hover:bg-[#262626]'
                  }`}
                >
                  Outcome Matrix (CLO)
                </button>
              </div>

              <div className="flex items-center gap-2 text-xs font-mono text-[#16A34A]">
                <span className="w-2 h-2 rounded-full bg-[#16A34A] animate-ping" />
                <span>Live AI Simulation</span>
              </div>
            </div>

            {/* Dynamic Interactive Tab Content */}
            {activeInteractiveTab === 'taxonomy' && (
              <div className="space-y-5 text-left animate-fadeIn">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                  <div>
                    <h3 className="text-base font-bold text-white">Cognitive Level Breakdown</h3>
                    <p className="text-xs text-[#A3A3A3]">Evaluates question distribution against Bloom&apos;s Revised Taxonomy</p>
                  </div>
                  <span className="font-mono text-xs px-2.5 py-1 rounded bg-[#262626] border border-[#3E3E3E] text-white">
                    Target: 20% / 60% / 20%
                  </span>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                  <div className="p-4 rounded-xl bg-[#202020] border border-[#2E2E2E] space-y-2">
                    <span className="text-[11px] font-mono uppercase text-[#16A34A] font-bold">Remember & Understand</span>
                    <div className="text-2xl font-bold font-mono text-white">20%</div>
                    <p className="text-[11px] text-[#A3A3A3]">Foundational conceptual recall and definitions.</p>
                  </div>
                  <div className="p-4 rounded-xl bg-[#202020] border border-[#2E2E2E] space-y-2">
                    <span className="text-[11px] font-mono uppercase text-white font-bold">Apply & Analyze</span>
                    <div className="text-2xl font-bold font-mono text-white">60%</div>
                    <p className="text-[11px] text-[#A3A3A3]">Algorithmic solutions, relational queries & proofs.</p>
                  </div>
                  <div className="p-4 rounded-xl bg-[#202020] border border-[#2E2E2E] space-y-2">
                    <span className="text-[11px] font-mono uppercase text-white/80 font-bold">Evaluate & Create</span>
                    <div className="text-2xl font-bold font-mono text-white">20%</div>
                    <p className="text-[11px] text-[#A3A3A3]">Architecture synthesis and critical trade-off evaluation.</p>
                  </div>
                </div>

                {/* Animated multi-tier bar */}
                <div className="space-y-1.5">
                  <div className="h-3 w-full bg-[#2E2E2E] rounded-full overflow-hidden flex shadow-inner">
                    <div className="bg-[#16A34A] h-full transition-all duration-700" style={{ width: '20%' }} />
                    <div className="bg-white h-full transition-all duration-700" style={{ width: '60%' }} />
                    <div className="bg-[#737373] h-full transition-all duration-700" style={{ width: '20%' }} />
                  </div>
                  <div className="flex justify-between text-[10px] font-mono text-[#737373]">
                    <span>Lower Order (20%)</span>
                    <span>Application Tier (60%)</span>
                    <span>Higher Synthesis (20%)</span>
                  </div>
                </div>
              </div>
            )}

            {activeInteractiveTab === 'similarity' && (
              <div className="space-y-4 text-left animate-fadeIn">
                <div className="flex items-center justify-between">
                  <div>
                    <h3 className="text-base font-bold text-white">Semantic Cross-Term Collision Radar</h3>
                    <p className="text-xs text-[#A3A3A3]">Scanning historical papers across Spring 2024 - Fall 2025</p>
                  </div>
                  <Badge variant="Critical" dot>2 Collisions Flagged</Badge>
                </div>

                <div className="p-4 rounded-xl bg-[#2A1818] border border-[#5C2424] space-y-2">
                  <div className="flex items-center justify-between text-xs">
                    <span className="font-bold text-[#FCA5A5] font-mono">Question #7 Collision Detected (87% Match)</span>
                    <span className="font-mono text-[10px] px-2 py-0.5 rounded bg-black/40 text-[#FCA5A5]">Matched: Spring 2025 Midterm Q5</span>
                  </div>
                  <p className="text-xs text-[#FCA5A5]/80 font-mono">
                    &ldquo;Compare the search time complexity of B+ Tree index versus Hash index for range query operations.&rdquo;
                  </p>
                  <p className="text-[11px] text-white/90 pt-1">
                    <strong className="text-[#16A34A]">AI Recommendation:</strong> Replace with an open architectural synthesis problem assessing index selectivity on skew-distributed datasets.
                  </p>
                </div>
              </div>
            )}

            {activeInteractiveTab === 'matrix' && (
              <div className="space-y-4 text-left animate-fadeIn">
                <div className="flex items-center justify-between">
                  <div>
                    <h3 className="text-base font-bold text-white">Course Learning Outcome (CLO) Coverage Matrix</h3>
                    <p className="text-xs text-[#A3A3A3]">Real-time accreditation alignment balance</p>
                  </div>
                  <span className="font-mono text-xs text-[#16A34A]">5 / 5 CLOs Tracked</span>
                </div>

                <div className="space-y-2.5">
                  <div className="flex items-center justify-between p-2.5 rounded-lg bg-[#202020] border border-[#2E2E2E] text-xs">
                    <span className="font-mono text-white font-bold">CLO-1: Conceptual & Logical Relational Modeling</span>
                    <span className="font-mono text-[#16A34A] font-bold">92% (2 Questions)</span>
                  </div>
                  <div className="flex items-center justify-between p-2.5 rounded-lg bg-[#202020] border border-[#2E2E2E] text-xs">
                    <span className="font-mono text-white font-bold">CLO-2: Relational Algebra & Advanced SQL Querying</span>
                    <span className="font-mono text-[#16A34A] font-bold">95% (3 Questions)</span>
                  </div>
                  <div className="flex items-center justify-between p-2.5 rounded-lg bg-[#202020] border border-[#2E2E2E] text-xs">
                    <span className="font-mono text-white font-bold">CLO-3: Schema Normalization & Lossless Decompositions</span>
                    <span className="font-mono text-[#16A34A] font-bold">88% (2 Questions)</span>
                  </div>
                  <div className="flex items-center justify-between p-2.5 rounded-lg bg-[#332211] border border-[#664422] text-xs">
                    <span className="font-mono text-[#FBBF24] font-bold">CLO-4: Transaction ACID & Concurrency Control</span>
                    <span className="font-mono text-[#FBBF24] font-bold">40% (1 Question — Low Coverage)</span>
                  </div>
                </div>
              </div>
            )}

            {/* Bottom Interactive Action Callout */}
            <div className="pt-4 border-t border-[#2E2E2E] flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-[#A3A3A3]">
              <span>Ready to inspect your university department&apos;s question papers?</span>
              <Button
                variant="secondary"
                size="sm"
                onClick={() => navigate('/login')}
                className="bg-white text-[#111111] hover:bg-[#E5E5E5] font-bold"
              >
                Analyze Paper Now
              </Button>
            </div>
          </div>
        </div>
      </section>

      {/* Narrative Section: The Academic Dilemma & Solution */}
      <section className="py-20 sm:py-24 bg-white border-b border-[#E5E5E5]">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center text-left">
            <div className="lg:col-span-6 space-y-4">
              <Badge variant="outline" className="font-mono font-bold tracking-widest text-[11px]">
                PEDAGOGICAL CHALLENGE
              </Badge>
              <h2 className="text-3xl sm:text-4xl font-extrabold text-[#111111] tracking-tight leading-snug">
                Why Faculty Need an AI Decision-Support Co-Pilot
              </h2>
              <p className="text-sm sm:text-base text-[#737373] leading-relaxed">
                Designing academic examinations requires balancing complex curriculum objectives, historical exam papers, Bloom’s cognitive taxonomy distribution, and strict accreditation criteria.
              </p>
              <p className="text-sm sm:text-base text-[#737373] leading-relaxed">
                Without specialized assistive intelligence, subtle curriculum gaps, repetitive questions from previous semesters, and cognitive level imbalances easily go unnoticed amidst demanding academic schedules.
              </p>
            </div>

            <div className="lg:col-span-6 space-y-4">
              <div className="p-6 rounded-2xl bg-[#F7F7F5] border border-[#E5E5E5] space-y-3">
                <div className="flex items-center gap-2.5 font-bold text-[#111111]">
                  <Compass className="w-5 h-5 text-[#111111]" />
                  <span>The FacultyLens Decision Framework</span>
                </div>
                <p className="text-xs sm:text-sm text-[#737373] leading-relaxed">
                  FacultyLens bridges the gap by acting as an objective analytical co-pilot. It extracts syllabus structures, measures question similarity across terms, maps cognitive taxonomy levels, and highlights curriculum gaps — empowering professors to make informed, data-backed adjustments with total academic autonomy.
                </p>
              </div>

              <div className="grid grid-cols-2 gap-3 text-left">
                <div className="p-4 rounded-xl border border-[#E5E5E5] bg-white">
                  <span className="text-2xl font-extrabold text-[#111111] font-mono">100%</span>
                  <p className="text-xs text-[#737373] pt-1">Faculty Decision Ownership</p>
                </div>
                <div className="p-4 rounded-xl border border-[#E5E5E5] bg-white">
                  <span className="text-2xl font-extrabold text-[#111111] font-mono">&lt; 3s</span>
                  <p className="text-xs text-[#737373] pt-1">Automated Assessment Audit</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Expanded Features Section (8 Cards) */}
      <section id="features" className="py-20 sm:py-28 bg-[#F7F7F5] border-b border-[#E5E5E5]">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="text-center max-w-3xl mx-auto mb-16 space-y-3">
            <Badge variant="outline" className="font-mono font-bold tracking-widest text-[11px]">COMPREHENSIVE CAPABILITIES</Badge>
            <h2 className="text-3xl sm:text-4xl font-extrabold text-[#111111] tracking-tight">
              Designed for Academic Rigor & Faculty Control
            </h2>
            <p className="text-base text-[#737373]">
              Purpose-built tools to enhance assessment design, ensure learning outcome compliance, and uphold university examination integrity.
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 text-left">
            {features.map((feat) => {
              const Icon = feat.icon;
              return (
                <Card
                  key={feat.title}
                  variant="default"
                  className="hover:border-[#111111] hover:shadow-card transition-all duration-200 flex flex-col justify-between p-6"
                >
                  <div className="space-y-4">
                    <div className="flex items-center justify-between">
                      <div className="w-10 h-10 rounded-xl bg-[#111111] text-white flex items-center justify-center shadow-subtle">
                        <Icon className="w-5 h-5" />
                      </div>
                      <Badge variant="neutral" className="text-[10px] font-semibold">{feat.tag}</Badge>
                    </div>
                    <div>
                      <h3 className="text-base font-bold text-[#111111] mb-2 tracking-tight">{feat.title}</h3>
                      <p className="text-xs text-[#737373] leading-relaxed">{feat.description}</p>
                    </div>
                  </div>
                </Card>
              );
            })}
          </div>
        </div>
      </section>

      {/* How It Works Section */}
      <section id="how-it-works" className="py-20 sm:py-28 bg-white border-b border-[#E5E5E5]">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="text-center max-w-3xl mx-auto mb-16 space-y-3">
            <Badge variant="outline" className="font-mono font-bold tracking-widest text-[11px]">FOUR-STEP WORKFLOW</Badge>
            <h2 className="text-3xl sm:text-4xl font-extrabold text-[#111111] tracking-tight">
              How FacultyLens Operates
            </h2>
            <p className="text-base text-[#737373]">
              A streamlined, transparent decision support cycle from initial document upload to final academic approval.
            </p>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 text-left">
            {steps.map((st) => {
              const Icon = st.icon;
              return (
                <div
                  key={st.step}
                  className="relative p-6 sm:p-7 rounded-xl border border-[#E5E5E5] bg-[#F7F7F5] space-y-4 hover:border-[#111111] transition-colors"
                >
                  <div className="flex items-center justify-between">
                    <span className="text-3xl font-black font-mono text-[#111111]/25 tracking-tighter">{st.step}</span>
                    <div className="w-10 h-10 rounded-xl bg-white border border-[#E5E5E5] flex items-center justify-center text-[#111111] shadow-subtle">
                      <Icon className="w-4 h-4" />
                    </div>
                  </div>
                  <div>
                    <h3 className="text-base font-bold text-[#111111] mb-1.5 tracking-tight">{st.title}</h3>
                    <p className="text-xs text-[#737373] leading-relaxed">{st.desc}</p>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </section>

      {/* Human-in-the-Loop Section */}
      <section id="about" className="py-20 sm:py-28 bg-[#111111] text-white">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="max-w-3xl mx-auto text-center space-y-6">
            <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-[#262626] border border-[#3E3E3E] text-xs font-semibold text-[#A3A3A3]">
              <ShieldCheck className="w-4 h-4 text-[#16A34A]" />
              <span>Ethical AI & Academic Governance</span>
            </div>

            <h2 className="text-3xl sm:text-4xl lg:text-5xl font-extrabold tracking-tight text-white leading-tight">
              FacultyLens supports faculty decisions — it does not replace them.
            </h2>

            <p className="text-base sm:text-lg text-[#A3A3A3] font-normal leading-relaxed">
              We believe university educators hold irreplaceable pedagogical judgment. FacultyLens serves as an analytical co-pilot: surfacing hidden imbalances, highlighting curriculum gaps, and providing evidence-based recommendations, while leaving all final grading and design decisions entirely with faculty.
            </p>

            <div className="pt-4">
              <Button
                variant="secondary"
                size="lg"
                onClick={() => navigate('/login')}
                className="bg-white text-[#111111] hover:bg-[#E5E5E5] font-bold"
              >
                Experience FacultyLens
              </Button>
            </div>
          </div>
        </div>
      </section>
    </div>
  );
};
