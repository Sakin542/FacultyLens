import React, { useState } from 'react';
import { Card, CardHeader, CardTitle, CardContent, CardDescription } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import { ProgressBar } from '@/components/dashboard/ProgressBar';
import { Button } from '@/components/common/Button';
import {
  Target,
  Info,
  ChevronDown,
  ChevronUp,
  Sparkles,
} from 'lucide-react';
import { AlignmentAnalysisResult } from '@/types';

interface LearningOutcomeAlignmentSectionProps {
  alignmentData?: AlignmentAnalysisResult | null;
  onTriggerAnalysis?: () => void;
  isLoading?: boolean;
}

export const LearningOutcomeAlignmentSection: React.FC<LearningOutcomeAlignmentSectionProps> = ({
  alignmentData,
  onTriggerAnalysis,
  isLoading = false,
}) => {
  const [expandedQuestions, setExpandedQuestions] = useState<Record<string | number, boolean>>({});

  const toggleQuestion = (id: string | number) => {
    setExpandedQuestions((prev) => ({ ...prev, [id]: !prev[id] }));
  };

  if (!alignmentData) {
    return (
      <Card className="p-6 text-center space-y-3">
        <div className="w-12 h-12 rounded-2xl bg-[#F7F7F5] border border-[#E5E5E5] flex items-center justify-center mx-auto text-[#737373]">
          <Target className="w-6 h-6" />
        </div>
        <div>
          <h3 className="text-sm font-bold text-[#111111]">No Alignment Analysis Available</h3>
          <p className="text-xs text-[#737373] mt-1 max-w-md mx-auto">
            Analyze examination questions against defined Course Learning Outcomes using dense sentence embeddings.
          </p>
        </div>
        {onTriggerAnalysis && (
          <Button
            variant="primary"
            size="sm"
            onClick={onTriggerAnalysis}
            disabled={isLoading}
            className="gap-2 mx-auto text-xs"
          >
            <Sparkles className="w-3.5 h-3.5" />
            <span>Analyze Alignment</span>
          </Button>
        )}
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      {/* Top Alignment Statistics */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <Card className="p-5 flex flex-col justify-between">
          <div className="flex items-start justify-between">
            <div>
              <span className="text-xs uppercase font-semibold text-[#737373] tracking-wider">Overall LO Score</span>
              <div className="text-3xl font-extrabold text-[#111111] mt-1 font-mono">
                {alignmentData.overall_alignment_score}%
              </div>
            </div>
            <Badge variant={alignmentData.overall_alignment_score >= 75 ? 'Good' : 'Attention'}>
              {alignmentData.overall_alignment_score >= 75 ? 'High Alignment' : 'Review Needed'}
            </Badge>
          </div>
          <div className="pt-3">
            <ProgressBar value={alignmentData.overall_alignment_score} showValue={false} size="sm" />
          </div>
        </Card>

        <Card className="p-5 flex flex-col justify-between">
          <div className="flex items-start justify-between">
            <div>
              <span className="text-xs uppercase font-semibold text-[#737373] tracking-wider">LO Coverage</span>
              <div className="text-3xl font-extrabold text-[#111111] mt-1 font-mono">
                {alignmentData.covered_learning_outcomes_count} / {alignmentData.total_learning_outcomes}
              </div>
            </div>
            <Badge variant="neutral">Outcomes Covered</Badge>
          </div>
          <div className="pt-3">
            <ProgressBar
              value={(alignmentData.covered_learning_outcomes_count / (alignmentData.total_learning_outcomes || 1)) * 100}
              showValue={false}
              size="sm"
            />
          </div>
        </Card>

        <Card className="p-5 flex flex-col justify-between">
          <div className="flex items-start justify-between">
            <div>
              <span className="text-xs uppercase font-semibold text-[#737373] tracking-wider">Aligned Questions</span>
              <div className="text-3xl font-extrabold text-[#111111] mt-1 font-mono">
                {alignmentData.aligned_questions_count} / {alignmentData.total_questions}
              </div>
            </div>
            <Badge variant="neutral">Questions</Badge>
          </div>
          <div className="pt-3">
            <ProgressBar
              value={(alignmentData.aligned_questions_count / (alignmentData.total_questions || 1)) * 100}
              showValue={false}
              size="sm"
            />
          </div>
        </Card>
      </div>

      {/* Structured Findings */}
      {alignmentData.findings && alignmentData.findings.length > 0 && (
        <Card className="p-4 bg-[#F7F7F5] border border-[#E5E5E5] space-y-2">
          <div className="flex items-center gap-2 text-xs font-bold text-[#111111] uppercase tracking-wider">
            <Info className="w-4 h-4" />
            <span>Semantic Alignment Insights</span>
          </div>
          <ul className="space-y-1.5 text-xs text-[#404040]">
            {alignmentData.findings.map((finding, idx) => (
              <li key={idx} className="flex items-start gap-2">
                <span className="text-[#111111] font-bold font-mono">•</span>
                <span>{finding}</span>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {/* LO Coverage Grid */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Learning Outcome Coverage</CardTitle>
          <CardDescription>
            Evaluation of how effectively each course learning outcome is addressed across questions.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {alignmentData.learning_outcome_coverage.map((lo, idx) => {
            const isCovered = lo.coverage_status === 'COVERED';
            const isWeak = lo.coverage_status === 'WEAKLY_COVERED';
            return (
              <div
                key={idx}
                className="p-3.5 bg-white border border-[#E5E5E5] rounded-xl flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-2xs"
              >
                <div className="space-y-1 flex-1">
                  <div className="flex items-center gap-2">
                    {lo.code && (
                      <span className="px-2 py-0.5 bg-[#111111] text-white rounded font-mono text-[11px] font-semibold">
                        {lo.code}
                      </span>
                    )}
                    <span className="text-xs font-semibold text-[#111111]">{lo.description}</span>
                  </div>
                  {lo.matching_questions_count > 0 && (
                    <div className="text-[11px] text-[#737373]">
                      Represented in questions: <strong className="text-[#111111]">{lo.matching_question_numbers.map(n => `Q${n}`).join(', ')}</strong> (Peak similarity: {(lo.max_similarity * 100).toFixed(1)}%)
                    </div>
                  )}
                </div>

                <div className="shrink-0">
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
        </CardContent>
      </Card>

      {/* Question Mapping Breakdown */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Question-to-Outcome Mapping</CardTitle>
          <CardDescription>
            Detailed semantic similarity alignments for individual examination questions.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {alignmentData.question_alignment.map((qa, idx) => {
            const key = qa.question_id || idx;
            const isExpanded = !!expandedQuestions[key];
            const matchedLo = qa.matched_learning_outcome;

            return (
              <div key={key} className="p-4 bg-[#FAFAFA] border border-[#E5E5E5] rounded-xl space-y-2">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex items-start gap-2.5 flex-1">
                    <span className="px-2 py-0.5 bg-white border border-[#E5E5E5] rounded text-xs font-mono font-bold text-[#111111] shrink-0">
                      Q{qa.question_number || idx + 1}
                    </span>
                    <p className="text-xs font-medium text-[#111111] leading-relaxed">
                      {qa.question_text}
                    </p>
                  </div>
                  <div className="shrink-0">
                    {qa.alignment_status === 'STRONG' ? (
                      <Badge variant="Good">Strong</Badge>
                    ) : qa.alignment_status === 'WEAK' ? (
                      <Badge variant="Attention">Weak</Badge>
                    ) : (
                      <Badge variant="Critical">Unmapped</Badge>
                    )}
                  </div>
                </div>

                {matchedLo && (
                  <div className="p-2.5 bg-white rounded-lg text-xs flex items-center justify-between gap-2 border border-[#E5E5E5]">
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
                  <p className="text-[11px] text-[#737373] italic">{qa.reasoning}</p>
                )}

                {qa.alternative_matches && qa.alternative_matches.length > 0 && (
                  <div className="pt-1">
                    <button
                      onClick={() => toggleQuestion(key)}
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
        </CardContent>
      </Card>
    </div>
  );
};
