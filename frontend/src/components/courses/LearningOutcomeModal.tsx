import React, { useState, useEffect } from 'react';
import { LearningOutcome, CognitiveLevel } from '@/types';
import { LearningOutcomePayload } from '@/services/learningOutcomeService';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { X, Sparkles, AlertCircle, Loader2 } from 'lucide-react';

interface LearningOutcomeModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (data: LearningOutcomePayload) => Promise<void>;
  outcome?: LearningOutcome | null;
  defaultCode?: string;
}

const BLOOM_LEVELS: { value: CognitiveLevel; label: string; description: string }[] = [
  { value: 'Remember', label: '1. Remember', description: 'Recall facts and basic concepts' },
  { value: 'Understand', label: '2. Understand', description: 'Explain ideas or concepts' },
  { value: 'Apply', label: '3. Apply', description: 'Use information in new situations' },
  { value: 'Analyze', label: '4. Analyze', description: 'Draw connections among ideas' },
  { value: 'Evaluate', label: '5. Evaluate', description: 'Justify a stand or decision' },
  { value: 'Create', label: '6. Create', description: 'Produce new or original work' },
];

export const LearningOutcomeModal: React.FC<LearningOutcomeModalProps> = ({
  isOpen,
  onClose,
  onSubmit,
  outcome,
  defaultCode = 'CLO-1',
}) => {
  const [formData, setFormData] = useState<LearningOutcomePayload>({
    code: defaultCode,
    description: '',
    cognitive_level: 'Understand',
  });

  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    if (outcome) {
      setFormData({
        code: outcome.code || defaultCode,
        description: outcome.description || '',
        cognitive_level: outcome.cognitive_level || outcome.bloomLevel || 'Understand',
        sort_order: outcome.sort_order,
      });
    } else {
      setFormData({
        code: defaultCode,
        description: '',
        cognitive_level: 'Understand',
      });
    }
    setError(null);
  }, [outcome, defaultCode, isOpen]);

  if (!isOpen) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!formData.code.trim()) {
      setError('Outcome code is required (e.g. CLO-1)');
      return;
    }
    if (!formData.description.trim()) {
      setError('Outcome description is required');
      return;
    }

    try {
      setIsSubmitting(true);
      await onSubmit({
        ...formData,
        code: formData.code.trim().toUpperCase(),
        description: formData.description.trim(),
      });
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to save learning outcome');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
      <div className="bg-white dark:bg-[#1C1C1E] rounded-2xl border border-sage-200 dark:border-[#2C2C2E] shadow-xl max-w-lg w-full p-6 space-y-5 animate-in fade-in zoom-in duration-150">
        <div className="flex items-center justify-between pb-3 border-b border-sage-200 dark:border-[#2C2C2E]">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-sage-100 dark:bg-[#2C2C2E] border border-sage-200 dark:border-[#3A3A3C] flex items-center justify-center text-sage-800 dark:text-white">
              <Sparkles className="w-4 h-4" />
            </div>
            <h3 className="text-lg font-bold text-sage-800 dark:text-white">
              {outcome ? 'Edit Learning Outcome' : 'Add Learning Outcome'}
            </h3>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-1 rounded-lg text-sage-500 hover:text-sage-800 dark:hover:text-white hover:bg-sage-100 dark:hover:bg-[#2C2C2E] transition-colors"
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
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Input
              label="Outcome Code"
              placeholder="e.g. CLO-1"
              value={formData.code}
              onChange={(e) => setFormData({ ...formData, code: e.target.value })}
              required
              disabled={isSubmitting}
            />
            <div>
              <label className="block text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-200 mb-1.5">
                Bloom's Taxonomy Level <span className="text-[#DC2626]">*</span>
              </label>
              <select
                value={formData.cognitive_level}
                onChange={(e) =>
                  setFormData({ ...formData, cognitive_level: e.target.value as CognitiveLevel })
                }
                disabled={isSubmitting}
                className="w-full rounded-lg border border-sage-200 dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-sm text-sage-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-sage-600 dark:focus:ring-white"
              >
                {BLOOM_LEVELS.map((bloom) => (
                  <option key={bloom.value} value={bloom.value}>
                    {bloom.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div>
            <label className="block text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-200 mb-1.5">
              Outcome Description <span className="text-[#DC2626]">*</span>
            </label>
            <textarea
              rows={4}
              placeholder="Clearly describe what students should be able to demonstrate upon completing this course..."
              value={formData.description}
              onChange={(e) => setFormData({ ...formData, description: e.target.value })}
              required
              disabled={isSubmitting}
              className="w-full rounded-lg border border-sage-200 dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-sm text-sage-800 dark:text-white placeholder:text-sage-400 focus:outline-none focus:ring-2 focus:ring-sage-600 dark:focus:ring-white resize-none"
            />
          </div>

          <div className="flex items-center justify-end gap-3 pt-4 border-t border-sage-200 dark:border-[#2C2C2E]">
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
              {isSubmitting ? 'Saving...' : outcome ? 'Update Outcome' : 'Add Outcome'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
};

