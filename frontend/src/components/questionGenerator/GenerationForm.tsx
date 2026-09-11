import React, { useMemo, useState } from 'react';
import { Sparkles } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import {
  COGNITIVE_LEVELS, CreateGenerationInput, DIFFICULTY_LEVELS, GenCognitive, GenDifficulty, GenQuestionType, QUESTION_TYPE_LABELS,
} from '@/types/questionGeneration';
import { cn } from '@/utils/cn';

/** STEP 33: constraint form. Nothing here grants access — the API re-authorizes every id. */

export interface FormCourse { id: number | string; course_code?: string; course_name?: string }
export interface FormAssessment { id: number | string; title: string; total_marks?: number }
export interface FormOutcome { id: number | string; code: string; description: string }
export interface FormProgramOutcome { id: number | string; code: string; title: string }
export interface FormDocument { id: number | string; original_file_name: string; indexing_status?: string }

export const selectClass = 'w-full rounded-lg border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-3 py-2 text-sm text-sage-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-sage-600 dark:focus:ring-white disabled:opacity-60';
const labelClass = 'block text-xs font-medium text-sage-600 dark:text-sage-400 mb-1';

export interface ConstraintValues {
  topic: string;
  learning_outcome_id: string;
  program_outcome_id: string;
  question_type: GenQuestionType;
  difficulty_level: GenDifficulty | '';
  cognitive_level: GenCognitive | '';
  marks: string;
  number_of_questions: string;
  include_expected_answer: boolean;
  include_explanation: boolean;
}

export const GenerationConstraints: React.FC<{
  values: ConstraintValues;
  onChange: (patch: Partial<ConstraintValues>) => void;
  outcomes: FormOutcome[];
  programOutcomes: FormProgramOutcome[];
  maxQuestions?: number;
}> = ({ values, onChange, outcomes, programOutcomes, maxQuestions = 20 }) => (
  <div data-testid="generation-constraints" className="grid grid-cols-1 md:grid-cols-2 gap-3">
    <label className="md:col-span-2">
      <span className={labelClass}>Topic</span>
      <Input aria-label="Topic" placeholder="e.g. Normalization" value={values.topic} onChange={(e) => onChange({ topic: e.target.value })} maxLength={255} />
    </label>
    <label>
      <span className={labelClass}>Course outcome (CO)</span>
      <select aria-label="Course outcome" className={selectClass} value={values.learning_outcome_id} onChange={(e) => onChange({ learning_outcome_id: e.target.value })}>
        <option value="">Any / not specified</option>
        {outcomes.map((o) => <option key={o.id} value={String(o.id)}>{o.code} — {o.description.slice(0, 70)}</option>)}
      </select>
    </label>
    <label>
      <span className={labelClass}>Program outcome (PO, context only)</span>
      <select aria-label="Program outcome" className={selectClass} value={values.program_outcome_id} onChange={(e) => onChange({ program_outcome_id: e.target.value })} disabled={programOutcomes.length === 0}>
        <option value="">{programOutcomes.length ? 'None' : 'No program linked to this course'}</option>
        {programOutcomes.map((p) => <option key={p.id} value={String(p.id)}>{p.code} — {p.title}</option>)}
      </select>
    </label>
    <label>
      <span className={labelClass}>Question type</span>
      <select aria-label="Question type" className={selectClass} value={values.question_type} onChange={(e) => onChange({ question_type: e.target.value as GenQuestionType })}>
        {(Object.keys(QUESTION_TYPE_LABELS) as GenQuestionType[]).map((t) => <option key={t} value={t}>{QUESTION_TYPE_LABELS[t]}</option>)}
      </select>
    </label>
    <label>
      <span className={labelClass}>Difficulty</span>
      <select aria-label="Difficulty" className={selectClass} value={values.difficulty_level} onChange={(e) => onChange({ difficulty_level: e.target.value as GenDifficulty | '' })}>
        <option value="">Any</option>
        {DIFFICULTY_LEVELS.map((d) => <option key={d} value={d}>{d[0].toUpperCase() + d.slice(1)}</option>)}
      </select>
    </label>
    <label>
      <span className={labelClass}>Cognitive level (Bloom)</span>
      <select aria-label="Cognitive level" className={selectClass} value={values.cognitive_level} onChange={(e) => onChange({ cognitive_level: e.target.value as GenCognitive | '' })}>
        <option value="">Any</option>
        {COGNITIVE_LEVELS.map((c) => <option key={c} value={c}>{c}</option>)}
      </select>
    </label>
    <div className="grid grid-cols-2 gap-3">
      <label>
        <span className={labelClass}>Marks per question</span>
        <Input aria-label="Marks" type="number" min={0.5} step={0.5} value={values.marks} onChange={(e) => onChange({ marks: e.target.value })} />
      </label>
      <label>
        <span className={labelClass}>Number of questions</span>
        <Input aria-label="Number of questions" type="number" min={1} max={maxQuestions} value={values.number_of_questions} onChange={(e) => onChange({ number_of_questions: e.target.value })} />
      </label>
    </div>
    <div className="md:col-span-2 flex flex-wrap gap-4 text-sm text-sage-800 dark:text-white">
      <label className="inline-flex items-center gap-2">
        <input type="checkbox" checked={values.include_expected_answer} onChange={(e) => onChange({ include_expected_answer: e.target.checked })} /> Include expected answers (drafts)
      </label>
      <label className="inline-flex items-center gap-2">
        <input type="checkbox" checked={values.include_explanation} onChange={(e) => onChange({ include_explanation: e.target.checked })} /> Include explanation
      </label>
    </div>
  </div>
);

export type DocScope = { scope_type: 'COURSE' | 'DOCUMENT' | 'ASSESSMENT'; document_id?: string };

export const DocumentContextSelector: React.FC<{
  value: DocScope;
  onChange: (v: DocScope) => void;
  documents: FormDocument[];
  hasAssessment: boolean;
}> = ({ value, onChange, documents, hasAssessment }) => (
  <div data-testid="document-context-selector" className="space-y-2">
    <span className={labelClass}>Document context (grounding)</span>
    <div className="flex flex-wrap gap-2">
      {([['COURSE', 'All course documents'], ['DOCUMENT', 'Specific document'], ['ASSESSMENT', 'This assessment']] as const)
        .filter(([k]) => k !== 'ASSESSMENT' || hasAssessment)
        .map(([k, label]) => (
          <button key={k} type="button" data-testid={`doc-scope-${k.toLowerCase()}`} onClick={() => onChange({ scope_type: k, document_id: k === 'DOCUMENT' ? value.document_id : undefined })}
            className={cn('rounded-lg border px-3 py-1.5 text-sm', value.scope_type === k ? 'border-sage-700 dark:border-white bg-sage-100 dark:bg-[#1F1F1F]' : 'border-sage-200 dark:border-[#2A2A2A]')}>
            {label}
          </button>
        ))}
    </div>
    {value.scope_type === 'DOCUMENT' && (
      <select aria-label="Document" className={selectClass} value={value.document_id ?? ''} onChange={(e) => onChange({ scope_type: 'DOCUMENT', document_id: e.target.value })}>
        <option value="">Select a document…</option>
        {documents.map((d) => (
          <option key={d.id} value={String(d.id)}>{d.original_file_name}{d.indexing_status && d.indexing_status !== 'INDEXED' ? ` (${d.indexing_status.toLowerCase()})` : ''}</option>
        ))}
      </select>
    )}
    <p className="text-[11px] text-sage-400">Only indexed documents you own are used. Retrieved passages ground the drafts; they are never treated as instructions.</p>
  </div>
);

export interface GenerationFormProps {
  courses: FormCourse[];
  assessments: FormAssessment[];
  outcomes: FormOutcome[];
  programOutcomes: FormProgramOutcome[];
  documents: FormDocument[];
  courseId: string;
  assessmentId: string;
  onCourseChange: (id: string) => void;
  onAssessmentChange: (id: string) => void;
  onSubmit: (input: CreateGenerationInput) => Promise<void> | void;
  submitting?: boolean;
  maxQuestions?: number;
  lockCourse?: boolean;
}

export const defaultConstraints: ConstraintValues = {
  topic: '', learning_outcome_id: '', program_outcome_id: '', question_type: 'descriptive', difficulty_level: 'medium',
  cognitive_level: '', marks: '10', number_of_questions: '3', include_expected_answer: true, include_explanation: false,
};

export const GenerationForm: React.FC<GenerationFormProps> = ({
  courses, assessments, outcomes, programOutcomes, documents, courseId, assessmentId, onCourseChange, onAssessmentChange, onSubmit, submitting, maxQuestions = 20, lockCourse,
}) => {
  const [values, setValues] = useState<ConstraintValues>(defaultConstraints);
  const [scope, setScope] = useState<DocScope>({ scope_type: 'COURSE' });
  const [error, setError] = useState<string | null>(null);

  const marks = Number(values.marks);
  const count = Number(values.number_of_questions);
  const valid = useMemo(() => !!courseId && marks > 0 && Number.isInteger(count) && count >= 1 && count <= maxQuestions
    && (scope.scope_type !== 'DOCUMENT' || !!scope.document_id), [courseId, marks, count, maxQuestions, scope]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    if (!valid) {
      setError(!courseId ? 'Select a course.' : marks <= 0 ? 'Marks must be greater than zero.' : count < 1 || count > maxQuestions ? `Number of questions must be between 1 and ${maxQuestions}.` : 'Select a document for document scope.');
      return;
    }
    await onSubmit({
      course_id: courseId,
      assessment_id: assessmentId || null,
      topic: values.topic.trim() || null,
      learning_outcome_id: values.learning_outcome_id || null,
      program_outcome_id: values.program_outcome_id || null,
      question_type: values.question_type,
      difficulty_level: values.difficulty_level || null,
      cognitive_level: values.cognitive_level || null,
      marks,
      number_of_questions: count,
      include_expected_answer: values.include_expected_answer,
      include_explanation: values.include_explanation,
      document_scope: scope.scope_type === 'DOCUMENT' ? { scope_type: 'DOCUMENT', document_id: scope.document_id } : scope.scope_type === 'ASSESSMENT' ? { scope_type: 'ASSESSMENT', assessment_id: assessmentId } : { scope_type: 'COURSE' },
    });
  };

  return (
    <form data-testid="generation-form" onSubmit={submit} noValidate className="space-y-4 rounded-xl border border-sage-200 dark:border-[#2A2A2A] bg-white dark:bg-[#161616] p-5">
      <div>
        <h3 className="text-base font-semibold text-sage-800 dark:text-white">Generate questions</h3>
        <p className="text-xs text-sage-500">Drafts are generated under your constraints, validated, and always require your review before use.</p>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        <label>
          <span className={labelClass}>Course</span>
          <select aria-label="Course" className={selectClass} value={courseId} onChange={(e) => onCourseChange(e.target.value)} disabled={lockCourse}>
            <option value="">Select a course…</option>
            {courses.map((c) => <option key={c.id} value={String(c.id)}>{[c.course_code, c.course_name].filter(Boolean).join(' — ')}</option>)}
          </select>
        </label>
        <label>
          <span className={labelClass}>Assessment (optional)</span>
          <select aria-label="Assessment" className={selectClass} value={assessmentId} onChange={(e) => onAssessmentChange(e.target.value)} disabled={!courseId}>
            <option value="">Standalone drafts (no assessment)</option>
            {assessments.map((a) => <option key={a.id} value={String(a.id)}>{a.title}{a.total_marks != null ? ` (${a.total_marks} marks)` : ''}</option>)}
          </select>
        </label>
      </div>
      <GenerationConstraints values={values} onChange={(p) => setValues((v) => ({ ...v, ...p }))} outcomes={outcomes} programOutcomes={programOutcomes} maxQuestions={maxQuestions} />
      <DocumentContextSelector value={scope} onChange={setScope} documents={documents} hasAssessment={!!assessmentId} />
      {error && <p role="alert" className="text-xs text-red-600">{error}</p>}
      <Button type="submit" isLoading={submitting} disabled={!courseId} leftIcon={<Sparkles className="w-4 h-4" />} data-testid="generate-button" className="w-full md:w-auto">
        Generate questions
      </Button>
    </form>
  );
};
