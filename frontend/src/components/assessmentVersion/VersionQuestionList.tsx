import React from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { AssessmentVersionQuestion, VersionQuestionInput } from '@/types/assessmentVersion';
import { fmtMarks, humanize } from './versionUtils';

export const QUESTION_TYPES = ['mcq', 'short_answer', 'descriptive', 'problem_solving', 'true_false', 'conceptual', 'analytical', 'other'];
export const DIFFICULTIES = ['easy', 'medium', 'hard'];
export const COGNITIVE_LEVELS = ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'];

export interface OutcomeOption { id: number; code: string }

export const toInputRows = (questions: AssessmentVersionQuestion[]): VersionQuestionInput[] =>
  questions.map((q) => ({
    original_question_id: q.original_question_id, question_number: q.question_number, section_name: q.section_name, question_text: q.question_text, question_type: q.question_type, marks: q.marks,
    difficulty_level: q.difficulty_level, cognitive_level: q.cognitive_level, topic: q.topic, learning_outcome_id: q.learning_outcome_id, program_outcome_id: q.program_outcome_id, expected_answer: q.expected_answer,
  }));

const cell = 'rounded-md border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#111111] px-2 py-1 text-xs text-[#111111] dark:text-white w-full';

/** STEP 38: question snapshot of a version — read-only, or an editable draft table (replaced wholesale on save). */
export const VersionQuestionList: React.FC<{
  questions: AssessmentVersionQuestion[];
  editable?: boolean;
  rows?: VersionQuestionInput[];
  onChangeRows?: (rows: VersionQuestionInput[]) => void;
  outcomes?: OutcomeOption[];
  programOutcomes?: OutcomeOption[];
}> = ({ questions, editable = false, rows, onChangeRows, outcomes = [], programOutcomes = [] }) => {
  if (editable && rows && onChangeRows) {
    const set = (i: number, patch: Partial<VersionQuestionInput>) => onChangeRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)));
    const remove = (i: number) => onChangeRows(rows.filter((_, j) => j !== i).map((r, j) => ({ ...r, question_number: j + 1 })));
    const add = () => onChangeRows([...rows, { question_number: rows.length + 1, question_text: '', question_type: 'descriptive', marks: 5, difficulty_level: 'medium', cognitive_level: 'Understand', learning_outcome_id: null, program_outcome_id: null, original_question_id: null }]);
    const sum = rows.reduce((s, r) => s + (Number(r.marks) || 0), 0);
    return (
      <Card data-testid="version-question-editor" className="p-4 space-y-3">
        <div className="flex items-center justify-between"><h3 className="text-sm font-semibold text-[#111111] dark:text-white">Questions (draft)</h3><span className="text-xs text-[#737373]">{rows.length} questions · {fmtMarks(sum)} marks</span></div>
        <div className="space-y-3">
          {rows.map((r, i) => (
            <div key={i} className="rounded-lg border border-[#E5E5E5] dark:border-[#2A2A2A] p-3 space-y-2" data-testid={`question-row-${i + 1}`}>
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-semibold text-[#111111] dark:text-white">Q{r.question_number ?? i + 1}{r.original_question_id ? <span className="text-[#737373] font-normal"> · from question #{r.original_question_id}</span> : <span className="text-[#737373] font-normal"> · new</span>}</span>
                <Button type="button" size="sm" variant="ghost" onClick={() => remove(i)} aria-label={`Remove question ${i + 1}`} className="text-red-700"><Trash2 className="w-3.5 h-3.5" /></Button>
              </div>
              <textarea aria-label={`Question ${i + 1} text`} value={r.question_text} onChange={(e) => set(i, { question_text: e.target.value })} rows={2} className={cell} />
              <div className="grid grid-cols-2 md:grid-cols-6 gap-2">
                <label className="text-[10px] uppercase tracking-wide text-[#737373]">Marks<input aria-label={`Question ${i + 1} marks`} type="number" min={0.5} step={0.5} value={r.marks} onChange={(e) => set(i, { marks: Number(e.target.value) })} className={cell} /></label>
                <label className="text-[10px] uppercase tracking-wide text-[#737373]">Type<select aria-label={`Question ${i + 1} type`} value={r.question_type ?? 'descriptive'} onChange={(e) => set(i, { question_type: e.target.value })} className={cell}>{QUESTION_TYPES.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</select></label>
                <label className="text-[10px] uppercase tracking-wide text-[#737373]">Difficulty<select aria-label={`Question ${i + 1} difficulty`} value={r.difficulty_level ?? ''} onChange={(e) => set(i, { difficulty_level: e.target.value || null })} className={cell}><option value="">—</option>{DIFFICULTIES.map((d) => <option key={d} value={d}>{humanize(d)}</option>)}</select></label>
                <label className="text-[10px] uppercase tracking-wide text-[#737373]">Bloom<select aria-label={`Question ${i + 1} cognitive level`} value={r.cognitive_level ?? ''} onChange={(e) => set(i, { cognitive_level: e.target.value || null })} className={cell}><option value="">—</option>{COGNITIVE_LEVELS.map((c) => <option key={c} value={c}>{c}</option>)}</select></label>
                <label className="text-[10px] uppercase tracking-wide text-[#737373]">CO / LO<select aria-label={`Question ${i + 1} learning outcome`} value={r.learning_outcome_id ?? ''} onChange={(e) => set(i, { learning_outcome_id: e.target.value ? Number(e.target.value) : null })} className={cell}><option value="">—</option>{outcomes.map((o) => <option key={o.id} value={o.id}>{o.code}</option>)}</select></label>
                <label className="text-[10px] uppercase tracking-wide text-[#737373]">PO<select aria-label={`Question ${i + 1} program outcome`} value={r.program_outcome_id ?? ''} onChange={(e) => set(i, { program_outcome_id: e.target.value ? Number(e.target.value) : null })} className={cell} disabled={programOutcomes.length === 0}><option value="">—</option>{programOutcomes.map((o) => <option key={o.id} value={o.id}>{o.code}</option>)}</select></label>
              </div>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                <label className="text-[10px] uppercase tracking-wide text-[#737373]">Topic<input aria-label={`Question ${i + 1} topic`} value={r.topic ?? ''} onChange={(e) => set(i, { topic: e.target.value || null })} className={cell} /></label>
                <label className="text-[10px] uppercase tracking-wide text-[#737373]">Expected answer<input aria-label={`Question ${i + 1} expected answer`} value={r.expected_answer ?? ''} onChange={(e) => set(i, { expected_answer: e.target.value || null })} className={cell} /></label>
              </div>
            </div>
          ))}
        </div>
        <Button type="button" size="sm" variant="outline" onClick={add} leftIcon={<Plus className="w-3.5 h-3.5" />}>Add question</Button>
      </Card>
    );
  }

  return (
    <Card data-testid="version-question-list" className="p-4">
      <h3 className="text-sm font-semibold text-[#111111] dark:text-white mb-2">Questions ({questions.length})</h3>
      {questions.length === 0 ? <p className="text-sm text-[#737373]">This version has no questions.</p> : (
        <ol className="divide-y divide-[#F0F0F0] dark:divide-[#2A2A2A]">
          {questions.map((q) => (
            <li key={q.id} className="py-2 flex gap-3" data-testid={`version-question-${q.question_number}`}>
              <span className="text-xs font-semibold text-[#737373] w-8 shrink-0 mt-0.5">Q{q.question_number}</span>
              <div className="flex-1 min-w-0">
                <p className="text-sm text-[#111111] dark:text-white whitespace-pre-wrap">{q.question_text}</p>
                <p className="text-[11px] text-[#737373] mt-1 flex flex-wrap gap-x-2">
                  <span>{fmtMarks(q.marks)} marks</span><span>· {humanize(q.question_type)}</span>{q.difficulty_level && <span>· {humanize(q.difficulty_level)}</span>}{q.cognitive_level && <span>· {q.cognitive_level}</span>}
                  {q.learning_outcome_code && <span>· {q.learning_outcome_code}</span>}{q.program_outcome_code && <span>· {q.program_outcome_code}</span>}{q.topic && <span>· {q.topic}</span>}
                  {q.rubric_snapshot && <span>· rubric v{q.rubric_snapshot.rubric_version} ({q.rubric_snapshot.criteria.length} criteria)</span>}
                  {q.original_question_id === null && <span>· new in this version</span>}
                </p>
              </div>
            </li>
          ))}
        </ol>
      )}
    </Card>
  );
};
