import { EditableCriterion, RubricCriterion, RubricCriterionPayload } from '@/types/rubric';

export const MARK_TOLERANCE = 0.005;

export function round2(n: number): number {
  return Math.round(n * 100) / 100;
}

export function formatMarks(n: number | string | null | undefined): string {
  const v = Number(n ?? 0);
  if (Number.isNaN(v)) return '0';
  return Number.isInteger(v) ? String(v) : String(round2(v));
}

export function marksMatch(total: number, questionMarks: number): boolean {
  return Math.abs(round2(total) - round2(questionMarks)) <= MARK_TOLERANCE;
}

export function parseMarks(value: string): number | null {
  const trimmed = value.trim();
  if (trimmed === '') return null;
  const n = Number(trimmed);
  return Number.isFinite(n) ? n : null;
}

export function criteriaTotal(criteria: EditableCriterion[]): number {
  return round2(criteria.reduce((sum, c) => sum + (parseMarks(c.max_marks) ?? 0), 0));
}

let keyCounter = 0;
export function newCriterionKey(): string {
  keyCounter += 1;
  return `c-${Date.now()}-${keyCounter}`;
}

export function toEditable(criteria: RubricCriterion[]): EditableCriterion[] {
  return [...criteria]
    .sort((a, b) => a.sort_order - b.sort_order)
    .map((c) => ({
      key: newCriterionKey(),
      criterion: c.criterion,
      description: c.description,
      max_marks: formatMarks(c.max_marks),
      scoring_guidance: c.scoring_guidance ?? '',
      expected_indicators: [...(c.expected_indicators ?? [])],
    }));
}

export function emptyCriterion(): EditableCriterion {
  return {
    key: newCriterionKey(),
    criterion: '',
    description: '',
    max_marks: '0',
    scoring_guidance: '',
    expected_indicators: [],
  };
}

/** Client-side validation mirroring the server rules. Returns a list of human-readable problems. */
export function validateEditable(criteria: EditableCriterion[], questionMarks: number, title: string): string[] {
  const errors: string[] = [];
  if (!title.trim()) errors.push('Rubric title is required.');
  if (criteria.length === 0) errors.push('Add at least one criterion.');
  if (criteria.length > 12) errors.push('A rubric may contain at most 12 criteria.');

  criteria.forEach((c, idx) => {
    const n = idx + 1;
    if (!c.criterion.trim()) errors.push(`Criterion ${n} needs a name.`);
    if (!c.description.trim()) errors.push(`Criterion ${n} needs a description.`);
    const marks = parseMarks(c.max_marks);
    if (marks === null) errors.push(`Criterion ${n} needs a valid marks value.`);
    else if (marks < 0) errors.push(`Criterion ${n} cannot have negative marks.`);
  });

  const total = criteriaTotal(criteria);
  if (criteria.length > 0 && !marksMatch(total, questionMarks)) {
    errors.push(
      `Rubric total does not match the question's total marks. Question marks: ${formatMarks(questionMarks)} · Rubric marks: ${formatMarks(total)}.`
    );
  }
  return errors;
}

export function toPayload(criteria: EditableCriterion[]): RubricCriterionPayload[] {
  return criteria.map((c, idx) => ({
    criterion: c.criterion.trim(),
    description: c.description.trim(),
    max_marks: parseMarks(c.max_marks) ?? 0,
    scoring_guidance: c.scoring_guidance.trim() || null,
    expected_indicators: c.expected_indicators.map((i) => i.trim()).filter(Boolean),
    sort_order: idx + 1,
  }));
}
