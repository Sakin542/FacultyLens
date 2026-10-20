import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Building2, AlertTriangle, CheckCircle2 } from 'lucide-react';
import { planningService } from '@/services/planningService';
import type { DepartmentOverview } from '@/types/planning';

const pct = (v: number | null) => (v === null ? '—' : `${v}%`);

/** /department — read-only department head view: course quality across the department and faculty load. */
export const Department: React.FC = () => {
  const [data, setData] = useState<DepartmentOverview | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => { planningService.department().then(setData).catch((e) => setError(e instanceof Error ? e.message : 'Could not load the department view.')); }, []);

  if (error) return <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] p-4 text-sm text-[#991B1B]">{error}</p>;
  if (!data) return <p className="py-10 text-center text-sm text-sage-500">Loading department…</p>;

  return (
    <div className="space-y-5 page-enter" data-testid="department-page">
      <div className="flex items-start gap-3">
        <span className="w-10 h-10 rounded-xl bg-white border border-sage-200 flex items-center justify-center text-sage-800"><Building2 className="w-5 h-5" aria-hidden="true" strokeWidth={1.8} /></span>
        <div>
          <h2 className="text-xl font-semibold tracking-tight text-sage-800">{data.department}</h2>
          <p className="text-sm text-sage-500">Read-only overview of every course in your department. Open a course to see its assessments, analysis and OBE evidence.</p>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
        {[
          ['Faculty', String(data.summary.faculty)],
          ['Courses', String(data.summary.courses)],
          ['Higher-order share', pct(data.summary.mean_higher_order_percent)],
          ['CO coverage', pct(data.summary.mean_co_coverage_percent)],
          ['Need attention', String(data.summary.courses_needing_attention)],
        ].map(([label, value]) => (
          <div key={label} className="rounded-xl border border-sage-200 bg-white p-4 shadow-subtle">
            <p className="text-[11px] uppercase tracking-[0.14em] text-sage-500">{label}</p>
            <p className="mt-2 text-2xl font-semibold tabular-nums text-sage-800">{value}</p>
          </div>
        ))}
      </div>

      <section className="overflow-x-auto rounded-xl border border-sage-200 bg-white shadow-subtle" aria-label="Courses" data-testid="department-courses">
        <table className="w-full min-w-[900px] text-sm">
          <thead><tr className="border-b border-sage-200 text-left text-[11px] uppercase tracking-[0.12em] text-sage-500">
            <th className="px-4 py-2 font-medium">Course</th><th className="px-2 py-2 font-medium">Instructor</th><th className="px-2 py-2 font-medium">Questions</th>
            <th className="px-2 py-2 font-medium">Higher-order</th><th className="px-2 py-2 font-medium">CO coverage</th><th className="px-2 py-2 font-medium">COs attained</th>
            <th className="px-2 py-2 font-medium">Class avg</th><th className="px-2 py-2 font-medium">Status</th>
          </tr></thead>
          <tbody className="divide-y divide-sage-100">
            {data.courses.map((c) => (
              <tr key={c.course.id} className="align-top">
                <td className="px-4 py-2.5"><Link to={`/courses/${c.course.id}`} className="font-medium text-sage-800 hover:underline">{c.course.course_code}</Link><p className="text-xs text-sage-500">{c.course.course_name} · {c.course.term}</p></td>
                <td className="px-2 py-2.5 text-sage-700">{c.instructor?.name ?? '—'}</td>
                <td className="px-2 py-2.5 tabular-nums text-sage-700">{c.questions}</td>
                <td className="px-2 py-2.5 tabular-nums text-sage-700">{c.questions ? `${c.higher_order_percent}%` : '—'}</td>
                <td className="px-2 py-2.5 tabular-nums text-sage-700">{pct(c.co_coverage_percent)}</td>
                <td className="px-2 py-2.5 tabular-nums text-sage-700">{pct(c.co_attained_percent)}</td>
                <td className="px-2 py-2.5 tabular-nums text-sage-700">{pct(c.class_average_percent)}</td>
                <td className="px-2 py-2.5">
                  {c.flags.length === 0
                    ? <span className="inline-flex items-center gap-1 text-xs text-[#166534]"><CheckCircle2 className="w-3.5 h-3.5" aria-hidden="true" />On track</span>
                    : <ul className="space-y-0.5">{c.flags.map((f) => <li key={f} className="flex items-start gap-1 text-xs text-[#92400E]"><AlertTriangle className="mt-0.5 w-3 h-3 shrink-0" aria-hidden="true" />{f}</li>)}</ul>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      <section className="overflow-x-auto rounded-xl border border-sage-200 bg-white shadow-subtle" aria-label="Faculty workload">
        <header className="border-b border-sage-200 px-4 py-3"><h3 className="text-sm font-semibold text-sage-800">Faculty workload</h3></header>
        <table className="w-full min-w-[640px] text-sm">
          <thead><tr className="border-b border-sage-200 text-left text-[11px] uppercase tracking-[0.12em] text-sage-500"><th className="px-4 py-2 font-medium">Name</th><th className="px-2 py-2 font-medium">Active courses</th><th className="px-2 py-2 font-medium">Contact h/week</th><th className="px-2 py-2 font-medium">Answers to grade</th><th className="px-2 py-2 font-medium">Est. hours this week</th></tr></thead>
          <tbody className="divide-y divide-sage-100">
            {data.faculty.map((f) => (
              <tr key={f.id}>
                <td className="px-4 py-2.5"><p className="font-medium text-sage-800">{f.name}</p><p className="text-xs text-sage-500">{f.designation ?? ''}{f.role === 'DEPARTMENT_HEAD' ? ' · Department head' : ''}</p></td>
                <td className="px-2 py-2.5 tabular-nums text-sage-700">{f.active_courses}</td>
                <td className="px-2 py-2.5 tabular-nums text-sage-700">{f.contact_hours_per_week}</td>
                <td className="px-2 py-2.5 tabular-nums text-sage-700">{f.ungraded_answers}</td>
                <td className="px-2 py-2.5 tabular-nums font-medium text-sage-800">{f.estimated_hours_this_week}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </div>
  );
};
