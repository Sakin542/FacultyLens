import React, { useState } from 'react';
import { Assessment, AssessmentQualityResult } from '@/types';
import { aiService } from '@/services/aiService';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { AssessmentQualitySection } from '@/components/analysis/AssessmentQualitySection';
import {
  X,
  Sparkles,
  BarChart3,
  AlertTriangle,
  Loader2,
  Sliders,
} from 'lucide-react';

interface AssessmentQualityModalProps {
  isOpen: boolean;
  onClose: () => void;
  assessment: Assessment;
  onQualityCompleted?: (result: AssessmentQualityResult) => void;
}

export const AssessmentQualityModal: React.FC<AssessmentQualityModalProps> = ({
  isOpen,
  onClose,
  assessment,
  onQualityCompleted,
}) => {
  const [isAnalyzing, setIsAnalyzing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [qualityResult, setQualityResult] = useState<AssessmentQualityResult | null>(null);

  // Weight overrides
  const [showSettings, setShowSettings] = useState(false);
  const [topicWeight, setTopicWeight] = useState(20);
  const [loWeight, setLoWeight] = useState(20);
  const [difficultyWeight, setDifficultyWeight] = useState(15);
  const [cognitiveWeight, setCognitiveWeight] = useState(15);
  const [questionDiversityWeight, setQuestionDiversityWeight] = useState(15);
  const [marksWeight, setMarksWeight] = useState(15);

  if (!isOpen) return null;

  const currentQuestionsCount = (assessment.questions || []).length;

  const handleRunQualityAudit = async () => {
    if (currentQuestionsCount === 0) {
      setError('Assessment has no questions. Please add questions before running quality audit.');
      return;
    }

    try {
      setIsAnalyzing(true);
      setError(null);

      const response = await aiService.analyzeAssessmentQuality(assessment.id, {
        weights: {
          topic: topicWeight,
          lo: loWeight,
          difficulty: difficultyWeight,
          cognitive: cognitiveWeight,
          question_diversity: questionDiversityWeight,
          marks: marksWeight,
        },
      });

      if (response.data?.quality) {
        setQualityResult(response.data.quality);
        if (onQualityCompleted) {
          onQualityCompleted(response.data.quality);
        }
      }
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to evaluate assessment quality.');
      }
    } finally {
      setIsAnalyzing(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
      <div
        className="bg-white rounded-2xl max-w-5xl w-full max-h-[92vh] flex flex-col shadow-2xl border border-[#E5E5E5] animate-in fade-in zoom-in-95 duration-200"
        role="dialog"
        aria-modal="true"
      >
        {/* Header */}
        <div className="p-6 border-b border-[#E5E5E5] flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-[#111111] text-white flex items-center justify-center shadow-subtle">
              <BarChart3 className="w-5 h-5" />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h2 className="text-lg font-bold text-[#111111]">Assessment Quality Engine</h2>
                <Badge variant="neutral" className="text-[10px]">STEP 13</Badge>
              </div>
              <p className="text-xs text-[#737373]">
                {assessment.title} • {assessment.courseCode || assessment.course?.course_code || 'Course'}
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 rounded-lg text-[#737373] hover:text-[#111111] hover:bg-[#F7F7F5] transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Modal Body */}
        <div className="p-6 overflow-y-auto space-y-6 flex-1 text-sm">
          {error && (
            <div className="p-3.5 bg-[#FEF2F2] border border-[#FCA5A5] rounded-xl flex items-start gap-2.5 text-[#991B1B]">
              <AlertTriangle className="w-5 h-5 shrink-0 mt-0.5" />
              <div className="flex-1 text-xs">
                <span className="font-semibold">Notice: </span>
                {error}
              </div>
            </div>
          )}

          {/* Action Ribbon */}
          <div className="bg-[#F7F7F5] rounded-xl p-4 border border-[#E5E5E5] flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
            <div className="text-xs text-[#525252]">
              <span>Evaluating <strong>{currentQuestionsCount}</strong> questions across 6 core academic dimensions.</span>
            </div>

            <div className="flex items-center gap-2 self-end sm:self-auto">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setShowSettings(!showSettings)}
                className="gap-1.5 text-xs"
              >
                <Sliders className="w-3.5 h-3.5" />
                <span>Scoring Weights</span>
              </Button>

              <Button
                variant="primary"
                size="sm"
                onClick={handleRunQualityAudit}
                disabled={isAnalyzing || currentQuestionsCount === 0}
                className="gap-2 text-xs"
              >
                {isAnalyzing ? (
                  <>
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    <span>Evaluating Engine...</span>
                  </>
                ) : (
                  <>
                    <Sparkles className="w-3.5 h-3.5" />
                    <span>Run Quality Audit</span>
                  </>
                )}
              </Button>
            </div>
          </div>

          {/* Expandable Scoring Weight Settings */}
          {showSettings && (
            <div className="p-4 bg-white border border-[#E5E5E5] rounded-xl space-y-3 shadow-subtle animate-in slide-in-from-top-2">
              <div className="flex items-center justify-between">
                <span className="text-xs font-bold text-[#111111] uppercase tracking-wider">Configurable Dimension Weights (%)</span>
                <span className="text-[11px] text-[#737373]">
                  Total: {topicWeight + loWeight + difficultyWeight + cognitiveWeight + questionDiversityWeight + marksWeight}%
                </span>
              </div>
              <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <div>
                  <label className="text-[11px] text-[#737373] block mb-1">Topics ({topicWeight}%)</label>
                  <input
                    type="number"
                    min="0"
                    max="50"
                    value={topicWeight}
                    onChange={(e) => setTopicWeight(parseInt(e.target.value) || 0)}
                    className="w-full px-2.5 py-1 text-xs border border-[#E5E5E5] rounded-lg font-mono"
                  />
                </div>
                <div>
                  <label className="text-[11px] text-[#737373] block mb-1">LOs ({loWeight}%)</label>
                  <input
                    type="number"
                    min="0"
                    max="50"
                    value={loWeight}
                    onChange={(e) => setLoWeight(parseInt(e.target.value) || 0)}
                    className="w-full px-2.5 py-1 text-xs border border-[#E5E5E5] rounded-lg font-mono"
                  />
                </div>
                <div>
                  <label className="text-[11px] text-[#737373] block mb-1">Difficulty ({difficultyWeight}%)</label>
                  <input
                    type="number"
                    min="0"
                    max="50"
                    value={difficultyWeight}
                    onChange={(e) => setDifficultyWeight(parseInt(e.target.value) || 0)}
                    className="w-full px-2.5 py-1 text-xs border border-[#E5E5E5] rounded-lg font-mono"
                  />
                </div>
                <div>
                  <label className="text-[11px] text-[#737373] block mb-1">Cognitive ({cognitiveWeight}%)</label>
                  <input
                    type="number"
                    min="0"
                    max="50"
                    value={cognitiveWeight}
                    onChange={(e) => setCognitiveWeight(parseInt(e.target.value) || 0)}
                    className="w-full px-2.5 py-1 text-xs border border-[#E5E5E5] rounded-lg font-mono"
                  />
                </div>
                <div>
                  <label className="text-[11px] text-[#737373] block mb-1">Formats ({questionDiversityWeight}%)</label>
                  <input
                    type="number"
                    min="0"
                    max="50"
                    value={questionDiversityWeight}
                    onChange={(e) => setQuestionDiversityWeight(parseInt(e.target.value) || 0)}
                    className="w-full px-2.5 py-1 text-xs border border-[#E5E5E5] rounded-lg font-mono"
                  />
                </div>
                <div>
                  <label className="text-[11px] text-[#737373] block mb-1">Marks ({marksWeight}%)</label>
                  <input
                    type="number"
                    min="0"
                    max="50"
                    value={marksWeight}
                    onChange={(e) => setMarksWeight(parseInt(e.target.value) || 0)}
                    className="w-full px-2.5 py-1 text-xs border border-[#E5E5E5] rounded-lg font-mono"
                  />
                </div>
              </div>
            </div>
          )}

          {/* Results View */}
          {qualityResult ? (
            <AssessmentQualitySection qualityData={qualityResult} />
          ) : (
            <div className="py-12 text-center text-xs text-[#737373] space-y-2">
              <BarChart3 className="w-8 h-8 text-[#A3A3A3] mx-auto mb-2" />
              <p>Click <strong>Run Quality Audit</strong> to evaluate examination balance and pedagogical rigor.</p>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="p-4 border-t border-[#E5E5E5] bg-[#F7F7F5] flex items-center justify-between rounded-b-2xl">
          <span className="text-xs text-[#737373]">
            {qualityResult ? 'Quality report successfully computed and synchronized.' : 'Ready to evaluate.'}
          </span>
          <Button variant="secondary" size="sm" onClick={onClose}>
            Close
          </Button>
        </div>
      </div>
    </div>
  );
};
