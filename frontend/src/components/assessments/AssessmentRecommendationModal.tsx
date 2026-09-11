import React, { useState, useEffect } from 'react';
import { Assessment, EvidenceBasedRecommendation, RecommendationStatus } from '@/types';
import { aiService } from '@/services/aiService';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { RecommendationSection } from '@/components/analysis/RecommendationSection';
import {
  X,
  Sparkles,
  Loader2,
  RefreshCw,
  CheckCircle2,
  AlertTriangle,
} from 'lucide-react';

interface AssessmentRecommendationModalProps {
  isOpen: boolean;
  onClose: () => void;
  assessment: Assessment;
  onRecommendationsUpdated?: () => void;
}

export const AssessmentRecommendationModal: React.FC<AssessmentRecommendationModalProps> = ({
  isOpen,
  onClose,
  assessment,
  onRecommendationsUpdated,
}) => {
  const [recommendations, setRecommendations] = useState<EvidenceBasedRecommendation[]>([]);
  const [isLoading, setIsLoading] = useState<boolean>(false);
  const [isGenerating, setIsGenerating] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (isOpen) {
      loadRecommendations();
    }
  }, [isOpen, assessment.id]);

  const loadRecommendations = async () => {
    setIsLoading(true);
    setError(null);
    try {
      const res = await aiService.getAssessmentRecommendations(assessment.id);
      if (res.data?.recommendations) {
        setRecommendations(res.data.recommendations);
      }
    } catch (err: any) {
      // If no recommendations exist yet, we can generate them
      setRecommendations([]);
    } finally {
      setIsLoading(false);
    }
  };

  const handleGenerate = async () => {
    setIsGenerating(true);
    setError(null);
    try {
      const res = await aiService.generateAssessmentRecommendations(assessment.id);
      if (res.data?.recommendations) {
        setRecommendations(res.data.recommendations);
        if (onRecommendationsUpdated) {
          onRecommendationsUpdated();
        }
      }
    } catch (err: any) {
      setError(err?.message || 'Failed to generate recommendations. Please ensure analysis has been run.');
    } finally {
      setIsGenerating(false);
    }
  };

  const handleStatusChange = async (id: number | string, status: RecommendationStatus, notes?: string) => {
    try {
      await aiService.updateRecommendationStatus(id, status, notes);
      if (onRecommendationsUpdated) {
        onRecommendationsUpdated();
      }
    } catch (err: any) {
      console.error('Failed to update recommendation status:', err);
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-in fade-in duration-200">
      <div className="bg-sage-100 border border-sage-200 w-full max-w-5xl max-h-[90vh] rounded-2xl shadow-2xl flex flex-col overflow-hidden">
        {/* Modal Header */}
        <div className="p-6 bg-white border-b border-sage-200 flex items-center justify-between shrink-0">
          <div className="space-y-1">
            <div className="flex items-center gap-2">
              <span className="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-sage-700 text-white flex items-center gap-1.5 font-mono">
                <Sparkles className="w-3.5 h-3.5 text-amber-300" />
                AI RECOMMENDATION ENGINE
              </span>
              <Badge variant="neutral" className="font-mono text-xs">
                {assessment.title}
              </Badge>
            </div>
            <h2 className="text-xl font-bold text-sage-800">
              Prioritized Pedagogical Recommendations
            </h2>
            <p className="text-xs text-sage-500">
              Deterministic, evidence-grounded recommendations to elevate assessment balance, LO coverage, and originality.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              leftIcon={isGenerating ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <RefreshCw className="w-3.5 h-3.5" />}
              onClick={handleGenerate}
              disabled={isGenerating}
            >
              {isGenerating ? 'Analyzing...' : recommendations.length > 0 ? 'Regenerate' : 'Generate Recommendations'}
            </Button>
            <button
              onClick={onClose}
              className="p-2 text-sage-500 hover:text-sage-800 hover:bg-sage-100 rounded-lg transition-colors"
            >
              <X className="w-5 h-5" />
            </button>
          </div>
        </div>

        {/* Modal Body */}
        <div className="p-6 overflow-y-auto flex-1 space-y-6">
          {error && (
            <div className="p-4 rounded-xl border border-[#FECACA] bg-[#FEF2F2] flex items-start gap-3 text-xs text-[#991B1B]">
              <AlertTriangle className="w-4 h-4 text-[#DC2626] shrink-0 mt-0.5" />
              <div className="space-y-1">
                <p className="font-bold">Recommendation Generation Issue</p>
                <p>{error}</p>
              </div>
            </div>
          )}

          {isLoading ? (
            <div className="p-16 text-center space-y-3">
              <Loader2 className="w-8 h-8 text-sage-800 animate-spin mx-auto" />
              <p className="text-xs text-sage-500 font-medium">Loading recommendations...</p>
            </div>
          ) : recommendations.length === 0 && !isGenerating ? (
            <div className="p-12 text-center bg-white rounded-2xl border border-dashed border-sage-200 space-y-4">
              <div className="w-12 h-12 rounded-xl bg-sage-100 border border-sage-200 flex items-center justify-center mx-auto text-sage-800">
                <Sparkles className="w-6 h-6" />
              </div>
              <div className="space-y-1">
                <h3 className="text-base font-bold text-sage-800">No Recommendations Generated Yet</h3>
                <p className="text-xs text-sage-500 max-w-md mx-auto">
                  Click the button below to inspect assessment questions, learning outcomes, and difficulty distributions for actionable suggestions.
                </p>
              </div>
              <Button variant="primary" size="md" onClick={handleGenerate}>
                Generate AI Recommendations
              </Button>
            </div>
          ) : (
            <RecommendationSection
              recommendations={recommendations}
              onStatusChange={handleStatusChange}
              isLoading={isGenerating}
            />
          )}
        </div>

        {/* Modal Footer */}
        <div className="p-4 bg-white border-t border-sage-200 flex items-center justify-between text-xs text-sage-500 shrink-0">
          <span className="flex items-center gap-1.5">
            <CheckCircle2 className="w-4 h-4 text-[#16A34A]" />
            Faculty Decision-in-the-loop: The system will never automatically change questions.
          </span>
          <Button variant="ghost" size="sm" onClick={onClose}>
            Close
          </Button>
        </div>
      </div>
    </div>
  );
};

