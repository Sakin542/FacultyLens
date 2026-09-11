import React, { useMemo, useState } from 'react';
import { AlertCircle, Save, X } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { EditableCriterion, Rubric, RubricUpdatePayload } from '@/types/rubric';
import { RubricCriteriaEditor } from './RubricCriteriaEditor';
import { criteriaTotal, formatMarks, marksMatch, toEditable, toPayload, validateEditable } from './rubricMath';

interface RubricEditorProps {
  rubric: Rubric;
  questionMarks: number;
  onSave: (payload: RubricUpdatePayload) => Promise<void>;
  onCancel: () => void;
  isSaving?: boolean;
}

/**
 * Faculty editing surface for a draft rubric. The live total is recalculated on
 * every change and saving is blocked until it equals the question's marks.
 */
export const RubricEditor: React.FC<RubricEditorProps> = ({ rubric, questionMarks, onSave, onCancel, isSaving = false }) => {
  const [title, setTitle] = useState(rubric.title);
  const [generalGuidance, setGeneralGuidance] = useState(rubric.general_guidance ?? '');
  const [criteria, setCriteria] = useState<EditableCriterion[]>(() => toEditable(rubric.criteria));
  const [showErrors, setShowErrors] = useState(false);

  const total = useMemo(() => criteriaTotal(criteria), [criteria]);
  const isConsistent = marksMatch(total, questionMarks);
  const errors = useMemo(() => validateEditable(criteria, questionMarks, title), [criteria, questionMarks, title]);
  const canSave = errors.length === 0 && !isSaving;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setShowErrors(true);
    if (errors.length > 0) return;
    await onSave({
      title: title.trim(),
      general_guidance: generalGuidance.trim() || null,
      criteria: toPayload(criteria),
    });
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4" data-testid="rubric-editor" noValidate>
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-bold text-sage-800 dark:text-white">Edit Rubric Draft</h3>
        <div className="text-right">
          <p className="text-[10px] uppercase tracking-wider text-sage-500">Total Criterion Marks</p>
          <p
            className={`text-sm font-mono font-bold ${isConsistent ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'}`}
            data-testid="editor-total"
          >
            {formatMarks(total)} / {formatMarks(questionMarks)}
          </p>
        </div>
      </div>

      <Input
        id="rubric-title"
        label="Rubric title"
        value={title}
        onChange={(e) => setTitle(e.target.value)}
        maxLength={255}
        required
        disabled={isSaving}
      />

      <RubricCriteriaEditor criteria={criteria} onChange={setCriteria} disabled={isSaving} />

      <div className="space-y-1.5">
        <label htmlFor="rubric-general-guidance" className="block text-[10px] font-medium uppercase tracking-wider text-sage-700 dark:text-sage-200">
          General guidance
        </label>
        <textarea
          id="rubric-general-guidance"
          rows={3}
          maxLength={2000}
          value={generalGuidance}
          onChange={(e) => setGeneralGuidance(e.target.value)}
          disabled={isSaving}
          placeholder="Overall marking notes for this question"
          className="w-full rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] px-3 py-2 text-xs text-sage-800 dark:text-white placeholder:text-sage-400 focus:outline-none focus:ring-2 focus:ring-sage-600 dark:focus:ring-white focus:border-transparent"
        />
      </div>

      {!isConsistent && criteria.length > 0 && (
        <div className="p-3 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 rounded-xl text-xs text-red-700 dark:text-red-300 space-y-0.5" role="alert" data-testid="marks-mismatch">
          <p className="font-semibold">Rubric total does not match the question&apos;s total marks.</p>
          <p>Question marks: {formatMarks(questionMarks)}</p>
          <p>Rubric marks: {formatMarks(total)}</p>
          <p>Please adjust the criteria before saving.</p>
        </div>
      )}

      {showErrors && errors.length > 0 && (
        <ul className="p-3 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 rounded-xl text-xs text-amber-800 dark:text-amber-300 space-y-1" role="alert">
          {errors.map((err, i) => (
            <li key={i} className="flex items-start gap-2">
              <AlertCircle className="w-3.5 h-3.5 shrink-0 mt-0.5" />
              <span>{err}</span>
            </li>
          ))}
        </ul>
      )}

      <p className="text-[11px] text-sage-500 italic">
        AI-generated rubric. Review and adjust before use.
      </p>

      <div className="flex items-center justify-end gap-2 pt-2 border-t border-sage-200 dark:border-[#2C2C2E]">
        <Button type="button" variant="ghost" size="sm" leftIcon={<X className="w-3.5 h-3.5" />} onClick={onCancel} disabled={isSaving}>
          Cancel
        </Button>
        <Button type="submit" variant="primary" size="sm" leftIcon={<Save className="w-3.5 h-3.5" />} disabled={!canSave} isLoading={isSaving}>
          Save Draft
        </Button>
      </div>
    </form>
  );
};
