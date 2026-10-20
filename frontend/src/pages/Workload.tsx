import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { BriefcaseBusiness, BookOpen, Clock, ClipboardCheck, CalendarClock, Info } from 'lucide-react';
import { planningService } from '@/services/planningService';
import type { Workload as WorkloadData } from '@/types/planning';

/** /workload — teaching load, grading backlog and upcoming deadlines in one place. */
export const Workload: React.FC = () => {
  const [data, setData] = useState<WorkloadData | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => { planningService.workload().then(setData).catch((e) => setError(e instanceof Error ? e.message : 'Could not load workload.')); }, []);

  if (error) return <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] p-4 text-sm text-[#991B1B]">{error}</p>;
  if (!data) return <p className="py-10 text-center text-sm text-sage-500">Loading workload…</p>;

  const e = data.estimate_hours_this_week;
  const parts = [{ label: 'Teaching', value: e.teaching, cls: 'bg-sage-700' }, { label: 'Grading', value: e.grading, cls: 'bg-sage-500' }, { label: 'Exam preparation', value: e.exam_preparation, cls: 'bg-sage-300' }];

  return (
    <div className="space-y-5 page-enter" data-testid="workload-page">
      <div className="flex items-start gap-3">
        <span className="w-10 h-10 rounded-xl bg-white border border-sage-200 flex items-center justify-center text-sage-800"><BriefcaseBusiness className="w-5 h-5" aria-hidden="true" strokeWidth={1.8} /></span>
        <div>
          <h2 className="text-xl font-semibold tracking-tight text-sage-800">My workload</h2>
          <p className="text-sm text-sage-500">Courses you teach, hours this week, grading still to do and what is coming up.</p>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {[
          ['Active courses', data.totals.active_courses, BookOpen, `${data.totals.draft_assessments} draft assessment(s)`],
          ['Contact hours / week', data.totals.contact_hours_per_week, Clock, 'From course settings (credits if not set)'],
          ['Answers to grade', data.totals.ungraded_answers, ClipboardCheck, `${data.totals.submissions_awaiting_grading} submission(s) waiting`],
          ['Next 14 days', data.totals.upcoming_14d, CalendarClock, 'Exams, deadlines and meetings'],
        ].map(([label, value, Icon, hint]) => {
          const I = Icon as React.ElementType;
          return (
            <div key={label as string} className="rounded-xl border border-sage-200 bg-white p-4 shadow-subtle">
              <div className="flex items-center justify-between"><p className="text-[11px] uppercase tracking-[0.14em] text-sage-500">{label as string}</p><I className="w-4 h-4 text-sage-400" aria-hidden="true" /></div>
              <p className="mt-2 text-2xl font-semibold tabular-nums text-sage-800">{value as number}</p>
              <p className="mt-0.5 text-xs text-sage-500">{hint as string}</p>
            </div>
          );
        })}
      </div>

      <section className="rounded-xl border border-sage-200 bg-white p-4 shadow-subtle" aria-label="Estimated hours this week" data-testid="workload-estimate">
        <div className="flex items-baseline justify-between gap-2">
          <h3 className="text-sm font-semibold text-sage-800">Estimated hours this week</h3>
          <span className="text-2xl font-semibold tabular-nums text-sage-800">{e.total} h</span>
        </div>
        <div className="mt-3 flex h-3 overflow-hidden rounded-full bg-sage-100" aria-hidden="true">
          {parts.map((p) => e.total > 0 && p.value > 0 && <div key={p.label} className={`${p.cls} h-full border-r-2 border-white last:border-r-0`} style={{ width: `${(p.value / e.total) * 100}%` }} />)}
        </div>
        <ul className="mt-2 flex flex-wrap gap-4 text-xs text-sage-700">
          {parts.map((p) => <li key={p.label} className="flex items-center gap-1.5"><span className={`h-2.5 w-2.5 rounded-sm ${p.cls}`} aria-hidden="true" />{p.label}: <span className="font-semibold tabular-nums">{p.value} h</span></li>)}
        </ul>
        <p className="mt-2 flex items-start gap-1.5 text-[11px] text-sage-500"><Info className="mt-0.5 w-3 h-3 shrink-0" aria-hidden="true" />Estimate: {e.formula}.</p>
      </section>

      <div className="grid gap-5 lg:grid-cols-[1.4fr,1fr]">
        <section className="overflow-x-auto rounded-xl border border-sage-200 bg-white shadow-subtle" aria-label="Courses">
          <table className="w-full min-w-[560px] text-sm">
            <thead><tr className="border-b border-sage-200 text-left text-[11px] uppercase tracking-[0.12em] text-sage-500"><th className="px-4 py-2 font-medium">Course</th><th className="px-2 py-2 font-medium">Hours/week</th><th className="px-2 py-2 font-medium">Assessments</th><th className="px-2 py-2 font-medium">To grade</th></tr></thead>
            <tbody className="divide-y divide-sage-100">
              {data.courses.map((c) => (
                <tr key={c.id}>
                  <td className="px-4 py-2.5"><Link to={`/courses/${c.id}`} className="font-medium text-sage-800 hover:underline">{c.course_code}</Link><p className="text-xs text-sage-500">{c.course_name} · {c.term}</p></td>
                  <td className="px-2 py-2.5 tabular-nums text-sage-700">{c.contact_hours_per_week}{c.contact_hours_is_estimate && <span className="text-[10px] text-sage-400"> (credits)</span>}</td>
                  <td className="px-2 py-2.5 tabular-nums text-sage-700">{c.assessments}{c.draft_assessments > 0 && <span className="text-xs text-sage-500"> · {c.draft_assessments} draft</span>}</td>
                  <td className="px-2 py-2.5 tabular-nums text-sage-700">{c.ungraded_answers}</td>
                </tr>
              ))}
              {data.courses.length === 0 && <tr><td colSpan={4} className="px-4 py-6 text-center text-sage-500">No active courses.</td></tr>}
            </tbody>
          </table>
        </section>

        <section className="rounded-xl border border-sage-200 bg-white p-4 shadow-subtle" aria-label="Upcoming">
          <div className="flex items-center justify-between"><h3 className="text-sm font-semibold text-sage-800">Next 14 days</h3><Link to="/calendar" className="text-xs font-medium text-sage-700 hover:underline">Calendar →</Link></div>
          {data.upcoming.length === 0 ? <p className="mt-2 text-sm text-sage-500">Nothing scheduled.</p> : (
            <ul className="mt-2 divide-y divide-sage-100">
              {data.upcoming.map((u) => (
                <li key={u.id} className="flex items-center justify-between gap-2 py-2 text-sm">
                  <span className="min-w-0"><span className="block truncate text-sage-800">{u.title}</span><span className="text-xs capitalize text-sage-500">{u.type.replace(/_/g, ' ')}{u.course ? ` · ${u.course.course_code}` : ''}</span></span>
                  <span className="shrink-0 text-xs text-sage-500">{new Date(u.starts_at).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' })}</span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </div>
  );
};
