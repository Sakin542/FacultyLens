import React, { useState } from 'react';
import { Check, Edit3, ThumbsDown } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { AIGradingResult, FinalGradePayload } from '@/types/grading';
import { StudentAnswer } from '@/types/submission';
import { FinalGradeForm } from './FinalGradeForm';

interface FacultyGradeEditorProps {
  answer: StudentAnswer;
  result: AIGradingResult;
  maxMarks: number;
  readOnly?: boolean;
  onFinalize: (data: FinalGradePayload) => Promise<void>;
  onReject: () => Promise<void>;
}

/**
 * Faculty review controls for an AI suggestion: accept, edit final marks, or reject.
 * Accepting copies the suggestion into the faculty marks; the AI result itself is never changed.
 */
export const FacultyGradeEditor: React.FC<FacultyGradeEditorProps> = ({
  answer,
  result,
  maxMarks,
  readOnly = false,
  onFinalize,
  onReject,
}) => {
  const [mode, setMode] = useState<'idle' | 'edit'>('idle');
  const [busy, setBusy] = useState<'accept' | 'reject' | null>(null);
  const [error, setError] = useState<string | null>(null);

  const canAccept = result.suggested_marks !== null && result.suggested_marks <= maxMarks && result.suggested_marks >= 0;
  const alreadyFinal = result.grading_status === 'FINALIZED';
  const rejected = result.faculty_decision === 'REJECTED';

  const run = async (kind: 'accept' | 'reject', fn: () => Promise<void>) => {
    try {
      setBusy(kind);
      setError(null);
      await fn();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The action could not be completed.');
    } finally {
      setBusy(null);
    }
  };

  if (readOnly) return null;

  if (mode === 'edit') {
    return (
      <div className="space-y-2" data-testid="faculty-grade-editor">
        <span className="block text-[10px] uppercase tracking-wider font-semibold text-sage-500">Faculty Final Marks</span>
        <FinalGradeForm
          answerId={answer.id}
          maxMarks={maxMarks}
          initialMarks={answer.awarded_marks ?? result.suggested_marks}
          initialFeedback={answer.faculty_feedback}
          suggestedMarks={result.suggested_marks}
          onSubmit={async (data) => { await onFinalize(data); setMode('idle'); }}
          onCancel={() => setMode('idle')}
        />
      </div>
    );
  }

  return (
    <div className="space-y-2" data-testid="faculty-grade-editor">
      {error && <p className="text-xs text-red-600" role="alert">{error}</p>}
      <div className="flex flex-wrap items-center justify-end gap-2">
        {!alreadyFinal && !rejected && (
          <Button
            variant="ghost"
            size="sm"
            leftIcon={<ThumbsDown className="w-3.5 h-3.5" />}
            onClick={() => run('reject', onReject)}
            isLoading={busy === 'reject'}
            disabled={busy !== null}
            data-testid="reject-suggestion"
          >
            Reject AI Suggestion
          </Button>
        )}
        <Button
          variant="outline"
          size="sm"
          leftIcon={<Edit3 className="w-3.5 h-3.5" />}
          onClick={() => setMode('edit')}
          disabled={busy !== null}
          data-testid="edit-final-marks"
        >
          {alreadyFinal || answer.awarded_marks !== null && answer.awarded_marks !== undefined ? 'Edit Final Marks' : 'Enter Final Marks'}
        </Button>
        {!alreadyFinal && !rejected && canAccept && (
          <Button
            variant="primary"
            size="sm"
            leftIcon={<Check className="w-3.5 h-3.5" />}
            onClick={() => run('accept', () => onFinalize({
              final_marks: result.suggested_marks as number,
              faculty_feedback: answer.faculty_feedback ?? null,
              decision: 'ACCEPTED',
            }))}
            isLoading={busy === 'accept'}
            disabled={busy !== null}
            data-testid="accept-suggestion"
          >
            Accept Suggested Marks
          </Button>
        )}
      </div>
    </div>
  );
};
