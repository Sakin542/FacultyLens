import React from 'react';
import { AlertTriangle, CheckCircle2, RotateCcw, Target } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { AIGradingResult } from '@/types/grading';
import { RubricAlignment } from '@/types/rubricAlignment';
import { RubricAlignmentSummary } from './RubricAlignmentSummary';
import { CriterionAlignmentList } from './CriterionAlignmentList';
import { MissingElements } from './MissingElements';
import { AlignmentDisclaimer, ALIGNMENT_NOT_CORRECTNESS } from './AlignmentDisclaimer';

interface RubricAlignmentCardProps {
  alignment: RubricAlignment;
  aiGrading?: AIGradingResult | null;
  readOnly?: boolean;
  isRegenerating?: boolean;
  isReviewing?: boolean;
  onRegenerate: () => void;
  onMarkReviewed: () => void;
}

/** Completed Answer <-> Rubric alignment analysis with criterion breakdown and faculty controls. */
export const RubricAlignmentCard: React.FC<RubricAlignmentCardProps> = ({
  alignment,
  aiGrading,
  readOnly = false,
  isRegenerating = false,
  isReviewing = false,
  onRegenerate,
  onMarkReviewed,
}) => (
  <section
    className="rounded-xl border border-[#E5E5E5] dark:border-[#2C2C2E] bg-white dark:bg-[#1C1C1E] p-4 space-y-4"
    data-testid="rubric-alignment-card"
    aria-label="Answer rubric alignment"
  >
    <header className="flex flex-wrap items-center justify-between gap-2">
      <h4 className="text-xs font-bold uppercase tracking-wider text-[#111111] dark:text-white flex items-center gap-2">
        <Target className="w-3.5 h-3.5 text-[#737373]" /> Answer ↔ Rubric Alignment
      </h4>
      <div className="flex items-center gap-2 flex-wrap">
        {alignment.analysis_status === 'REVIEWED' && (
          <Badge variant="Good" className="text-[10px]" data-testid="alignment-reviewed">Faculty reviewed</Badge>
        )}
        {alignment.rubric_version && <span className="text-[10px] text-[#737373]">Rubric v{alignment.rubric_version}</span>}
        {!readOnly && (
          <Button variant="ghost" size="sm" leftIcon={<RotateCcw className={`w-3.5 h-3.5 ${isRegenerating ? 'animate-spin' : ''}`} />} onClick={onRegenerate} disabled={isRegenerating} data-testid="regenerate-alignment">
            Regenerate
          </Button>
        )}
      </div>
    </header>

    {alignment.is_stale && (
      <div className="p-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 flex items-start gap-2 text-amber-800 dark:text-amber-300" role="alert" data-testid="alignment-stale-warning">
        <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
        <div className="text-xs space-y-0.5">
          <p className="font-semibold">Alignment analysis may be outdated.</p>
          {alignment.stale_reasons.map((r, i) => <p key={i}>{r}</p>)}
          <p>Regenerate to analyze the current answer and rubric.</p>
        </div>
      </div>
    )}

    <RubricAlignmentSummary alignment={alignment} aiGrading={aiGrading} />

    <CriterionAlignmentList items={alignment.criterion_alignments} />

    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div>
        <span className="block text-[10px] uppercase tracking-wider text-[#737373] mb-1">Strengths</span>
        {alignment.strengths.length > 0 ? (
          <ul className="space-y-1" data-testid="alignment-strengths">
            {alignment.strengths.map((s, i) => (
              <li key={i} className="flex items-start gap-1.5 text-xs text-[#262626] dark:text-[#E5E5E5]">
                <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600 shrink-0 mt-0.5" /> {s}
              </li>
            ))}
          </ul>
        ) : <p className="text-xs text-[#737373] italic">No strongly aligned criteria were identified.</p>}
      </div>
      <MissingElements items={alignment.missing_elements} title="Missing across criteria" />
    </div>

    <p className="text-[11px] text-[#737373]">{ALIGNMENT_NOT_CORRECTNESS}</p>

    <div className="pt-3 border-t border-[#E5E5E5] dark:border-[#2C2C2E] flex flex-wrap items-center justify-between gap-2">
      <AlignmentDisclaimer className="max-w-lg" />
      {!readOnly && alignment.analysis_status !== 'REVIEWED' && (
        <Button variant="outline" size="sm" leftIcon={<CheckCircle2 className="w-3.5 h-3.5" />} onClick={onMarkReviewed} isLoading={isReviewing} data-testid="mark-alignment-reviewed">
          Mark as Reviewed
        </Button>
      )}
    </div>

    {alignment.model_name && (
      <p className="text-[10px] text-[#737373]">
        Engine: {alignment.model_name}{alignment.model_version ? ` v${alignment.model_version}` : ''}{alignment.analysis_method ? ` · ${alignment.analysis_method.replace(/_/g, ' ')}` : ''}
        {alignment.thresholds ? ` · thresholds ${alignment.thresholds.strong}/${alignment.thresholds.partial}/${alignment.thresholds.weak}` : ''}
      </p>
    )}
  </section>
);
