import React from 'react';
import { Sparkles } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { GradingDisclaimer } from './GradingDisclaimer';

interface AIGradingButtonProps {
  hasApprovedRubric: boolean;
  hasContent: boolean;
  isImageOnly?: boolean;
  disabled?: boolean;
  isLoading?: boolean;
  onClick: () => void;
}

/**
 * Entry point for requesting AI grading assistance. Disabled with an explanation when the
 * prerequisites (approved rubric, gradable answer content) are not met.
 */
export const AIGradingButton: React.FC<AIGradingButtonProps> = ({
  hasApprovedRubric,
  hasContent,
  isImageOnly = false,
  disabled = false,
  isLoading = false,
  onClick,
}) => {
  let reason: string | null = null;
  if (!hasApprovedRubric) {
    reason = 'An approved rubric is required before AI grading assistance can be requested.';
  } else if (isImageOnly) {
    reason = 'Image-based AI grading is not currently supported. Add the answer text to continue.';
  } else if (!hasContent) {
    reason = 'This answer has no content to evaluate.';
  }

  return (
    <div className="space-y-2" data-testid="ai-grading-request">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <GradingDisclaimer />
        <Button
          variant="primary"
          size="sm"
          leftIcon={<Sparkles className="w-3.5 h-3.5" />}
          onClick={onClick}
          disabled={disabled || reason !== null}
          isLoading={isLoading}
          data-testid="ai-grading-button"
        >
          Get AI Grading Assistance
        </Button>
      </div>
      {reason && (
        <p className="text-[11px] text-amber-700 dark:text-amber-400" data-testid="ai-grading-blocked">
          {reason}
        </p>
      )}
    </div>
  );
};
