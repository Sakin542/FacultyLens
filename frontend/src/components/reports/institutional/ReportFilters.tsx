import React from 'react';
import { ReportFilter, ReportFilterKey, ReportFilterOptions, ReportScope } from '@/types/report';

interface ReportFiltersProps {
  scope: ReportScope | '';
  applicable: ReportFilterKey[];
  options: ReportFilterOptions['options'] | null;
  value: ReportFilter;
  onChange: (next: ReportFilter) => void;
  disabled?: boolean;
  errors?: Partial<Record<ReportFilterKey, string>>;
}

const selectClass = 'w-full rounded-lg border border-sage-200 bg-white px-3 py-2 text-sm text-sage-800 focus:outline-none focus:ring-2 focus:ring-sage-300 disabled:bg-sage-100';

const Field: React.FC<{ id: string; label: string; error?: string; required?: boolean; children: React.ReactNode }> = ({ id, label, error, required, children }) => (
  <div>
    <label htmlFor={id} className="block text-xs font-medium text-sage-700 mb-1.5">{label}{required && <span className="text-red-600 ml-0.5">*</span>}</label>
    {children}
    {error && <p role="alert" className="mt-1 text-[11px] text-red-700">{error}</p>}
  </div>
);

/** Renders only the filters applicable to the selected report + scope; option lists come from the server (already access-scoped). */
export const ReportFilters: React.FC<ReportFiltersProps> = ({ scope, applicable, options, value, onChange, disabled, errors = {} }) => {
  if (!scope || applicable.length === 0) return null;
  const set = (key: ReportFilterKey, v: string) => {
    const next: ReportFilter = { ...value };
    if (v === '') delete next[key]; else next[key] = v;
    // Cascading resets: course → assessment → version
    if (key === 'course_id') { delete next.assessment_id; delete next.assessment_version_id; }
    if (key === 'assessment_id') { delete next.assessment_version_id; }
    onChange(next);
  };
  const has = (k: ReportFilterKey) => applicable.includes(k);
  const courseId = value.course_id ? Number(value.course_id) : null;
  const assessmentId = value.assessment_id ? Number(value.assessment_id) : null;
  const assessments = (options?.assessments ?? []).filter((a) => !courseId || a.course_id === courseId);
  const versions = (options?.assessment_versions ?? []).filter((v) => assessmentId && v.assessment_id === assessmentId);
  const required = (k: ReportFilterKey) =>
    (k === 'course_id' && (scope === 'COURSE' || scope === 'ASSESSMENT' || scope === 'ASSESSMENT_VERSION')) ||
    (k === 'assessment_id' && (scope === 'ASSESSMENT' || scope === 'ASSESSMENT_VERSION')) ||
    (k === 'assessment_version_id' && scope === 'ASSESSMENT_VERSION') ||
    (k === 'department' && scope === 'DEPARTMENT');

  return (
    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4" data-testid="report-filters">
      {has('course_id') && (
        <Field id="f-course" label="Course" required={required('course_id')} error={errors.course_id}>
          <select id="f-course" className={selectClass} disabled={disabled} value={value.course_id ?? ''} onChange={(e) => set('course_id', e.target.value)}>
            <option value="">{required('course_id') ? 'Select a course…' : 'All courses'}</option>
            {(options?.courses ?? []).map((c) => <option key={c.id} value={c.id}>{c.code} — {c.name}{c.semester ? ` (${c.semester} ${c.academic_year ?? ''})` : ''}</option>)}
          </select>
        </Field>
      )}
      {has('assessment_id') && (
        <Field id="f-assessment" label="Assessment" required={required('assessment_id')} error={errors.assessment_id}>
          <select id="f-assessment" className={selectClass} disabled={disabled || (!courseId && required('course_id'))} value={value.assessment_id ?? ''} onChange={(e) => set('assessment_id', e.target.value)}>
            <option value="">{courseId ? 'Select an assessment…' : 'Select a course first'}</option>
            {assessments.map((a) => <option key={a.id} value={a.id}>{a.title} ({a.type}{a.date ? `, ${a.date}` : ''})</option>)}
          </select>
        </Field>
      )}
      {has('assessment_version_id') && (
        <Field id="f-version" label="Version" required={required('assessment_version_id')} error={errors.assessment_version_id}>
          <select id="f-version" className={selectClass} disabled={disabled || !assessmentId} value={value.assessment_version_id ?? ''} onChange={(e) => set('assessment_version_id', e.target.value)}>
            <option value="">{assessmentId ? (versions.length ? 'Select a version…' : 'No versions recorded') : 'Select an assessment first'}</option>
            {versions.map((v) => <option key={v.id} value={v.id}>{v.version_label} · {v.status} · {v.question_count} questions · {v.total_marks} marks</option>)}
          </select>
        </Field>
      )}
      {has('department') && (
        <Field id="f-department" label="Department" required={required('department')} error={errors.department}>
          <select id="f-department" className={selectClass} disabled={disabled} value={value.department ?? ''} onChange={(e) => set('department', e.target.value)}>
            <option value="">Select a department…</option>
            {(options?.departments ?? []).map((d) => <option key={d} value={d}>{d}</option>)}
          </select>
        </Field>
      )}
      {has('program_id') && (
        <Field id="f-program" label="Program" error={errors.program_id}>
          <select id="f-program" className={selectClass} disabled={disabled} value={value.program_id ?? ''} onChange={(e) => set('program_id', e.target.value)}>
            <option value="">All programs</option>
            {(options?.programs ?? []).map((p) => <option key={p.id} value={p.id}>{p.code} — {p.name}</option>)}
          </select>
        </Field>
      )}
      {has('semester') && (
        <Field id="f-semester" label="Semester" error={errors.semester}>
          <select id="f-semester" className={selectClass} disabled={disabled} value={value.semester ?? ''} onChange={(e) => set('semester', e.target.value)}>
            <option value="">All semesters</option>
            {(options?.semesters ?? []).map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        </Field>
      )}
      {has('academic_year') && (
        <Field id="f-year" label="Academic Year" error={errors.academic_year}>
          <select id="f-year" className={selectClass} disabled={disabled} value={value.academic_year ?? ''} onChange={(e) => set('academic_year', e.target.value)}>
            <option value="">All years</option>
            {(options?.academic_years ?? []).map((y) => <option key={y} value={y}>{y}</option>)}
          </select>
        </Field>
      )}
      {has('assessment_type') && (
        <Field id="f-type" label="Assessment Type" error={errors.assessment_type}>
          <select id="f-type" className={selectClass} disabled={disabled} value={value.assessment_type ?? ''} onChange={(e) => set('assessment_type', e.target.value)}>
            <option value="">All types</option>
            {(options?.assessment_types ?? []).map((t) => <option key={t} value={t}>{t}</option>)}
          </select>
        </Field>
      )}
      {has('status') && (
        <Field id="f-status" label="Assessment Status" error={errors.status}>
          <select id="f-status" className={selectClass} disabled={disabled} value={value.status ?? ''} onChange={(e) => set('status', e.target.value)}>
            <option value="">All statuses</option>
            {(options?.statuses ?? []).map((s) => <option key={s} value={s}>{s}</option>)}
          </select>
        </Field>
      )}
      {has('start_date') && (
        <Field id="f-start" label="From Date" error={errors.start_date}>
          <input id="f-start" type="date" className={selectClass} disabled={disabled} value={value.start_date ?? ''} onChange={(e) => set('start_date', e.target.value)} />
        </Field>
      )}
      {has('end_date') && (
        <Field id="f-end" label="To Date" error={errors.end_date}>
          <input id="f-end" type="date" className={selectClass} disabled={disabled} value={value.end_date ?? ''} onChange={(e) => set('end_date', e.target.value)} />
        </Field>
      )}
    </div>
  );
};
