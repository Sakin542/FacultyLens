import React, { useState } from 'react';
import { AlertTriangle, Check, CheckCircle2, Edit3, Link2, PlusCircle, RefreshCw, X, XCircle } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import {
  COGNITIVE_LEVELS, DIFFICULTY_LEVELS, GenCognitive, GenDifficulty, GenQuestionType, GeneratedQuestion, QUESTION_TYPE_LABELS, QuestionValidation,
  UpdateGeneratedQuestionInput,
} from '@/types/questionGeneration';
import { cn } from '@/utils/cn';
import { selectClass } from './GenerationForm';

/** STEP 33: draft card + validation display + inline editor. Text is always rendered as text, never HTML. */

const fmt = (v: string | null | undefined) => (v ? v.replace(/_/g, ' ').toLowerCase().replace(/^\w/, (c) => c.toUpperCase()) : '—');

export const QuestionAlignmentBadge: React.FC<{ validation: QuestionValidation | null }> = ({ validation }) => {
  if (!validation || validation.co_alignment_status == null) return <Badge variant="neutral" data-testid="alignment-badge">CO alignment: n/a</Badge>;
  const variant = validation.co_alignment_status === 'STRONG' ? 'Good' : validation.co_alignment_status === 'WEAK' ? 'Attention' : 'Critical';
  return (
    <Badge variant={variant} data-testid="alignment-badge">
      CO alignment {validation.co_alignment_score?.toFixed(2)} — {fmt(validation.co_alignment_status)}
    </Badge>
  );
};

export const QuestionSimilarityWarning: React.FC<{ validation: QuestionValidation | null; onViewSimilar?: (existingId: number, source: string) => void }> = ({ validation, onViewSimilar }) => {
  if (!validation || !validation.similarity_status || !['POTENTIAL_DUPLICATE', 'HIGHLY_SIMILAR'].includes(validation.similarity_status)) return null;
  const top = validation.similar_questions[0];
  return (
    <div data-testid="similarity-warning" className="rounded-lg border border-amber-200 dark:border-amber-900/50 bg-amber-50 dark:bg-amber-950/20 px-3 py-2 text-xs text-amber-800 dark:text-amber-300">
      <p className="font-semibold flex items-center gap-1"><AlertTriangle className="w-3.5 h-3.5" /> {validation.similarity_status === 'POTENTIAL_DUPLICATE' ? 'Potential duplicate' : 'Highly similar'}</p>
      {top && (
        <p className="mt-1">
          Similarity {top.similarity_score.toFixed(2)} with {top.label || fmt(top.source)}: <span className="italic">“{top.text}”</span>
          {onViewSimilar && top.existing_id != null && (
            <button type="button" className="ml-2 underline" onClick={() => onViewSimilar(top.existing_id as number, top.source)}>View similar question</button>
          )}
        </p>
      )}
      <p className="mt-1 text-[11px]">Similarity is a retrieval metric, not proof of duplication. Keep, edit, regenerate or reject — your decision.</p>
    </div>
  );
};

const Check_ = ({ ok }: { ok: boolean | null }) => ok == null ? <span className="text-[#A3A3A3]">—</span> : ok ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <X className="w-3.5 h-3.5 text-red-600" />;

export const ConstraintValidation: React.FC<{ question: GeneratedQuestion }> = ({ question }) => {
  const v = question.validation;
  if (!v) return null;
  const rows: [string, string, string, boolean | null][] = [
    ['Type', QUESTION_TYPE_LABELS[question.question_type] ?? question.question_type, fmt(v.detected_question_type), v.constraints.question_type],
    ['Difficulty', fmt(question.difficulty_level), fmt(v.detected_difficulty), v.constraints.difficulty],
    ['Bloom level', question.cognitive_level ?? '—', fmt(v.detected_cognitive_level), v.constraints.cognitive_level],
    ['Topic', question.topic ?? '—', v.detected_topics.slice(0, 2).join(', ') || '—', v.constraints.topic],
    ['CO alignment', question.learning_outcome_id ? 'Selected CO' : '—', v.co_alignment_status ? `${fmt(v.co_alignment_status)} (${v.co_alignment_score?.toFixed(2)})` : '—', v.constraints.co_alignment],
    ['Similarity', '< 0.70', `${fmt(v.similarity_status)} (${(v.max_similarity_score ?? 0).toFixed(2)})`, v.constraints.similarity],
  ];
  const variant = v.overall_status === 'PASSED' ? 'Good' : v.overall_status === 'PASSED_WITH_WARNINGS' ? 'Attention' : 'Critical';
  return (
    <div data-testid="constraint-validation" className="rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] p-3 text-xs">
      <div className="flex items-center justify-between mb-2">
        <span className="font-semibold text-[#111111] dark:text-white">Constraint validation</span>
        <Badge variant={variant} data-testid="validation-status">{fmt(v.overall_status)}</Badge>
      </div>
      <table className="w-full">
        <thead><tr className="text-[#737373] text-left"><th className="py-0.5">Constraint</th><th>Requested</th><th>AI-estimated</th><th className="w-8"></th></tr></thead>
        <tbody>
          {rows.map(([k, req, det, ok]) => (
            <tr key={k} className="border-t border-[#F0F0F0] dark:border-[#1F1F1F]"><td className="py-1 text-[#525252] dark:text-[#A3A3A3]">{k}</td><td>{req}</td><td>{det}</td><td><Check_ ok={ok} /></td></tr>
          ))}
        </tbody>
      </table>
      {v.warnings.length > 0 && (
        <ul className="mt-2 space-y-0.5 text-amber-800 dark:text-amber-300" data-testid="validation-warnings">
          {v.warnings.map((w, i) => <li key={i}>⚠ {w}</li>)}
        </ul>
      )}
    </div>
  );
};

export const GeneratedQuestionEditor: React.FC<{
  question: GeneratedQuestion;
  outcomes: { id: number | string; code: string; description: string }[];
  onSave: (data: UpdateGeneratedQuestionInput) => Promise<void> | void;
  onCancel: () => void;
  saving?: boolean;
}> = ({ question, outcomes, onSave, onCancel, saving }) => {
  const [text, setText] = useState(question.question_text);
  const [marks, setMarks] = useState(String(question.marks));
  const [type, setType] = useState<GenQuestionType>(question.question_type);
  const [difficulty, setDifficulty] = useState<GenDifficulty | ''>(question.difficulty_level ?? '');
  const [cog, setCog] = useState<GenCognitive | ''>(question.cognitive_level ?? '');
  const [lo, setLo] = useState(question.learning_outcome_id ? String(question.learning_outcome_id) : '');
  const [expected, setExpected] = useState(question.expected_answer ?? '');
  const [options, setOptions] = useState((question.options ?? []).join('\n'));
  const valid = text.trim().length >= 10 && Number(marks) > 0;

  return (
    <form data-testid="generated-question-editor" className="space-y-3" onSubmit={(e) => { e.preventDefault(); if (valid) void onSave({
      question_text: text.trim(), marks: Number(marks), question_type: type, difficulty_level: difficulty || null, cognitive_level: cog || null,
      learning_outcome_id: lo ? Number(lo) : null, expected_answer: expected.trim() || null,
      options: type === 'mcq' ? options.split('\n').map((o) => o.trim()).filter(Boolean) : null,
    }); }}>
      <textarea aria-label="Question text" value={text} onChange={(e) => setText(e.target.value)} rows={4} maxLength={5000}
        className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-3 py-2 text-sm text-[#111111] dark:text-white" />
      <div className="grid grid-cols-2 md:grid-cols-5 gap-2">
        <Input aria-label="Edit marks" type="number" min={0.5} step={0.5} value={marks} onChange={(e) => setMarks(e.target.value)} />
        <select aria-label="Edit type" className={selectClass} value={type} onChange={(e) => setType(e.target.value as GenQuestionType)}>
          {(Object.keys(QUESTION_TYPE_LABELS) as GenQuestionType[]).map((t) => <option key={t} value={t}>{QUESTION_TYPE_LABELS[t]}</option>)}
        </select>
        <select aria-label="Edit difficulty" className={selectClass} value={difficulty} onChange={(e) => setDifficulty(e.target.value as GenDifficulty | '')}>
          <option value="">Difficulty</option>{DIFFICULTY_LEVELS.map((d) => <option key={d} value={d}>{fmt(d)}</option>)}
        </select>
        <select aria-label="Edit cognitive level" className={selectClass} value={cog} onChange={(e) => setCog(e.target.value as GenCognitive | '')}>
          <option value="">Bloom level</option>{COGNITIVE_LEVELS.map((c) => <option key={c} value={c}>{c}</option>)}
        </select>
        <select aria-label="Edit course outcome" className={selectClass} value={lo} onChange={(e) => setLo(e.target.value)}>
          <option value="">No CO</option>{outcomes.map((o) => <option key={o.id} value={String(o.id)}>{o.code}</option>)}
        </select>
      </div>
      {type === 'mcq' && (
        <textarea aria-label="Options (one per line)" value={options} onChange={(e) => setOptions(e.target.value)} rows={4} placeholder="One option per line"
          className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-3 py-2 text-sm text-[#111111] dark:text-white" />
      )}
      <textarea aria-label="Expected answer" value={expected} onChange={(e) => setExpected(e.target.value)} rows={3} placeholder="Expected answer (draft)"
        className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-3 py-2 text-sm text-[#111111] dark:text-white" />
      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={!valid} isLoading={saving} data-testid="editor-save">Save changes</Button>
        <Button type="button" size="sm" variant="ghost" onClick={onCancel}>Cancel</Button>
      </div>
    </form>
  );
};

export interface GeneratedQuestionCardProps {
  question: GeneratedQuestion;
  outcomes: { id: number | string; code: string; description: string }[];
  onEdit: (q: GeneratedQuestion, data: UpdateGeneratedQuestionInput) => Promise<void>;
  onApprove: (q: GeneratedQuestion) => void;
  onReject: (q: GeneratedQuestion) => void;
  onRegenerate: (q: GeneratedQuestion) => void;
  onAddToAssessment: (q: GeneratedQuestion) => void;
  onGenerateRubric?: (q: GeneratedQuestion) => void;
  onViewSimilar?: (existingId: number, source: string) => void;
  regenerationsLeft: number;
  hasAssessment: boolean;
}

export const GeneratedQuestionCard: React.FC<GeneratedQuestionCardProps> = ({
  question: q, outcomes, onEdit, onApprove, onReject, onRegenerate, onAddToAssessment, onGenerateRubric, onViewSimilar, regenerationsLeft, hasAssessment,
}) => {
  const [editing, setEditing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [showOriginal, setShowOriginal] = useState(false);
  const co = outcomes.find((o) => String(o.id) === String(q.learning_outcome_id));
  const reviewVariant = q.review_status === 'APPROVED' ? 'Good' : q.review_status === 'REJECTED' ? 'Critical' : q.review_status === 'REVIEWED' ? 'Attention' : 'neutral';
  const isFinal = !!q.official_question_id;

  return (
    <article data-testid={`generated-question-${q.id}`} className={cn('rounded-xl border bg-white dark:bg-[#161616] p-4 space-y-3', q.review_status === 'REJECTED' ? 'border-[#E5E5E5] dark:border-[#2A2A2A] opacity-70' : 'border-[#E5E5E5] dark:border-[#2A2A2A]')}>
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className="text-sm font-semibold text-[#111111] dark:text-white">Question {q.sequence}</span>
          <Badge variant={reviewVariant} data-testid="review-status">{fmt(q.review_status)}</Badge>
          {q.is_edited && <Badge variant="outline">Edited v{q.version}</Badge>}
          {isFinal && <Badge variant="Good" data-testid="added-badge">Added to assessment</Badge>}
        </div>
        <div className="flex flex-wrap gap-1.5 text-xs text-[#737373]">
          <span className="font-mono">{q.marks} marks</span><span>·</span><span>{QUESTION_TYPE_LABELS[q.question_type] ?? q.question_type}</span>
          {q.difficulty_level && <><span>·</span><span>{fmt(q.difficulty_level)}</span></>}
          {q.cognitive_level && <><span>·</span><span>{q.cognitive_level}</span></>}
          {co && <><span>·</span><span>{co.code}</span></>}
        </div>
      </header>

      {editing ? (
        <GeneratedQuestionEditor question={q} outcomes={outcomes} saving={saving} onCancel={() => setEditing(false)}
          onSave={async (data) => { setSaving(true); try { await onEdit(q, data); setEditing(false); } finally { setSaving(false); } }} />
      ) : (
        <>
          <p className="text-sm text-[#111111] dark:text-white whitespace-pre-wrap break-words" data-testid="question-text">{q.question_text}</p>
          {q.options && q.options.length > 0 && (
            <ol className="list-[upper-alpha] pl-6 text-sm text-[#525252] dark:text-[#A3A3A3] space-y-0.5">
              {q.options.map((o, i) => <li key={i} className={cn(o === q.correct_option && 'font-semibold text-[#111111] dark:text-white')}>{o}</li>)}
            </ol>
          )}
          {q.expected_answer && (
            <details className="text-xs text-[#525252] dark:text-[#A3A3A3]"><summary className="cursor-pointer">Expected answer (draft)</summary><p className="mt-1 whitespace-pre-wrap">{q.expected_answer}</p></details>
          )}
          {q.is_edited && (
            <button type="button" className="text-[11px] underline text-[#737373]" onClick={() => setShowOriginal((s) => !s)}>{showOriginal ? 'Hide' : 'Show'} original AI draft</button>
          )}
          {showOriginal && <p className="text-xs italic text-[#737373] whitespace-pre-wrap" data-testid="original-text">{q.original_question_text}</p>}
        </>
      )}

      <div className="flex flex-wrap gap-2"><QuestionAlignmentBadge validation={q.validation} /></div>
      <QuestionSimilarityWarning validation={q.validation} onViewSimilar={onViewSimilar} />
      <ConstraintValidation question={q} />
      {q.review_note && <p className="text-xs text-[#737373]">Note: {q.review_note}</p>}

      {!editing && (
        <footer className="flex flex-wrap gap-2 pt-1">
          {!isFinal && q.review_status !== 'REJECTED' && <Button size="sm" variant="outline" leftIcon={<Edit3 className="w-3.5 h-3.5" />} onClick={() => setEditing(true)} data-testid="edit-button">Edit</Button>}
          {!isFinal && q.review_status !== 'APPROVED' && q.review_status !== 'REJECTED' && <Button size="sm" leftIcon={<CheckCircle2 className="w-3.5 h-3.5" />} onClick={() => onApprove(q)} data-testid="approve-button">Approve</Button>}
          {!isFinal && q.review_status !== 'REJECTED' && <Button size="sm" variant="outline" leftIcon={<XCircle className="w-3.5 h-3.5" />} onClick={() => onReject(q)} data-testid="reject-button">Reject</Button>}
          {!isFinal && q.review_status !== 'APPROVED' && <Button size="sm" variant="outline" leftIcon={<RefreshCw className="w-3.5 h-3.5" />} onClick={() => onRegenerate(q)} disabled={regenerationsLeft <= 0} title={regenerationsLeft <= 0 ? 'Regeneration limit reached' : undefined} data-testid="regenerate-button">Regenerate</Button>}
          {q.can_add_to_assessment && <Button size="sm" variant="secondary" leftIcon={<PlusCircle className="w-3.5 h-3.5" />} onClick={() => onAddToAssessment(q)} data-testid="add-to-assessment-button">{hasAssessment ? 'Add to assessment' : 'Add to an assessment'}</Button>}
          {isFinal && onGenerateRubric && <Button size="sm" variant="outline" leftIcon={<Link2 className="w-3.5 h-3.5" />} onClick={() => onGenerateRubric(q)} data-testid="generate-rubric-button">Generate rubric</Button>}
        </footer>
      )}
    </article>
  );
};
