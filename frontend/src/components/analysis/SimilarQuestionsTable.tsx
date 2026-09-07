import React from 'react';
import { Card } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import { CopyCheck } from 'lucide-react';
import { SimilarityAnalysisResult } from '@/types';

interface SimilarQuestionsTableProps {
  similarityAnalysis?: SimilarityAnalysisResult | null;
  similarityMatches?: Array<{
    id: number | string;
    current_question_id: number | string;
    previous_question_id: number | string;
    previous_question_text?: string;
    previous_assessment_title?: string;
    previous_year?: string | number;
    similarity_score: number;
    similarity_status: string;
    reasoning?: string;
  }>;
}

export const SimilarQuestionsTable: React.FC<SimilarQuestionsTableProps> = ({
  similarityAnalysis,
  similarityMatches = [],
}) => {
  const potentialCount = similarityAnalysis?.potential_duplicates_count ??
    similarityMatches.filter((m) => m.similarity_status === 'POTENTIAL_DUPLICATE').length;
  const highlyCount = similarityAnalysis?.highly_similar_count ??
    similarityMatches.filter((m) => m.similarity_status === 'HIGHLY_SIMILAR').length;
  const somewhatCount = similarityAnalysis?.somewhat_similar_count ??
    similarityMatches.filter((m) => m.similarity_status === 'SOMEWHAT_SIMILAR').length;

  const getStatusBadge = (status: string) => {
    const s = status.toUpperCase();
    if (s === 'POTENTIAL_DUPLICATE') {
      return <Badge variant="Critical" size="sm" dot>Potential Duplicate</Badge>;
    }
    if (s === 'HIGHLY_SIMILAR') {
      return <Badge variant="Attention" size="sm" dot>Highly Similar</Badge>;
    }
    return <Badge variant="neutral" size="sm">Somewhat Similar</Badge>;
  };

  return (
    <Card className="p-5 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-sm space-y-4">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-[#E5E5E5] dark:border-[#2C2C2E]">
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center text-[#111111] dark:text-white">
            <CopyCheck className="w-4 h-4" />
          </div>
          <div>
            <h3 className="text-sm font-bold text-[#111111] dark:text-white">Similar Questions</h3>
            <p className="text-xs text-[#737373]">Semantic overlap detected against historical question bank</p>
          </div>
        </div>

        {/* Summary Badges */}
        <div className="flex items-center gap-2">
          <div className="px-2.5 py-1 rounded-lg bg-[#FEF2F2] border border-[#FECACA] text-[#991B1B] text-xs font-semibold">
            Potential Duplicates: <strong>{potentialCount}</strong>
          </div>
          <div className="px-2.5 py-1 rounded-lg bg-[#FFFBEB] border border-[#FDE68A] text-[#92400E] text-xs font-semibold">
            Highly Similar: <strong>{highlyCount}</strong>
          </div>
          <div className="px-2.5 py-1 rounded-lg bg-[#F7F7F5] border border-[#E5E5E5] text-[#737373] text-xs font-semibold">
            Somewhat: <strong>{somewhatCount}</strong>
          </div>
        </div>
      </div>

      {similarityMatches.length === 0 ? (
        <div className="p-4 text-center text-xs text-[#737373] italic">
          No significant semantic similarity detected with past examination questions. All current questions appear original.
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs border-collapse">
            <thead>
              <tr className="border-b border-[#E5E5E5] dark:border-[#3A3A3C] text-[#737373]">
                <th className="py-2.5 px-3 font-semibold">Current Question</th>
                <th className="py-2.5 px-3 font-semibold">Matched Past Question</th>
                <th className="py-2.5 px-3 font-semibold">Past Assessment</th>
                <th className="py-2.5 px-3 font-semibold">Similarity</th>
                <th className="py-2.5 px-3 font-semibold">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#E5E5E5] dark:divide-[#3A3A3C]">
              {similarityMatches.map((match) => (
                <tr key={match.id} className="hover:bg-[#F7F7F5] dark:hover:bg-[#2C2C2E]">
                  <td className="py-2.5 px-3 font-mono font-bold text-[#111111] dark:text-white">
                    Q#{match.current_question_id}
                  </td>
                  <td className="py-2.5 px-3 text-[#262626] dark:text-[#D4D4D4] max-w-sm truncate">
                    {match.previous_question_text || `Question #${match.previous_question_id}`}
                  </td>
                  <td className="py-2.5 px-3 text-[#737373]">
                    {match.previous_assessment_title || 'Past Exam'}
                    {match.previous_year ? ` (${match.previous_year})` : ''}
                  </td>
                  <td className="py-2.5 px-3 font-mono font-bold text-[#111111] dark:text-white">
                    {Math.round(match.similarity_score * 100)}%
                  </td>
                  <td className="py-2.5 px-3">
                    {getStatusBadge(match.similarity_status)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] text-[11px] text-[#737373] leading-relaxed">
        Semantic similarity is evidence for faculty review, not proof of exact duplication. Verify question contexts before deciding whether revisions are needed.
      </div>
    </Card>
  );
};
