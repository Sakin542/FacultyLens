import React, { useEffect, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Wand2, Download, Check, CalendarPlus, AlertTriangle, BookOpenCheck, ListTree } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { courseDesignService } from '@/services/courseDesignService';
import { courseService } from '@/services/courseService';
import type { CoursePlan, LessonPlanContent, SyllabusContent } from '@/types/courseDesign';
import { cn } from '@/utils/cn';

const BLOOM = ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'];
const field = 'w-full rounded-lg border border-sage-200 bg-white px-3 py-2 text-sm text-sage-800 focus:outline-none focus:ring-2 focus:ring-sage-600';
const Notice: React.FC<{ tone: 'ok' | 'err' | 'warn'; children: React.ReactNode }> = ({ tone, children }) => (
  <p role={tone === 'err' ? 'alert' : 'status'} className={cn('rounded-lg border px-3 py-2 text-sm', tone === 'ok' ? 'border-[#BBF7D0] bg-[#F0FDF4] text-[#166534]' : tone === 'warn' ? 'border-[#FDE68A] bg-[#FFFBEB] text-[#92400E]' : 'border-[#FECACA] bg-[#FEF2F2] text-[#991B1B]')}>{children}</p>
);

/** Syllabus assistant: draft COs + CO→PO links + outline, review and edit, then apply to the course. */
const SyllabusTab: React.FC<{ courseId: string; description: string }> = ({ courseId, description }) => {
  const [desc, setDesc] = useState(description);
  const [weeks, setWeeks] = useState(14);
  const [count, setCount] = useState(5);
  const [plan, setPlan] = useState<CoursePlan<SyllabusContent> | null>(null);
  const [outcomes, setOutcomes] = useState<{ include: boolean; code: string; statement: string; cognitive_level: string }[]>([]);
  const [links, setLinks] = useState<{ include: boolean; co: string; po: string; level: number; justification: string }[]>([]);
  const [updateExisting, setUpdateExisting] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'err' | 'warn'; text: string } | null>(null);

  useEffect(() => { setDesc(description); }, [description]);
  useEffect(() => {
    courseDesignService.plans(courseId, 'syllabus').then((p) => { if (p[0]) load(p[0] as CoursePlan<SyllabusContent>); }).catch(() => undefined);
  }, [courseId]); // eslint-disable-line react-hooks/exhaustive-deps

  const load = (p: CoursePlan<SyllabusContent>) => {
    setPlan(p);
    setOutcomes(p.content.outcomes.map((o) => ({ include: true, code: o.code, statement: o.statement, cognitive_level: o.cognitive_level })));
    setLinks(p.content.co_po.map((l) => ({ include: true, co: l.co, po: l.po, level: l.level, justification: l.justification })));
  };

  const draft = async () => {
    setBusy('draft');
    setNotice(null);
    try {
      const res = await courseDesignService.draftSyllabus(courseId, { description: desc, weeks, outcome_count: count });
      load(res.data);
      setNotice({ tone: 'ok', text: res.message ?? 'Draft ready.' });
    } catch (e) {
      setNotice({ tone: 'err', text: e instanceof Error ? e.message : 'The draft failed.' });
    } finally {
      setBusy(null);
    }
  };

  const apply = async () => {
    if (!plan) return;
    setBusy('apply');
    try {
      const res = await courseDesignService.applySyllabus(courseId, plan.id, {
        outcomes: outcomes.filter((o) => o.include).map(({ code, statement, cognitive_level }) => ({ code, statement, cognitive_level })),
        links: links.filter((l) => l.include && outcomes.find((o) => o.code === l.co)?.include).map(({ co, po, level }) => ({ co, po, level })),
        update_existing: updateExisting,
      });
      setNotice({ tone: 'ok', text: res.message ?? 'Applied.' });
    } catch (e) {
      setNotice({ tone: 'err', text: e instanceof Error ? e.message : 'Could not apply the draft.' });
    } finally {
      setBusy(null);
    }
  };

  const existing = new Set((plan?.content.existing_outcomes ?? []).map((o) => o.code.toUpperCase()));

  return (
    <div className="space-y-4">
      <section className="space-y-3 rounded-xl border border-sage-200 bg-white p-4 shadow-subtle">
        <label className="block text-xs font-medium text-sage-700">Course description (catalogue text or topic list)
          <textarea rows={3} className={cn(field, 'mt-1')} value={desc} onChange={(e) => setDesc(e.target.value)} placeholder="e.g. Relational model, SQL, normalization, indexing, transactions, NoSQL…" data-testid="syllabus-description" />
        </label>
        <div className="flex flex-wrap items-end gap-3">
          <label className="w-32 text-xs font-medium text-sage-700">Weeks<input type="number" min={6} max={20} className={cn(field, 'mt-1')} value={weeks} onChange={(e) => setWeeks(Number(e.target.value))} /></label>
          <label className="w-40 text-xs font-medium text-sage-700">Course outcomes<select className={cn(field, 'mt-1')} value={count} onChange={(e) => setCount(Number(e.target.value))}>{[3, 4, 5, 6].map((n) => <option key={n} value={n}>{n}</option>)}</select></label>
          <Button onClick={draft} isLoading={busy === 'draft'} leftIcon={<Wand2 className="w-4 h-4" />} data-testid="draft-syllabus">Draft syllabus</Button>
          {plan && <Button variant="outline" leftIcon={<Download className="w-4 h-4" />} onClick={() => courseDesignService.exportPlan(plan.id).catch((e) => setNotice({ tone: 'err', text: e.message }))}>Export PDF</Button>}
        </div>
      </section>

      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {plan?.content.warnings.map((w) => <Notice key={w} tone="warn">{w}</Notice>)}

      {plan && (
        <>
          <section className="rounded-xl border border-sage-200 bg-white shadow-subtle" aria-label="Draft course outcomes" data-testid="draft-outcomes">
            <header className="flex flex-wrap items-center justify-between gap-2 border-b border-sage-200 px-4 py-3">
              <div>
                <h3 className="text-sm font-semibold text-sage-800">Course outcomes</h3>
                <p className="text-xs text-sage-500">Edit the wording and Bloom level; untick any you do not want. Written by the {plan.content.engine === 'model' ? 'generation model' : 'template engine'}.</p>
              </div>
              {existing.size > 0 && (
                <label className="flex items-center gap-2 text-xs text-sage-700"><input type="checkbox" checked={updateExisting} onChange={(e) => setUpdateExisting(e.target.checked)} className="rounded border-sage-300" />Replace wording of existing outcomes with the same code</label>
              )}
            </header>
            <ul className="divide-y divide-sage-100">
              {outcomes.map((o, i) => (
                <li key={o.code} className="flex flex-wrap items-start gap-3 px-4 py-3">
                  <input type="checkbox" aria-label={`Include ${o.code}`} checked={o.include} onChange={(e) => setOutcomes(outcomes.map((x, k) => (k === i ? { ...x, include: e.target.checked } : x)))} className="mt-2 rounded border-sage-300" />
                  <span className="mt-1.5 w-10 text-sm font-semibold text-sage-800">{o.code}</span>
                  <textarea rows={2} aria-label={`${o.code} statement`} className={cn(field, 'min-w-[240px] flex-1')} value={o.statement} onChange={(e) => setOutcomes(outcomes.map((x, k) => (k === i ? { ...x, statement: e.target.value } : x)))} />
                  <select aria-label={`${o.code} Bloom level`} className={cn(field, 'w-36')} value={o.cognitive_level} onChange={(e) => setOutcomes(outcomes.map((x, k) => (k === i ? { ...x, cognitive_level: e.target.value } : x)))}>{BLOOM.map((b) => <option key={b}>{b}</option>)}</select>
                  {existing.has(o.code.toUpperCase()) && <span className="mt-2 text-[11px] text-[#92400E]">{updateExisting ? 'Will replace the existing outcome' : 'Already exists: kept as is'}</span>}
                </li>
              ))}
            </ul>
          </section>

          <section className="rounded-xl border border-sage-200 bg-white shadow-subtle" aria-label="Suggested CO-PO links" data-testid="draft-links">
            <header className="border-b border-sage-200 px-4 py-3">
              <h3 className="text-sm font-semibold text-sage-800">Suggested CO → PO mapping</h3>
              <p className="text-xs text-sage-500">{plan.content.co_po_method === 'embedding' ? 'Ranked by semantic similarity between each outcome and program outcome.' : plan.content.co_po_method === 'lexical' ? 'Ranked by keyword overlap (the embedding model was not available).' : 'No program outcomes found.'} {plan.content.program_outcome_source === 'all_programs' && 'This course has no program, so outcomes from all programs were considered.'}</p>
            </header>
            {links.length === 0 ? <p className="p-4 text-sm text-sage-500">No links suggested.</p> : (
              <table className="w-full text-sm">
                <thead><tr className="border-b border-sage-200 text-left text-[11px] uppercase tracking-[0.12em] text-sage-500"><th className="px-4 py-2 font-medium">Use</th><th className="px-2 py-2 font-medium">CO</th><th className="px-2 py-2 font-medium">PO</th><th className="px-2 py-2 font-medium">Strength</th><th className="px-2 py-2 font-medium">Evidence</th></tr></thead>
                <tbody className="divide-y divide-sage-100">
                  {links.map((l, i) => (
                    <tr key={`${l.co}-${l.po}`}>
                      <td className="px-4 py-2"><input type="checkbox" aria-label={`Use ${l.co} to ${l.po}`} checked={l.include} onChange={(e) => setLinks(links.map((x, k) => (k === i ? { ...x, include: e.target.checked } : x)))} className="rounded border-sage-300" /></td>
                      <td className="px-2 py-2 font-semibold text-sage-800">{l.co}</td>
                      <td className="px-2 py-2 font-semibold text-sage-800">{l.po}</td>
                      <td className="px-2 py-2"><select aria-label={`Strength ${l.co} ${l.po}`} className="rounded-md border border-sage-200 px-2 py-1 text-xs" value={l.level} onChange={(e) => setLinks(links.map((x, k) => (k === i ? { ...x, level: Number(e.target.value) } : x)))}><option value={1}>1 · low</option><option value={2}>2 · medium</option><option value={3}>3 · high</option></select></td>
                      <td className="px-2 py-2 text-xs text-sage-500">{l.justification}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </section>

          <div className="grid gap-4 lg:grid-cols-2">
            <section className="rounded-xl border border-sage-200 bg-white p-4 shadow-subtle" aria-label="Weekly outline">
              <h3 className="text-sm font-semibold text-sage-800">Weekly outline</h3>
              <ol className="mt-2 space-y-1 text-sm">
                {plan.content.weekly_outline.map((w) => <li key={w.week} className="flex gap-2"><span className="w-14 shrink-0 text-xs text-sage-500">Week {w.week}</span><span className={cn('flex-1', w.kind !== 'teaching' && 'font-medium')}>{w.topic}</span><span className="text-xs text-sage-500">{w.outcomes.join(', ')}</span></li>)}
              </ol>
            </section>
            <section className="rounded-xl border border-sage-200 bg-white p-4 shadow-subtle" aria-label="Assessment plan">
              <h3 className="text-sm font-semibold text-sage-800">Assessment plan</h3>
              <ul className="mt-2 space-y-2 text-sm">
                {plan.content.assessment_plan.map((a) => (
                  <li key={a.component}>
                    <div className="flex justify-between"><span className="text-sage-800">{a.component}</span><span className="font-semibold tabular-nums">{a.weight}%</span></div>
                    <div className="mt-1 h-1.5 rounded-full bg-sage-100"><div className="h-1.5 rounded-full bg-sage-600" style={{ width: `${a.weight}%` }} /></div>
                    <p className="text-[11px] text-sage-500">{a.outcomes.join(', ')}</p>
                  </li>
                ))}
              </ul>
            </section>
          </div>

          <div className="flex items-center gap-3">
            <Button onClick={apply} isLoading={busy === 'apply'} leftIcon={<Check className="w-4 h-4" />} disabled={!outcomes.some((o) => o.include)} data-testid="apply-syllabus">Apply selected outcomes and links to the course</Button>
            {plan.applied_at && <span className="text-xs text-sage-500">Last applied {new Date(plan.applied_at).toLocaleString()}</span>}
          </div>
        </>
      )}
    </div>
  );
};

/** Lesson planner: week-by-week sessions, activities and assessments from the course outcomes. */
const LessonTab: React.FC<{ courseId: string }> = ({ courseId }) => {
  const [weeks, setWeeks] = useState(14);
  const [sessions, setSessions] = useState(2);
  const [minutes, setMinutes] = useState(75);
  const [plan, setPlan] = useState<CoursePlan<LessonPlanContent> | null>(null);
  const [start, setStart] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'err' | 'warn'; text: string } | null>(null);

  useEffect(() => {
    courseDesignService.plans(courseId, 'lesson_plan').then((p) => { if (p[0]) setPlan(p[0] as CoursePlan<LessonPlanContent>); }).catch(() => undefined);
  }, [courseId]);

  const generate = async () => {
    setBusy('gen');
    setNotice(null);
    try {
      const res = await courseDesignService.lessonPlan(courseId, { weeks, sessions_per_week: sessions, session_minutes: minutes });
      setPlan(res.data);
      setNotice({ tone: 'ok', text: res.message ?? 'Lesson plan ready.' });
    } catch (e) {
      setNotice({ tone: 'err', text: e instanceof Error ? e.message : 'The plan could not be generated.' });
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="space-y-4">
      <section className="flex flex-wrap items-end gap-3 rounded-xl border border-sage-200 bg-white p-4 shadow-subtle">
        <label className="w-28 text-xs font-medium text-sage-700">Weeks<input type="number" min={6} max={20} className={cn(field, 'mt-1')} value={weeks} onChange={(e) => setWeeks(Number(e.target.value))} /></label>
        <label className="w-36 text-xs font-medium text-sage-700">Sessions / week<input type="number" min={1} max={5} className={cn(field, 'mt-1')} value={sessions} onChange={(e) => setSessions(Number(e.target.value))} /></label>
        <label className="w-36 text-xs font-medium text-sage-700">Minutes / session<input type="number" min={30} max={240} step={5} className={cn(field, 'mt-1')} value={minutes} onChange={(e) => setMinutes(Number(e.target.value))} /></label>
        <Button onClick={generate} isLoading={busy === 'gen'} leftIcon={<Wand2 className="w-4 h-4" />} data-testid="generate-lesson-plan">Generate lesson plan</Button>
        {plan && <Button variant="outline" leftIcon={<Download className="w-4 h-4" />} onClick={() => courseDesignService.exportPlan(plan.id).catch((e) => setNotice({ tone: 'err', text: e.message }))}>Export PDF</Button>}
      </section>
      <p className="text-xs text-sage-500">Uses this course's outcomes, and the weekly topics from your latest syllabus draft when there is one.</p>

      {notice && <Notice tone={notice.tone}>{notice.text}</Notice>}
      {plan?.content.warnings.map((w) => <Notice key={w} tone="warn">{w}</Notice>)}

      {plan && (
        <>
          <section className="flex flex-wrap items-end gap-3 rounded-xl border border-sage-200 bg-white p-4 shadow-subtle">
            <label className="text-xs font-medium text-sage-700">Semester starts (Monday of week 1)<input type="date" className={cn(field, 'mt-1')} value={start} onChange={(e) => setStart(e.target.value)} /></label>
            <Button variant="outline" leftIcon={<CalendarPlus className="w-4 h-4" />} disabled={!start} isLoading={busy === 'cal'}
              onClick={async () => { setBusy('cal'); try { const r = await courseDesignService.schedule(plan.id, start); setNotice({ tone: 'ok', text: r.message }); } catch (e) { setNotice({ tone: 'err', text: e instanceof Error ? e.message : 'Failed.' }); } finally { setBusy(null); } }}>Add assessments to my calendar</Button>
            <span className="text-xs text-sage-500">Coverage: {Object.entries(plan.content.outcome_coverage).map(([co, n]) => `${co} ${n} wk`).join(' · ')}</span>
          </section>
          <ol className="grid gap-3 md:grid-cols-2" data-testid="lesson-weeks">
            {plan.content.weeks.map((w) => (
              <li key={w.week} className={cn('rounded-xl border bg-white p-4', w.kind === 'teaching' ? 'border-sage-200' : 'border-sage-400')}>
                <div className="flex items-start justify-between gap-2">
                  <p className="text-sm font-semibold text-sage-800"><span className="text-sage-500">Week {w.week} · </span>{w.topic}</p>
                  <span className="shrink-0 text-[11px] text-sage-500">{w.outcomes.join(', ')}{w.bloom_focus ? ` · ${w.bloom_focus}` : ''}</span>
                </div>
                <ul className="mt-2 space-y-1.5">
                  {w.sessions.map((s, i) => <li key={i} className="text-xs text-sage-700"><span className="font-medium text-sage-800">{s.title}</span> <span className="text-sage-500">({s.minutes} min)</span>: {s.description}</li>)}
                </ul>
                {w.formative_checks.length > 0 && <p className="mt-2 text-[11px] text-sage-500">Checks: {w.formative_checks.join('; ')}</p>}
              </li>
            ))}
          </ol>
        </>
      )}
    </div>
  );
};

/** /courses/:courseId/design — AI-assisted syllabus and lesson planning. */
export const CourseDesign: React.FC = () => {
  const { courseId = '' } = useParams();
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'lessons' ? 'lessons' : 'syllabus';
  const [course, setCourse] = useState<{ course_code?: string; course_name?: string; description?: string } | null>(null);

  useEffect(() => {
    courseService.getById(courseId)
      .then((r) => setCourse({ course_code: r.data.course_code, course_name: r.data.course_name, description: r.data.description ?? '' }))
      .catch(() => setCourse(null));
  }, [courseId]);

  return (
    <div className="space-y-5 page-enter" data-testid="course-design-page">
      <Link to={`/courses/${courseId}`} className="inline-flex items-center gap-1.5 text-xs font-semibold text-sage-500 hover:text-sage-800"><ArrowLeft className="w-3.5 h-3.5" />Back to course</Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-xs font-mono text-sage-500">{course?.course_code}</p>
          <h2 className="text-xl font-semibold tracking-tight text-sage-800">{course?.course_name ?? 'Course'}: course design assistant</h2>
          <p className="flex items-center gap-1.5 text-sm text-sage-500"><AlertTriangle className="w-3.5 h-3.5" aria-hidden="true" />Drafts for you to review. Nothing changes in the course until you apply it.</p>
        </div>
        <div role="tablist" className="inline-flex rounded-lg border border-sage-200 bg-white p-0.5">
          {([['syllabus', 'Syllabus & CO→PO', BookOpenCheck], ['lessons', 'Lesson plan', ListTree]] as const).map(([key, label, Icon]) => (
            <button key={key} type="button" role="tab" aria-selected={tab === key} onClick={() => setParams(key === 'syllabus' ? {} : { tab: key }, { replace: true })}
              className={cn('inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium', tab === key ? 'bg-sage-700 text-white' : 'text-sage-800 hover:bg-sage-100')}>
              <Icon className="w-3.5 h-3.5" aria-hidden="true" />{label}
            </button>
          ))}
        </div>
      </div>
      {tab === 'syllabus' ? <SyllabusTab courseId={courseId} description={course?.description ?? ''} /> : <LessonTab courseId={courseId} />}
    </div>
  );
};
