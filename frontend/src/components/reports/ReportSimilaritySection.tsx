import React from 'react';
import { Badge } from '@/components/common/Badge';
import { CheckCircle2 } from 'lucide-react';
import { ReportSimilarQuestions } from '@/types/report';

interface ReportSimilaritySectionProps {
  similarQuestions: ReportSimilarQuestions;
}

export const ReportSimilaritySection: React.FC<ReportSimilaritySectionProps> = ({
  similarQuestions,
}) => {
  const allMatches = [
    ...similarQuestions.potential_duplicates,
    ...similarQuestions.highly_similar,
    ...similarQuestions.somewhat_similar,
  ];

  return (
    <div className="bg-white border border-sage-200 rounded-xl p-6 mb-6 shadow-subtle">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-5">
        <div>
          <h3 className="text-base font-bold text-sage-800">
            4. Question Similarity &amp; Historical Overlap
          </h3>
          <p className="text-xs text-sage-500 mt-0.5">
            Cross-checks assessment questions against stored historical question banks to detect repetition and preserve examination freshness.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <span className="text-xs text-sage-500">Question Diversity Rating:</span>
          <span className="text-sm font-bold text-sage-800 bg-sage-100 px-2.5 py-1 rounded border border-sage-200">
            {similarQuestions.rating}
          </span>
        </div>
      </div>

      {/* Summary counters */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
        <div className="p-3 bg-sage-50 border border-[#EBEBEB] rounded-lg text-center">
          <div className="text-xs text-sage-500">Total Comparisons</div>
          <div className="text-lg font-bold text-sage-800 mt-0.5">{similarQuestions.total_matches}</div>
        </div>
        <div className="p-3 bg-[#FEF2F2] border border-[#FECACA] rounded-lg text-center">
          <div className="text-xs text-[#991B1B]">Potential Duplicates (&ge;85%)</div>
          <div className="text-lg font-bold text-[#991B1B] mt-0.5">{similarQuestions.potential_duplicates_count}</div>
        </div>
        <div className="p-3 bg-[#FFFBEB] border border-[#FDE68A] rounded-lg text-center">
          <div className="text-xs text-[#92400E]">Highly Similar (70–84%)</div>
          <div className="text-lg font-bold text-[#92400E] mt-0.5">{similarQuestions.highly_similar_count}</div>
        </div>
        <div className="p-3 bg-[#F0FDF4] border border-[#BBF7D0] rounded-lg text-center">
          <div className="text-xs text-[#166534]">Somewhat Similar (50–69%)</div>
          <div className="text-lg font-bold text-[#166534] mt-0.5">{similarQuestions.somewhat_similar_count}</div>
        </div>
      </div>

      {allMatches.length > 0 ? (
        <div className="border border-sage-200 rounded-lg overflow-hidden">
          <table className="w-full text-left text-xs border-collapse">
            <thead className="bg-sage-100 border-b border-sage-200 text-sage-700 font-semibold">
              <tr>
                <th className="py-2.5 px-3 text-center w-12">Q#</th>
                <th className="py-2.5 px-4 w-1/3">Current Assessment Question</th>
                <th className="py-2.5 px-4 w-1/3">Matched Historical Question</th>
                <th className="py-2.5 px-3 w-32">Source Exam / Year</th>
                <th className="py-2.5 px-3 text-center w-24">Similarity</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#EBEBEB]">
              {allMatches.map((m, idx) => {
                const score = m.similarity_score;
                const badgeVariant = score >= 0.85 ? 'Critical' : score >= 0.70 ? 'Attention' : 'Good';

                return (
                  <tr key={idx} className="hover:bg-sage-50 transition-colors">
                    <td className="py-2.5 px-3 text-center font-bold text-sage-800">
                      Q{m.current_question_number}
                    </td>
                    <td className="py-2.5 px-4 text-sage-700 font-medium leading-relaxed">
                      {m.current_question_text}
                    </td>
                    <td className="py-2.5 px-4 text-sage-600 leading-relaxed">
                      {m.previous_question_text}
                    </td>
                    <td className="py-2.5 px-3 text-sage-500 text-[11px]">
                      <div>{m.previous_assessment_title}</div>
                      <div className="font-mono text-[10px]">{m.previous_year}</div>
                    </td>
                    <td className="py-2.5 px-3 text-center">
                      <Badge variant={badgeVariant} size="sm">
                        {Math.round(score * 100)}%
                      </Badge>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="flex items-center gap-3 p-4 bg-[#F0FDF4] border border-[#BBF7D0] rounded-lg text-xs text-[#166534]">
          <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0" />
          <div>
            <strong>Excellent Originality:</strong> No critical question overlap or near-duplicate phrasing was identified against historical examination archives.
          </div>
        </div>
      )}
    </div>
  );
};
