import React, { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Download, FolderArchive, CheckCircle2, XCircle, MinusCircle } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { planningService } from '@/services/planningService';
import type { ObeSummary } from '@/types/planning';

const Attained: React.FC<{ value: boolean | null }> = ({ value }) =>
  value === null ? <span className="inline-flex items-center gap-1 text-xs text-sage-500"><MinusCircle className="w-3.5 h-3.5" aria-hidden="true" />No data</span>
    : value ? <span className="inline-flex items-center gap-1 text-xs font-medium text-[#166534]"><CheckCircle2 className="w-3.5 h-3.5" aria-hidden="true" />Attained</span>
      : <span className="inline-flex items-center gap-1 text-xs font-medium text-[#991B1B]"><XCircle className="w-3.5 h-3.5" aria-hidden="true" />Not attained</span>;

/** /courses/:courseId/obe — CO/PO attainment, the mapping matrix, and one-click accreditation pack / course file. */
export const CourseObe: React.FC = () => {
  const { courseId = '' } = useParams();
  const [data, setData] = useState<ObeSummary | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<'pack' | 'archive' | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    planningService.obe(courseId).then(setData).catch((e) => setError(e instanceof Error ? e.message : 'Could not load OBE data.'));
  }, [courseId]);

  const download = async (kind: 'pack' | 'archive') => {
    setBusy(kind);
    setNotice(null);
    try {
      await (kind === 'pack' ? planningService.downloadObePack(courseId) : planningService.downloadArchive(courseId));
      setNotice(kind === 'pack' ? 'OBE evidence pack downloaded.' : 'Course file downloaded.');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Download failed.');
    } finally {
      setBusy(null);
    }
  };

  if (error && !data) return <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] p-4 text-sm text-[#991B1B]">{error}</p>;
  if (!data) return <p className="py-10 text-center text-sm text-sage-500">Calculating attainment…</p>;

  return (
    <div className="space-y-5 page-enter" data-testid="course-obe-page">
      <Link to={`/courses/${courseId}`} className="inline-flex items-center gap-1.5 text-xs font-semibold text-sage-500 hover:text-sage-800"><ArrowLeft className="w-3.5 h-3.5" />Back to course</Link>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-xs font-mono text-sage-500">{data.course.course_code} · {data.course.term}</p>
          <h2 className="text-xl font-semibold tracking-tight text-sage-800">Outcome-based evidence (OBE)</h2>
          <p className="text-sm text-sage-500">Course outcome attainment, CO→PO mapping and program outcome attainment, ready for accreditation review.</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button leftIcon={<Download className="w-4 h-4" />} isLoading={busy === 'pack'} onClick={() => download('pack')} data-testid="download-obe-pack">OBE pack (ZIP)</Button>
          <Button variant="outline" leftIcon={<FolderArchive className="w-4 h-4" />} isLoading={busy === 'archive'} onClick={() => download('archive')} data-testid="download-course-file">Full course file (ZIP)</Button>
        </div>
      </div>
      {notice && <p role="status" className="rounded-lg border border-[#BBF7D0] bg-[#F0FDF4] px-3 py-2 text-sm text-[#166534]">{notice}</p>}
      {error && data && <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] px-3 py-2 text-sm text-[#991B1B]">{error}</p>}

      <section className="overflow-x-auto rounded-xl border border-sage-200 bg-white shadow-subtle" aria-label="Course outcome attainment" data-testid="co-attainment">
        <header className="border-b border-sage-200 px-4 py-3">
          <h3 className="text-sm font-semibold text-sage-800">Course outcome attainment</h3>
          <p className="text-xs text-sage-500">Class average on the questions mapped to each CO, from the latest performance analysis. Target {data.co_target}%.</p>
        </header>
        <table className="w-full min-w-[640px] text-sm">
          <thead><tr className="border-b border-sage-200 text-left text-[11px] uppercase tracking-[0.12em] text-sage-500"><th className="px-4 py-2 font-medium">CO</th><th className="px-2 py-2 font-medium">Description</th><th className="px-2 py-2 font-medium">Class average</th><th className="px-2 py-2 font-medium">Level (0–3)</th><th className="px-2 py-2 font-medium">Status</th></tr></thead>
          <tbody className="divide-y divide-sage-100">
            {data.co_attainment.map((c) => (
              <tr key={c.id}>
                <td className="px-4 py-2.5 font-semibold text-sage-800">{c.code}</td>
                <td className="px-2 py-2.5 text-sage-700">{c.description}</td>
                <td className="px-2 py-2.5">
                  {c.average_percent === null ? <span className="text-xs text-sage-500">Not assessed yet</span> : (
                    <div className="flex items-center gap-2">
                      <div className="relative h-2 w-28 rounded-full bg-sage-100" aria-hidden="true">
                        <div className="h-2 rounded-full bg-sage-600" style={{ width: `${Math.min(100, c.average_percent)}%` }} />
                        <div className="absolute -top-0.5 h-3 w-0.5 bg-sage-800" style={{ left: `${data.co_target}%` }} />
                      </div>
                      <span className="tabular-nums text-sage-800">{c.average_percent}%</span>
                    </div>
                  )}
                </td>
                <td className="px-2 py-2.5 tabular-nums text-sage-800">{c.level ?? '—'}</td>
                <td className="px-2 py-2.5"><Attained value={c.assessed ? c.attained : null} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      <section className="overflow-x-auto rounded-xl border border-sage-200 bg-white shadow-subtle" aria-label="CO-PO matrix and PO attainment">
        <header className="border-b border-sage-200 px-4 py-3">
          <h3 className="text-sm font-semibold text-sage-800">CO → PO mapping and program outcome attainment</h3>
          <p className="text-xs text-sage-500">PO attainment = Σ(CO level × mapping strength) ÷ Σ(mapping strength). Target {data.po_target} on the 0–3 scale.</p>
        </header>
        {data.program_outcomes.length === 0 ? (
          <p className="p-4 text-sm text-sage-500">No CO→PO mappings yet. Map outcomes on the <Link className="font-medium text-sage-800 underline" to={`/courses/${courseId}/co-po-mapping`}>CO/PO mapping page</Link> to calculate program outcome attainment.</p>
        ) : (
          <div className="p-4">
            <table className="text-sm" data-testid="co-po-matrix">
              <thead><tr><th className="px-3 py-1.5 text-left text-xs text-sage-500">CO \ PO</th>{data.program_outcomes.map((p) => <th key={p.id} className="px-3 py-1.5 text-center text-xs font-semibold text-sage-800" title={p.title}>{p.code}</th>)}</tr></thead>
              <tbody>
                {data.matrix.map((row) => (
                  <tr key={row.code}>
                    <td className="px-3 py-1.5 font-semibold text-sage-800">{row.code}</td>
                    {data.program_outcomes.map((p) => {
                      const level = row.levels[p.code] ?? 0;
                      return <td key={p.id} className="px-3 py-1.5 text-center"><span className={`inline-flex h-7 w-7 items-center justify-center rounded-md text-xs font-semibold ${level === 3 ? 'bg-sage-700 text-white' : level === 2 ? 'bg-sage-400 text-white' : level === 1 ? 'bg-sage-200 text-sage-800' : 'text-sage-300'}`}>{level || '·'}</span></td>;
                    })}
                  </tr>
                ))}
                <tr className="border-t border-sage-200">
                  <td className="px-3 py-2 text-xs font-medium text-sage-600">Attainment</td>
                  {data.po_attainment.map((p) => <td key={p.id} className="px-3 py-2 text-center text-xs"><span className="block font-semibold tabular-nums text-sage-800">{p.attainment ?? '—'}</span><Attained value={p.attainment === null ? null : p.attained} /></td>)}
                </tr>
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="rounded-xl border border-sage-200 bg-white p-4 shadow-subtle" aria-label="Evidence">
        <h3 className="text-sm font-semibold text-sage-800">Evidence included in the pack</h3>
        <ul className="mt-2 space-y-1 text-sm text-sage-700">
          {data.assessments.map((a) => <li key={a.id}>{a.title}: {a.questions} question(s), {a.mapped} mapped to a CO{a.mapped < a.questions && <span className="text-[#92400E]"> · {a.questions - a.mapped} unmapped</span>}</li>)}
          {data.assessments.length === 0 && <li className="text-sage-500">No assessments yet.</li>}
        </ul>
        <p className="mt-2 text-xs text-sage-500">The pack contains the OBE report PDF (with anonymised high/median/low sample answers if you have student data access) and CSV files for CO attainment, PO attainment and the mapping matrix. The full course file adds materials, every question paper with answers, rubrics, item analysis, results and final exam sets.</p>
      </section>
    </div>
  );
};
