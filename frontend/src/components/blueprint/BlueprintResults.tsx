import React from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { Card } from '@/components/common/Card';
import { AssessmentBlueprint, BlueprintComparison, BlueprintCoverage, BlueprintMatrix, BlueprintRecommendation, BlueprintValidation, BlueprintWarning, ComparisonDimension, Distribution, QuestionValidationResponse } from '@/types/blueprint';
import { fmtMarks, humanize, statusVariant } from './BlueprintStates';

/** STEP 37: validation results, matrices, preview and blueprint-vs-question comparison. */

const Section: React.FC<{ title: string; testId: string; children: React.ReactNode; subtitle?: string; actions?: React.ReactNode }> = ({ title, testId, children, subtitle, actions }) => (
  <Card data-testid={testId} className="p-4 space-y-3">
    <div className="flex items-start justify-between gap-2"><div><h3 className="text-sm font-semibold text-[#111111] dark:text-white">{title}</h3>{subtitle && <p className="text-xs text-[#737373] mt-0.5">{subtitle}</p>}</div>{actions}</div>
    {children}
  </Card>
);

export const BlueprintValidationPanel: React.FC<{ validation: BlueprintValidation }> = ({ validation }) => (
  <Section testId="blueprint-validation" title="Validation" subtitle={`Validated ${new Date(validation.validated_at).toLocaleString()}`} actions={<Badge variant={statusVariant(validation.status)} dot size="md">{humanize(validation.status)}</Badge>}>
    {validation.errors.length > 0 && (
      <ul className="space-y-1" aria-label="Blueprint errors">
        {validation.errors.map((e, i) => <li key={i} className="flex items-start gap-2 text-sm text-red-700 dark:text-red-300"><XCircle className="w-4 h-4 mt-0.5 flex-shrink-0" aria-hidden="true" /><span><span className="text-xs uppercase text-[#A3A3A3] mr-1">{humanize(e.dimension)}</span>{e.message}</span></li>)}
      </ul>
    )}
    {validation.errors.length === 0 && <p className="flex items-center gap-2 text-sm text-green-700"><CheckCircle2 className="w-4 h-4" aria-hidden="true" />No blocking errors. {validation.status === 'VALID' ? 'The blueprint is internally consistent.' : 'Review the warnings below before finalizing.'}</p>}
    <dl className="grid grid-cols-2 md:grid-cols-4 gap-2 text-sm">
      <div className="rounded-md border border-[#F0F0F0] dark:border-[#2A2A2A] px-2 py-1"><dt className="text-xs text-[#737373]">Blueprint Completeness</dt><dd className="font-semibold tabular-nums">{validation.completeness.score}%</dd><p className="text-[10px] text-[#A3A3A3]">{validation.completeness.note}</p></div>
      <div className="rounded-md border border-[#F0F0F0] dark:border-[#2A2A2A] px-2 py-1"><dt className="text-xs text-[#737373]">Section marks</dt><dd className="font-semibold tabular-nums">{fmtMarks(validation.totals.section_marks)} / {fmtMarks(validation.totals.total_marks)}</dd></div>
      <div className="rounded-md border border-[#F0F0F0] dark:border-[#2A2A2A] px-2 py-1"><dt className="text-xs text-[#737373]">Section questions</dt><dd className="font-semibold tabular-nums">{validation.totals.section_questions} / {validation.totals.total_questions}</dd></div>
      <div className="rounded-md border border-[#F0F0F0] dark:border-[#2A2A2A] px-2 py-1"><dt className="text-xs text-[#737373]">Time (planning indicator)</dt><dd className="font-semibold tabular-nums">{validation.time_indicator.available ? `${validation.time_indicator.minutes_per_mark} min/mark · ${validation.time_indicator.band}` : '—'}</dd><p className="text-[10px] text-[#A3A3A3]">{validation.time_indicator.note}</p></div>
    </dl>
    <ul className="flex flex-wrap gap-2 text-xs" aria-label="Configured planning dimensions">{Object.entries(validation.completeness.dimensions).map(([k, ok]) => <li key={k}><Badge variant={ok ? 'Good' : 'neutral'} size="sm">{humanize(k)}{ok ? ' ✓' : ''}</Badge></li>)}</ul>
  </Section>
);

export const BlueprintWarnings: React.FC<{ warnings: BlueprintWarning[] }> = ({ warnings }) => (
  <Section testId="blueprint-warnings" title="Warnings" subtitle="Evidence-based signals; the blueprint is never modified automatically.">
    {warnings.length === 0 ? <p className="text-sm text-[#737373]">No warnings.</p> : (
      <ul className="space-y-1">{warnings.map((w, i) => <li key={i} className="flex items-start gap-2 text-sm text-amber-800 dark:text-amber-300"><AlertTriangle className="w-4 h-4 mt-0.5 flex-shrink-0" aria-hidden="true" /><span><span className="text-xs uppercase text-[#A3A3A3] mr-1">{humanize(w.dimension)}</span>{w.message}</span></li>)}</ul>
    )}
  </Section>
);

export const BlueprintRecommendations: React.FC<{ recommendations: BlueprintRecommendation[] }> = ({ recommendations }) => (
  recommendations.length === 0 ? null : (
    <Section testId="blueprint-recommendations" title="Recommendations" subtitle="Cautious planning suggestions for faculty review (STEP 14 categories).">
      <ul className="space-y-2">{recommendations.map((r, i) => <li key={i} className="text-sm"><Badge variant="neutral" size="sm">{humanize(r.category)}</Badge> <span className="font-medium">{r.title}</span><p className="text-xs text-[#737373]">{r.message}</p></li>)}</ul>
    </Section>
  )
);

const Matrix: React.FC<{ title: string; matrix: BlueprintMatrix }> = ({ title, matrix }) => (
  matrix.rows.length === 0 ? null : (
    <div className="overflow-x-auto">
      <table className="text-sm"><caption className="text-xs text-[#737373] text-left mb-1">{title}</caption>
        <thead><tr><th scope="col" className="p-1 text-left text-xs text-[#737373]"></th>{matrix.columns.map((c) => <th key={c} scope="col" className="p-1 text-xs font-medium">{humanize(c)}</th>)}<th scope="col" className="p-1 text-xs font-medium">Total</th></tr></thead>
        <tbody>
          {matrix.rows.map((r) => <tr key={r.label}><th scope="row" className="p-1 text-left text-xs font-medium">{r.label}</th>{matrix.columns.map((c) => <td key={c} className="p-1 text-center tabular-nums border border-[#F0F0F0] dark:border-[#2A2A2A]">{r.cells[c] ?? 0}</td>)}<td className="p-1 text-center tabular-nums font-semibold">{r.total}</td></tr>)}
          <tr><th scope="row" className="p-1 text-left text-xs font-medium">Total</th>{matrix.columns.map((c) => <td key={c} className="p-1 text-center tabular-nums font-semibold">{matrix.column_totals[c] ?? 0}</td>)}<td className="p-1 text-center tabular-nums font-semibold">{matrix.total}</td></tr>
        </tbody>
      </table>
    </div>
  )
);

export const BlueprintCoverageMatrix: React.FC<{ coverage: BlueprintCoverage }> = ({ coverage }) => {
  const any = Object.values(coverage.matrices).some((m) => m.rows.length > 0);
  return (
    <Section testId="blueprint-coverage-matrix" title="Blueprint matrix" subtitle="Built from the cross-dimension question plan rows.">
      {!any ? <p className="text-sm text-[#737373]">Add question plan rows (CO × difficulty × Bloom) to see the matrices.</p> : (
        <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
          <Matrix title="CO × Difficulty" matrix={coverage.matrices.co_x_difficulty} /><Matrix title="CO × Bloom" matrix={coverage.matrices.co_x_bloom} />
          <Matrix title="Topic × Difficulty" matrix={coverage.matrices.topic_x_difficulty} /><Matrix title="Topic × Bloom" matrix={coverage.matrices.topic_x_bloom} />
        </div>
      )}
    </Section>
  );
};

const DistList: React.FC<{ title: string; dist: Distribution; unit?: string }> = ({ title, dist }) => (
  !dist.configured ? null : (
    <div><p className="text-xs font-semibold uppercase tracking-wide text-[#737373]">{title}</p>
      <ul className="text-sm">{dist.rows.filter((r) => r.configured && (r.target_percentage !== null || r.target_count !== null)).map((r) => <li key={r.key} className="flex justify-between border-t border-[#F0F0F0] dark:border-[#2A2A2A] py-0.5"><span>{r.label}</span><span className="tabular-nums">{r.target_percentage !== null ? `${r.target_percentage}%` : ''}{r.derived_count !== undefined && r.derived_count !== null ? ` (≈${r.derived_count})` : r.target_count !== null && r.target_count !== undefined ? ` (${r.target_count})` : ''}{r.target_marks !== null && r.target_marks !== undefined ? ` · ${fmtMarks(r.target_marks)} marks` : ''}</span></li>)}</ul>
      {dist.allocation && !dist.allocation.exact && <p className="text-xs text-amber-800 dark:text-amber-300 mt-1">Exact allocation impossible — suggested {Object.entries(dist.allocation.allocation).map(([k, v]) => `${v} ${humanize(k)}`).join(' / ')} (requires your confirmation).</p>}
    </div>
  )
);

export const BlueprintPreview: React.FC<{ blueprint: AssessmentBlueprint; coverage: BlueprintCoverage | null }> = ({ blueprint, coverage }) => (
  <Section testId="blueprint-preview" title="Blueprint preview" subtitle="Informational exam-plan view of the current version.">
    <div className="rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-[#FAFAF8] dark:bg-[#1A1A1A] p-4 text-sm space-y-3">
      <div><p className="font-semibold text-[#111111] dark:text-white">{blueprint.course ? `${blueprint.course.code} — ${blueprint.course.name}` : ''}</p><p>{blueprint.assessment.title} · {humanize(blueprint.assessment.type)}</p>
        <p className="text-xs text-[#737373]">Duration: {blueprint.duration_minutes ? `${blueprint.duration_minutes} minutes` : '—'} · Total marks: {fmtMarks(blueprint.total_marks)} · Questions: {blueprint.total_questions}</p></div>
      {blueprint.sections.length > 0 && <ul className="space-y-0.5">{blueprint.sections.map((s) => <li key={s.section_order} className="flex justify-between"><span><span className="font-medium">{s.title}</span> · {s.question_type ? humanize(s.question_type) : 'Any type'}</span><span className="tabular-nums">{s.question_count} × {fmtMarks(s.marks_per_question)} = {fmtMarks(s.total_marks ?? s.question_count * s.marks_per_question)}</span></li>)}</ul>}
      {coverage && <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
        <DistList title="Difficulty" dist={coverage.distributions.difficulty} /><DistList title="Bloom" dist={coverage.distributions.cognitive} /><DistList title="CO coverage" dist={coverage.distributions.learning_outcomes} />
        <DistList title="PO coverage" dist={coverage.distributions.program_outcomes} /><DistList title="Topics" dist={coverage.distributions.topics} /><DistList title="Question types" dist={coverage.distributions.question_types} />
      </div>}
      {blueprint.instructions && <p className="text-xs text-[#737373] whitespace-pre-line">{blueprint.instructions}</p>}
    </div>
  </Section>
);

const CmpTable: React.FC<{ title: string; dim: ComparisonDimension }> = ({ title, dim }) => {
  const rows = dim.rows.filter((r) => r.status !== 'NOT_CONFIGURED');
  if (!dim.configured || rows.length === 0) return <div><p className="text-xs font-semibold uppercase tracking-wide text-[#737373]">{title}</p><p className="text-xs text-[#A3A3A3]">{dim.message ?? 'Not configured'}</p></div>;
  return (
    <div><p className="text-xs font-semibold uppercase tracking-wide text-[#737373]">{title} <span className="font-normal normal-case">({dim.basis}-based)</span></p>
      <table className="w-full text-sm"><caption className="sr-only">{title} target versus actual</caption>
        <thead><tr className="text-left text-xs text-[#737373]"><th className="py-0.5">Item</th><th className="py-0.5 text-right">Target</th><th className="py-0.5 text-right">Actual</th><th className="py-0.5 text-right">Diff</th><th className="py-0.5">Status</th></tr></thead>
        <tbody>{rows.map((r) => <tr key={r.key} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A]"><td className="py-0.5">{r.label}</td><td className="py-0.5 text-right tabular-nums">{r.target_percentage}%</td><td className="py-0.5 text-right tabular-nums">{r.actual_percentage === null ? '—' : `${r.actual_percentage}%`}</td><td className="py-0.5 text-right tabular-nums">{r.difference === null ? '—' : `${r.difference > 0 ? '+' : ''}${r.difference}%`}</td><td className="py-0.5"><Badge variant={statusVariant(r.status)} size="sm">{humanize(r.status)}</Badge></td></tr>)}</tbody>
      </table>
    </div>
  );
};

export const BlueprintComparisonPanel: React.FC<{ comparison: BlueprintComparison; assessmentId: number }> = ({ comparison, assessmentId }) => (
  <Section testId="blueprint-comparison" title="Blueprint vs actual question set" subtitle={`Tolerance ±${comparison.tolerance_percent}% · compared ${new Date(comparison.compared_at).toLocaleString()}`}
    actions={<Badge variant={comparison.compliance_percent === null ? 'neutral' : comparison.compliance_percent >= 90 ? 'Good' : comparison.compliance_percent >= 70 ? 'Attention' : 'Critical'} size="md" dot>{comparison.compliance_percent === null ? 'No questions yet' : `Compliance ${comparison.compliance_percent}%`}</Badge>}>
    {!comparison.actual.has_questions && <p className="text-sm text-[#737373]">The assessment has no questions yet. <Link to={`/assessments/${assessmentId}`} className="underline underline-offset-2">Add or approve questions</Link>, then compare again.</p>}
    <ul className="flex flex-wrap gap-3 text-sm" aria-label="Structure comparison">{comparison.structure.map((s) => <li key={s.dimension}><span className="text-[#737373]">{s.label}:</span> <span className="tabular-nums">{fmtMarks(s.actual)} / {fmtMarks(s.target)}</span> <Badge variant={statusVariant(s.status)} size="sm">{humanize(s.status)}</Badge></li>)}</ul>
    <ul className="flex flex-wrap gap-2 text-xs" aria-label="Dimension summary">{Object.entries(comparison.summary).map(([k, v]) => <li key={k}><Badge variant={statusVariant(v)} size="sm">{humanize(k)}: {humanize(v)}</Badge></li>)}</ul>
    <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
      <CmpTable title="Difficulty" dim={comparison.dimensions.difficulty} /><CmpTable title="Bloom" dim={comparison.dimensions.cognitive} />
      <CmpTable title="CO coverage" dim={comparison.dimensions.learning_outcomes} /><CmpTable title="PO coverage" dim={comparison.dimensions.program_outcomes} />
      <CmpTable title="Topics" dim={comparison.dimensions.topics} /><CmpTable title="Question types" dim={comparison.dimensions.question_types} />
    </div>
    <p className="flex items-start gap-1 text-xs text-[#737373]"><Info className="w-3.5 h-3.5 mt-0.5" aria-hidden="true" />{comparison.note}</p>
    {comparison.recommendations_sync && <p className="text-xs text-[#737373]">Recommendations: {comparison.recommendations_sync.created} created, {comparison.recommendations_sync.skipped} already present.{comparison.recommendations_sync.note ? ` ${comparison.recommendations_sync.note}` : ''}</p>}
  </Section>
);

export const QuestionValidationPanel: React.FC<{ result: QuestionValidationResponse }> = ({ result }) => (
  <Section testId="question-validation" title="Question set validation" subtitle={`${result.matched} of ${result.evaluated} candidate question(s) match a plan row`}>
    <p className="text-xs text-[#737373]">{result.note}</p>
    {result.results.length > 0 && (
      <ul className="space-y-1 text-sm">{result.results.map((r) => (
        <li key={`${r.source}-${r.id}`} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A] pt-1">
          <div className="flex flex-wrap items-center gap-2"><Badge variant={statusVariant(r.status === 'MATCH' ? 'MATCH' : r.status === 'NO_PLAN' ? null : 'MISMATCH')} size="sm">{humanize(r.status)}</Badge><span className="font-medium">{r.source === 'previous_question' ? 'Bank' : 'Q'} #{r.id}</span><span className="text-xs text-[#737373] truncate max-w-md">{r.text}</span></div>
          {r.failed_constraints.length > 0 && <p className="text-xs text-red-700 dark:text-red-300">Failed: {r.failed_constraints.map((c) => `${humanize(c)} (target ${String(r.checks[c]?.target ?? '—')}, actual ${String(r.checks[c]?.actual ?? '—')})`).join('; ')}</p>}
        </li>
      ))}</ul>
    )}
  </Section>
);
