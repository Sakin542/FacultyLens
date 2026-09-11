import React from 'react';
import { Badge } from '@/components/common/Badge';
import { ReportTopicCoverage } from '@/types/report';

interface ReportTopicSectionProps {
  topicCoverage: ReportTopicCoverage;
}

export const ReportTopicSection: React.FC<ReportTopicSectionProps> = ({ topicCoverage }) => {
  const getStatusBadge = (status: string) => {
    switch (status.toUpperCase()) {
      case 'COVERED':
        return <Badge variant="Good" size="sm">COVERED</Badge>;
      case 'LOW_COVERAGE':
        return <Badge variant="Attention" size="sm">LOW COVERAGE</Badge>;
      default:
        return <Badge variant="Critical" size="sm">NOT COVERED</Badge>;
    }
  };

  return (
    <div className="bg-white border border-sage-200 rounded-xl p-6 mb-6 shadow-subtle">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-5">
        <div>
          <h3 className="text-base font-bold text-sage-800">
            1. Syllabus Topic Coverage
          </h3>
          <p className="text-xs text-sage-500 mt-0.5">
            Analysis of question allocation and marks representation across syllabus curriculum topics.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <span className="text-xs text-sage-500">Overall Topic Coverage:</span>
          <span className="text-sm font-bold text-sage-800 bg-sage-100 px-2.5 py-1 rounded border border-sage-200">
            {Math.round(topicCoverage.score * 10) / 10}%
          </span>
        </div>
      </div>

      {/* Stats summary row */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
        <div className="p-3 bg-sage-50 border border-[#EBEBEB] rounded-lg text-center">
          <div className="text-xs text-sage-500">Total Topics</div>
          <div className="text-lg font-bold text-sage-800 mt-0.5">{topicCoverage.total_topics}</div>
        </div>
        <div className="p-3 bg-[#F0FDF4] border border-[#BBF7D0] rounded-lg text-center">
          <div className="text-xs text-[#166534]">Covered</div>
          <div className="text-lg font-bold text-[#166534] mt-0.5">{topicCoverage.covered_topics}</div>
        </div>
        <div className="p-3 bg-[#FFFBEB] border border-[#FDE68A] rounded-lg text-center">
          <div className="text-xs text-[#92400E]">Low Coverage</div>
          <div className="text-lg font-bold text-[#92400E] mt-0.5">{topicCoverage.low_coverage_topics}</div>
        </div>
        <div className="p-3 bg-[#FEF2F2] border border-[#FECACA] rounded-lg text-center">
          <div className="text-xs text-[#991B1B]">Not Covered</div>
          <div className="text-lg font-bold text-[#991B1B] mt-0.5">{topicCoverage.not_covered_topics}</div>
        </div>
      </div>

      {/* Topics Table */}
      {topicCoverage.topics && topicCoverage.topics.length > 0 ? (
        <div className="border border-sage-200 rounded-lg overflow-hidden">
          <table className="w-full text-left text-xs border-collapse">
            <thead className="bg-sage-100 border-b border-sage-200 text-sage-700 font-semibold">
              <tr>
                <th className="py-2.5 px-4">Topic Name</th>
                <th className="py-2.5 px-4 text-center">Coverage Status</th>
                <th className="py-2.5 px-4 text-center">Questions Assigned</th>
                <th className="py-2.5 px-4 text-right">Total Marks</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#EBEBEB]">
              {topicCoverage.topics.map((t, index) => (
                <tr key={index} className="hover:bg-sage-50 transition-colors">
                  <td className="py-2.5 px-4 font-medium text-sage-800">
                    {t.topic}
                  </td>
                  <td className="py-2.5 px-4 text-center">
                    {getStatusBadge(t.status)}
                  </td>
                  <td className="py-2.5 px-4 text-center font-mono text-sage-600">
                    {t.question_count}
                  </td>
                  <td className="py-2.5 px-4 text-right font-mono text-sage-800">
                    {t.marks}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="text-center py-6 text-xs text-sage-500 bg-sage-50 rounded-lg border border-[#EBEBEB]">
          No topic breakdown records recorded in current analysis snapshot.
        </div>
      )}
    </div>
  );
};

