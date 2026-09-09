import React from 'react';
import { AlertTriangle, CheckCircle2, MinusCircle, RotateCcw, Sparkles } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { AIGradingResult as AIGradingResultType, FinalGradePayload } from '@/types/grading';
import { RubricAlignment } from '@/types/rubricAlignment';
import { StudentAnswer } from '@/types/submission';
import { SuggestedMarksCard } from './SuggestedMarksCard';
import { CriterionGradingBreakdown } from './CriterionGradingBreakdown';
import { FacultyGradeEditor } from './FacultyGradeEditor';
import { GradingDisclaimer } from './GradingDisclaimer';

interface AIGradingResultProps {
  result: AIGradingResultType;
  answer: StudentAnswer;
  maxMarks: number;
  readOnly?: boolean;
  isRegenerating?: boolean;
  onRegenerate: () => void;
  onFinalize: (data: FinalGradePayload) => Promise<void>;
  onReject: () => Promise<void>;
  /** STEP 28: separate alignment signal (never merged into marks). */
  alignment?: RubricAlignment | null;
}

const DECISION_LABEL: Record<string, string> = {
  ACCEPTED: 'Faculty accepted the AI suggestion',
  MODIFIED: 'Faculty modified the AI suggestion',
  REJECTED: 'Faculty rejected the AI suggestion',
};

/**
 * Completed AI grading suggestion with criterion breakdown, evidence, and faculty review controls.
 */
export const AIGradingResult: React.FC<AIGradingResultProps> = ({
  result,
  answer,
  maxMarks,
  readOnly = false,
  isRegenerating = false,
  onRegenerate,
  onFinalize,
  onReject,
  alignment,
}) => (
  <section
    className="rounded-xl border border-[#E5E5E5] dark:border-[#2C2C2E] bg-white dark:bg-[#1C1C1E] p-4 space-y-4"
    data-testid="ai-grading-result"
    aria-label="AI grading assistance"
  >
    <header className="flex flex-wrap items-center justify-between gap-2">
      <h4 className="text-xs font-bold uppercase tracking-wider text-[#111111] dark:text-white flex items-center gap-2">
        <Sparkles className="w-3.5 h-3.5 text-amber-500" /> AI Grading Assistance
      </h4>
      <div className="flex items-center gap-2 flex-wrap">
        {result.faculty_decision && (
          <Badge variant={result.faculty_decision === 'REJECTED' ? 'neutral' : 'Good'} className="text-[10px]" data-testid="faculty-decision">
            {DECISION_LABEL[result.faculty_decision] ?? result.faculty_decision}
          </Badge>
        )}
        {!readOnly && (
          <Button
            variant="ghost"
            size="sm"
            leftIcon={<RotateCcw className={`w-3.5 h-3.5 ${isRegenerating ? 'animate-spin' : ''}`} />}
            onClick={onRegenerate}
            disabled={isRegenerating}
            data-testid="regenerate-grading"
          >
            Regenerate
          </Button>
        )}
      </div>
    </header>

    {result.is_stale && (
      <div className="p-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 flex items-start gap-2 text-amber-800 dark:text-amber-300" role="alert" data-testid="stale-warning">
        <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
        <div className="text-xs space-y-0.5">
          <p className="font-semibold">AI grading result may be outdated.</p>
          {result.stale_reasons.map((r, i) => <p key={i}>{r}</p>)}
          <p>Regenerate to evaluate the current answer and rubric.</p>
        </div>
      </div>
    )}

    <SuggestedMarksCard result={result} facultyMarks={answer.awarded_marks} alignment={alignment} />

    {result.overall_feedback && (
      <div className="space-y-1">
        <span className="block text-[10px] uppercase tracking-wider font-semibold text-[#737373]">AI Assessment</span>
        <p className="text-xs text-[#262626] dark:text-[#E5E5E5] leading-relaxed" data-testid="ai-feedback">{result.overall_feedback}</p>
      </div>
    )}

    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div className="space-y-1">
        <span className="block text-[10px] uppercase tracking-wider font-semibold text-[#737373]">Strengths</span>
        {result.strengths.length > 0 ? (
          <ul className="space-y-1" data-testid="ai-strengths">
            {result.strengths.map((s, i) => (
              <li key={i} className="flex items-start gap-1.5 text-xs text-[#262626] dark:text-[#E5E5E5]">
                <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600 shrink-0 mt-0.5" /> {s}
              </li>
            ))}
          </ul>
        ) : <p className="text-xs text-[#737373] italic">No specific strengths were identified.</p>}
      </div>
      <div className="space-y-1">
        <span className="block text-[10px] uppercase tracking-wider font-semibold text-[#737373]">Missing</span>
        {result.missing_elements.length > 0 ? (
          <ul className="space-y-1" data-testid="ai-missing">
            {result.missing_elements.map((m, i) => (
              <li key={i} className="flex items-start gap-1.5 text-xs text-[#262626] dark:text-[#E5E5E5]">
                <MinusCircle className="w-3.5 h-3.5 text-amber-600 shrink-0 mt-0.5" /> {m}
              </li>
            ))}
          </ul>
        ) : <p className="text-xs text-[#737373] italic">No missing elements were identified.</p>}
      </div>
    </div>

    <CriterionGradingBreakdown criteria={result.criterion_results} />

    {result.evaluation_summary && (
      <p className="text-[11px] text-[#737373] leading-relaxed" data-testid="ai-summary">{result.evaluation_summary}</p>
    )}

    <div className="pt-3 border-t border-[#E5E5E5] dark:border-[#2C2C2E] space-y-3">
      <GradingDisclaimer variant="block" />
      <FacultyGradeEditor
        answer={answer}
        result={result}
        maxMarks={maxMarks}
        readOnly={readOnly}
        onFinalize={onFinalize}
        onReject={onReject}
      />
    </div>

    {result.model_name && (
      <p className="text-[10px] text-[#737373]">
        Engine: {result.model_name}{result.model_version ? ` v${result.model_version}` : ''}{result.generation_method ? ` · ${result.generation_method.replace(/_/g, ' ')}` : ''}
      </p>
    )}
  </section>
);
