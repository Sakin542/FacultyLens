import React, { useState, useEffect } from 'react';
import { PreviousQuestion, CognitiveLevel } from '@/types';
import { PreviousQuestionPayload } from '@/services/previousQuestionService';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { X, HelpCircle, AlertCircle, Loader2 } from 'lucide-react';

interface PreviousQuestionModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (data: PreviousQuestionPayload) => Promise<void>;
  question?: PreviousQuestion | null;
}

const QUESTION_TYPES = [
  { value: 'descriptive', label: 'Descriptive' },
  { value: 'problem_solving', label: 'Problem Solving' },
  { value: 'short_answer', label: 'Short Answer' },
  { value: 'mcq', label: 'Multiple Choice (MCQ)' },
  { value: 'true_false', label: 'True / False' },
  { value: 'other', label: 'Other' },
];

const DIFFICULTY_LEVELS = [
  { value: 'easy', label: 'Easy' },
  { value: 'medium', label: 'Medium' },
  { value: 'hard', label: 'Hard' },
];

const BLOOM_LEVELS: { value: CognitiveLevel; label: string }[] = [
  { value: 'Remember', label: '1. Remember' },
  { value: 'Understand', label: '2. Understand' },
  { value: 'Apply', label: '3. Apply' },
  { value: 'Analyze', label: '4. Analyze' },
  { value: 'Evaluate', label: '5. Evaluate' },
  { value: 'Create', label: '6. Create' },
];

const SOURCES = [
  { value: 'previous_exam', label: 'Previous Exam' },
  { value: 'question_bank', label: 'Question Bank' },
  { value: 'manual', label: 'Manual Entry' },
  { value: 'other', label: 'Other' },
];

export const PreviousQuestionModal: React.FC<PreviousQuestionModalProps> = ({
  isOpen,
  onClose,
  onSubmit,
  question,
}) => {
  const [formData, setFormData] = useState<PreviousQuestionPayload>({
    question_text: '',
    question_type: 'descriptive',
    marks: 10,
    difficulty_level: 'medium',
    cognitive_level: 'Understand',
    source: 'previous_exam',
    source_year: String(new Date().getFullYear() - 1),
    source_assessment: '',
  });

  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    if (question) {
      setFormData({
        question_text: question.question_text || '',
        question_type: question.question_type || 'descriptive',
        marks: question.marks ? Number(question.marks) : 10,
        difficulty_level: question.difficulty_level || 'medium',
        cognitive_level: question.cognitive_level || 'Understand',
        source: question.source || 'previous_exam',
        source_year: question.source_year || '',
        source_assessment: question.source_assessment || '',
      });
    } else {
      setFormData({
        question_text: '',
        question_type: 'descriptive',
        marks: 10,
        difficulty_level: 'medium',
        cognitive_level: 'Understand',
        source: 'previous_exam',
        source_year: String(new Date().getFullYear() - 1),
        source_assessment: '',
      });
    }
    setError(null);
  }, [question, isOpen]);

  if (!isOpen) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!formData.question_text?.trim()) {
      setError('Question text is required');
      return;
    }

    try {
      setIsSubmitting(true);
      await onSubmit({
        ...formData,
        question_text: formData.question_text.trim(),
        marks: formData.marks ? Number(formData.marks) : undefined,
        source_year: formData.source_year?.trim(),
        source_assessment: formData.source_assessment?.trim(),
      });
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to save previous question.');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
      <div className="bg-white dark:bg-[#1C1C1E] rounded-2xl border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-xl max-w-xl w-full p-6 space-y-5 animate-in fade-in zoom-in duration-150">
        <div className="flex items-center justify-between pb-3 border-b border-[#E5E5E5] dark:border-[#2C2C2E]">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center text-[#111111] dark:text-white">
              <HelpCircle className="w-4 h-4" />
            </div>
            <h3 className="text-lg font-bold text-[#111111] dark:text-white">
              {question ? 'Edit Historical Question' : 'Add Historical / Previous Question'}
            </h3>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-1 rounded-lg text-[#737373] hover:text-[#111111] dark:hover:text-white hover:bg-[#F7F7F5] dark:hover:bg-[#2C2C2E] transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {error && (
          <div className="p-3 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 rounded-lg text-xs text-red-600 dark:text-red-400 flex items-center gap-2">
            <AlertCircle className="w-4 h-4 shrink-0" />
            <span>{error}</span>
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="block text-xs font-medium uppercase tracking-wider text-[#262626] dark:text-[#E5E5E5] mb-1.5">
              Question Text <span className="text-[#DC2626]">*</span>
            </label>
            <textarea
              rows={4}
              placeholder="Enter the full question prompt or problem statement..."
              value={formData.question_text || ''}
              onChange={(e) => setFormData({ ...formData, question_text: e.target.value })}
              required
              disabled={isSubmitting}
              className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-sm text-[#111111] dark:text-white placeholder:text-[#A3A3A3] focus:outline-none focus:ring-2 focus:ring-[#111111] dark:focus:ring-white resize-none"
            />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label className="block text-xs font-medium uppercase tracking-wider text-[#262626] dark:text-[#E5E5E5] mb-1.5">
                Type
              </label>
              <select
                value={formData.question_type}
                onChange={(e) => setFormData({ ...formData, question_type: e.target.value })}
                disabled={isSubmitting}
                className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-sm text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#111111]"
              >
                {QUESTION_TYPES.map((t) => (
                  <option key={t.value} value={t.value}>
                    {t.label}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-xs font-medium uppercase tracking-wider text-[#262626] dark:text-[#E5E5E5] mb-1.5">
                Difficulty
              </label>
              <select
                value={formData.difficulty_level}
                onChange={(e) => setFormData({ ...formData, difficulty_level: e.target.value })}
                disabled={isSubmitting}
                className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-sm text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#111111]"
              >
                {DIFFICULTY_LEVELS.map((d) => (
                  <option key={d.value} value={d.value}>
                    {d.label}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-xs font-medium uppercase tracking-wider text-[#262626] dark:text-[#E5E5E5] mb-1.5">
                Bloom Level
              </label>
              <select
                value={formData.cognitive_level}
                onChange={(e) => setFormData({ ...formData, cognitive_level: e.target.value as CognitiveLevel })}
                disabled={isSubmitting}
                className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-sm text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#111111]"
              >
                {BLOOM_LEVELS.map((b) => (
                  <option key={b.value} value={b.value}>
                    {b.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <Input
              label="Marks"
              type="number"
              min={1}
              value={formData.marks || ''}
              onChange={(e) => setFormData({ ...formData, marks: Number(e.target.value) || undefined })}
              disabled={isSubmitting}
            />
            <div>
              <label className="block text-xs font-medium uppercase tracking-wider text-[#262626] dark:text-[#E5E5E5] mb-1.5">
                Source
              </label>
              <select
                value={formData.source}
                onChange={(e) => setFormData({ ...formData, source: e.target.value })}
                disabled={isSubmitting}
                className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-sm text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#111111]"
              >
                {SOURCES.map((s) => (
                  <option key={s.value} value={s.value}>
                    {s.label}
                  </option>
                ))}
              </select>
            </div>
            <Input
              label="Year"
              placeholder="e.g. 2025"
              value={formData.source_year || ''}
              onChange={(e) => setFormData({ ...formData, source_year: e.target.value })}
              disabled={isSubmitting}
            />
            <div>
              <Input
                label="Exam / Context"
                placeholder="e.g. Final Exam"
                value={formData.source_assessment || ''}
                onChange={(e) => setFormData({ ...formData, source_assessment: e.target.value })}
                disabled={isSubmitting}
              />
            </div>
          </div>

          <div className="flex items-center justify-end gap-3 pt-4 border-t border-[#E5E5E5] dark:border-[#2C2C2E]">
            <Button
              variant="outline"
              size="sm"
              type="button"
              onClick={onClose}
              disabled={isSubmitting}
            >
              Cancel
            </Button>
            <Button
              variant="primary"
              size="sm"
              type="submit"
              disabled={isSubmitting}
              leftIcon={isSubmitting ? <Loader2 className="w-4 h-4 animate-spin" /> : undefined}
            >
              {isSubmitting ? 'Saving...' : question ? 'Update Question' : 'Add to Bank'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
};
