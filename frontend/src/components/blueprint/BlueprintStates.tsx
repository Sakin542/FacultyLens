import React from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, ClipboardList, Loader2, Lock } from 'lucide-react';
import { Badge, BadgeVariant } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { Card } from '@/components/common/Card';
import { ApiError } from '@/services/api';
import { cn } from '@/utils/cn';
import { AssessmentBlueprint, BlueprintStatus, BlueprintValidation, BlueprintValidationStatus, BlueprintVersion, ComparisonStatus } from '@/types/blueprint';

/** STEP 37: header, summary, actions and state components for the Assessment Blueprint page. */

export const humanize = (s: string | null | undefined): string => (s ?? '').replace(/_/g, ' ').toLowerCase().replace(/^\w/, (c) => c.toUpperCase());
export const fmtMarks = (v: number | null | undefined): string => (v === null || v === undefined ? '—' : Number.isInteger(v) ? String(v) : v.toFixed(2).replace(/\.?0+$/, ''));

export const statusVariant = (s: BlueprintStatus | BlueprintValidationStatus | ComparisonStatus | string | null | undefined): BadgeVariant => {
  switch (s) {
    case 'VALID': case 'FINALIZED': case 'MATCH': return 'Good';
    case 'VALID_WITH_WARNINGS': case 'VALIDATED': case 'CLOSE': return 'Attention';
    case 'INVALID': case 'MISMATCH': return 'Critical';
    case 'DRAFT': return 'Pending';
    default: return 'neutral';
  }
};

export function getBlueprintErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.status === 401) return 'Your session has expired. Please sign in again.';
    if (err.status === 403) return err.message || 'You do not have access to this assessment blueprint.';
    if (err.status === 404) return 'The assessment or blueprint no longer exists.';
    if (err.status === 409) return err.message || 'This blueprint cannot be changed in its current state.';
    if (err.status === 422) return err.message || 'Please correct the highlighted blueprint values.';
    if (err.status === 429) return 'Too many requests. Please wait a moment.';
    if (err.status >= 500) return 'The blueprint service is temporarily unavailable.';
    return err.message || 'Something went wrong.';
  }
  return err instanceof Error ? err.message : 'Something went wrong.';
}

export const BlueprintHeader: React.FC<{ assessment: { id: number; title: string; type: string } | null; course: { id: number; code: string; name: string } | null; blueprint: AssessmentBlueprint | null }> = ({ assessment, course, blueprint }) => (
  <header data-testid="blueprint-header" className="space-y-1">
    <nav aria-label="Breadcrumb" className="text-xs text-sage-500 flex gap-1">
      <Link to="/assessments" className="hover:underline">Assessments</Link><span>/</span>
      {assessment && <Link to={`/assessments/${assessment.id}`} className="hover:underline">{assessment.title}</Link>}<span>/</span><span>Blueprint</span>
    </nav>
    <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
      <div className="flex items-center gap-2"><ClipboardList className="w-5 h-5" aria-hidden="true" /><h1 className="text-2xl font-bold text-sage-800 dark:text-white">Assessment Blueprint</h1>
        {blueprint && <Badge variant={statusVariant(blueprint.status)} dot size="md">{humanize(blueprint.status)} · v{blueprint.version}</Badge>}</div>
      {course && assessment && <p className="text-sm text-sage-500">{course.code} — {course.name} · {assessment.title} ({humanize(assessment.type)})</p>}
    </div>
    <p className="text-xs text-sage-500 max-w-3xl">Plan and validate the structure before selecting or generating questions. The blueprint never publishes or finalizes the assessment, and it never changes questions automatically.</p>
  </header>
);

export const BlueprintSummary: React.FC<{ blueprint: AssessmentBlueprint; validation: BlueprintValidation | null }> = ({ blueprint, validation }) => (
  <dl data-testid="blueprint-summary" className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-3">
    {([
      ['Total marks', fmtMarks(blueprint.total_marks)], ['Questions', String(blueprint.total_questions)], ['Duration', blueprint.duration_minutes ? `${blueprint.duration_minutes} min` : '—'],
      ['Sections', String(blueprint.sections.length)],
      ['Validation', validation ? humanize(validation.status) : 'Not validated'],
      ['Blueprint Completeness', validation ? `${validation.completeness.score}%` : (blueprint.blueprint_completeness !== null ? `${blueprint.blueprint_completeness}%` : '—')],
    ] as const).map(([l, v]) => (
      <div key={l} className="rounded-lg border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-4 py-3">
        <dt className="text-xs uppercase tracking-wide text-sage-500">{l}</dt>
        <dd className="text-xl font-semibold text-sage-800 dark:text-white mt-1 tabular-nums">{v}</dd>
        {l === 'Blueprint Completeness' && <p className="text-[10px] text-sage-400">Planning indicator, not assessment quality</p>}
      </div>
    ))}
  </dl>
);

export const BlueprintActions: React.FC<{
  blueprint: AssessmentBlueprint; canEdit: boolean; canGenerate: boolean; busy?: boolean;
  onValidate: () => void; onFinalize: () => void; onDelete: () => void; onGenerate: () => void; onCompare: () => void; onNewVersion: () => void;
}> = ({ blueprint, canEdit, canGenerate, busy, onValidate, onFinalize, onDelete, onGenerate, onCompare, onNewVersion }) => {
  const finalized = blueprint.status === 'FINALIZED';
  const canFinalize = blueprint.validation_status !== null && blueprint.validation_status !== 'INVALID' && !finalized;
  return (
    <div data-testid="blueprint-actions" className="flex flex-wrap items-center gap-2" role="group" aria-label="Blueprint actions">
      {canEdit && !finalized && <Button size="sm" variant="outline" onClick={onValidate} disabled={busy}>Validate</Button>}
      {canEdit && !finalized && <Button size="sm" onClick={onFinalize} disabled={busy || !canFinalize} title={canFinalize ? 'Finalize this blueprint version' : 'Validate the blueprint (without errors) before finalizing'}><Lock className="w-3.5 h-3.5 mr-1" aria-hidden="true" />Finalize</Button>}
      {canEdit && finalized && <Button size="sm" variant="outline" onClick={onNewVersion} disabled={busy}>Create new version</Button>}
      <Button size="sm" variant="outline" onClick={onCompare} disabled={busy}>Compare with questions</Button>
      {canGenerate && <Button size="sm" variant="outline" onClick={onGenerate} disabled={busy || !finalized} title={finalized ? 'Create STEP 33 generation requests from this blueprint' : 'Finalize the blueprint first'}>Generate Questions from Blueprint</Button>}
      {blueprint.course && <Link to={`/courses/${blueprint.course.id}/question-bank`} className="text-xs underline underline-offset-2 ml-1">Select from Question Bank</Link>}
      {canEdit && !finalized && <Button size="sm" variant="ghost" onClick={onDelete} disabled={busy} className="text-red-700">Delete draft</Button>}
    </div>
  );
};

export const BlueprintVersions: React.FC<{ versions: BlueprintVersion[] }> = ({ versions }) => (
  versions.length <= 1 ? null : (
    <Card data-testid="blueprint-versions" className="p-4">
      <h3 className="text-sm font-semibold text-sage-800 dark:text-white mb-2">Versions</h3>
      <ul className="text-sm divide-y divide-sage-100 dark:divide-[#2A2A2A]">
        {versions.map((v) => <li key={v.id} className="py-1 flex flex-wrap items-center gap-2"><span className="font-medium">Version {v.version}</span><Badge variant={statusVariant(v.status)} size="sm">{humanize(v.status)}</Badge><span className="text-xs text-sage-500">{v.total_questions} questions / {fmtMarks(v.total_marks)} marks{v.finalized_at ? ` · finalized ${new Date(v.finalized_at).toLocaleDateString()}` : ''}{v.is_current ? ' · current' : ''}</span></li>)}
      </ul>
    </Card>
  )
);

export const BlueprintLoading: React.FC<{ label?: string }> = ({ label = 'Loading blueprint…' }) => (
  <div data-testid="blueprint-loading" role="status" className="flex items-center gap-2 rounded-lg border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-4 py-6 text-sm text-sage-600 dark:text-sage-400"><Loader2 className="w-4 h-4 animate-spin" aria-hidden="true" /><span>{label}</span></div>
);

export const BlueprintError: React.FC<{ message: string; onRetry?: () => void; className?: string }> = ({ message, onRetry, className }) => (
  <div data-testid="blueprint-error" role="alert" className={cn('flex items-start gap-2 rounded-lg border border-red-200 dark:border-red-900/50 bg-red-50 dark:bg-red-950/30 px-3 py-2 text-sm text-red-700 dark:text-red-300', className)}>
    <AlertTriangle className="w-4 h-4 mt-0.5 flex-shrink-0" aria-hidden="true" />
    <div className="flex-1"><p>{message}</p>{onRetry && <button type="button" onClick={onRetry} className="mt-1 text-xs font-medium underline underline-offset-2">Retry</button>}</div>
  </div>
);

export const BlueprintEmptyState: React.FC<{ canEdit: boolean; onCreate: () => void }> = ({ canEdit, onCreate }) => (
  <div data-testid="blueprint-empty-state" className="flex flex-col items-center justify-center text-center py-12 px-6 rounded-xl border border-dashed border-sage-200 dark:border-[#2A2A2A]">
    <div className="w-12 h-12 rounded-full bg-sage-100 dark:bg-[#1F1F1F] border border-sage-200 dark:border-[#2A2A2A] flex items-center justify-center text-sage-500 mb-4"><ClipboardList className="w-6 h-6" aria-hidden="true" /></div>
    <h4 className="text-base font-semibold text-sage-800 dark:text-white mb-1">No blueprint yet</h4>
    <p className="text-sm text-sage-500 max-w-md">Define marks, sections, difficulty, Bloom, CO/PO and topic targets, then validate the plan before generating or selecting questions.</p>
    {canEdit && <Button className="mt-4" size="sm" onClick={onCreate}>Create blueprint</Button>}
  </div>
);
