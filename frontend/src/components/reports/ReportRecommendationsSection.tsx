import React from 'react';
import { Badge } from '@/components/common/Badge';
import { ReportRecommendation, ReportRecommendationSummary } from '@/types/report';

interface ReportRecommendationsSectionProps {
  recommendations: ReportRecommendation[];
  summary: ReportRecommendationSummary;
}

export const ReportRecommendationsSection: React.FC<ReportRecommendationsSectionProps> = ({
  recommendations,
  summary,
}) => {
  const getPriorityBadge = (prio: string) => {
    switch (prio.toLowerCase()) {
      case 'high':
        return <Badge variant="Critical" size="sm">HIGH</Badge>;
      case 'medium':
        return <Badge variant="Attention" size="sm">MEDIUM</Badge>;
      default:
        return <Badge variant="neutral" size="sm">LOW</Badge>;
    }
  };

  const getStatusBadge = (status: string) => {
    switch (status.toLowerCase()) {
      case 'accepted':
        return <Badge variant="Good" size="sm">ACCEPTED</Badge>;
      case 'dismissed':
        return <Badge variant="neutral" size="sm">DISMISSED</Badge>;
      case 'reviewed':
        return <Badge variant="default" size="sm">REVIEWED</Badge>;
      default:
        return <Badge variant="Attention" size="sm">PENDING</Badge>;
    }
  };

  return (
    <div className="bg-white border border-[#E5E5E5] rounded-xl p-6 mb-6 shadow-subtle">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-5">
        <div>
          <h3 className="text-base font-bold text-[#111111]">
            6. Actionable Recommendations &amp; Faculty Decision Log
          </h3>
          <p className="text-xs text-[#737373] mt-0.5">
            Documented decision-support advisory record showing faculty judgment and audit status.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <span className="text-xs font-semibold text-[#111111] bg-[#F7F7F5] px-2.5 py-1 rounded border border-[#E5E5E5]">
            {summary.total} Recommendations
          </span>
          <span className="text-xs font-semibold text-emerald-700 bg-emerald-50 px-2 py-1 rounded border border-emerald-200">
            {summary.accepted} Accepted
          </span>
          <span className="text-xs font-semibold text-amber-700 bg-amber-50 px-2 py-1 rounded border border-amber-200">
            {summary.pending} Pending
          </span>
        </div>
      </div>

      {recommendations && recommendations.length > 0 ? (
        <div className="border border-[#E5E5E5] rounded-lg overflow-hidden">
          <table className="w-full text-left text-xs border-collapse">
            <thead className="bg-[#F7F7F5] border-b border-[#E5E5E5] text-[#262626] font-semibold">
              <tr>
                <th className="py-2.5 px-3 text-center w-20">Priority</th>
                <th className="py-2.5 px-4 w-36">Category</th>
                <th className="py-2.5 px-4">Problem &amp; Suggested Revision</th>
                <th className="py-2.5 px-3 text-center w-32">Faculty Action</th>
                <th className="py-2.5 px-3 text-center w-28">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#EBEBEB]">
              {recommendations.map((rec) => (
                <tr key={rec.id} className="hover:bg-[#FAFAFA] transition-colors">
                  <td className="py-3 px-3 text-center">
                    {getPriorityBadge(rec.priority)}
                  </td>
                  <td className="py-3 px-4 font-semibold text-[#111111]">
                    {rec.category}
                  </td>
                  <td className="py-3 px-4 text-[#262626] leading-relaxed">
                    <div className="font-medium text-[#111111]">
                      {rec.problem}
                    </div>
                    <div className="text-blue-900 mt-1">
                      <strong className="text-blue-950">Actionable Suggestion:</strong> {rec.recommendation}
                    </div>
                    {rec.explanation && (
                      <div className="text-[#666666] text-[11px] mt-1 italic">
                        {rec.explanation}
                      </div>
                    )}
                  </td>
                  <td className="py-3 px-3 text-center font-medium text-[#333333]">
                    {rec.action_taken}
                  </td>
                  <td className="py-3 px-3 text-center">
                    {getStatusBadge(rec.status)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="p-4 bg-[#F8FAFC] border border-[#E2E8F0] rounded-lg text-xs text-[#475569] text-center">
          No open recommendations pending for this assessment.
        </div>
      )}
    </div>
  );
};

