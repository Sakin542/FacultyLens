import React, { useState } from 'react';
import { Card } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import { QuestionChange, QuestionChangeStatus } from '@/types/assessmentVersion';
import { VersionDiff } from './VersionDiff';
import { statusVariant } from './versionUtils';

const FILTERS: (QuestionChangeStatus | 'ALL')[] = ['ALL', 'MODIFIED', 'ADDED', 'REMOVED', 'UNCHANGED'];

/** STEP 38: per-question change list (Changed / Added / Removed / Unchanged) between two versions. */
export const QuestionChangeList: React.FC<{ items: QuestionChange[]; fromLabel: string; toLabel: string }> = ({ items, fromLabel, toLabel }) => {
  const [filter, setFilter] = useState<QuestionChangeStatus | 'ALL'>('ALL');
  const visible = items.filter((i) => filter === 'ALL' || i.status === filter);
  return (
    <Card data-testid="question-change-list" className="p-4 space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-sage-800 dark:text-white">Question changes</h3>
        <div role="tablist" aria-label="Filter question changes" className="flex flex-wrap gap-1">
          {FILTERS.map((f) => {
            const n = f === 'ALL' ? items.length : items.filter((i) => i.status === f).length;
            return <button key={f} role="tab" aria-selected={filter === f} type="button" onClick={() => setFilter(f)} className={`text-xs px-2 py-1 rounded-md border ${filter === f ? 'bg-sage-700 text-white border-sage-700' : 'border-sage-200 text-sage-600 hover:bg-sage-100'}`}>{f === 'ALL' ? 'All' : f.charAt(0) + f.slice(1).toLowerCase()} ({n})</button>;
          })}
        </div>
      </div>
      {visible.length === 0 ? <p className="text-sm text-sage-500">No questions in this category.</p> : (
        <ul className="space-y-3">
          {visible.map((i, idx) => (
            <li key={`${i.status}-${i.question_number}-${idx}`} data-testid={`question-change-${i.question_number}`} className="rounded-lg border border-sage-200 dark:border-[#2A2A2A] p-3 space-y-2">
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-semibold text-sage-800 dark:text-white">Question {i.question_number}</span>
                <Badge variant={statusVariant(i.status)} dot>{i.status}</Badge>
                {i.replaced && <Badge variant="Attention">Replaced question</Badge>}
                {i.status === 'MODIFIED' && <span className="text-xs text-sage-500">{i.changes.length} field{i.changes.length === 1 ? '' : 's'} changed</span>}
              </div>
              {i.status === 'UNCHANGED' ? <p className="text-sm text-sage-600 dark:text-sage-400 whitespace-pre-wrap">{i.to?.question_text}</p>
                : <VersionDiff fromLabel={fromLabel} toLabel={toLabel} fromText={i.from?.question_text ?? null} toText={i.to?.question_text ?? null} changes={i.changes} />}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
};
