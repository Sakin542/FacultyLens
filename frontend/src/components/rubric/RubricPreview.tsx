import React from 'react';
import { Sparkles, CheckCircle2, Edit, RotateCcw, Trash2 } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { Rubric } from '@/types/rubric';
import { RubricStatusBadge } from './RubricStatusBadge';
import { formatMarks, marksMatch } from './rubricMath';
import { WhyButton } from '@/components/explainability/WhyButton';
import { useExplanationModal } from '@/hooks/useExplanationModal';

interface RubricPreviewProps {
  rubric: Rubric;
  questionMarks: number;
  questionText?: string;
  onEdit?: () => void;
  onApprove?: () => void;
  onRegenerate?: () => void;
  onDelete?: () => void;
  isApproving?: boolean;
  isRegenerating?: boolean;
  isDeleting?: boolean;
}

export const RubricPreview: React.FC<RubricPreviewProps> = ({
  rubric,
  questionMarks,
  questionText,
  onEdit,
  onApprove,
  onRegenerate,
  onDelete,
  isApproving = false,
  isRegenerating = false,
  isDeleting = false,
}) => {
  const total = rubric.criteria_total ?? rubric.criteria.reduce((s, c) => s + Number(c.max_marks || 0), 0);
  const isConsistent = marksMatch(total, questionMarks);
  const busy = isApproving || isRegenerating || isDeleting;
  const isReadOnly = rubric.status === 'ARCHIVED';
  const { explain, modal: explanationModal } = useExplanationModal();

  return (
    <div className="space-y-4" data-testid="rubric-preview">
      {explanationModal}
      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="space-y-1">
          <div className="flex items-center gap-2 flex-wrap">
            {rubric.is_ai_generated && (
              <Badge variant="outline" className="text-[10px] uppercase tracking-wide">
                <Sparkles className="w-3 h-3 mr-1 text-amber-500" />
                AI-generated rubric
              </Badge>
            )}
            <RubricStatusBadge status={rubric.status} />
            <Badge variant="neutral" className="text-[10px] font-mono">
              v{rubric.version}
            </Badge>
            {rubric.id > 0 && <WhyButton describes="how this rubric was generated and validated" onClick={() => explain('rubric', rubric.id, 'Rubric')} />}
          </div>
          <h3 className="text-sm font-bold text-sage-800 dark:text-white">{rubric.title}</h3>
          {rubric.status === 'DRAFT' && (
            <p className="text-[11px] text-amber-700 dark:text-amber-400 font-medium">
              Draft — Faculty review required
            </p>
          )}
          {rubric.status === 'APPROVED' && rubric.approved_at && (
            <p className="text-[11px] text-emerald-700 dark:text-emerald-400 font-medium">
              Faculty-approved on {new Date(rubric.approved_at).toLocaleDateString()}
            </p>
          )}
        </div>
        <div className="text-right">
          <p className="text-[10px] uppercase tracking-wider text-sage-500">Total Marks</p>
          <p
            className={`text-sm font-mono font-bold ${isConsistent ? 'text-sage-800 dark:text-white' : 'text-red-600'}`}
            data-testid="rubric-total"
          >
            {formatMarks(total)} / {formatMarks(questionMarks)}
          </p>
        </div>
      </div>

      {questionText && (
        <div className="p-3 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl border border-sage-200 dark:border-[#3A3A3C]">
          <p className="text-[10px] uppercase tracking-wider text-sage-500 mb-1">Question</p>
          <p className="text-xs text-sage-700 dark:text-sage-200 leading-relaxed">{questionText}</p>
        </div>
      )}

      {!isConsistent && (
        <div className="p-3 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 rounded-xl text-xs text-red-700 dark:text-red-300" role="alert">
          Rubric total does not match the question&apos;s total marks. Question marks: {formatMarks(questionMarks)} · Rubric marks: {formatMarks(total)}. Edit the rubric before approving.
        </div>
      )}

      {/* Criteria */}
      <ol className="space-y-2">
        {rubric.criteria.map((c, idx) => (
          <li
            key={c.id ?? idx}
            className="p-3 rounded-xl border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] space-y-1.5"
            data-testid="rubric-criterion"
          >
            <div className="flex items-start justify-between gap-3">
              <div className="space-y-0.5">
                <p className="text-[10px] uppercase tracking-wider text-sage-500">Suggested criterion {c.sort_order}</p>
                <p className="text-xs font-bold text-sage-800 dark:text-white">{c.criterion}</p>
              </div>
              <span className="text-xs font-mono font-bold text-sage-800 dark:text-white whitespace-nowrap">
                {formatMarks(c.max_marks)} {Number(c.max_marks) === 1 ? 'mark' : 'marks'}
              </span>
            </div>
            <p className="text-xs text-sage-700 dark:text-sage-200 leading-relaxed">{c.description}</p>
            {c.scoring_guidance && (
              <p className="text-[11px] text-sage-500 leading-relaxed">
                <span className="font-semibold text-sage-800 dark:text-white">Scoring guidance: </span>
                {c.scoring_guidance}
              </p>
            )}
            {c.expected_indicators && c.expected_indicators.length > 0 && (
              <div className="flex flex-wrap items-center gap-1.5 pt-0.5">
                <span className="text-[10px] font-semibold text-sage-800 dark:text-white">Expected indicators:</span>
                {c.expected_indicators.map((ind, i) => (
                  <span
                    key={i}
                    className="px-2 py-0.5 rounded bg-sage-100 dark:bg-[#2C2C2E] border border-sage-200 dark:border-[#3A3A3C] text-[10px] text-sage-700 dark:text-sage-200"
                  >
                    {ind}
                  </span>
                ))}
              </div>
            )}
          </li>
        ))}
      </ol>

      {rubric.general_guidance && (
        <div className="p-3 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl border border-sage-200 dark:border-[#3A3A3C]">
          <p className="text-[10px] uppercase tracking-wider text-sage-500 mb-1">General guidance</p>
          <p className="text-xs text-sage-700 dark:text-sage-200 leading-relaxed">{rubric.general_guidance}</p>
        </div>
      )}

      <p className="text-[11px] text-sage-500 italic">
        AI-generated rubric. Review and adjust before use. Faculty retain full responsibility for the final grading criteria.
      </p>

      {/* Actions */}
      {!isReadOnly && (
        <div className="flex flex-wrap items-center justify-end gap-2 pt-2 border-t border-sage-200 dark:border-[#2C2C2E]">
          {onDelete && (
            <Button variant="ghost" size="sm" leftIcon={<Trash2 className="w-3.5 h-3.5" />} onClick={onDelete} disabled={busy} isLoading={isDeleting}>
              Delete
            </Button>
          )}
          {onRegenerate && (
            <Button variant="outline" size="sm" leftIcon={<RotateCcw className="w-3.5 h-3.5" />} onClick={onRegenerate} disabled={busy} isLoading={isRegenerating}>
              Regenerate
            </Button>
          )}
          {onEdit && (
            <Button variant="outline" size="sm" leftIcon={<Edit className="w-3.5 h-3.5" />} onClick={onEdit} disabled={busy}>
              Edit Rubric
            </Button>
          )}
          {onApprove && rubric.status === 'DRAFT' && (
            <Button
              variant="primary"
              size="sm"
              leftIcon={<CheckCircle2 className="w-3.5 h-3.5" />}
              onClick={onApprove}
              disabled={busy || !isConsistent || rubric.criteria.length === 0}
              isLoading={isApproving}
              title={!isConsistent ? 'Rubric marks must match the question marks before approval' : undefined}
            >
              Approve Rubric
            </Button>
          )}
        </div>
      )}
    </div>
  );
};
