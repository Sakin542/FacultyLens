import React, { useState } from 'react';
import { ArrowDown, ArrowUp, Plus, Trash2, X } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { EditableCriterion } from '@/types/rubric';
import { emptyCriterion } from './rubricMath';

interface RubricCriteriaEditorProps {
  criteria: EditableCriterion[];
  onChange: (criteria: EditableCriterion[]) => void;
  disabled?: boolean;
}

const textareaClass =
  'w-full rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] px-3 py-2 text-xs text-[#111111] dark:text-white placeholder:text-[#A3A3A3] focus:outline-none focus:ring-2 focus:ring-[#111111] dark:focus:ring-white focus:border-transparent disabled:bg-[#F7F7F5] disabled:cursor-not-allowed';

const labelClass = 'block text-[10px] font-medium uppercase tracking-wider text-[#262626] dark:text-[#E5E5E5]';

export const RubricCriteriaEditor: React.FC<RubricCriteriaEditorProps> = ({ criteria, onChange, disabled = false }) => {
  const [indicatorDrafts, setIndicatorDrafts] = useState<Record<string, string>>({});

  const updateAt = (index: number, patch: Partial<EditableCriterion>) => {
    onChange(criteria.map((c, i) => (i === index ? { ...c, ...patch } : c)));
  };

  const move = (index: number, direction: -1 | 1) => {
    const target = index + direction;
    if (target < 0 || target >= criteria.length) return;
    const next = [...criteria];
    [next[index], next[target]] = [next[target], next[index]];
    onChange(next);
  };

  const remove = (index: number) => onChange(criteria.filter((_, i) => i !== index));

  const add = () => onChange([...criteria, emptyCriterion()]);

  const addIndicator = (index: number) => {
    const key = criteria[index].key;
    const value = (indicatorDrafts[key] ?? '').trim();
    if (!value) return;
    updateAt(index, { expected_indicators: [...criteria[index].expected_indicators, value] });
    setIndicatorDrafts((d) => ({ ...d, [key]: '' }));
  };

  const removeIndicator = (index: number, indIdx: number) => {
    updateAt(index, {
      expected_indicators: criteria[index].expected_indicators.filter((_, i) => i !== indIdx),
    });
  };

  return (
    <div className="space-y-3" data-testid="rubric-criteria-editor">
      {criteria.map((c, index) => (
        <fieldset
          key={c.key}
          className="p-3 rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] space-y-3"
          data-testid="criterion-editor"
          disabled={disabled}
        >
          <div className="flex items-center justify-between">
            <legend className="text-[10px] uppercase tracking-wider text-[#737373] font-semibold">
              Criterion {index + 1}
            </legend>
            <div className="flex items-center gap-1">
              <button
                type="button"
                onClick={() => move(index, -1)}
                disabled={disabled || index === 0}
                aria-label={`Move criterion ${index + 1} up`}
                className="p-1 rounded text-[#737373] hover:text-[#111111] dark:hover:text-white disabled:opacity-30"
              >
                <ArrowUp className="w-3.5 h-3.5" />
              </button>
              <button
                type="button"
                onClick={() => move(index, 1)}
                disabled={disabled || index === criteria.length - 1}
                aria-label={`Move criterion ${index + 1} down`}
                className="p-1 rounded text-[#737373] hover:text-[#111111] dark:hover:text-white disabled:opacity-30"
              >
                <ArrowDown className="w-3.5 h-3.5" />
              </button>
              <button
                type="button"
                onClick={() => remove(index)}
                disabled={disabled}
                aria-label={`Remove criterion ${index + 1}`}
                className="p-1 rounded text-[#737373] hover:text-red-600 disabled:opacity-30"
              >
                <Trash2 className="w-3.5 h-3.5" />
              </button>
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-[1fr_110px] gap-3">
            <Input
              id={`criterion-${c.key}`}
              label="Criterion"
              value={c.criterion}
              onChange={(e) => updateAt(index, { criterion: e.target.value })}
              placeholder="e.g. Definition of normalization"
              maxLength={255}
              required
              disabled={disabled}
            />
            <Input
              id={`marks-${c.key}`}
              label="Marks"
              type="number"
              inputMode="decimal"
              step="0.5"
              min={0}
              value={c.max_marks}
              onChange={(e) => updateAt(index, { max_marks: e.target.value })}
              required
              disabled={disabled}
              className="font-mono"
            />
          </div>

          <div className="space-y-1.5">
            <label htmlFor={`description-${c.key}`} className={labelClass}>
              Description <span className="text-[#DC2626]">*</span>
            </label>
            <textarea
              id={`description-${c.key}`}
              rows={2}
              maxLength={2000}
              value={c.description}
              onChange={(e) => updateAt(index, { description: e.target.value })}
              placeholder="What a response must demonstrate for this criterion"
              className={textareaClass}
              disabled={disabled}
            />
          </div>

          <div className="space-y-1.5">
            <label htmlFor={`guidance-${c.key}`} className={labelClass}>
              Scoring guidance
            </label>
            <textarea
              id={`guidance-${c.key}`}
              rows={2}
              maxLength={2000}
              value={c.scoring_guidance}
              onChange={(e) => updateAt(index, { scoring_guidance: e.target.value })}
              placeholder="e.g. Full marks for a clear, accurate definition..."
              className={textareaClass}
              disabled={disabled}
            />
          </div>

          <div className="space-y-1.5">
            <span className={labelClass}>Expected indicators</span>
            <div className="flex flex-wrap gap-1.5">
              {c.expected_indicators.map((ind, indIdx) => (
                <span
                  key={indIdx}
                  className="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] text-[11px] text-[#262626] dark:text-[#E5E5E5]"
                >
                  {ind}
                  <button
                    type="button"
                    onClick={() => removeIndicator(index, indIdx)}
                    disabled={disabled}
                    aria-label={`Remove indicator ${ind}`}
                    className="text-[#737373] hover:text-red-600"
                  >
                    <X className="w-3 h-3" />
                  </button>
                </span>
              ))}
            </div>
            <div className="flex items-center gap-2">
              <input
                type="text"
                value={indicatorDrafts[c.key] ?? ''}
                onChange={(e) => setIndicatorDrafts((d) => ({ ...d, [c.key]: e.target.value }))}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    addIndicator(index);
                  }
                }}
                placeholder="Add an indicator and press Enter"
                maxLength={255}
                aria-label={`New indicator for criterion ${index + 1}`}
                disabled={disabled}
                className="flex-1 rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] px-3 py-1.5 text-xs text-[#111111] dark:text-white placeholder:text-[#A3A3A3] focus:outline-none focus:ring-2 focus:ring-[#111111] dark:focus:ring-white"
              />
              <Button type="button" variant="outline" size="sm" onClick={() => addIndicator(index)} disabled={disabled}>
                Add
              </Button>
            </div>
          </div>
        </fieldset>
      ))}

      <Button
        type="button"
        variant="outline"
        size="sm"
        leftIcon={<Plus className="w-3.5 h-3.5" />}
        onClick={add}
        disabled={disabled || criteria.length >= 12}
      >
        Add Criterion
      </Button>
    </div>
  );
};
