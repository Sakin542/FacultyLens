import React, { useState } from 'react';
import { Card } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import { Target, ChevronDown, ChevronUp } from 'lucide-react';
import { AlignmentAnalysisResult } from '@/types';

interface LearningOutcomeAlignmentCardProps {
  alignmentAnalysis?: AlignmentAnalysisResult | null;
  overallScore?: number | null;
  alignmentsList?: Array<{
    id: number | string;
    question_id: number | string;
    learning_outcome_id: number | string;
    learning_outcome_code?: string;
    learning_outcome_description?: string;
    similarity_score: number;
    alignment: string;
    reasoning?: string;
  }>;
}

export const LearningOutcomeAlignmentCard: React.FC<LearningOutcomeAlignmentCardProps> = ({
  alignmentAnalysis,
  overallScore,
  alignmentsList = [],
}) => {
  const [showMappingTable, setShowMappingTable] = useState(false);
  const coverage = alignmentAnalysis?.learning_outcome_coverage || [];
  const score = overallScore ?? alignmentAnalysis?.overall_alignment_score;

  const getAlignmentBadge = (status: string) => {
    const s = (status || '').toUpperCase();
    if (s === 'STRONG' || s === 'COVERED') {
      return <Badge variant="Good" size="sm">Strong</Badge>;
    }
    if (s === 'WEAK' || s === 'WEAKLY_COVERED') {
      return <Badge variant="Attention" size="sm">Weak</Badge>;
    }
    return <Badge variant="Critical" size="sm">Not Aligned</Badge>;
  };

  return (
    <Card className="p-5 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-sm space-y-4">
      <div className="flex items-center justify-between pb-3 border-b border-[#E5E5E5] dark:border-[#2C2C2E]">
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center text-[#111111] dark:text-white">
            <Target className="w-4 h-4" />
          </div>
          <div>
            <h3 className="text-sm font-bold text-[#111111] dark:text-white">
              Learning Outcome Alignment
            </h3>
            <p className="text-xs text-[#737373]">Semantic mapping to course learning outcomes</p>
          </div>
        </div>

        <div className="text-right">
          <span className="text-xs text-[#737373] block">Overall Alignment</span>
          <span className="text-lg font-extrabold font-mono text-[#111111] dark:text-white">
            {score !== null && score !== undefined ? `${Math.round(score)}%` : '—'}
          </span>
        </div>
      </div>

      {coverage.length === 0 && alignmentsList.length === 0 ? (
        <div className="p-4 text-center text-xs text-[#737373] italic">
          Learning outcome analysis unavailable. Define course learning outcomes in course settings to enable semantic alignment scoring.
        </div>
      ) : (
        <div className="space-y-3">
          {/* LO List */}
          <div className="space-y-2">
            {coverage.map((lo, idx) => (
              <div
                key={idx}
                className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-between gap-3 text-xs"
              >
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <span className="font-mono font-bold text-xs text-[#111111] dark:text-white">
                      {lo.code}
                    </span>
                    <span className="text-[11px] text-[#737373]">
                      {lo.matching_questions_count} {lo.matching_questions_count === 1 ? 'question' : 'questions'}
                    </span>
                  </div>
                  {lo.description && (
                    <p className="text-[11px] text-[#737373] mt-0.5 truncate max-w-md">
                      {lo.description}
                    </p>
                  )}
                </div>

                <div className="shrink-0 flex items-center gap-2">
                  {lo.max_similarity !== undefined && (
                    <span className="font-mono text-[11px] text-[#737373]">
                      Semantic similarity: {lo.max_similarity.toFixed(2)}
                    </span>
                  )}
                  {getAlignmentBadge(lo.coverage_status)}
                </div>
              </div>
            ))}
          </div>

          {/* Question -> LO Mapping Toggle */}
          {alignmentsList.length > 0 && (
            <div className="pt-2 border-t border-[#E5E5E5] dark:border-[#2C2C2E]">
              <button
                type="button"
                onClick={() => setShowMappingTable(!showMappingTable)}
                className="flex items-center justify-between w-full text-xs font-semibold text-[#111111] dark:text-white hover:text-[#737373] transition-colors py-1"
              >
                <span>Question → Learning Outcome Mapping ({alignmentsList.length})</span>
                {showMappingTable ? <ChevronUp className="w-4 h-4" /> : <ChevronDown className="w-4 h-4" />}
              </button>

              {showMappingTable && (
                <div className="mt-2.5 overflow-x-auto">
                  <table className="w-full text-left text-xs border-collapse">
                    <thead>
                      <tr className="border-b border-[#E5E5E5] dark:border-[#3A3A3C] text-[#737373]">
                        <th className="py-2 px-2.5 font-semibold">Question ID</th>
                        <th className="py-2 px-2.5 font-semibold">Target LO</th>
                        <th className="py-2 px-2.5 font-semibold">Semantic Similarity</th>
                        <th className="py-2 px-2.5 font-semibold">Alignment</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-[#E5E5E5] dark:divide-[#3A3A3C]">
                      {alignmentsList.map((item) => (
                        <tr key={item.id} className="hover:bg-[#F7F7F5] dark:hover:bg-[#2C2C2E]">
                          <td className="py-2 px-2.5 font-mono text-[11px] text-[#111111] dark:text-white">
                            #{item.question_id}
                          </td>
                          <td className="py-2 px-2.5 font-mono text-[11px] text-[#111111] dark:text-white">
                            {item.learning_outcome_code || `LO #${item.learning_outcome_id}`}
                          </td>
                          <td className="py-2 px-2.5 font-mono text-[11px] text-[#737373]">
                            {item.similarity_score.toFixed(2)}
                          </td>
                          <td className="py-2 px-2.5">
                            {getAlignmentBadge(item.alignment)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </Card>
  );
};

