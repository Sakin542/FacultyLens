import React, { useState, useEffect } from 'react';
import { Assessment, LearningOutcome, AlignmentAnalysisResult } from '@/types';
import { aiService } from '@/services/aiService';
import { learningOutcomeService } from '@/services/learningOutcomeService';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { ProgressBar } from '@/components/dashboard/ProgressBar';
import {
  X,
  Sparkles,
  Target,
  CheckCircle2,
  AlertTriangle,
  HelpCircle,
  Loader2,
  Info,
  ChevronDown,
  ChevronUp,
  Sliders,
  BookOpen,
} from 'lucide-react';

interface LearningOutcomeAlignmentModalProps {
  isOpen: boolean;
  onClose: () => void;
  assessment: Assessment;
  onAlignmentCompleted?: (result: AlignmentAnalysisResult) => void;
}

export const LearningOutcomeAlignmentModal: React.FC<LearningOutcomeAlignmentModalProps> = ({
  isOpen,
  onClose,
  assessment,
  onAlignmentCompleted,
}) => {
  const [learningOutcomes, setLearningOutcomes] = useState<LearningOutcome[]>([]);
  const [isLoadingLos, setIsLoadingLos] = useState(false);
  const [isAnalyzing, setIsAnalyzing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [alignmentResult, setAlignmentResult] = useState<AlignmentAnalysisResult | null>(null);

  // Threshold controls
  const [strongThreshold, setStrongThreshold] = useState<number>(0.70);
  const [weakThreshold, setWeakThreshold] = useState<number>(0.50);
  const [showThresholdSettings, setShowThresholdSettings] = useState(false);

  // UI state for expandable alternative matches
  const [expandedQuestions, setExpandedQuestions] = useState<Record<string | number, boolean>>({});

  const toggleQuestionExpand = (qId: string | number) => {
    setExpandedQuestions((prev) => ({ ...prev, [qId]: !prev[qId] }));
  };

  // Fetch course learning outcomes when modal opens
  useEffect(() => {
    if (!isOpen) return;

    const fetchCourseLos = async () => {
      const courseId = assessment.course_id || assessment.courseId || assessment.course?.id;
      if (!courseId) return;

      try {
        setIsLoadingLos(true);
        setError(null);
        const res = await learningOutcomeService.getByCourse(courseId);
        setLearningOutcomes(res.data || []);
      } catch (err: unknown) {
        if (err instanceof Error) {
          setError(err.message);
        } else {
          setError('Failed to fetch course learning outcomes.');
        }
      } finally {
        setIsLoadingLos(false);
      }
    };

    fetchCourseLos();
  }, [isOpen, assessment]);

  const handleRunAlignment = async () => {
    if (!assessment.id) return;

    const questions = assessment.questions || [];
    if (questions.length === 0) {
      setError('Assessment has no questions. Please add questions before running alignment.');
      return;
    }

    if (learningOutcomes.length === 0) {
      setError('The course has no defined learning outcomes. Please add learning outcomes in the Course page.');
      return;
    }

    try {
      setIsAnalyzing(true);
      setError(null);

      const response = await aiService.analyzeAssessmentAlignment(assessment.id, {
        thresholds: {
          strong: strongThreshold,
          weak: weakThreshold,
        },
      });

      if (response.data?.alignment) {
        setAlignmentResult(response.data.alignment);
        if (onAlignmentCompleted) {
          onAlignmentCompleted(response.data.alignment);
        }
      }
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to analyze learning outcome alignment.');
      }
    } finally {
      setIsAnalyzing(false);
    }
  };

  if (!isOpen) return null;

  const questionsCount = (assessment.questions || []).length;
  const losCount = learningOutcomes.length;

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
      <div
        className="bg-white rounded-2xl max-w-4xl w-full max-h-[92vh] flex flex-col shadow-2xl border border-[#E5E5E5] animate-in fade-in zoom-in-95 duration-200"
        role="dialog"
        aria-modal="true"
      >
        {/* Modal Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-[#E5E5E5]">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-xl bg-[#111111] text-white flex items-center justify-center shadow-subtle">
              <Target className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-base font-bold text-[#111111] tracking-tight">
                Learning Outcome Alignment Analysis
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
                <span className="font-semibold">Analysis Notice: </span>
                {error}
              </div>
            </div>
          )}

          {/* Configuration & Action Ribbon */}
          <div className="bg-[#F7F7F5] rounded-xl p-4 border border-[#E5E5E5] flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
            <div className="flex items-center gap-4 text-xs text-[#525252]">
              <div className="flex items-center gap-1.5 font-medium">
                <BookOpen className="w-4 h-4 text-[#111111]" />
                <span><strong>{losCount}</strong> Defined LOs</span>
              </div>
              <div className="flex items-center gap-1.5 font-medium">
                <HelpCircle className="w-4 h-4 text-[#111111]" />
                <span><strong>{questionsCount}</strong> Questions</span>
              </div>
            </div>

            <div className="flex items-center gap-2 self-end sm:self-auto">
              <Button
                variant="outline"
                size="sm"
                onClick={() => setShowThresholdSettings(!showThresholdSettings)}
                className="gap-1.5 text-xs"
              >
                <Sliders className="w-3.5 h-3.5" />
                <span>Thresholds</span>
              </Button>

              <Button
                variant="primary"
                size="sm"
                onClick={handleRunAlignment}
                disabled={isAnalyzing || questionsCount === 0 || losCount === 0 || isLoadingLos}
                className="gap-2 text-xs"
              >
                {isAnalyzing ? (
                  <>
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    <span>Analyzing LO Vectors...</span>
                  </>
                ) : (
                  <>
                    <Sparkles className="w-3.5 h-3.5" />
                    <span>Run Alignment Analysis</span>
                  </>
                )}
              </Button>
            </div>
          </div>

          {/* Expandable Threshold Settings */}
          {showThresholdSettings && (
            <div className="p-4 bg-white border border-[#E5E5E5] rounded-xl space-y-3 shadow-subtle animate-in slide-in-from-top-2">
              <div className="flex items-center justify-between">
                <span className="text-xs font-bold text-[#111111] uppercase tracking-wider">Semantic Match Thresholds</span>
                <span className="text-[11px] text-[#737373]">Cosine Similarity Range: 0.0 - 1.0</span>
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="text-xs text-[#737373] block mb-1">
                    Strong Alignment Threshold: <strong className="text-[#111111] font-mono">{strongThreshold}</strong>
                  </label>
                  <input
                    type="range"
                    min="0.50"
                    max="0.95"
                    step="0.05"
                    value={strongThreshold}
                    onChange={(e) => setStrongThreshold(parseFloat(e.target.value))}
                    className="w-full accent-[#111111]"
                  />
                  <span className="text-[10px] text-[#A3A3A3]">High semantic certainty (recommended 0.70)</span>
                </div>
                <div>
                  <label className="text-xs text-[#737373] block mb-1">
                    Weak Alignment Threshold: <strong className="text-[#111111] font-mono">{weakThreshold}</strong>
                  </label>
                  <input
                    type="range"
                    min="0.30"
                    max="0.65"
                    step="0.05"
                    value={weakThreshold}
                    onChange={(e) => setWeakThreshold(parseFloat(e.target.value))}
                    className="w-full accent-[#111111]"
                  />
                  <span className="text-[10px] text-[#A3A3A3]">Partial / secondary relevance (recommended 0.50)</span>
                </div>
              </div>
            </div>
          )}

          {/* Analysis Results View */}
          {alignmentResult ? (
            <div className="space-y-6 animate-in fade-in duration-300">
              {/* Score & KPI Dashboard Cards */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="p-4 bg-white border border-[#E5E5E5] rounded-xl flex flex-col justify-between shadow-subtle">
                  <span className="text-[11px] uppercase font-bold text-[#737373] tracking-wider">Overall Alignment</span>
                  <div className="flex items-baseline gap-2 mt-1">
                    <span className="text-3xl font-black font-mono text-[#111111]">
                      {alignmentResult.overall_alignment_score}%
                    </span>
                    <span className="text-xs font-medium text-[#737373]">
                      {alignmentResult.aligned_questions_count} / {alignmentResult.total_questions} Questions
                    </span>
                  </div>
                  <div className="mt-3">
                    <ProgressBar value={alignmentResult.overall_alignment_score} size="sm" showValue={false} />
                  </div>
                </div>

                <div className="p-4 bg-white border border-[#E5E5E5] rounded-xl flex flex-col justify-between shadow-subtle">
                  <span className="text-[11px] uppercase font-bold text-[#737373] tracking-wider">LO Coverage</span>
                  <div className="flex items-baseline gap-2 mt-1">
                    <span className="text-3xl font-black font-mono text-[#111111]">
                      {alignmentResult.covered_learning_outcomes_count} / {alignmentResult.total_learning_outcomes}
                    </span>
                    <span className="text-xs font-medium text-[#737373]">Outcomes Met</span>
                  </div>
                  <div className="mt-3">
                    <ProgressBar
                      value={(alignmentResult.covered_learning_outcomes_count / (alignmentResult.total_learning_outcomes || 1)) * 100}
                      size="sm"
                      showValue={false}
                    />
                  </div>
                </div>

                <div className="p-4 bg-white border border-[#E5E5E5] rounded-xl flex flex-col justify-between shadow-subtle">
                  <span className="text-[11px] uppercase font-bold text-[#737373] tracking-wider">Embedding Engine</span>
                  <div className="mt-1">
                    <div className="text-xs font-mono font-semibold text-[#111111] truncate">all-MiniLM-L6-v2</div>
                    <div className="text-[11px] text-[#737373] mt-0.5">384-dim Dense Vectors</div>
                  </div>
                  <div className="mt-2 text-[10px] text-[#A3A3A3] flex items-center gap-1">
                    <CheckCircle2 className="w-3 h-3 text-[#16A34A]" /> Cosine Distance Analyzed
                  </div>
                </div>
              </div>

              {/* Actionable Findings */}
              {alignmentResult.findings && alignmentResult.findings.length > 0 && (
                <div className="p-4 bg-[#F7F7F5] border border-[#E5E5E5] rounded-xl space-y-2">
                  <div className="flex items-center gap-2 text-xs font-bold text-[#111111] uppercase tracking-wider">
                    <Info className="w-4 h-4 text-[#111111]" />
                    <span>Pedagogical Alignment Findings</span>
                  </div>
                  <ul className="space-y-1.5 text-xs text-[#404040]">
                    {alignmentResult.findings.map((f, idx) => (
                      <li key={idx} className="flex items-start gap-2">
                        <span className="text-[#111111] font-bold font-mono shrink-0">•</span>
                        <span>{f}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {/* Learning Outcome Coverage Breakdown */}
              <div className="space-y-3">
                <h3 className="text-xs font-bold text-[#111111] uppercase tracking-wider flex items-center justify-between">
                  <span>Course Learning Outcome Coverage</span>
                  <span className="text-[#737373] font-normal lowercase">{alignmentResult.learning_outcome_coverage.length} learning outcomes</span>
                </h3>

                <div className="grid grid-cols-1 gap-2.5">
                  {alignmentResult.learning_outcome_coverage.map((lo, idx) => {
                    const isCovered = lo.coverage_status === 'COVERED';
                    const isWeak = lo.coverage_status === 'WEAKLY_COVERED';
                    return (
                      <div
                        key={idx}
                        className="p-3.5 bg-white border border-[#E5E5E5] rounded-xl flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-2xs"
                      >
                        <div className="flex-1 space-y-1">
                          <div className="flex items-center gap-2">
                            {lo.code && (
                              <span className="px-2 py-0.5 bg-[#111111] text-white rounded font-mono text-[11px] font-semibold">
                                {lo.code}
                              </span>
                            )}
                            <span className="text-xs font-medium text-[#111111]">{lo.description}</span>
                          </div>
                          {lo.matching_questions_count > 0 && (
                            <div className="text-[11px] text-[#737373]">
                              Assessed in Questions: <strong className="text-[#111111]">{lo.matching_question_numbers.map(n => `Q${n}`).join(', ')}</strong> (Max sim: {(lo.max_similarity * 100).toFixed(1)}%)
                            </div>
                          )}
                        </div>

                        <div>
                          {isCovered ? (
                            <Badge variant="Good" dot>Covered</Badge>
                          ) : isWeak ? (
                            <Badge variant="Attention" dot>Weakly Covered</Badge>
                          ) : (
                            <Badge variant="Critical" dot>Not Covered</Badge>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>

              {/* Question to LO Alignment Mapping List */}
              <div className="space-y-3">
                <h3 className="text-xs font-bold text-[#111111] uppercase tracking-wider flex items-center justify-between">
                  <span>Question-by-Question Alignment Breakdown</span>
                  <span className="text-[#737373] font-normal lowercase">{alignmentResult.question_alignment.length} questions mapped</span>
                </h3>

                <div className="space-y-3">
                  {alignmentResult.question_alignment.map((qa, idx) => {
                    const qKey = qa.question_id || idx;
                    const isExpanded = !!expandedQuestions[qKey];
                    const status = qa.alignment_status;
                    const matchedLo = qa.matched_learning_outcome;

                    return (
                      <div
                        key={qKey}
                        className="p-4 bg-white border border-[#E5E5E5] rounded-xl space-y-2.5 shadow-2xs"
                      >
                        <div className="flex items-start justify-between gap-3">
                          <div className="flex items-start gap-2.5 flex-1">
                            <span className="px-2 py-0.5 bg-[#F7F7F5] border border-[#E5E5E5] rounded text-xs font-mono font-bold text-[#111111] shrink-0">
                              Q{qa.question_number || idx + 1}
                            </span>
                            <p className="text-xs font-medium text-[#111111] leading-relaxed">
                              {qa.question_text}
                            </p>
                          </div>

                          <div className="shrink-0 flex items-center gap-2">
                            {status === 'STRONG' ? (
                              <Badge variant="Good">Strong Match</Badge>
                            ) : status === 'WEAK' ? (
                              <Badge variant="Attention">Weak Match</Badge>
                            ) : (
                              <Badge variant="Critical">No Clear Match</Badge>
                            )}
                          </div>
                        </div>

                        {/* Matched LO summary */}
                        {matchedLo && (
                          <div className="p-2.5 bg-[#F7F7F5] rounded-lg text-xs flex items-center justify-between gap-2 border border-[#E5E5E5]">
                            <div className="flex items-center gap-2 truncate">
                              <Target className="w-3.5 h-3.5 text-[#737373] shrink-0" />
                              <span className="font-mono font-bold text-[#111111]">
                                {matchedLo.code ? `[${matchedLo.code}]` : 'Matched:'}
                              </span>
                              <span className="text-[#525252] truncate">{matchedLo.description}</span>
                            </div>
                            <span className="font-mono text-[11px] font-semibold text-[#111111] shrink-0">
                              {(qa.similarity_score * 100).toFixed(1)}% Sim
                            </span>
                          </div>
                        )}

                        {qa.reasoning && (
                          <p className="text-[11px] text-[#737373] italic">
                            {qa.reasoning}
                          </p>
                        )}

                        {/* Alternative matches toggle */}
                        {qa.alternative_matches && qa.alternative_matches.length > 0 && (
                          <div className="pt-1">
                            <button
                              onClick={() => toggleQuestionExpand(qKey)}
                              className="text-[11px] text-[#525252] hover:text-[#111111] flex items-center gap-1 font-medium transition-colors"
                            >
                              <span>{qa.alternative_matches.length} Alternative Match{qa.alternative_matches.length > 1 ? 'es' : ''}</span>
                              {isExpanded ? <ChevronUp className="w-3 h-3" /> : <ChevronDown className="w-3 h-3" />}
                            </button>

                            {isExpanded && (
                              <div className="mt-2 space-y-1 pl-3 border-l-2 border-[#E5E5E5]">
                                {qa.alternative_matches.map((alt, altIdx) => (
                                  <div key={altIdx} className="text-[11px] text-[#525252] flex items-center justify-between">
                                    <span>
                                      <strong>{alt.code || 'LO'}:</strong> {alt.description.substring(0, 70)}...
                                    </span>
                                    <span className="font-mono text-[#737373]">
                                      {(alt.similarity * 100).toFixed(1)}%
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
                <Target className="w-6 h-6" />
              </div>
              <div className="max-w-md">
                <h3 className="text-sm font-bold text-[#111111]">Ready for Alignment Analysis</h3>
                <p className="text-xs text-[#737373] mt-1">
                  Click <strong>Run Alignment Analysis</strong> above to evaluate how well your {questionsCount} assessment questions map to the {losCount} defined Course Learning Outcomes using Hugging Face semantic embeddings.
                </p>
              </div>
            </div>
          )}

          {/* Academic Decision Support Disclaimer */}
          <div className="pt-2 border-t border-[#E5E5E5] flex items-center gap-2 text-[11px] text-[#A3A3A3]">
            <Info className="w-3.5 h-3.5 shrink-0" />
            <span>
              Decision Support Notice: Semantic similarity matches serve as pedagogical heuristics. Final curriculum alignment is verified by the instructor.
            </span>
          </div>
        </div>

        {/* Modal Footer */}
        <div className="px-6 py-3.5 border-t border-[#E5E5E5] bg-[#FAFAFA] rounded-b-2xl flex items-center justify-between">
          <span className="text-xs text-[#737373]">
            {alignmentResult ? `Completed • Status: ${alignmentResult.status}` : 'Pending analysis execution'}
          </span>
          <Button variant="secondary" size="sm" onClick={onClose}>
            Close
          </Button>
        </div>
      </div>
    </div>
  );
};
