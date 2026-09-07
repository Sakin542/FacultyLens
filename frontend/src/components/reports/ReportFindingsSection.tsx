import React from 'react';
import { Badge } from '@/components/common/Badge';
import { AlertCircle } from 'lucide-react';
import { ReportFinding } from '@/types/report';

interface ReportFindingsSectionProps {
  findings: ReportFinding[];
}

export const ReportFindingsSection: React.FC<ReportFindingsSectionProps> = ({ findings }) => {
  const getSeverityBadge = (severity: string) => {
    switch (severity.toLowerCase()) {
      case 'critical':
      case 'high':
        return <Badge variant="Critical" size="sm">CRITICAL</Badge>;
      case 'warning':
      case 'medium':
        return <Badge variant="Attention" size="sm">WARNING</Badge>;
      default:
        return <Badge variant="neutral" size="sm">INFO</Badge>;
    }
  };

  return (
    <div className="bg-white border border-[#E5E5E5] rounded-xl p-6 mb-6 shadow-subtle">
      <div className="mb-5">
        <h3 className="text-base font-bold text-[#111111]">
          5. Diagnostic AI Findings &amp; Observations
        </h3>
        <p className="text-xs text-[#737373] mt-0.5">
          Systematic analytical observations regarding syllabus span, cognitive depth, and marks distribution patterns.
        </p>
      </div>

      {findings && findings.length > 0 ? (
        <div className="border border-[#E5E5E5] rounded-lg overflow-hidden">
          <table className="w-full text-left text-xs border-collapse">
            <thead className="bg-[#F7F7F5] border-b border-[#E5E5E5] text-[#262626] font-semibold">
              <tr>
                <th className="py-2.5 px-3 text-center w-24">Severity</th>
                <th className="py-2.5 px-4 w-48">Area / Category</th>
                <th className="py-2.5 px-4">Observed Finding</th>
                <th className="py-2.5 px-4">Diagnostic Context &amp; Evidence</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#EBEBEB]">
              {findings.map((f, idx) => (
                <tr key={idx} className="hover:bg-[#FAFAFA] transition-colors">
                  <td className="py-2.5 px-3 text-center">
                    {getSeverityBadge(f.severity)}
                  </td>
                  <td className="py-2.5 px-4 font-semibold text-[#111111]">
                    {f.category}
                  </td>
                  <td className="py-2.5 px-4 text-[#262626] leading-relaxed">
                    {f.problem}
                  </td>
                  <td className="py-2.5 px-4 text-[#525252] leading-relaxed text-[11px]">
                    {f.explanation || f.evidence || 'Identified through assessment parameter analysis.'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="flex items-center gap-3 p-4 bg-[#F8FAFC] border border-[#E2E8F0] rounded-lg text-xs text-[#475569]">
          <AlertCircle className="w-4 h-4 text-[#64748B] shrink-0" />
          <span>No critical diagnostic findings noted for this assessment.</span>
        </div>
      )}
    </div>
  );
};

