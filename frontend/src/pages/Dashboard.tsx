import React, { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import {
  AlertTriangle, ArrowRight, BarChart3, BookOpen, BrainCircuit, ClipboardList, FileCheck2, GitCompare, HelpCircle, Lightbulb, Loader2, Plus, RefreshCw, Sparkles, Target,
} from 'lucide-react';
import { useAuth } from '@/context/AuthContext';
import { academicAnalyticsService } from '@/services/academicAnalyticsService';
import { ApiError } from '@/services/api';
import { AnalyticsOverview, AssessmentRow, AttentionArea, AttentionSeverity, QualityRating } from '@/types/analytics';
import { CollaborationSummaryCard } from '@/components/collaboration/CollaborationActivity';
import { Reveal } from '@/components/landing/Motion';

/* ------------------------------------------------------------------ helpers */

const fmt = (v: number | null | undefined, unit?: 'percent' | 'score'): string => (v === null || v === undefined ? '—' : unit === 'percent' ? `${Math.round(v)}%` : Number.isInteger(v) ? String(v) : v.toFixed(1));
const fmtDate = (iso: string | null | undefined): string => (iso ? new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : '—');
const humanize = (s: string | null | undefined): string => (s ?? '').replace(/_/g, ' ').toLowerCase().replace(/^\w/, (c) => c.toUpperCase());

const ratingTone = (r: QualityRating | null): string => {
  switch (r) {
    case 'EXCELLENT': case 'GOOD': return 'bg-[#F0FDF4] text-[#166534] border-[#BBF7D0]';
    case 'FAIR': case 'NEEDS_REVIEW': return 'bg-[#FFFBEB] text-[#92400E] border-[#FDE68A]';
    case 'REQUIRES_ATTENTION': return 'bg-[#FEF2F2] text-[#991B1B] border-[#FECACA]';
    default: return 'bg-sage-100 text-sage-700 border-sage-200';
  }
};
const severityTone = (s: AttentionSeverity): string => (s === 'HIGH' ? 'border-[#FECACA] bg-[#FEF2F2] text-[#991B1B]' : s === 'MEDIUM' ? 'border-[#FDE68A] bg-[#FFFBEB] text-[#92400E]' : 'border-sage-200 bg-sage-50 text-sage-700');
const areaLink = (a: AttentionArea): string | null => (!a.link ? null : a.link.type === 'assessment' ? `/assessments/${a.link.id}` : `/courses/${a.link.id}`);

const errorMessage = (e: unknown): string => {
  if (e instanceof ApiError) {
    if (e.status === 401) return 'Your session has expired. Please sign in again.';
    if (e.status === 403) return 'You do not have access to analytics for these courses.';
    if (e.status >= 500) return 'The analytics service is temporarily unavailable.';
    return e.message || 'Could not load your dashboard.';
  }
  return e instanceof Error ? e.message : 'Could not load your dashboard.';
};

/* ------------------------------------------------------------------ pieces */

const Panel: React.FC<{ title: string; subtitle?: string; action?: React.ReactNode; children: React.ReactNode; className?: string; testId?: string }> = ({ title, subtitle, action, children, className, testId }) => (
  <section data-testid={testId} className={`rounded-xl border border-sage-200 bg-white p-5 ${className ?? ''}`}>
    <header className="flex items-start justify-between gap-3 mb-4">
      <div><h2 className="font-serif text-lg leading-tight text-sage-800">{title}</h2>{subtitle && <p className="text-xs text-sage-500 mt-0.5">{subtitle}</p>}</div>
      {action}
    </header>
    {children}
  </section>
);

const Kpi: React.FC<{ label: string; value: string; note?: string; icon: React.ElementType; to: string; delay?: number }> = ({ label, value, note, icon: Icon, to, delay }) => (
  <Reveal delay={delay}>
    <Link to={to} className="group relative block rounded-xl border border-sage-200 bg-white p-4 shadow-subtle transition-all hover:border-sage-300 hover:-translate-y-0.5 hover:shadow-card">
      <div className="relative flex items-start justify-between gap-2">
        <p className="text-[11px] uppercase tracking-[0.14em] text-sage-500">{label}</p>
        <span className="w-8 h-8 rounded-lg bg-white border border-sage-200 flex items-center justify-center text-sage-800 transition-colors group-hover:border-sage-300"><Icon className="w-4 h-4" strokeWidth={1.8} aria-hidden="true" /></span>
      </div>
      <p className="relative mt-2 font-serif text-3xl leading-none text-sage-800 tabular-nums">{value}</p>
      {note && <p className="relative mt-1.5 text-xs text-sage-500 truncate">{note}</p>}
      <ArrowRight aria-hidden="true" className="absolute right-4 bottom-4 w-3.5 h-3.5 text-sage-500 -translate-x-1 opacity-0 transition-all group-hover:translate-x-0 group-hover:opacity-100" />
    </Link>
  </Reveal>
);

const QuickAction: React.FC<{ icon: React.ElementType; label: string; to: string; primary?: boolean }> = ({ icon: Icon, label, to, primary }) => (
  <Link to={to} className={`inline-flex items-center gap-2 rounded-lg text-sm px-4 py-2 transition-all hover:-translate-y-0.5 ${primary ? 'bg-sage-700 text-white hover:bg-sage-800 hover:shadow-elevated' : 'border border-sage-300 bg-white text-sage-800 hover:bg-sage-100'}`}>
    <Icon className="w-4 h-4" strokeWidth={1.8} aria-hidden="true" />{label}
  </Link>
);

const DistributionBar: React.FC<{ rows: { label: string; pct: number | null; target?: number }[] }> = ({ rows }) => {
  const shades = ['bg-sage-300', 'bg-sage-500', 'bg-sage-700', 'bg-sage-800', 'bg-sage-400', 'bg-sage-600'];
  return (
    <div className="space-y-3">
      <div className="h-3 w-full rounded-full bg-sage-100 overflow-hidden flex">
        {rows.map((r, i) => <div key={r.label} className={`${shades[i % shades.length]} h-full transition-all duration-700`} style={{ width: `${r.pct ?? 0}%` }} title={`${r.label}: ${fmt(r.pct, 'percent')}`} />)}
      </div>
      <ul className="grid grid-cols-2 sm:grid-cols-3 gap-2 text-xs">
        {rows.map((r, i) => (
          <li key={r.label} className="flex items-center justify-between gap-2 rounded-lg border border-sage-200 bg-sage-50 px-2.5 py-1.5">
            <span className="flex items-center gap-1.5 min-w-0"><span className={`w-2 h-2 rounded-full ${shades[i % shades.length]}`} aria-hidden="true" /><span className="truncate">{r.label}</span></span>
            <span className="tabular-nums font-semibold">{fmt(r.pct, 'percent')}{r.target !== undefined && <span className="text-sage-400 font-normal"> / {r.target}%</span>}</span>
          </li>
        ))}
      </ul>
    </div>
  );
};

const AssessmentsTable: React.FC<{ rows: AssessmentRow[] }> = ({ rows }) => (
  <>
    {/* Mobile cards */}
    <ul className="md:hidden divide-y divide-sage-100">
      {rows.map((a) => (
        <li key={a.assessment_id} className="py-3">
          <Link to={`/assessments/${a.assessment_id}`} className="block">
            <div className="flex items-start justify-between gap-2">
              <p className="text-sm font-semibold text-sage-800 truncate">{a.title}</p>
              <span className={`shrink-0 rounded-full border px-2 py-0.5 text-[10px] ${ratingTone(a.quality_rating)}`}>{a.quality_score !== null ? `${Math.round(a.quality_score)} · ${humanize(a.quality_rating)}` : 'Not analyzed'}</span>
            </div>
            <p className="text-xs text-sage-500 mt-0.5">{a.course?.code ?? '—'} · {humanize(a.type)} · {a.questions} questions{a.date ? ` · ${fmtDate(a.date)}` : ''}</p>
          </Link>
        </li>
      ))}
    </ul>
    {/* Desktop table */}
    <div className="hidden md:block overflow-x-auto -mx-5">
      <table className="w-full text-left text-xs">
        <thead className="text-sage-500 uppercase tracking-wider border-b border-sage-200">
          <tr><th className="px-5 py-2 font-medium">Assessment</th><th className="px-5 py-2 font-medium">Course</th><th className="px-5 py-2 font-medium">Questions</th><th className="px-5 py-2 font-medium">Quality</th><th className="px-5 py-2 font-medium">Similar</th><th className="px-5 py-2 font-medium text-right">Open</th></tr>
        </thead>
        <tbody className="divide-y divide-sage-100">
          {rows.map((a) => (
            <tr key={a.assessment_id} className="hover:bg-sage-50 transition-colors">
              <td className="px-5 py-3"><span className="block text-sm font-semibold text-sage-800">{a.title}</span><span className="text-sage-500">{humanize(a.type)}{a.date ? ` · ${fmtDate(a.date)}` : ''}</span></td>
              <td className="px-5 py-3"><span className="block font-medium text-sage-800">{a.course?.code ?? '—'}</span><span className="text-sage-500 truncate block max-w-[14rem]">{a.course?.name}</span></td>
              <td className="px-5 py-3 tabular-nums">{a.questions}</td>
              <td className="px-5 py-3"><span className={`inline-flex rounded-full border px-2 py-0.5 text-[10px] ${ratingTone(a.quality_rating)}`}>{a.quality_score !== null ? `${Math.round(a.quality_score)} · ${humanize(a.quality_rating)}` : 'Not analyzed'}</span></td>
              <td className="px-5 py-3 tabular-nums">{a.similar_questions ?? '—'}</td>
              <td className="px-5 py-3 text-right">
                <Link to={a.quality_score !== null ? `/assessments/${a.assessment_id}/analysis` : `/assessments/${a.assessment_id}`} className="inline-flex items-center gap-1 text-sage-700 hover:text-sage-800 hover:underline">{a.quality_score !== null ? 'Analysis' : 'Details'}<ArrowRight className="w-3 h-3" aria-hidden="true" /></Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  </>
);

/* ------------------------------------------------------------------ page */

export const Dashboard: React.FC = () => {
  const navigate = useNavigate();
  const { user } = useAuth();
  const [data, setData] = useState<AnalyticsOverview | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (fresh = false) => {
    fresh ? setRefreshing(true) : setLoading(true);
    setError(null);
    try {
      const res = await academicAnalyticsService.getOverview(undefined, fresh);
      setData(res.data);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const displayName = user?.name || user?.fullName || 'Faculty Member';
  const firstName = displayName.split(/\s+/)[0];
  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';

  const k = data?.kpis;
  const hasCourses = (k?.courses.value ?? 0) > 0;
  const recent = [...(data?.assessments ?? [])].sort((a, b) => (b.date ?? '').localeCompare(a.date ?? '')).slice(0, 6);
  const attention = (data?.attention_areas ?? []).slice(0, 5);
  const difficulty = data?.difficulty.distribution.map((r) => ({ label: humanize(r.level), pct: r.percentage, target: r.target_percentage })) ?? [];
  const cognitive = data?.cognitive.distribution.filter((r) => r.count > 0).map((r) => ({ label: r.level, pct: r.percentage })) ?? [];
  const weakOutcomes = data?.learning_outcomes.weak_outcomes.slice(0, 5) ?? [];

  return (
    <div className="space-y-6" data-testid="dashboard-page">
      {/* Welcome */}
      <Reveal>
        <section className="relative overflow-hidden rounded-2xl border border-sage-200 bg-white p-5 sm:p-6">
          <div className="absolute -right-10 -top-12 w-48 h-48 rounded-full bg-sage-100 blur-2xl pointer-events-none" aria-hidden="true" />
          <div className="relative flex flex-col lg:flex-row lg:items-end lg:justify-between gap-5">
            <div className="space-y-1.5 min-w-0">
              <p className="text-[11px] uppercase tracking-[0.18em] text-sage-500">{greeting}</p>
              <h2 className="font-serif text-2xl sm:text-3xl leading-tight tracking-tight text-sage-800 truncate">Welcome back, {firstName}.</h2>
              <p className="text-sm text-sage-500 max-w-xl">
                {data && hasCourses
                  ? <>You have <strong className="text-sage-800">{fmt(k?.assessments.value)}</strong> assessment{k?.assessments.value === 1 ? '' : 's'} across <strong className="text-sage-800">{fmt(k?.courses.value)}</strong> course{k?.courses.value === 1 ? '' : 's'}{(k?.open_gaps.value ?? 0) > 0 && <> and <strong className="text-sage-800">{k?.open_gaps.value}</strong> open learning gap{k?.open_gaps.value === 1 ? '' : 's'}</>}. {user?.department ? `${user.department}.` : ''}</>
                  : 'Create a course and upload an assessment to see quality, alignment and similarity insights here.'}
              </p>
            </div>
            <div className="flex flex-wrap gap-2 shrink-0">
              <QuickAction icon={Plus} label="New course" to="/courses" />
              <QuickAction icon={FileCheck2} label="Assessments" to="/assessments" />
              <QuickAction icon={Sparkles} label="Run analysis" to="/analysis" primary />
            </div>
          </div>
        </section>
      </Reveal>

      {error && (
        <div role="alert" className="flex items-start gap-3 rounded-xl border border-[#FECACA] bg-[#FEF2F2] px-4 py-3 text-sm text-[#991B1B]">
          <AlertTriangle className="w-4 h-4 mt-0.5 shrink-0" aria-hidden="true" />
          <div className="flex-1"><p>{error}</p><button type="button" onClick={() => load()} className="mt-1 text-xs font-medium underline underline-offset-2">Retry</button></div>
        </div>
      )}

      {loading ? (
        <div role="status" className="grid grid-cols-2 lg:grid-cols-4 gap-4" aria-label="Loading dashboard">
          {Array.from({ length: 8 }).map((_, i) => <div key={i} className="h-28 rounded-xl border border-sage-200 bg-white animate-pulse" />)}
          <p className="col-span-full flex items-center gap-2 text-xs text-sage-500"><Loader2 className="w-3.5 h-3.5 animate-spin" aria-hidden="true" />Loading your dashboard…</p>
        </div>
      ) : data && (
        <>
          {/* KPIs */}
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <Kpi label="Courses" value={fmt(k?.courses.value)} note={k?.courses.basis} icon={BookOpen} to="/courses" />
            <Kpi label="Assessments" value={fmt(k?.assessments.value)} note={k?.assessments.basis} icon={FileCheck2} to="/assessments" delay={60} />
            <Kpi label="Questions" value={fmt(k?.questions.value)} note={k?.questions.basis} icon={HelpCircle} to="/question-bank" delay={120} />
            <Kpi label="Avg quality" value={fmt(k?.average_quality.value, 'score')} note={k?.average_quality.basis} icon={BrainCircuit} to="/analytics" delay={180} />
            <Kpi label="CO coverage" value={fmt(k?.co_coverage.value, 'percent')} note={k?.co_coverage.basis} icon={Target} to="/analytics" delay={240} />
            <Kpi label="Student performance" value={fmt(k?.student_performance.value, 'percent')} note={k?.student_performance.basis} icon={BarChart3} to="/analytics" delay={300} />
            <Kpi label="Open gaps" value={fmt(k?.open_gaps.value)} note={k?.open_gaps.basis} icon={AlertTriangle} to="/analytics" delay={360} />
            <Kpi label="Analysis runs" value={fmt(k?.ai_analysis_runs.value)} note={k?.ai_analysis_runs.basis} icon={Sparkles} to="/history" delay={420} />
          </div>

          {!hasCourses ? (
            <Reveal>
              <section className="rounded-2xl border border-dashed border-sage-300 bg-white p-8 text-center space-y-3">
                <span className="mx-auto w-12 h-12 rounded-full bg-sage-100 flex items-center justify-center text-sage-700"><BookOpen className="w-6 h-6" strokeWidth={1.6} aria-hidden="true" /></span>
                <h3 className="font-serif text-xl text-sage-800">Start with a course</h3>
                <p className="text-sm text-sage-500 max-w-md mx-auto">Add a course and its learning outcomes, then upload or build an assessment. FacultyLens will analyze it and everything here fills in.</p>
                <button type="button" onClick={() => navigate('/courses')} className="inline-flex items-center gap-2 rounded-lg bg-sage-700 hover:bg-sage-800 text-white text-sm px-5 py-2.5 transition-colors">Create your first course <ArrowRight className="w-4 h-4" /></button>
              </section>
            </Reveal>
          ) : (
            <>
              <div className="grid grid-cols-1 xl:grid-cols-12 gap-6">
                {/* Attention */}
                <Reveal className="xl:col-span-5">
                  <Panel title="Needs attention" subtitle={attention.length ? 'Signals from your latest analyses' : 'No open signals — nice work'} testId="attention-panel" className="h-full"
                    action={<Link to="/analytics" className="text-xs text-sage-700 hover:underline inline-flex items-center gap-1">Analytics <ArrowRight className="w-3 h-3" /></Link>}>
                    {attention.length === 0 ? (
                      <p className="text-sm text-sage-500">When an analysis finds a learning gap, weak outcome coverage or similar questions, it shows up here with a link to the record.</p>
                    ) : (
                      <ul className="space-y-2">
                        {attention.map((a, i) => {
                          const to = areaLink(a);
                          const body = (
                            <div className={`rounded-lg border p-3 ${severityTone(a.severity)} transition-transform hover:-translate-y-0.5`}>
                              <div className="flex items-center justify-between gap-2"><p className="text-sm font-semibold">{a.title}</p><span className="text-[10px] uppercase tracking-wide">{humanize(a.type)}</span></div>
                              <p className="text-xs mt-0.5 opacity-90">{a.detail}</p>
                            </div>
                          );
                          return <li key={`${a.type}-${i}`}>{to ? <Link to={to} className="block">{body}</Link> : body}</li>;
                        })}
                      </ul>
                    )}
                  </Panel>
                </Reveal>

                {/* Distributions */}
                <Reveal delay={80} className="xl:col-span-7">
                  <Panel title="Question profile" subtitle={`${data.difficulty.total_questions} classified questions · ${data.difficulty.balance_status ? humanize(data.difficulty.balance_status) : 'balance not assessed'}`} testId="profile-panel" className="h-full">
                    <div className="space-y-5">
                      <div><p className="text-[11px] uppercase tracking-[0.14em] text-sage-500 mb-2">Difficulty vs target</p>{difficulty.length ? <DistributionBar rows={difficulty} /> : <p className="text-sm text-sage-500">No classified questions yet.</p>}</div>
                      <div><p className="text-[11px] uppercase tracking-[0.14em] text-sage-500 mb-2">Bloom levels · {data.cognitive.distinct_levels} distinct</p>{cognitive.length ? <DistributionBar rows={cognitive} /> : <p className="text-sm text-sage-500">No cognitive levels recorded yet.</p>}</div>
                    </div>
                  </Panel>
                </Reveal>
              </div>

              <div className="grid grid-cols-1 xl:grid-cols-12 gap-6">
                {/* Recent assessments */}
                <Reveal className="xl:col-span-8">
                  <Panel title="Recent assessments" subtitle="Latest papers with their analysis status" testId="recent-assessments" className="h-full"
                    action={<div className="flex items-center gap-2"><button type="button" onClick={() => load(true)} className="p-1.5 rounded-md text-sage-500 hover:text-sage-800 hover:bg-sage-100" aria-label="Refresh"><RefreshCw className={`w-3.5 h-3.5 ${refreshing ? 'animate-spin' : ''}`} /></button><Link to="/assessments" className="text-xs text-sage-700 hover:underline inline-flex items-center gap-1">All <ArrowRight className="w-3 h-3" /></Link></div>}>
                    {recent.length === 0 ? <p className="text-sm text-sage-500">No assessments yet. <Link to="/assessments" className="underline">Create one</Link> to get started.</p> : <AssessmentsTable rows={recent} />}
                  </Panel>
                </Reveal>

                {/* Outcomes + recommendations */}
                <div className="xl:col-span-4 space-y-6">
                  <Reveal delay={80}>
                    <Panel title="Outcome coverage" subtitle={`${data.learning_outcomes.covered_outcomes}/${data.learning_outcomes.total_outcomes} outcomes covered`} testId="outcomes-panel"
                      action={<Link to="/analytics" className="text-xs text-sage-700 hover:underline">Detail</Link>}>
                      <div className="h-2 rounded-full bg-sage-100 overflow-hidden mb-3"><div className="h-full bg-sage-600 transition-all duration-700" style={{ width: `${data.learning_outcomes.coverage_percentage ?? 0}%` }} /></div>
                      {weakOutcomes.length === 0 ? <p className="text-xs text-sage-500">{data.learning_outcomes.total_outcomes ? 'No weak outcomes in analyzed assessments.' : 'Add learning outcomes to your courses to track coverage.'}</p> : (
                        <ul className="space-y-1.5 text-xs">
                          {weakOutcomes.map((o) => (
                            <li key={o.learning_outcome_id} className="flex items-center justify-between gap-2 rounded-lg border border-[#FDE68A] bg-[#FFFBEB] px-2.5 py-1.5 text-[#92400E]">
                              <span className="truncate"><span className="font-semibold">{o.code}</span> · {o.course_code}</span><span className="shrink-0">{humanize(o.status)}</span>
                            </li>
                          ))}
                        </ul>
                      )}
                    </Panel>
                  </Reveal>
                  <Reveal delay={140}>
                    <Panel title="Recommendations" subtitle={`${data.recommendations.active} open · ${data.recommendations.accepted} accepted`} testId="recommendations-panel"
                      action={<Link to="/feedback" className="text-xs text-sage-700 hover:underline">Feedback</Link>}>
                      <ul className="grid grid-cols-3 gap-2 text-center">
                        {(['high', 'medium', 'low'] as const).map((p) => (
                          <li key={p} className={`rounded-lg border px-2 py-2 ${p === 'high' ? 'border-[#FECACA] bg-[#FEF2F2] text-[#991B1B]' : p === 'medium' ? 'border-[#FDE68A] bg-[#FFFBEB] text-[#92400E]' : 'border-sage-200 bg-sage-50 text-sage-700'}`}>
                            <p className="font-serif text-2xl leading-none tabular-nums">{data.recommendations.active_by_priority[p]}</p><p className="text-[10px] uppercase tracking-wide mt-1">{p}</p>
                          </li>
                        ))}
                      </ul>
                      <p className="mt-3 text-[11px] text-sage-500 flex items-start gap-1.5"><Lightbulb className="w-3.5 h-3.5 mt-0.5 shrink-0" aria-hidden="true" />Recommendations are proposals — nothing changes until you accept one.</p>
                    </Panel>
                  </Reveal>
                </div>
              </div>

              {/* Similarity + shortcuts */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <Reveal>
                  <Panel title="Similarity checks" subtitle={`${data.similarity.analyzed_assessments} analyzed assessment${data.similarity.analyzed_assessments === 1 ? '' : 's'}`} testId="similarity-panel" className="h-full">
                    <ul className="grid grid-cols-2 gap-2 text-xs">
                      {([['POTENTIAL_DUPLICATE', 'Potential duplicates'], ['HIGHLY_SIMILAR', 'Highly similar'], ['SOMEWHAT_SIMILAR', 'Somewhat similar'], ['NOT_SIMILAR', 'Not similar']] as const).map(([key, label]) => (
                        <li key={key} className="flex items-center justify-between rounded-lg border border-sage-200 bg-sage-50 px-2.5 py-1.5"><span className="flex items-center gap-1.5"><GitCompare className="w-3.5 h-3.5 text-sage-500" aria-hidden="true" />{label}</span><span className="font-semibold tabular-nums">{data.similarity.by_status[key]?.questions ?? 0}</span></li>
                      ))}
                    </ul>
                  </Panel>
                </Reveal>
                <Reveal delay={80}>
                  <Panel title="Shortcuts" subtitle="Jump straight into the tools" className="h-full">
                    <ul className="grid grid-cols-2 gap-2">
                      {[[ClipboardList, 'Blueprints & versions', '/assessments'], [Sparkles, 'Generate questions', '/question-generator'], [HelpCircle, 'Question bank', '/question-bank'], [BarChart3, 'Academic analytics', '/analytics']].map(([Icon, label, to]) => (
                        <li key={label as string}><Link to={to as string} className="group flex items-center gap-2 rounded-lg border border-sage-200 bg-sage-50 px-3 py-2.5 text-sm text-sage-800 hover:bg-white hover:shadow-card transition-all"><span className="w-7 h-7 rounded-md bg-white border border-sage-200 flex items-center justify-center text-sage-700 group-hover:bg-sage-700 group-hover:text-white transition-colors">{React.createElement(Icon as React.ElementType, { className: 'w-3.5 h-3.5', strokeWidth: 1.8, 'aria-hidden': true })}</span>{label as string}</Link></li>
                      ))}
                    </ul>
                  </Panel>
                </Reveal>
              </div>
            </>
          )}

          <Reveal><CollaborationSummaryCard className="border-sage-200" /></Reveal>

          <p className="text-[11px] text-sage-400 text-right">{data.meta.disclaimer}{data.meta.generated_at ? ` · Generated ${new Date(data.meta.generated_at).toLocaleString()}` : ''}</p>
        </>
      )}
    </div>
  );
};
