import React from 'react';
import { FieldChange } from '@/types/assessmentVersion';
import { fieldLabel, fmtMarks } from './versionUtils';

const show = (v: unknown, label?: string | null): string => {
  if (label) return label;
  if (v === null || v === undefined || v === '') return '—';
  if (typeof v === 'number') return fmtMarks(v);
  return String(v);
};

/** STEP 38: deterministic side-by-side diff of one question (text + changed attributes). */
export const VersionDiff: React.FC<{ fromLabel: string; toLabel: string; fromText: string | null; toText: string | null; changes: FieldChange[] }> = ({ fromLabel, toLabel, fromText, toText, changes }) => {
  const textChanged = changes.some((c) => c.field === 'question_text') || (fromText ?? null) !== (toText ?? null);
  const attrs = changes.filter((c) => c.field !== 'question_text');
  return (
    <div data-testid="version-diff" className="space-y-2">
      <div className="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
        <div className={`rounded-md border px-3 py-2 ${textChanged && fromText !== null ? 'border-red-200 bg-red-50/60 dark:bg-red-950/20 dark:border-red-900/40' : 'border-sage-200 dark:border-[#2A2A2A]'}`}>
          <p className="text-[10px] uppercase tracking-wide text-sage-500 mb-1">{fromLabel}</p>
          <p className="whitespace-pre-wrap text-sage-800 dark:text-white">{fromText ?? <span className="text-sage-500 italic">(not present)</span>}</p>
        </div>
        <div className={`rounded-md border px-3 py-2 ${textChanged && toText !== null ? 'border-green-200 bg-green-50/60 dark:bg-green-950/20 dark:border-green-900/40' : 'border-sage-200 dark:border-[#2A2A2A]'}`}>
          <p className="text-[10px] uppercase tracking-wide text-sage-500 mb-1">{toLabel}</p>
          <p className="whitespace-pre-wrap text-sage-800 dark:text-white">{toText ?? <span className="text-sage-500 italic">(removed)</span>}</p>
        </div>
      </div>
      {attrs.length > 0 && (
        <ul className="flex flex-wrap gap-2 text-xs">
          {attrs.map((c) => (
            <li key={c.field} className="rounded-md border border-sage-200 dark:border-[#2A2A2A] px-2 py-1 bg-sage-100 dark:bg-[#1F1F1F]">
              <span className="text-sage-500">{fieldLabel(c.field)}: </span><span className="line-through text-sage-500">{show(c.from, c.from_label)}</span><span className="mx-1">→</span><span className="font-medium text-sage-800 dark:text-white">{show(c.to, c.to_label)}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};
