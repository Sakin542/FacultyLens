import React, { useState, useEffect } from 'react';
import { Assessment, PreviousQuestion, SimilarityAnalysisResult, SimilarityStatus } from '@/types';
import { aiService } from '@/services/aiService';
import { previousQuestionService } from '@/services/previousQuestionService';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { ProgressBar } from '@/components/dashboard/ProgressBar';
import {
  X,
  Sparkles,
  CopyCheck,
  AlertTriangle,
  HelpCircle,
  Loader2,
  Info,
  ChevronDown,
  ChevronUp,
  Sliders,
  Archive,
  ArrowRight,
  Check,
} from 'lucide-react';

interface SemanticSimilarityModalProps {
  isOpen: boolean;
  onClose: () => void;
  assessment: Assessment;
  onSimilarityCompleted?: (result: SimilarityAnalysisResult) => void;
}

export const SemanticSimilarityModal: React.FC<SemanticSimilarityModalProps> = ({
  isOpen,
  onClose,
  assessment,
  onSimilarityCompleted,
}) => {
  const [previousQuestions, setPreviousQuestions] = useState<PreviousQuestion[]>([]);
  const [isLoadingBank, setIsLoadingBank] = useState(false);
  const [isAnalyzing, setIsAnalyzing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [similarityResult, setSimilarityResult] = useState<SimilarityAnalysisResult | null>(null);

  // Threshold controls
  const [duplicateThreshold, setDuplicateThreshold] = useState<number>(0.85);
  const [highThreshold, setHighThreshold] = useState<number>(0.70);
  const [moderateThreshold, setModerateThreshold] = useState<number>(0.50);
  const [topK, setTopK] = useState<number>(5);
  const [showSettings, setShowSettings] = useState(false);

  // Expandable match items
  const [expandedQuestions, setExpandedQuestions] = useState<Record<string | number, boolean>>({});
  // Faculty review decisions (stored in-memory for decision support)
  const [reviewedDecisions, setReviewedDecisions] = useState<Record<string | number, 'accepted' | 'flagged'>>({});

  const toggleExpand = (qId: string | number) => {
    setExpandedQuestions((prev) => ({ ...prev, [qId]: !prev[qId] }));
  };

  const markDecision = (qId: string | number, decision: 'accepted' | 'flagged') => {
    setReviewedDecisions((prev) => ({
      ...prev,
      [qId]: prev[qId] === decision ? undefined as any : decision,
    }));
  };

  // Fetch course previous questions bank when modal opens
  useEffect(() => {
    if (!isOpen) return;

    const fetchQuestionBank = async () => {
      const courseId = assessment.course_id || assessment.courseId || assessment.course?.id;
      if (!courseId) return;

      try {
        setIsLoadingBank(true);
        setError(null);
        const res = await previousQuestionService.getByCourse(courseId);
        setPreviousQuestions(res.data || []);
      } catch (err: unknown) {
        if (err instanceof Error) {
          setError(err.message);
        } else {
          setError('Failed to fetch historical course questions bank.');
        }
      } finally {
        setIsLoadingBank(false);
      }
    };

    fetchQuestionBank();
  }, [isOpen, assessment]);

  const handleRunSimilarityAnalysis = async () => {
    if (!assessment.id) return;

    const questions = assessment.questions || [];
    if (questions.length === 0) {
      setError('Assessment has no questions. Please add questions before running similarity analysis.');
      return;
    }

    try {
      setIsAnalyzing(true);
      setError(null);

      const response = await aiService.analyzeAssessmentSimilarity(assessment.id, {
        thresholds: {
          duplicate: duplicateThreshold,
          high: highThreshold,
          moderate: moderateThreshold,
          top_k: topK,
        },
        top_k: topK,
      });

      if (response.data?.similarity) {
        setSimilarityResult(response.data.similarity);
        if (onSimilarityCompleted) {
          onSimilarityCompleted(response.data.similarity);
        }
      }
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to analyze semantic similarity.');
      }
    } finally {
      setIsAnalyzing(false);
    }
  };

  if (!isOpen) return null;

  const currentQuestionsCount = (assessment.questions || []).length;
  const bankQuestionsCount = previousQuestions.length;

  const getStatusBadge = (status: SimilarityStatus) => {
    switch (status) {
      case 'POTENTIAL_DUPLICATE':
        return <Badge variant="Critical" dot>Potential Duplicate</Badge>;
      case 'HIGHLY_SIMILAR':
        return <Badge variant="Attention" dot>Highly Similar</Badge>;
      case 'SOMEWHAT_SIMILAR':
        return <Badge variant="neutral">Somewhat Similar</Badge>;
      default:
        return <Badge variant="Good" dot>Novel Question</Badge>;
    }
  };

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
      <div
        className="bg-white rounded-2xl max-w-4xl w-full max-h-[92vh] flex flex-col shadow-2xl border border-[#E5E5E5] animate-in fade-in zoom-in-95 duration-200"
        role="dialog"
        aria-modal="true"
      >
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-[#E5E5E5]">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-xl bg-[#111111] text-white flex items-center justify-center shadow-subtle">
              <CopyCheck className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-base font-bold text-[#111111] tracking-tight">
                Semantic Similarity & Duplicate Detection
              </h2>
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
            <div className="flex items-center gap-4 text-xs text-[#525252]">
              <div className="flex items-center gap-1.5 font-medium">
                <Archive className="w-4 h-4 text-[#111111]" />
                <span><strong>{bankQuestionsCount}</strong> Historical Questions in Bank</span>
              </div>
              <div className="flex items-center gap-1.5 font-medium">
                <HelpCircle className="w-4 h-4 text-[#111111]" />
                <span><strong>{currentQuestionsCount}</strong> Current Questions</span>
              </div>
            </div>

            <div className="flex items-center gap-2 self-end sm:self-auto">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setShowSettings(!showSettings)}
                className="gap-1.5 text-xs"
              >
                <Sliders className="w-3.5 h-3.5" />
                <span>Thresholds</span>
              </Button>

              <Button
                variant="primary"
                size="sm"
                onClick={handleRunSimilarityAnalysis}
                disabled={isAnalyzing || currentQuestionsCount === 0 || isLoadingBank}
                className="gap-2 text-xs"
              >
                {isAnalyzing ? (
                  <>
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    <span>Comparing Vectors...</span>
                  </>
                ) : (
                  <>
                    <Sparkles className="w-3.5 h-3.5" />
                    <span>Run Similarity Check</span>
                  </>
                )}
              </Button>
            </div>
          </div>

          {/* Expandable Threshold Settings */}
          {showSettings && (
            <div className="p-4 bg-white border border-[#E5E5E5] rounded-xl space-y-3 shadow-subtle animate-in slide-in-from-top-2">
              <div className="flex items-center justify-between">
                <span className="text-xs font-bold text-[#111111] uppercase tracking-wider">Configurable Similarity Cutoffs</span>
                <span className="text-[11px] text-[#737373]">Cosine Distance Range: 0.0 - 1.0</span>
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div>
                  <label className="text-xs text-[#737373] block mb-1">
                    Potential Duplicate: <strong className="text-[#111111] font-mono">{duplicateThreshold}</strong>
                  </label>
                  <input
                    type="range"
                    min="0.75"
                    max="0.95"
                    step="0.05"
                    value={duplicateThreshold}
                    onChange={(e) => setDuplicateThreshold(parseFloat(e.target.value))}
                    className="w-full accent-[#111111]"
                  />
                  <span className="text-[10px] text-[#A3A3A3]">Threshold for repetition alert</span>
                </div>
                <div>
                  <label className="text-xs text-[#737373] block mb-1">
                    Highly Similar: <strong className="text-[#111111] font-mono">{highThreshold}</strong>
                  </label>
                  <input
                    type="range"
                    min="0.60"
                    max="0.80"
                    step="0.05"
                    value={highThreshold}
                    onChange={(e) => setHighThreshold(parseFloat(e.target.value))}
                    className="w-full accent-[#111111]"
                  />
                  <span className="text-[10px] text-[#A3A3A3]">High concept & phrasing overlap</span>
                </div>
                <div>
                  <label className="text-xs text-[#737373] block mb-1">
                    Moderate Similar: <strong className="text-[#111111] font-mono">{moderateThreshold}</strong>
                  </label>
                  <input
                    type="range"
                    min="0.40"
                    max="0.65"
                    step="0.05"
                    value={moderateThreshold}
                    onChange={(e) => setModerateThreshold(parseFloat(e.target.value))}
                    className="w-full accent-[#111111]"
                  />
                  <span className="text-[10px] text-[#A3A3A3]">Moderate topical overlap</span>
                </div>
                <div>
                  <label className="text-xs text-[#737373] block mb-1">
                    Top-K Matches: <strong className="text-[#111111] font-mono">{topK}</strong>
                  </label>
                  <input
                    type="number"
                    min="1"
                    max="10"
                    value={topK}
                    onChange={(e) => setTopK(parseInt(e.target.value) || 5)}
                    className="w-full px-2.5 py-1 text-xs border border-[#E5E5E5] rounded-lg"
                  />
                  <span className="text-[10px] text-[#A3A3A3]">Ranked matches to return</span>
                </div>
              </div>
            </div>
          )}

          {/* Results View */}
          {similarityResult ? (
            <div className="space-y-6 animate-in fade-in duration-300">
              {/* Summary Cards */}
              <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div className="p-4 bg-white border border-[#E5E5E5] rounded-xl flex flex-col justify-between shadow-subtle">
                  <span className="text-[11px] uppercase font-bold text-[#737373] tracking-wider">Compared</span>
                  <div className="flex items-baseline gap-2 mt-1">
                    <span className="text-3xl font-black font-mono text-[#111111]">
                      {similarityResult.total_current_questions}
                    </span>
                    <span className="text-xs text-[#737373]">Questions</span>
                  </div>
                  <span className="text-[11px] text-[#737373] mt-2">vs {similarityResult.total_previous_questions} in bank</span>
                </div>

                <div className={`p-4 rounded-xl flex flex-col justify-between shadow-subtle border ${similarityResult.potential_duplicates_count > 0 ? 'bg-[#FEF2F2] border-[#FECACA]' : 'bg-white border-[#E5E5E5]'}`}>
                  <span className={`text-[11px] uppercase font-bold tracking-wider ${similarityResult.potential_duplicates_count > 0 ? 'text-[#991B1B]' : 'text-[#737373]'}`}>
                    Potential Duplicates
                  </span>
                  <div className="flex items-baseline gap-2 mt-1">
                    <span className={`text-3xl font-black font-mono ${similarityResult.potential_duplicates_count > 0 ? 'text-[#DC2626]' : 'text-[#111111]'}`}>
                      {similarityResult.potential_duplicates_count}
                    </span>
                    <span className="text-xs text-[#737373]">(&gt;= {Math.round(duplicateThreshold * 100)}%)</span>
                  </div>
                  <span className="text-[11px] text-[#737373] mt-2">Requires faculty review</span>
                </div>

                <div className="p-4 bg-white border border-[#E5E5E5] rounded-xl flex flex-col justify-between shadow-subtle">
                  <span className="text-[11px] uppercase font-bold text-[#737373] tracking-wider">Highly Similar</span>
                  <div className="flex items-baseline gap-2 mt-1">
                    <span className="text-3xl font-black font-mono text-[#111111]">
                      {similarityResult.highly_similar_count}
                    </span>
                    <span className="text-xs text-[#737373]">Items</span>
                  </div>
                  <span className="text-[11px] text-[#737373] mt-2">{Math.round(highThreshold * 100)}% - {Math.round(duplicateThreshold * 100)}% overlap</span>
                </div>

                <div className="p-4 bg-white border border-[#E5E5E5] rounded-xl flex flex-col justify-between shadow-subtle">
                  <span className="text-[11px] uppercase font-bold text-[#737373] tracking-wider">Avg Similarity</span>
                  <div className="flex items-baseline gap-2 mt-1">
                    <span className="text-3xl font-black font-mono text-[#111111]">
                      {similarityResult.average_similarity_score}%
                    </span>
                  </div>
                  <div className="mt-2">
                    <ProgressBar value={similarityResult.average_similarity_score} size="sm" showValue={false} />
                  </div>
                </div>
              </div>

              {/* Actionable Findings */}
              {similarityResult.findings && similarityResult.findings.length > 0 && (
                <div className="p-4 bg-[#F7F7F5] border border-[#E5E5E5] rounded-xl space-y-2">
                  <div className="flex items-center gap-2 text-xs font-bold text-[#111111] uppercase tracking-wider">
                    <Info className="w-4 h-4 text-[#111111]" />
                    <span>Historical Similarity Insights</span>
                  </div>
                  <ul className="space-y-1.5 text-xs text-[#404040]">
                    {similarityResult.findings.map((f, idx) => (
                      <li key={idx} className="flex items-start gap-2">
                        <span className="text-[#111111] font-bold font-mono">•</span>
                        <span>{f}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {/* High Risk / Duplicate Side-by-Side Review Section */}
              {similarityResult.results.some(r => r.max_similarity_status === 'POTENTIAL_DUPLICATE' || r.max_similarity_status === 'HIGHLY_SIMILAR') && (
                <div className="space-y-3">
                  <h3 className="text-xs font-bold text-[#111111] uppercase tracking-wider flex items-center gap-2">
                    <AlertTriangle className="w-4 h-4 text-[#D97706]" />
                    <span>Flagged Repetitions for Faculty Review</span>
                  </h3>

                  <div className="space-y-3">
                    {similarityResult.results
                      .filter(r => r.max_similarity_status === 'POTENTIAL_DUPLICATE' || r.max_similarity_status === 'HIGHLY_SIMILAR')
                      .map((item, idx) => {
                        const qId = item.current_question_id || idx;
                        const bestMatch = item.matches[0];
                        const decision = reviewedDecisions[qId];

                        return (
                          <div
                            key={qId}
                            className={`p-4 rounded-xl border transition-colors space-y-3 ${decision === 'accepted' ? 'bg-[#F0FDF4] border-[#86EFAC]' : item.max_similarity_status === 'POTENTIAL_DUPLICATE' ? 'bg-[#FEF2F2]/50 border-[#FECACA]' : 'bg-white border-[#E5E5E5]'}`}
                          >
                            <div className="flex items-center justify-between gap-3">
                              <div className="flex items-center gap-2">
                                <span className="px-2 py-0.5 bg-[#111111] text-white rounded text-xs font-mono font-bold">
                                  Q{item.current_question_number}
                                </span>
                                {getStatusBadge(item.max_similarity_status)}
                              </div>

                              <div className="flex items-center gap-2">
                                <Button
                                  variant={decision === 'accepted' ? 'primary' : 'outline'}
                                  size="sm"
                                  onClick={() => markDecision(qId, 'accepted')}
                                  className="text-xs gap-1 py-1"
                                >
                                  <Check className="w-3.5 h-3.5" />
                                  <span>{decision === 'accepted' ? 'Approved (Novel/Intended)' : 'Approve Item'}</span>
                                </Button>
                              </div>
                            </div>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-3 pt-1">
                              {/* Current Question */}
                              <div className="p-3 bg-white rounded-lg border border-[#E5E5E5] space-y-1.5 shadow-2xs">
                                <div className="flex items-center justify-between text-[11px] text-[#737373] font-semibold">
                                  <span>CURRENT EXAMINATION</span>
                                  {item.current_cognitive_level && (
                                    <span className="font-mono">Bloom: {item.current_cognitive_level}</span>
                                  )}
                                </div>
                                <p className="text-xs text-[#111111] font-medium leading-relaxed">
                                  {item.current_question_text}
                                </p>
                              </div>

                              {/* Previous Match Question */}
                              {bestMatch && (
                                <div className="p-3 bg-[#F7F7F5] rounded-lg border border-[#E5E5E5] space-y-1.5 shadow-2xs">
                                  <div className="flex items-center justify-between text-[11px] text-[#737373] font-semibold">
                                    <span>HISTORICAL MATCH ({(bestMatch.similarity_score * 100).toFixed(1)}%)</span>
                                    <span>
                                      {bestMatch.source_year ? `${bestMatch.source_year} ` : ''}
                                      {bestMatch.source_assessment || 'Past Exam'}
                                    </span>
                                  </div>
                                  <p className="text-xs text-[#525252] leading-relaxed">
                                    {bestMatch.previous_question_text}
                                  </p>
                                </div>
                              )}
                            </div>

                            {item.reasoning && (
                              <p className="text-[11px] text-[#737373] italic">
                                {item.reasoning}
                              </p>
                            )}
                          </div>
                        );
                      })}
                  </div>
                </div>
              )}

              {/* Complete Question-by-Question Similarity Audit */}
              <div className="space-y-3">
                <h3 className="text-xs font-bold text-[#111111] uppercase tracking-wider flex items-center justify-between">
                  <span>Question-by-Question Similarity Overview</span>
                  <span className="text-[#737373] font-normal lowercase">{similarityResult.results.length} questions compared</span>
                </h3>

                <div className="space-y-2.5">
                  {similarityResult.results.map((item, idx) => {
                    const qKey = item.current_question_id || idx;
                    const isExpanded = !!expandedQuestions[qKey];
                    const bestMatch = item.matches[0];

                    return (
                      <div
                        key={qKey}
                        className="p-3.5 bg-white border border-[#E5E5E5] rounded-xl space-y-2 shadow-2xs"
                      >
                        <div className="flex items-start justify-between gap-3">
                          <div className="flex items-start gap-2.5 flex-1">
                            <span className="px-2 py-0.5 bg-[#F7F7F5] border border-[#E5E5E5] rounded text-xs font-mono font-bold text-[#111111] shrink-0">
                              Q{item.current_question_number}
                            </span>
                            <p className="text-xs font-medium text-[#111111] leading-relaxed">
                              {item.current_question_text}
                            </p>
                          </div>

                          <div className="shrink-0 flex items-center gap-2">
                            {getStatusBadge(item.max_similarity_status)}
                            <span className="font-mono text-xs font-bold text-[#111111]">
                              {(item.max_similarity_score * 100).toFixed(1)}%
                            </span>
                          </div>
                        </div>

                        {bestMatch && bestMatch.similarity_score >= moderateThreshold && (
                          <div className="p-2.5 bg-[#F7F7F5] rounded-lg text-xs flex items-center justify-between gap-2 border border-[#E5E5E5]">
                            <div className="flex items-center gap-2 truncate">
                              <ArrowRight className="w-3.5 h-3.5 text-[#737373] shrink-0" />
                              <span className="text-[#737373] shrink-0 font-medium">
                                [{bestMatch.source_year || 'Past'} {bestMatch.source_assessment || 'Exam'}]:
                              </span>
                              <span className="text-[#525252] truncate">{bestMatch.previous_question_text}</span>
                            </div>
                            <span className="font-mono text-[11px] font-semibold text-[#111111] shrink-0">
                              {(bestMatch.similarity_score * 100).toFixed(1)}%
                            </span>
                          </div>
                        )}

                        {item.matches && item.matches.length > 1 && (
                          <div className="pt-0.5">
                            <button
                              onClick={() => toggleExpand(qKey)}
                              className="text-[11px] text-[#525252] hover:text-[#111111] flex items-center gap-1 font-medium transition-colors"
                            >
                              <span>{item.matches.length - 1} more historical candidate{item.matches.length > 2 ? 's' : ''}</span>
                              {isExpanded ? <ChevronUp className="w-3 h-3" /> : <ChevronDown className="w-3 h-3" />}
                            </button>

                            {isExpanded && (
                              <div className="mt-2 space-y-1.5 pl-3 border-l-2 border-[#E5E5E5]">
                                {item.matches.slice(1).map((alt, altIdx) => (
                                  <div key={altIdx} className="text-[11px] text-[#525252] flex items-center justify-between gap-2">
                                    <span className="truncate">
                                      <strong>{alt.source_year || 'Past Exam'}:</strong> {alt.previous_question_text}
                                    </span>
                                    <span className="font-mono text-[#737373] shrink-0">
                                      {(alt.similarity_score * 100).toFixed(1)}%
                                    </span>
                                  </div>
                                ))}
                              </div>
                            )}
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              </div>
            </div>
          ) : (
            <div className="py-12 flex flex-col items-center justify-center text-center space-y-3">
              <div className="w-12 h-12 rounded-2xl bg-[#F7F7F5] border border-[#E5E5E5] flex items-center justify-center text-[#737373]">
                <CopyCheck className="w-6 h-6" />
              </div>
              <div className="max-w-md">
                <h3 className="text-sm font-bold text-[#111111]">Ready for Semantic Similarity Check</h3>
                <p className="text-xs text-[#737373] mt-1">
                  Click <strong>Run Similarity Check</strong> to cross-reference {currentQuestionsCount} current questions against {bankQuestionsCount} historical course questions using Hugging Face dense semantic embeddings.
                </p>
              </div>
            </div>
          )}

          {/* Academic Decision Support Notice */}
          <div className="pt-2 border-t border-[#E5E5E5] flex items-center gap-2 text-[11px] text-[#A3A3A3]">
            <Info className="w-3.5 h-3.5 shrink-0" />
            <span>
              Decision Support Notice: Semantic embeddings provide similarity evidence. Same-topic questions or different cognitive tasks can legitimately yield high similarity. Faculty makes the final decision.
            </span>
          </div>
        </div>

        {/* Footer */}
        <div className="px-6 py-3.5 border-t border-[#E5E5E5] bg-[#FAFAFA] rounded-b-2xl flex items-center justify-between">
          <span className="text-xs text-[#737373]">
            {similarityResult ? `Complete • Model: ${similarityResult.model}` : 'Pending execution'}
          </span>
          <Button variant="secondary" size="sm" onClick={onClose}>
            Close
          </Button>
        </div>
      </div>
    </div>
  );
};
