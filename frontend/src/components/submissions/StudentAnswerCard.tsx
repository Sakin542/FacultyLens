import React, { useState } from 'react';
import { AlertCircle, ClipboardList, Download, Edit, FileText, Image as ImageIcon, Plus, Save, Trash2, X } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { ANSWER_STATUSES, AnswerPayload, AnswerStatus, StudentAnswer, SubmissionQuestion } from '@/types/submission';
import { AnswerStatusBadge, formatAnswerStatus } from './SubmissionStatusBadge';
import { AnswerTextEditor } from './AnswerTextEditor';
import { AnswerUpload } from './AnswerUpload';
import { AIGradingPanel } from '@/components/grading/AIGradingPanel';
import { RubricAlignmentPanel } from '@/components/rubricAlignment/RubricAlignmentPanel';

interface StudentAnswerCardProps {
  question: SubmissionQuestion;
  readOnly?: boolean;
  onAdd?: (questionId: number, data: AnswerPayload, file: File | null) => Promise<void>;
  onUpdate?: (answer: StudentAnswer, data: AnswerPayload, file: File | null) => Promise<void>;
  onDelete?: (answer: StudentAnswer) => Promise<void>;
  onDownload?: (answer: StudentAnswer) => Promise<void>;
  onViewRubric?: (rubricId: number) => void;
  /** STEP 27: called after faculty final marks are saved through the AI grading panel. */
  onGradeSaved?: () => Promise<void> | void;
  /** Disable the AI grading panel (e.g. in isolated tests). */
  showAIGrading?: boolean;
  /** STEP 28: disable the Answer <-> Rubric alignment panel. */
  showRubricAlignment?: boolean;
}

function formatBytes(bytes?: number | null) {
  if (!bytes) return '';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

/**
 * One question of the submission with the student's answer and faculty controls.
 * Faculty marks are entered here; AI suggestions (STEP 27) appear in a separate panel below.
 */
export const StudentAnswerCard: React.FC<StudentAnswerCardProps> = ({
  question,
  readOnly = false,
  onAdd,
  onUpdate,
  onDelete,
  onDownload,
  onViewRubric,
  onGradeSaved,
  showAIGrading = true,
  showRubricAlignment = true,
}) => {
  const answer = question.answer;
  const [mode, setMode] = useState<'view' | 'edit'>('view');
  const [text, setText] = useState(answer?.answer_text ?? '');
  const [marks, setMarks] = useState(answer?.awarded_marks !== null && answer?.awarded_marks !== undefined ? String(answer.awarded_marks) : '');
  const [feedback, setFeedback] = useState(answer?.faculty_feedback ?? '');
  const [status, setStatus] = useState<AnswerStatus>(answer?.answer_status ?? 'NOT_REVIEWED');
  const [file, setFile] = useState<File | null>(null);
  const [removeFile, setRemoveFile] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [showOriginal, setShowOriginal] = useState(false);

  const startEdit = () => {
    setText(answer?.answer_text ?? '');
    setMarks(answer?.awarded_marks !== null && answer?.awarded_marks !== undefined ? String(answer.awarded_marks) : '');
    setFeedback(answer?.faculty_feedback ?? '');
    setStatus(answer?.answer_status ?? 'NOT_REVIEWED');
    setFile(null);
    setRemoveFile(false);
    setError(null);
    setMode('edit');
  };

  const validate = (): string | null => {
    const trimmed = text.trim();
    const keepsFile = answer?.has_file && !removeFile;
    if (!trimmed && !file && !keepsFile) return 'Provide an answer text or an answer file.';
    if (marks.trim() !== '') {
      const n = Number(marks);
      if (!Number.isFinite(n)) return 'Awarded marks must be a number.';
      if (n < 0) return 'Awarded marks cannot be negative.';
      if (n > question.marks) return `Awarded marks cannot exceed ${question.marks} for this question.`;
    }
    return null;
  };

  const handleSave = async () => {
    const v = validate();
    if (v) { setError(v); return; }
    const payload: AnswerPayload = {
      answer_text: text.trim() || null,
      awarded_marks: marks.trim() === '' ? null : Number(marks),
      faculty_feedback: feedback.trim() || null,
      answer_status: status,
      remove_file: removeFile || undefined,
    };
    try {
      setBusy(true);
      setError(null);
      if (answer && onUpdate) await onUpdate(answer, payload, file);
      else if (!answer && onAdd) await onAdd(question.id, payload, file);
      setMode('view');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The answer could not be saved.');
    } finally {
      setBusy(false);
    }
  };

  const handleDelete = async () => {
    if (!answer || !onDelete) return;
    if (!window.confirm(`Delete the answer to Q${question.question_number ?? ''}? This cannot be undone.`)) return;
    try {
      setBusy(true);
      await onDelete(answer);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The answer could not be deleted.');
    } finally {
      setBusy(false);
    }
  };

  const FileIcon = answer?.answer_type === 'IMAGE' ? ImageIcon : FileText;

  return (
    <article className="p-4 rounded-xl border border-[#E5E5E5] dark:border-[#2C2C2E] bg-white dark:bg-[#1C1C1E] space-y-3" data-testid="student-answer-card">
      {/* Question header */}
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div className="space-y-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <span className="text-xs font-bold font-mono text-[#111111] dark:text-white">Question {question.question_number ?? question.id}</span>
            <Badge variant="neutral" className="text-[10px] capitalize">{question.question_type.replace(/_/g, ' ')}</Badge>
            <span className="text-[11px] font-mono text-[#737373]">{question.marks} marks</span>
            {question.approved_rubric ? (
              <button
                type="button"
                onClick={() => onViewRubric?.(question.approved_rubric!.id)}
                className="inline-flex items-center gap-1 text-[10px] font-medium text-emerald-700 dark:text-emerald-400 hover:underline"
                data-testid="rubric-available"
              >
                <ClipboardList className="w-3 h-3" /> Rubric: Approved (v{question.approved_rubric.version})
              </button>
            ) : (
              <span className="text-[10px] text-[#737373]">No approved rubric</span>
            )}
          </div>
          <p className="text-xs text-[#262626] dark:text-[#E5E5E5] leading-relaxed">{question.question_text}</p>
        </div>
        {answer && <AnswerStatusBadge status={answer.answer_status} />}
      </header>

      {/* Body */}
      {mode === 'edit' ? (
        <div className="space-y-3 pt-2 border-t border-[#E5E5E5] dark:border-[#2C2C2E]">
          <AnswerTextEditor
            id={`answer-text-${question.id}`}
            value={text}
            onChange={setText}
            disabled={busy}
            helperText={answer?.answer_text && text !== answer.answer_text ? 'Editing will mark this as a faculty-edited answer; the original is preserved.' : undefined}
          />

          <div className="space-y-1.5">
            <span className="block text-[10px] font-medium uppercase tracking-wider text-[#262626] dark:text-[#E5E5E5]">Answer file</span>
            {answer?.has_file && !removeFile && !file ? (
              <div className="flex items-center justify-between gap-2 p-2 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] text-xs">
                <span className="flex items-center gap-2 truncate"><FileIcon className="w-3.5 h-3.5" /> {answer.answer_file_name}</span>
                <button type="button" onClick={() => setRemoveFile(true)} className="text-[#737373] hover:text-red-600" aria-label="Remove answer file" disabled={busy}>
                  <X className="w-3.5 h-3.5" />
                </button>
              </div>
            ) : (
              <AnswerUpload file={file} onChange={setFile} onError={setError} disabled={busy} compact />
            )}
            {removeFile && !file && (
              <p className="text-[11px] text-amber-700 dark:text-amber-400">The current file will be removed when you save.</p>
            )}
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-[140px_1fr] gap-3">
            <Input
              id={`marks-${question.id}`}
              label={`Marks (max ${question.marks})`}
              type="number"
              inputMode="decimal"
              step="0.5"
              min={0}
              max={question.marks}
              value={marks}
              onChange={(e) => setMarks(e.target.value)}
              placeholder="Not graded"
              disabled={busy}
              className="font-mono"
            />
            <div className="space-y-1.5">
              <label htmlFor={`status-${question.id}`} className="block text-[10px] font-medium uppercase tracking-wider text-[#262626] dark:text-[#E5E5E5]">Review status</label>
              <select
                id={`status-${question.id}`}
                value={status}
                onChange={(e) => setStatus(e.target.value as AnswerStatus)}
                disabled={busy}
                className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] px-3 py-2 text-xs text-[#111111] dark:text-white"
              >
                {ANSWER_STATUSES.map((s) => <option key={s} value={s}>{formatAnswerStatus(s)}</option>)}
              </select>
            </div>
          </div>

          <AnswerTextEditor
            id={`feedback-${question.id}`}
            label="Faculty feedback (optional)"
            value={feedback}
            onChange={setFeedback}
            rows={2}
            maxLength={5000}
            placeholder="Notes for this answer…"
            disabled={busy}
          />

          {error && (
            <div className="p-2.5 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 rounded-lg flex items-center gap-2 text-red-700 dark:text-red-300 text-xs" role="alert">
              <AlertCircle className="w-3.5 h-3.5 shrink-0" /> {error}
            </div>
          )}

          <div className="flex items-center justify-end gap-2">
            <Button variant="ghost" size="sm" leftIcon={<X className="w-3.5 h-3.5" />} onClick={() => setMode('view')} disabled={busy}>Cancel</Button>
            <Button variant="primary" size="sm" leftIcon={<Save className="w-3.5 h-3.5" />} onClick={handleSave} isLoading={busy}>
              {answer ? 'Save Changes' : 'Save Answer'}
            </Button>
          </div>
        </div>
      ) : answer ? (
        <div className="space-y-3 pt-2 border-t border-[#E5E5E5] dark:border-[#2C2C2E]">
          <div className="space-y-1">
            <div className="flex items-center gap-2">
              <span className="text-[10px] uppercase tracking-wider text-[#737373]">Student answer</span>
              {answer.is_faculty_edited && (
                <Badge variant="Attention" className="text-[10px]" data-testid="faculty-edited">Faculty-edited answer</Badge>
              )}
            </div>
            {answer.answer_text ? (
              <p className="text-xs text-[#262626] dark:text-[#E5E5E5] leading-relaxed whitespace-pre-wrap" data-testid="answer-text">{answer.answer_text}</p>
            ) : (
              <p className="text-xs text-[#737373] italic">No text answer.</p>
            )}
            {answer.is_faculty_edited && answer.original_answer_text && (
              <div>
                <button type="button" onClick={() => setShowOriginal((v) => !v)} className="text-[11px] text-[#737373] hover:text-[#111111] dark:hover:text-white underline">
                  {showOriginal ? 'Hide original answer' : 'Show original answer'}
                </button>
                {showOriginal && (
                  <p className="mt-1 p-2 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] text-xs text-[#262626] dark:text-[#E5E5E5] whitespace-pre-wrap" data-testid="original-answer-text">
                    {answer.original_answer_text}
                  </p>
                )}
              </div>
            )}
          </div>

          {answer.has_file && (
            <div className="flex items-center justify-between gap-2 p-2.5 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C]">
              <span className="flex items-center gap-2 text-xs text-[#262626] dark:text-[#E5E5E5] truncate">
                <FileIcon className="w-4 h-4 shrink-0" />
                <span className="truncate">{answer.answer_file_name}</span>
                <span className="text-[#737373] shrink-0">{formatBytes(answer.answer_file_size)}</span>
              </span>
              {onDownload && (
                <Button variant="outline" size="sm" leftIcon={<Download className="w-3.5 h-3.5" />} onClick={() => onDownload(answer)}>
                  Download
                </Button>
              )}
            </div>
          )}

          <div className="grid grid-cols-2 gap-3 text-xs">
            <div>
              <span className="block text-[10px] uppercase tracking-wider text-[#737373]">Marks</span>
              <span className="font-mono font-bold text-[#111111] dark:text-white" data-testid="answer-marks">
                {answer.awarded_marks !== null && answer.awarded_marks !== undefined ? `${answer.awarded_marks} / ${question.marks}` : 'Not graded'}
              </span>
            </div>
            {answer.faculty_feedback && (
              <div>
                <span className="block text-[10px] uppercase tracking-wider text-[#737373]">Faculty feedback</span>
                <p className="text-[#262626] dark:text-[#E5E5E5] whitespace-pre-wrap">{answer.faculty_feedback}</p>
              </div>
            )}
          </div>

          {error && <p className="text-xs text-red-600" role="alert">{error}</p>}

          {!readOnly && (
            <div className="flex items-center justify-end gap-2 pt-1">
              {onDelete && (
                <Button variant="ghost" size="sm" leftIcon={<Trash2 className="w-3.5 h-3.5" />} onClick={handleDelete} disabled={busy}>Delete</Button>
              )}
              {onUpdate && (
                <Button variant="outline" size="sm" leftIcon={<Edit className="w-3.5 h-3.5" />} onClick={startEdit} disabled={busy}>Edit Answer</Button>
              )}
            </div>
          )}

          {showAIGrading && (
            <AIGradingPanel question={question} answer={answer} readOnly={readOnly} onGradeSaved={onGradeSaved} alignment={answer.rubric_alignment ?? null} />
          )}
          {showRubricAlignment && (
            <RubricAlignmentPanel question={question} answer={answer} readOnly={readOnly} aiGrading={answer.ai_grading ?? null} />
          )}
        </div>
      ) : (
        <div className="flex items-center justify-between gap-2 pt-2 border-t border-[#E5E5E5] dark:border-[#2C2C2E]">
          <p className="text-xs text-[#737373] italic" data-testid="no-answer">No answer recorded for this question.</p>
          {!readOnly && onAdd && (
            <Button variant="outline" size="sm" leftIcon={<Plus className="w-3.5 h-3.5" />} onClick={startEdit}>Add Answer</Button>
          )}
        </div>
      )}
    </article>
  );
};
