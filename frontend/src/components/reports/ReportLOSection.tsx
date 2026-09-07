import React from 'react';
import { Badge } from '@/components/common/Badge';
import { ReportLOAlignment } from '@/types/report';

interface ReportLOSectionProps {
  loAlignment: ReportLOAlignment;
}

export const ReportLOSection: React.FC<ReportLOSectionProps> = ({ loAlignment }) => {
  const getAlignmentBadge = (status: string) => {
    switch (status.toUpperCase()) {
      case 'STRONG':
      case 'HIGH':
        return <Badge variant="Good" size="sm">STRONG</Badge>;
      case 'WEAK':
      case 'MODERATE':
        return <Badge variant="Attention" size="sm">WEAK</Badge>;
      default:
        return <Badge variant="Critical" size="sm">NOT ALIGNED</Badge>;
    }
  };

  return (
    <div className="bg-white border border-[#E5E5E5] rounded-xl p-6 mb-6 shadow-subtle">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-5">
        <div>
          <h3 className="text-base font-bold text-[#111111]">
            2. Learning Outcome (LO) Alignment Analysis
          </h3>
          <p className="text-xs text-[#737373] mt-0.5">
            Evaluates semantic relevance and conceptual coherence between questions and target learning outcomes using NLP cosine similarity.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <span className="text-xs text-[#737373]">Composite LO Alignment:</span>
          <span className="text-sm font-bold text-[#111111] bg-[#F7F7F5] px-2.5 py-1 rounded border border-[#E5E5E5]">
            {Math.round(loAlignment.score * 10) / 10}%
          </span>
        </div>
      </div>

      {/* Outcomes Summary Table */}
      <div className="mb-6">
        <h4 className="text-xs font-bold uppercase tracking-wider text-[#525252] mb-2.5">
          Course Learning Outcomes Summary
        </h4>
        <div className="border border-[#E5E5E5] rounded-lg overflow-hidden">
          <table className="w-full text-left text-xs border-collapse">
            <thead className="bg-[#F7F7F5] border-b border-[#E5E5E5] text-[#262626] font-semibold">
              <tr>
                <th className="py-2.5 px-4 w-24">LO Code</th>
                <th className="py-2.5 px-4">Description</th>
                <th className="py-2.5 px-4 text-center w-28">Questions</th>
                <th className="py-2.5 px-4 text-center w-32">Mean Similarity</th>
                <th className="py-2.5 px-4 text-center w-32">Alignment Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#EBEBEB]">
              {loAlignment.outcomes && loAlignment.outcomes.length > 0 ? (
                loAlignment.outcomes.map((lo, idx) => (
                  <tr key={idx} className="hover:bg-[#FAFAFA] transition-colors">
                    <td className="py-2.5 px-4 font-bold text-[#111111]">
                      {lo.code}
                    </td>
                    <td className="py-2.5 px-4 text-[#525252]">
                      {lo.description}
                    </td>
                    <td className="py-2.5 px-4 text-center font-mono text-[#111111]">
                      {lo.question_count}
                    </td>
                    <td className="py-2.5 px-4 text-center font-mono font-medium text-[#111111]">
                      {Number(lo.average_score).toFixed(2)}
                    </td>
                    <td className="py-2.5 px-4 text-center">
                      {getAlignmentBadge(lo.alignment_status)}
                    </td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan={5} className="py-4 text-center text-xs text-[#737373]">
                    No learning outcome records defined for this course.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>

      {/* Question-to-LO Detailed Mapping */}
      {loAlignment.question_mappings && loAlignment.question_mappings.length > 0 && (
        <div>
          <h4 className="text-xs font-bold uppercase tracking-wider text-[#525252] mb-2.5">
            Question-to-Learning Outcome Detailed Mapping
          </h4>
          <div className="border border-[#E5E5E5] rounded-lg overflow-hidden">
            <table className="w-full text-left text-xs border-collapse">
              <thead className="bg-[#F7F7F5] border-b border-[#E5E5E5] text-[#262626] font-semibold">
                <tr>
                  <th className="py-2.5 px-3 text-center w-12">Q#</th>
                  <th className="py-2.5 px-4">Question Content</th>
                  <th className="py-2.5 px-3 w-24">Target LO</th>
                  <th className="py-2.5 px-3 text-center w-24">Similarity</th>
                  <th className="py-2.5 px-3 text-center w-28">Alignment</th>
                  <th className="py-2.5 px-4">AI Assessment Notes</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[#EBEBEB]">
                {loAlignment.question_mappings.map((qm, qIdx) => (
                  <tr key={qIdx} className="hover:bg-[#FAFAFA] transition-colors">
                    <td className="py-2.5 px-3 text-center font-bold text-[#111111]">
                      Q{qm.question_number}
                    </td>
                    <td className="py-2.5 px-4 text-[#333333] font-medium max-w-xs truncate">
                      {qm.question_text}
                    </td>
                    <td className="py-2.5 px-3 font-semibold text-[#111111]">
                      {qm.lo_code}
                    </td>
                    <td className="py-2.5 px-3 text-center font-mono font-medium text-[#111111]">
                      {Number(qm.similarity_score).toFixed(2)}
                    </td>
                    <td className="py-2.5 px-3 text-center">
                      {getAlignmentBadge(qm.alignment)}
                    </td>
                    <td className="py-2.5 px-4 text-[#666666] text-[11px]">
                      {qm.reasoning || 'Direct conceptual alignment observed.'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
};

