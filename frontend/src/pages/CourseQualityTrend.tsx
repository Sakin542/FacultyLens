import React, { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, TrendingUp, TrendingDown, Minus, Info } from 'lucide-react';
import { planningService } from '@/services/planningService';
import type { QualityTrend } from '@/types/planning';
import { TrendSparkline } from '@/components/planning/TrendSparkline';

const BLOOM = ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'];

/** /courses/:courseId/quality — the same course compared across semesters. */
export const CourseQualityTrend: React.FC = () => {
  const { courseId = '' } = useParams();
  const [data, setData] = useState<QualityTrend | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    planningService.qualityTrend(courseId).then(setData).catch((e) => setError(e instanceof Error ? e.message : 'Could not load trends.'));
  }, [courseId]);

  if (error) return <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] p-4 text-sm text-[#991B1B]">{error}</p>;
  if (!data) return <p className="py-10 text-center text-sm text-sage-500">Comparing semesters…</p>;

  const pts = (key: keyof QualityTrend['offerings'][number]) => data.offerings.map((o) => ({ label: o.course.term, value: (o[key] as number | null) ?? null }));

  return (
    <div className="space-y-5 page-enter" data-testid="quality-trend-page">
      <Link to={`/courses/${courseId}`} className="inline-flex items-center gap-1.5 text-xs font-semibold text-sage-500 hover:text-sage-800"><ArrowLeft className="w-3.5 h-3.5" />Back to course</Link>
      <div>
        <p className="text-xs font-mono text-sage-500">{data.course_code}</p>
        <h2 className="text-xl font-semibold tracking-tight text-sage-800">{data.course_name}: quality across semesters</h2>
        <p className="text-sm text-sage-500">Assessment design, outcome coverage and student results for every offering of this course you can access.</p>
      </div>

      {data.message && <p className="flex items-start gap-2 rounded-lg border border-sage-200 bg-white p-3 text-sm text-sage-600"><Info className="mt-0.5 w-4 h-4 shrink-0" aria-hidden="true" />{data.message}</p>}

      {data.insights.length > 0 && (
        <section className="rounded-xl border border-sage-200 bg-white p-4 shadow-subtle" aria-label="What changed" data-testid="trend-insights">
          <h3 className="text-sm font-semibold text-sage-800">What changed since the last offering</h3>
          <ul className="mt-2 space-y-1.5">
            {data.insights.map((i) => (
              <li key={i.metric} className="flex items-start gap-2 text-sm text-sage-700">
                {i.direction === 'improved' ? <TrendingUp className="mt-0.5 w-4 h-4 shrink-0 text-[#166534]" aria-label="Improved" /> : i.direction === 'declined' ? <TrendingDown className="mt-0.5 w-4 h-4 shrink-0 text-[#991B1B]" aria-label="Declined" /> : <Minus className="mt-0.5 w-4 h-4 shrink-0 text-sage-400" aria-label="Steady" />}
                <span>{i.text}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <TrendSparkline title="Higher-order questions (% of marks)" unit="%" max={100} points={pts('higher_order_percent')} testId="trend-higher-order" />
        <TrendSparkline title="Course outcomes assessed" unit="%" max={100} points={pts('co_coverage_percent')} />
        <TrendSparkline title="Course outcomes attained" unit="%" max={100} points={pts('co_attained_percent')} />
        <TrendSparkline title="Class average" unit="%" max={100} points={pts('class_average_percent')} />
        <TrendSparkline title="Average question discrimination (D)" points={pts('mean_discrimination')} max={1} />
        <TrendSparkline title="AI assessment quality score" points={pts('ai_quality_score')} max={100} />
      </div>

      <section className="overflow-x-auto rounded-xl border border-sage-200 bg-white shadow-subtle" aria-label="Offerings">
        <table className="w-full min-w-[720px] text-sm">
          <thead>
            <tr className="border-b border-sage-200 text-left text-[11px] uppercase tracking-[0.12em] text-sage-500">
              <th className="px-4 py-2 font-medium">Semester</th>
              <th className="px-2 py-2 font-medium">Assessments</th>
              <th className="px-2 py-2 font-medium">Questions</th>
              <th className="px-2 py-2 font-medium">Bloom mix (% of marks)</th>
              <th className="px-2 py-2 font-medium">Flagged questions</th>
              <th className="px-2 py-2 font-medium">Open</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-sage-100">
            {data.offerings.map((o) => (
              <tr key={o.course.id}>
                <td className="px-4 py-2.5 font-medium text-sage-800">{o.course.term}</td>
                <td className="px-2 py-2.5 tabular-nums text-sage-700">{o.assessments}</td>
                <td className="px-2 py-2.5 tabular-nums text-sage-700">{o.questions}</td>
                <td className="px-2 py-2.5 text-xs text-sage-600">{BLOOM.filter((b) => o.bloom_percent[b] > 0).map((b) => `${b} ${o.bloom_percent[b]}%`).join(' · ') || '—'}</td>
                <td className="px-2 py-2.5 tabular-nums text-sage-700">{o.assessments_with_item_analysis ? o.flagged_questions : '—'}</td>
                <td className="px-2 py-2.5"><Link to={`/courses/${o.course.id}`} className="text-xs font-medium text-sage-700 hover:underline">Course →</Link></td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </div>
  );
};
