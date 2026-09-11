import React, { useMemo, useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Card } from '@/components/common/Card';
import { Input } from '@/components/common/Input';
import {
  AssessmentBlueprint, BlueprintInput, COGNITIVE_LEVELS, CognitiveLevel, DIFFICULTY_LEVELS, DifficultyLevel, DistributionTarget, QUESTION_TYPES, QuestionType,
} from '@/types/blueprint';
import { fmtMarks, humanize } from './BlueprintStates';

/** STEP 37: blueprint editor. Client-side hints only — the server validator is authoritative. */

export interface OutcomeOption { id: number; code: string; description: string; }
export interface ProgramOutcomeOption { id: number; code: string; title: string; }

const STEP13_TARGETS: Record<DifficultyLevel, number> = { easy: 30, medium: 50, hard: 20 };

export const emptyInput = (assessment: { total_marks?: number | null; duration_minutes?: number | null; title?: string } | null): BlueprintInput => ({
  title: assessment?.title ? `${assessment.title} blueprint` : '', total_marks: Number(assessment?.total_marks ?? 0) || 0, total_questions: 0, duration_minutes: assessment?.duration_minutes ?? null, instructions: '',
  sections: [], constraints: { difficulty: DIFFICULTY_LEVELS.map((k) => ({ key: k, target_percentage: STEP13_TARGETS[k], target_count: null })), cognitive: [], learning_outcomes: [], program_outcomes: [], topics: [], question_types: [] }, items: [],
});

export const inputFromBlueprint = (bp: AssessmentBlueprint): BlueprintInput => ({
  title: bp.title, total_marks: bp.total_marks, total_questions: bp.total_questions, duration_minutes: bp.duration_minutes, instructions: bp.instructions ?? '',
  sections: bp.sections.map((s) => ({ title: s.title, section_order: s.section_order, instructions: s.instructions ?? null, question_type: s.question_type, question_count: s.question_count, marks_per_question: s.marks_per_question, difficulty_distribution: s.difficulty_distribution ?? null, cognitive_distribution: s.cognitive_distribution ?? null })),
  constraints: {
    difficulty: bp.constraints.difficulty, cognitive: bp.constraints.cognitive,
    learning_outcomes: bp.constraints.learning_outcomes.map(({ learning_outcome_id, target_percentage, target_marks, target_count }) => ({ learning_outcome_id, target_percentage, target_marks, target_count })),
    program_outcomes: bp.constraints.program_outcomes.map(({ program_outcome_id, target_percentage }) => ({ program_outcome_id, target_percentage })),
    topics: bp.constraints.topics, question_types: bp.constraints.question_types.map(({ question_type, target_count, marks_each }) => ({ question_type, target_count, marks_each })),
  },
  items: bp.items.map(({ section_order, topic, learning_outcome_id, program_outcome_id, question_type, difficulty_level, cognitive_level, question_count, marks_each }) => ({ section_order, topic, learning_outcome_id, program_outcome_id, question_type, difficulty_level, cognitive_level, question_count, marks_each })),
});

const num = (v: string): number | null => (v === '' ? null : Number(v));
const selectCls = 'w-full rounded-md border border-[#E5E5E5] dark:border-[#2A2A2A] bg-white dark:bg-[#161616] px-2 py-1.5 text-sm';
const Label: React.FC<{ htmlFor: string; children: React.ReactNode }> = ({ htmlFor, children }) => <label htmlFor={htmlFor} className="block text-xs font-medium uppercase tracking-wider text-[#525252] dark:text-[#A3A3A3] mb-1">{children}</label>;
const Hint: React.FC<{ ok: boolean; children: React.ReactNode }> = ({ ok, children }) => <p role="status" className={`text-xs ${ok ? 'text-[#737373]' : 'text-amber-800 dark:text-amber-300'}`}>{children}</p>;

export const BlueprintBasicSettings: React.FC<{ value: BlueprintInput; onChange: (v: BlueprintInput) => void; assessment: { title: string; type: string } | null }> = ({ value, onChange, assessment }) => (
  <Card data-testid="blueprint-basic-settings" className="p-4 space-y-3">
    <h3 className="text-sm font-semibold text-[#111111] dark:text-white">Basic settings</h3>
    {assessment && <p className="text-xs text-[#737373]">Linked assessment: <strong>{assessment.title}</strong> ({humanize(assessment.type)}) — no duplicate assessment is created.</p>}
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
      <Input id="bp-title" label="Blueprint title" value={value.title ?? ''} onChange={(e) => onChange({ ...value, title: e.target.value })} />
      <Input id="bp-total-marks" label="Total marks" type="number" min={1} step="0.5" value={value.total_marks || ''} onChange={(e) => onChange({ ...value, total_marks: Number(e.target.value) })} required />
      <Input id="bp-total-questions" label="Number of questions" type="number" min={1} value={value.total_questions || ''} onChange={(e) => onChange({ ...value, total_questions: Number(e.target.value) })} required />
      <Input id="bp-duration" label="Duration (minutes)" type="number" min={1} value={value.duration_minutes ?? ''} onChange={(e) => onChange({ ...value, duration_minutes: num(e.target.value) })} />
    </div>
    <div><Label htmlFor="bp-instructions">Instructions</Label><textarea id="bp-instructions" rows={2} value={value.instructions ?? ''} onChange={(e) => onChange({ ...value, instructions: e.target.value })} className={selectCls} /></div>
  </Card>
);

export const BlueprintSectionEditor: React.FC<{ value: BlueprintInput; onChange: (v: BlueprintInput) => void }> = ({ value, onChange }) => {
  const sections = value.sections;
  const marks = sections.reduce((s, x) => s + x.question_count * x.marks_per_question, 0);
  const count = sections.reduce((s, x) => s + x.question_count, 0);
  const update = (i: number, patch: Partial<BlueprintInput['sections'][number]>) => onChange({ ...value, sections: sections.map((s, j) => (j === i ? { ...s, ...patch } : s)) });
  return (
    <Card data-testid="blueprint-section-editor" className="p-4 space-y-3">
      <div className="flex items-center justify-between"><h3 className="text-sm font-semibold text-[#111111] dark:text-white">Sections & question structure</h3>
        <Button type="button" size="sm" variant="outline" onClick={() => onChange({ ...value, sections: [...sections, { title: `Section ${String.fromCharCode(65 + sections.length)}`, section_order: sections.length + 1, question_type: 'mcq', question_count: 1, marks_per_question: 1, instructions: null }] })}><Plus className="w-3.5 h-3.5 mr-1" aria-hidden="true" />Add section</Button></div>
      {sections.length === 0 && <p className="text-xs text-[#737373]">No sections yet. Sections define question type, count and marks per question (e.g. Section A: MCQ 10 × 1 = 10).</p>}
      {sections.map((s, i) => (
        <fieldset key={i} className="grid grid-cols-2 md:grid-cols-6 gap-2 items-end border-t border-[#F0F0F0] dark:border-[#2A2A2A] pt-2" data-testid={`section-row-${i}`}>
          <legend className="sr-only">Section {i + 1}</legend>
          <Input id={`sec-title-${i}`} label="Title" value={s.title} onChange={(e) => update(i, { title: e.target.value })} />
          <div><Label htmlFor={`sec-type-${i}`}>Type</Label><select id={`sec-type-${i}`} value={s.question_type ?? ''} onChange={(e) => update(i, { question_type: (e.target.value || null) as QuestionType | null })} className={selectCls}><option value="">Any</option>{QUESTION_TYPES.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</select></div>
          <Input id={`sec-count-${i}`} label="Questions" type="number" min={1} value={s.question_count} onChange={(e) => update(i, { question_count: Number(e.target.value) })} />
          <Input id={`sec-marks-${i}`} label="Marks each" type="number" min={0.5} step="0.5" value={s.marks_per_question} onChange={(e) => update(i, { marks_per_question: Number(e.target.value) })} />
          <div className="text-sm tabular-nums"><span className="text-xs text-[#737373] block">Total</span>{s.question_count} × {fmtMarks(s.marks_per_question)} = <strong>{fmtMarks(s.question_count * s.marks_per_question)}</strong></div>
          <Button type="button" size="sm" variant="ghost" aria-label={`Remove section ${s.title}`} onClick={() => onChange({ ...value, sections: sections.filter((_, j) => j !== i).map((x, j) => ({ ...x, section_order: j + 1 })) })}><Trash2 className="w-4 h-4" /></Button>
        </fieldset>
      ))}
      {sections.length > 0 && (
        <>
          <Hint ok={Math.abs(marks - value.total_marks) < 0.01}>Section marks {fmtMarks(marks)} / {fmtMarks(value.total_marks)} required{Math.abs(marks - value.total_marks) >= 0.01 ? ` (difference ${marks - value.total_marks > 0 ? '+' : ''}${fmtMarks(marks - value.total_marks)})` : ' ✓'}</Hint>
          <Hint ok={count === value.total_questions}>Section questions {count} / {value.total_questions}{count !== value.total_questions ? ' — question count mismatch' : ' ✓'}</Hint>
        </>
      )}
    </Card>
  );
};

export const BlueprintQuestionStructure: React.FC<{ value: BlueprintInput; onChange: (v: BlueprintInput) => void }> = ({ value, onChange }) => {
  const rows = value.constraints.question_types;
  const set = (rows2: BlueprintInput['constraints']['question_types']) => onChange({ ...value, constraints: { ...value.constraints, question_types: rows2 } });
  const count = rows.reduce((s, r) => s + r.target_count, 0);
  const marks = rows.reduce((s, r) => s + r.target_count * (r.marks_each ?? 0), 0);
  return (
    <Card data-testid="blueprint-question-structure" className="p-4 space-y-2">
      <div className="flex items-center justify-between"><h3 className="text-sm font-semibold text-[#111111] dark:text-white">Question type distribution</h3>
        <Button type="button" size="sm" variant="outline" onClick={() => set([...rows, { question_type: QUESTION_TYPES.find((t) => !rows.some((r) => r.question_type === t)) ?? 'descriptive', target_count: 1, marks_each: 1 }])} disabled={rows.length >= QUESTION_TYPES.length}><Plus className="w-3.5 h-3.5 mr-1" aria-hidden="true" />Add type</Button></div>
      {rows.length === 0 && <p className="text-xs text-[#737373]">Optional. Leave empty to rely on sections.</p>}
      {rows.map((r, i) => (
        <div key={i} className="grid grid-cols-2 md:grid-cols-5 gap-2 items-end">
          <div><Label htmlFor={`qt-type-${i}`}>Type</Label><select id={`qt-type-${i}`} value={r.question_type} onChange={(e) => set(rows.map((x, j) => (j === i ? { ...x, question_type: e.target.value as QuestionType } : x)))} className={selectCls}>{QUESTION_TYPES.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</select></div>
          <Input id={`qt-count-${i}`} label="Questions" type="number" min={0} value={r.target_count} onChange={(e) => set(rows.map((x, j) => (j === i ? { ...x, target_count: Number(e.target.value) } : x)))} />
          <Input id={`qt-marks-${i}`} label="Marks each" type="number" min={0} step="0.5" value={r.marks_each ?? ''} onChange={(e) => set(rows.map((x, j) => (j === i ? { ...x, marks_each: num(e.target.value) } : x)))} />
          <div className="text-sm tabular-nums"><span className="text-xs text-[#737373] block">Total</span>{r.target_count} × {fmtMarks(r.marks_each)} = <strong>{fmtMarks(r.target_count * (r.marks_each ?? 0))}</strong></div>
          <Button type="button" size="sm" variant="ghost" aria-label={`Remove type ${r.question_type}`} onClick={() => set(rows.filter((_, j) => j !== i))}><Trash2 className="w-4 h-4" /></Button>
        </div>
      ))}
      {rows.length > 0 && <Hint ok={count === value.total_questions && Math.abs(marks - value.total_marks) < 0.01}>{count} questions / {fmtMarks(marks)} marks planned by type (blueprint: {value.total_questions} / {fmtMarks(value.total_marks)})</Hint>}
    </Card>
  );
};

const PercentTable: React.FC<{ testId: string; title: string; keys: readonly string[]; rows: DistributionTarget[]; onChange: (rows: DistributionTarget[]) => void; total: number; targets?: Record<string, number>; note?: string; mode: 'percentage' | 'count'; onMode: (m: 'percentage' | 'count') => void }> = ({ testId, title, keys, rows, onChange, total, targets, note, mode, onMode }) => {
  const get = (k: string) => rows.find((r) => r.key === k);
  const setRow = (k: string, patch: Partial<DistributionTarget>) => {
    const exists = get(k);
    onChange(exists ? rows.map((r) => (r.key === k ? { ...r, ...patch } : r)) : [...rows, { key: k, target_percentage: null, target_count: null, ...patch }]);
  };
  const pctSum = rows.reduce((s, r) => s + (r.target_percentage ?? 0), 0);
  const cntSum = rows.reduce((s, r) => s + (r.target_count ?? 0), 0);
  const configured = rows.some((r) => r.target_percentage !== null || r.target_count !== null);
  return (
    <Card data-testid={testId} className="p-4 space-y-2">
      <div className="flex items-center justify-between gap-2"><h3 className="text-sm font-semibold text-[#111111] dark:text-white">{title}</h3>
        <div role="group" aria-label={`${title} input mode`} className="flex gap-1 text-xs">{(['percentage', 'count'] as const).map((m) => <button key={m} type="button" aria-pressed={mode === m} onClick={() => onMode(m)} className={`px-2 py-0.5 rounded border ${mode === m ? 'bg-[#111111] text-white border-[#111111] dark:bg-white dark:text-black' : 'border-[#E5E5E5] dark:border-[#2A2A2A]'}`}>{m === 'percentage' ? '%' : 'Count'}</button>)}</div>
        <Button type="button" size="sm" variant="ghost" onClick={() => onChange([])}>Clear</Button></div>
      <table className="w-full text-sm"><caption className="sr-only">{title} targets</caption>
        <thead><tr className="text-left text-xs text-[#737373]"><th className="py-1">Level</th><th className="py-1">{mode === 'percentage' ? 'Target %' : 'Target count'}</th>{targets && <th className="py-1">STEP 13 target</th>}<th className="py-1">Derived</th></tr></thead>
        <tbody>{keys.map((k) => {
          const r = get(k);
          const derivedCount = total > 0 && r?.target_percentage !== null && r?.target_percentage !== undefined ? Math.round((r.target_percentage / 100) * total * 10) / 10 : null;
          const derivedPct = total > 0 && r?.target_count !== null && r?.target_count !== undefined ? Math.round((r.target_count / total) * 1000) / 10 : null;
          return (
            <tr key={k} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A]">
              <td className="py-1"><label htmlFor={`${testId}-${k}`}>{humanize(k)}</label></td>
              <td className="py-1">{mode === 'percentage'
                ? <input id={`${testId}-${k}`} type="number" min={0} max={100} step="0.5" value={r?.target_percentage ?? ''} onChange={(e) => setRow(k, { target_percentage: num(e.target.value), target_count: null })} className={`${selectCls} w-24`} />
                : <input id={`${testId}-${k}`} type="number" min={0} value={r?.target_count ?? ''} onChange={(e) => setRow(k, { target_count: num(e.target.value), target_percentage: null })} className={`${selectCls} w-24`} />}</td>
              {targets && <td className="py-1 text-xs text-[#737373]">{targets[k] !== undefined ? `${targets[k]}%` : '—'}</td>}
              <td className="py-1 text-xs text-[#737373] tabular-nums">{mode === 'percentage' ? (derivedCount !== null ? `≈ ${derivedCount} of ${total} questions` : '') : (derivedPct !== null ? `${derivedPct}%` : '')}</td>
            </tr>
          );
        })}</tbody>
      </table>
      {configured && mode === 'percentage' && <Hint ok={Math.abs(pctSum - 100) < 0.5}>Total {Math.round(pctSum * 10) / 10}%{Math.abs(pctSum - 100) >= 0.5 ? ' — must equal 100% (blueprint requires correction)' : ' ✓'}</Hint>}
      {configured && mode === 'count' && <Hint ok={cntSum === total}>Total {cntSum} of {total} questions{cntSum !== total ? ' — counts must add up to the question count' : ' ✓'}</Hint>}
      {note && <p className="text-xs text-[#A3A3A3]">{note}</p>}
    </Card>
  );
};

export const BlueprintDifficultyDistribution: React.FC<{ value: BlueprintInput; onChange: (v: BlueprintInput) => void }> = ({ value, onChange }) => {
  const [mode, setMode] = useState<'percentage' | 'count'>(value.constraints.difficulty.some((r) => r.target_count !== null) ? 'count' : 'percentage');
  return <PercentTable testId="blueprint-difficulty" title="Difficulty distribution" keys={DIFFICULTY_LEVELS} rows={value.constraints.difficulty} onChange={(rows) => onChange({ ...value, constraints: { ...value.constraints, difficulty: rows } })} total={value.total_questions} targets={STEP13_TARGETS} mode={mode} onMode={setMode}
    note="If the percentages cannot be represented exactly by the question count, validation suggests an allocation for you to confirm — it is never applied silently." />;
};

export const BlueprintCognitiveDistribution: React.FC<{ value: BlueprintInput; onChange: (v: BlueprintInput) => void }> = ({ value, onChange }) => {
  const [mode, setMode] = useState<'percentage' | 'count'>(value.constraints.cognitive.some((r) => r.target_count !== null) ? 'count' : 'percentage');
  return <PercentTable testId="blueprint-cognitive" title="Bloom / cognitive distribution" keys={COGNITIVE_LEVELS} rows={value.constraints.cognitive} onChange={(rows) => onChange({ ...value, constraints: { ...value.constraints, cognitive: rows } })} total={value.total_questions} mode={mode} onMode={setMode} note="No institutional target is assumed unless configured." />;
};

export const BlueprintOutcomeDistribution: React.FC<{ value: BlueprintInput; onChange: (v: BlueprintInput) => void; outcomes: OutcomeOption[]; programOutcomes: ProgramOutcomeOption[] | null }> = ({ value, onChange, outcomes, programOutcomes }) => {
  const los = value.constraints.learning_outcomes;
  const pos = value.constraints.program_outcomes;
  const setLo = (id: number, patch: Partial<BlueprintInput['constraints']['learning_outcomes'][number]>) => {
    const exists = los.find((r) => r.learning_outcome_id === id);
    onChange({ ...value, constraints: { ...value.constraints, learning_outcomes: exists ? los.map((r) => (r.learning_outcome_id === id ? { ...r, ...patch } : r)) : [...los, { learning_outcome_id: id, target_percentage: null, target_marks: null, target_count: null, ...patch }] } });
  };
  const setPo = (id: number, pct: number | null) => {
    const exists = pos.find((r) => r.program_outcome_id === id);
    onChange({ ...value, constraints: { ...value.constraints, program_outcomes: exists ? pos.map((r) => (r.program_outcome_id === id ? { ...r, target_percentage: pct } : r)) : [...pos, { program_outcome_id: id, target_percentage: pct }] } });
  };
  const loSum = los.reduce((s, r) => s + (r.target_percentage ?? 0), 0);
  const poSum = pos.reduce((s, r) => s + (r.target_percentage ?? 0), 0);
  return (
    <Card data-testid="blueprint-outcomes" className="p-4 space-y-3">
      <h3 className="text-sm font-semibold text-[#111111] dark:text-white">Learning outcome (CO) coverage</h3>
      {outcomes.length === 0 ? <p className="text-xs text-[#737373]">This course has no learning outcomes yet.</p> : (
        <table className="w-full text-sm"><caption className="sr-only">Course outcome targets</caption>
          <thead><tr className="text-left text-xs text-[#737373]"><th className="py-1">Outcome</th><th className="py-1">Target %</th><th className="py-1">Target marks</th><th className="py-1">Questions</th></tr></thead>
          <tbody>{outcomes.map((o) => {
            const r = los.find((x) => x.learning_outcome_id === o.id);
            const derived = r?.target_percentage !== null && r?.target_percentage !== undefined ? Math.round((r.target_percentage / 100) * value.total_marks * 100) / 100 : null;
            return (
              <tr key={o.id} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A]">
                <td className="py-1"><span className="font-medium">{o.code}</span><p className="text-xs text-[#737373] truncate max-w-xs" title={o.description}>{o.description}</p></td>
                <td className="py-1"><input id={`lo-pct-${o.id}`} aria-label={`${o.code} target percentage`} type="number" min={0} max={100} step="0.5" value={r?.target_percentage ?? ''} onChange={(e) => setLo(o.id, { target_percentage: num(e.target.value) })} className={`${selectCls} w-24`} /></td>
                <td className="py-1 text-xs text-[#737373] tabular-nums">{derived !== null ? `≈ ${fmtMarks(derived)} marks` : ''}</td>
                <td className="py-1"><input aria-label={`${o.code} question count`} type="number" min={0} value={r?.target_count ?? ''} onChange={(e) => setLo(o.id, { target_count: num(e.target.value) })} className={`${selectCls} w-20`} /></td>
              </tr>
            );
          })}</tbody>
        </table>
      )}
      {los.some((r) => r.target_percentage !== null) && <Hint ok={Math.abs(loSum - 100) < 0.5}>CO total {Math.round(loSum * 10) / 10}%{Math.abs(loSum - 100) >= 0.5 ? ' — must equal 100%' : ' ✓'}</Hint>}
      <h3 className="text-sm font-semibold text-[#111111] dark:text-white pt-2">Program outcome (PO) coverage</h3>
      {!programOutcomes ? <p className="text-xs text-[#737373]" data-testid="po-not-configured">PO blueprint is not configured for this course.</p> : programOutcomes.length === 0 ? <p className="text-xs text-[#737373]">The linked program has no active outcomes.</p> : (
        <>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-2">{programOutcomes.map((p) => <div key={p.id}><Label htmlFor={`po-${p.id}`}>{p.code}</Label><input id={`po-${p.id}`} type="number" min={0} max={100} step="0.5" value={pos.find((x) => x.program_outcome_id === p.id)?.target_percentage ?? ''} onChange={(e) => setPo(p.id, num(e.target.value))} className={selectCls} title={p.title} /></div>)}</div>
          {pos.length > 0 && <Hint ok={Math.abs(poSum - 100) < 0.5}>PO total {Math.round(poSum * 10) / 10}%{Math.abs(poSum - 100) >= 0.5 ? ' — must equal 100%' : ' ✓'}</Hint>}
        </>
      )}
    </Card>
  );
};

export const BlueprintTopicDistribution: React.FC<{ value: BlueprintInput; onChange: (v: BlueprintInput) => void; suggestions?: string[] }> = ({ value, onChange, suggestions = [] }) => {
  const rows = value.constraints.topics;
  const set = (rows2: BlueprintInput['constraints']['topics']) => onChange({ ...value, constraints: { ...value.constraints, topics: rows2 } });
  const marks = rows.reduce((s, r) => s + (r.target_marks ?? 0), 0);
  const count = rows.reduce((s, r) => s + (r.target_count ?? 0), 0);
  return (
    <Card data-testid="blueprint-topics" className="p-4 space-y-2">
      <div className="flex items-center justify-between"><h3 className="text-sm font-semibold text-[#111111] dark:text-white">Topic coverage</h3><Button type="button" size="sm" variant="outline" onClick={() => set([...rows, { topic: '', target_count: 1, target_marks: null }])}><Plus className="w-3.5 h-3.5 mr-1" aria-hidden="true" />Add topic</Button></div>
      {rows.length === 0 && <p className="text-xs text-[#737373]">Optional. Topics are matched against AI-detected question topics during comparison.</p>}
      {rows.map((r, i) => (
        <div key={i} className="grid grid-cols-2 md:grid-cols-4 gap-2 items-end">
          <div><Label htmlFor={`topic-${i}`}>Topic</Label><input id={`topic-${i}`} list="blueprint-topic-suggestions" value={r.topic} onChange={(e) => set(rows.map((x, j) => (j === i ? { ...x, topic: e.target.value } : x)))} className={selectCls} /></div>
          <Input id={`topic-count-${i}`} label="Questions" type="number" min={0} value={r.target_count ?? ''} onChange={(e) => set(rows.map((x, j) => (j === i ? { ...x, target_count: num(e.target.value) } : x)))} />
          <Input id={`topic-marks-${i}`} label="Marks" type="number" min={0} step="0.5" value={r.target_marks ?? ''} onChange={(e) => set(rows.map((x, j) => (j === i ? { ...x, target_marks: num(e.target.value) } : x)))} />
          <Button type="button" size="sm" variant="ghost" aria-label={`Remove topic ${r.topic || i + 1}`} onClick={() => set(rows.filter((_, j) => j !== i))}><Trash2 className="w-4 h-4" /></Button>
        </div>
      ))}
      <datalist id="blueprint-topic-suggestions">{suggestions.map((s) => <option key={s} value={s} />)}</datalist>
      {rows.length > 0 && <Hint ok={marks <= value.total_marks + 0.01 && count <= value.total_questions}>{count} questions / {fmtMarks(marks)} marks planned across topics (blueprint: {value.total_questions} / {fmtMarks(value.total_marks)})</Hint>}
    </Card>
  );
};

export const BlueprintQuestionPlan: React.FC<{ value: BlueprintInput; onChange: (v: BlueprintInput) => void; outcomes: OutcomeOption[]; programOutcomes: ProgramOutcomeOption[] | null }> = ({ value, onChange, outcomes, programOutcomes }) => {
  const items = value.items;
  const set = (rows: BlueprintInput['items']) => onChange({ ...value, items: rows });
  const marks = items.reduce((s, r) => s + r.question_count * r.marks_each, 0);
  const count = items.reduce((s, r) => s + r.question_count, 0);
  const upd = (i: number, patch: Partial<BlueprintInput['items'][number]>) => set(items.map((x, j) => (j === i ? { ...x, ...patch } : x)));
  return (
    <Card data-testid="blueprint-question-plan" className="p-4 space-y-2">
      <div className="flex items-center justify-between"><div><h3 className="text-sm font-semibold text-[#111111] dark:text-white">Cross-dimension question plan</h3><p className="text-xs text-[#737373]">Rows like “CO2 / Analyze / Medium / Problem solving — 2 × 5 marks” drive the CO × difficulty and CO × Bloom matrices and the STEP 33 hand-off.</p></div>
        <Button type="button" size="sm" variant="outline" onClick={() => set([...items, { section_order: value.sections[0]?.section_order ?? null, topic: null, learning_outcome_id: outcomes[0]?.id ?? null, program_outcome_id: null, question_type: value.sections[0]?.question_type ?? 'descriptive', difficulty_level: 'medium', cognitive_level: 'Apply', question_count: 1, marks_each: value.sections[0]?.marks_per_question ?? 1 }])}><Plus className="w-3.5 h-3.5 mr-1" aria-hidden="true" />Add row</Button></div>
      {items.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-sm"><caption className="sr-only">Question plan rows</caption>
            <thead><tr className="text-left text-xs text-[#737373]"><th className="py-1">Section</th><th className="py-1">CO</th>{programOutcomes && programOutcomes.length > 0 && <th className="py-1">PO</th>}<th className="py-1">Topic</th><th className="py-1">Type</th><th className="py-1">Difficulty</th><th className="py-1">Bloom</th><th className="py-1">Count</th><th className="py-1">Marks each</th><th className="py-1">Total</th><th className="py-1"><span className="sr-only">Remove</span></th></tr></thead>
            <tbody>{items.map((it, i) => (
              <tr key={i} className="border-t border-[#F0F0F0] dark:border-[#2A2A2A]" data-testid={`plan-row-${i}`}>
                <td className="py-1 pr-1"><select aria-label={`Row ${i + 1} section`} value={it.section_order ?? ''} onChange={(e) => upd(i, { section_order: num(e.target.value) })} className={selectCls}><option value="">—</option>{value.sections.map((s) => <option key={s.section_order} value={s.section_order}>{s.title}</option>)}</select></td>
                <td className="py-1 pr-1"><select aria-label={`Row ${i + 1} outcome`} value={it.learning_outcome_id ?? ''} onChange={(e) => upd(i, { learning_outcome_id: num(e.target.value) })} className={selectCls}><option value="">—</option>{outcomes.map((o) => <option key={o.id} value={o.id}>{o.code}</option>)}</select></td>
                {programOutcomes && programOutcomes.length > 0 && <td className="py-1 pr-1"><select aria-label={`Row ${i + 1} program outcome`} value={it.program_outcome_id ?? ''} onChange={(e) => upd(i, { program_outcome_id: num(e.target.value) })} className={selectCls}><option value="">—</option>{programOutcomes.map((p) => <option key={p.id} value={p.id}>{p.code}</option>)}</select></td>}
                <td className="py-1 pr-1"><input aria-label={`Row ${i + 1} topic`} value={it.topic ?? ''} onChange={(e) => upd(i, { topic: e.target.value || null })} className={`${selectCls} w-28`} /></td>
                <td className="py-1 pr-1"><select aria-label={`Row ${i + 1} type`} value={it.question_type ?? ''} onChange={(e) => upd(i, { question_type: (e.target.value || null) as QuestionType | null })} className={selectCls}><option value="">—</option>{QUESTION_TYPES.map((t) => <option key={t} value={t}>{humanize(t)}</option>)}</select></td>
                <td className="py-1 pr-1"><select aria-label={`Row ${i + 1} difficulty`} value={it.difficulty_level ?? ''} onChange={(e) => upd(i, { difficulty_level: (e.target.value || null) as DifficultyLevel | null })} className={selectCls}><option value="">—</option>{DIFFICULTY_LEVELS.map((d) => <option key={d} value={d}>{humanize(d)}</option>)}</select></td>
                <td className="py-1 pr-1"><select aria-label={`Row ${i + 1} cognitive level`} value={it.cognitive_level ?? ''} onChange={(e) => upd(i, { cognitive_level: (e.target.value || null) as CognitiveLevel | null })} className={selectCls}><option value="">—</option>{COGNITIVE_LEVELS.map((c) => <option key={c} value={c}>{c}</option>)}</select></td>
                <td className="py-1 pr-1"><input aria-label={`Row ${i + 1} count`} type="number" min={1} value={it.question_count} onChange={(e) => upd(i, { question_count: Number(e.target.value) })} className={`${selectCls} w-16`} /></td>
                <td className="py-1 pr-1"><input aria-label={`Row ${i + 1} marks each`} type="number" min={0.5} step="0.5" value={it.marks_each} onChange={(e) => upd(i, { marks_each: Number(e.target.value) })} className={`${selectCls} w-20`} /></td>
                <td className="py-1 tabular-nums">{fmtMarks(it.question_count * it.marks_each)}</td>
                <td className="py-1"><Button type="button" size="sm" variant="ghost" aria-label={`Remove row ${i + 1}`} onClick={() => set(items.filter((_, j) => j !== i))}><Trash2 className="w-4 h-4" /></Button></td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      )}
      {items.length > 0 && <Hint ok={count === value.total_questions && Math.abs(marks - value.total_marks) < 0.01}>Plan covers {count} of {value.total_questions} questions and {fmtMarks(marks)} of {fmtMarks(value.total_marks)} marks</Hint>}
    </Card>
  );
};

export const BlueprintForm: React.FC<{
  initial: BlueprintInput; assessment: { title: string; type: string } | null; outcomes: OutcomeOption[]; programOutcomes: ProgramOutcomeOption[] | null; topicSuggestions?: string[];
  onSave: (v: BlueprintInput) => Promise<void>; onCancel?: () => void; saving?: boolean; submitLabel?: string;
}> = ({ initial, assessment, outcomes, programOutcomes, topicSuggestions, onSave, onCancel, saving, submitLabel = 'Save & validate' }) => {
  const [value, setValue] = useState<BlueprintInput>(initial);
  const [error, setError] = useState<string | null>(null);
  const problems = useMemo(() => {
    const p: string[] = [];
    if (!(value.total_marks > 0)) p.push('Total marks must be greater than zero.');
    if (!(value.total_questions > 0)) p.push('Number of questions must be greater than zero.');
    value.sections.forEach((s, i) => { if (!s.title.trim()) p.push(`Section ${i + 1} needs a title.`); });
    value.constraints.topics.forEach((t, i) => { if (!t.topic.trim()) p.push(`Topic row ${i + 1} needs a name.`); });
    return p;
  }, [value]);
  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    if (problems.length) { setError(problems[0]); return; }
    try { await onSave(value); } catch (err) { setError(err instanceof Error ? err.message : 'Failed to save the blueprint.'); }
  };
  return (
    <form data-testid="blueprint-form" noValidate onSubmit={submit} className="space-y-4">
      <BlueprintBasicSettings value={value} onChange={setValue} assessment={assessment} />
      <BlueprintSectionEditor value={value} onChange={setValue} />
      <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <BlueprintDifficultyDistribution value={value} onChange={setValue} />
        <BlueprintCognitiveDistribution value={value} onChange={setValue} />
      </div>
      <BlueprintOutcomeDistribution value={value} onChange={setValue} outcomes={outcomes} programOutcomes={programOutcomes} />
      <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <BlueprintTopicDistribution value={value} onChange={setValue} suggestions={topicSuggestions} />
        <BlueprintQuestionStructure value={value} onChange={setValue} />
      </div>
      <BlueprintQuestionPlan value={value} onChange={setValue} outcomes={outcomes} programOutcomes={programOutcomes} />
      {error && <p role="alert" className="text-sm text-red-700 dark:text-red-300">{error}</p>}
      <div className="flex justify-end gap-2">
        {onCancel && <Button type="button" variant="ghost" size="sm" onClick={onCancel} disabled={saving}>Cancel</Button>}
        <Button type="submit" size="sm" disabled={saving}>{saving ? 'Saving…' : submitLabel}</Button>
      </div>
    </form>
  );
};
