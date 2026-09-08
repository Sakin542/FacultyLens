import React, { useCallback, useEffect, useState } from 'react';
import { CheckCircle2, ClipboardList, Loader2, Sparkles, X } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import { QuestionDetail } from '@/types';
import { Rubric, RubricUpdatePayload } from '@/types/rubric';
import { rubricService } from '@/services/rubricService';
import { RubricPreview } from './RubricPreview';
import { RubricEditor } from './RubricEditor';
import { RubricVersionHistory } from './RubricVersionHistory';
import { RubricGenerationLoading } from './RubricGenerationLoading';
import { RubricError } from './RubricError';
import { formatMarks } from './rubricMath';

interface RubricGeneratorProps {
  isOpen: boolean;
  onClose: () => void;
  question: QuestionDetail | null;
  onRubricChanged?: () => void;
}

type Mode = 'preview' | 'edit';

/**
 * STEP 25: Question-level AI Rubric Generator.
 * Flow: Generate -> Draft -> Faculty reviews -> Edit / Save Draft -> Approve. Regeneration
 * always creates a new version; previous versions stay in the history list.
 */
export const RubricGenerator: React.FC<RubricGeneratorProps> = ({ isOpen, onClose, question, onRubricChanged }) => {
  const [rubrics, setRubrics] = useState<Rubric[]>([]);
  const [selected, setSelected] = useState<Rubric | null>(null);
  const [mode, setMode] = useState<Mode>('preview');
  const [isLoading, setIsLoading] = useState(false);
  const [isGenerating, setIsGenerating] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [isApproving, setIsApproving] = useState(false);
  const [isRegenerating, setIsRegenerating] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const questionId = question?.id;
  const questionMarks = Number(question?.marks ?? question?.maxMarks ?? 0);
  const questionText = question?.question_text || question?.text || '';
  const busy = isGenerating || isSaving || isApproving || isRegenerating || isDeleting;

  const applyList = useCallback((list: Rubric[], preferId?: number) => {
    setRubrics(list);
    const next = (preferId ? list.find((r) => r.id === preferId) : undefined) ?? list[0] ?? null;
    setSelected(next);
  }, []);

  const loadRubrics = useCallback(async () => {
    if (!questionId) return;
    try {
      setIsLoading(true);
      setError(null);
      const res = await rubricService.listForQuestion(questionId);
      applyList(res.data);
    } catch (err) {
      setError(err);
    } finally {
      setIsLoading(false);
    }
  }, [questionId, applyList]);

  useEffect(() => {
    if (isOpen && questionId) {
      setMode('preview');
      setNotice(null);
      loadRubrics();
    }
  }, [isOpen, questionId, loadRubrics]);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && isOpen && !busy) onClose();
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, busy, onClose]);

  if (!isOpen || !question) return null;

  const flash = (msg: string) => {
    setNotice(msg);
    setTimeout(() => setNotice(null), 4000);
  };

  const refreshAndSelect = async (preferId: number) => {
    const res = await rubricService.listForQuestion(question.id);
    applyList(res.data, preferId);
    onRubricChanged?.();
  };

  const handleGenerate = async () => {
    try {
      setIsGenerating(true);
      setError(null);
      const res = await rubricService.generate(question.id);
      await refreshAndSelect(res.data.id);
      setMode('preview');
      flash('Draft rubric generated. Review and adjust before approving.');
    } catch (err) {
      setError(err);
    } finally {
      setIsGenerating(false);
    }
  };

  const handleRegenerate = async () => {
    if (!selected) return;
    try {
      setIsRegenerating(true);
      setError(null);
      const res = await rubricService.regenerate(selected.id);
      await refreshAndSelect(res.data.id);
      setMode('preview');
      flash(`Draft version ${res.data.version} generated. Previous versions were kept.`);
    } catch (err) {
      setError(err);
    } finally {
      setIsRegenerating(false);
    }
  };

  const handleSave = async (payload: RubricUpdatePayload) => {
    if (!selected) return;
    try {
      setIsSaving(true);
      setError(null);
      const res = await rubricService.update(selected.id, payload);
      await refreshAndSelect(res.data.id);
      setMode('preview');
      flash('Rubric draft saved.');
    } catch (err) {
      setError(err);
    } finally {
      setIsSaving(false);
    }
  };

  const handleApprove = async () => {
    if (!selected) return;
    try {
      setIsApproving(true);
      setError(null);
      const res = await rubricService.approve(selected.id);
      await refreshAndSelect(res.data.id);
      flash('Rubric approved by faculty.');
    } catch (err) {
      setError(err);
    } finally {
      setIsApproving(false);
    }
  };

  const handleDelete = async () => {
    if (!selected) return;
    if (!window.confirm(`Delete rubric version ${selected.version}? This cannot be undone.`)) return;
    try {
      setIsDeleting(true);
      setError(null);
      await rubricService.delete(selected.id);
      const res = await rubricService.listForQuestion(question.id);
      applyList(res.data);
      onRubricChanged?.();
      flash('Rubric deleted.');
    } catch (err) {
      setError(err);
    } finally {
      setIsDeleting(false);
    }
  };

  const renderBody = () => {
    if (isLoading) {
      return (
        <div className="flex items-center justify-center py-10 text-[#737373]" role="status">
          <Loader2 className="w-5 h-5 animate-spin" />
        </div>
      );
    }
    if (isGenerating || isRegenerating) {
      return <RubricGenerationLoading />;
    }
    if (!selected) {
      return (
        <div className="p-8 text-center space-y-4 border border-dashed border-[#E5E5E5] dark:border-[#3A3A3C] rounded-2xl" data-testid="rubric-empty">
          <ClipboardList className="w-8 h-8 text-[#737373] mx-auto opacity-60" />
          <div className="space-y-1">
            <p className="text-sm font-bold text-[#111111] dark:text-white">No rubric yet</p>
            <p className="text-xs text-[#737373] max-w-sm mx-auto">
              FacultyLens can draft a marking rubric from this question&apos;s text, marks, type and learning outcome. You review and approve the final version.
            </p>
          </div>
          <Button
            variant="primary"
            size="sm"
            leftIcon={<Sparkles className="w-3.5 h-3.5" />}
            onClick={handleGenerate}
            disabled={busy || questionMarks <= 0}
            title={questionMarks <= 0 ? 'Set the question marks before generating a rubric' : undefined}
          >
            Generate AI Rubric
          </Button>
          {questionMarks <= 0 && (
            <p className="text-[11px] text-amber-700 dark:text-amber-400">This question has no marks allocated.</p>
          )}
        </div>
      );
    }
    if (mode === 'edit') {
      return (
        <RubricEditor
          key={selected.id}
          rubric={selected}
          questionMarks={questionMarks}
          onSave={handleSave}
          onCancel={() => setMode('preview')}
          isSaving={isSaving}
        />
      );
    }
    return (
      <RubricPreview
        rubric={selected}
        questionMarks={questionMarks}
        onEdit={() => setMode('edit')}
        onApprove={handleApprove}
        onRegenerate={handleRegenerate}
        onDelete={handleDelete}
        isApproving={isApproving}
        isRegenerating={isRegenerating}
        isDeleting={isDeleting}
      />
    );
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs animate-in fade-in duration-150">
      <div
        className="w-full max-w-3xl max-h-[90vh] flex flex-col bg-white dark:bg-[#1C1C1E] rounded-2xl shadow-2xl border border-[#E5E5E5] dark:border-[#2C2C2E] overflow-hidden"
        role="dialog"
        aria-modal="true"
        aria-labelledby="rubric-generator-title"
      >
        {/* Header */}
        <div className="flex items-start justify-between gap-3 p-4 border-b border-[#E5E5E5] dark:border-[#2C2C2E]">
          <div className="space-y-1.5 min-w-0">
            <div className="flex items-center gap-2">
              <Sparkles className="w-4 h-4 text-amber-500" />
              <h2 id="rubric-generator-title" className="text-sm font-bold text-[#111111] dark:text-white">
                AI Rubric Generator
              </h2>
            </div>
            <div className="flex items-center gap-1.5 flex-wrap">
              <span className="text-xs font-mono font-bold text-[#111111] dark:text-white">
                Q{question.question_number || question.questionNumber || ''}
              </span>
              <Badge variant="neutral" className="text-[10px] font-mono">{formatMarks(questionMarks)} marks</Badge>
              {(question.question_type || question.ai_question_type) && (
                <Badge variant="neutral" className="text-[10px] capitalize">
                  {(question.question_type || question.ai_question_type || '').replace(/_/g, ' ').toLowerCase()}
                </Badge>
              )}
              {(question.difficulty_level || question.difficulty) && (
                <Badge variant="neutral" className="text-[10px] capitalize">{question.difficulty_level || question.difficulty}</Badge>
              )}
              {(question.cognitive_level || question.cognitiveLevel || question.ai_cognitive_level) && (
                <Badge variant="outline" className="text-[10px]">
                  Bloom: {question.cognitive_level || question.cognitiveLevel || question.ai_cognitive_level}
                </Badge>
              )}
              {(question.learning_outcome?.code || question.learningOutcome?.code || question.learningOutcomeCode) && (
                <Badge variant="outline" className="text-[10px] font-mono">
                  LO: {question.learning_outcome?.code || question.learningOutcome?.code || question.learningOutcomeCode}
                </Badge>
              )}
            </div>
            <p className="text-xs text-[#262626] dark:text-[#E5E5E5] leading-relaxed line-clamp-3">{questionText}</p>
          </div>
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            aria-label="Close rubric generator"
            className="p-1 rounded-lg text-[#737373] hover:text-[#111111] dark:hover:text-white transition-colors shrink-0"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        {/* Body */}
        <div className="p-4 space-y-4 overflow-y-auto">
          {notice && (
            <div className="p-3 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-xl flex items-center gap-2 text-emerald-700 dark:text-emerald-300 text-xs font-medium">
              <CheckCircle2 className="w-4 h-4 shrink-0" />
              <span>{notice}</span>
            </div>
          )}

          {error !== null && error !== undefined && (
            <RubricError
              error={error}
              onRetry={selected ? undefined : handleGenerate}
              isRetrying={isGenerating}
            />
          )}

          {!isLoading && !isGenerating && !isRegenerating && (
            <RubricVersionHistory
              rubrics={rubrics}
              selectedId={selected?.id ?? null}
              onSelect={(r) => {
                setSelected(r);
                setMode('preview');
                setError(null);
              }}
            />
          )}

          {renderBody()}
        </div>
      </div>
    </div>
  );
};
