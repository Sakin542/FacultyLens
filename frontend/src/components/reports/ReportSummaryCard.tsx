import React from 'react';
import { Info, Award, Calendar, Cpu } from 'lucide-react';
import { Badge } from '@/components/common/Badge';
import { ReportOverallQuality, GeneratedPdfReportInfo } from '@/types/report';

interface ReportSummaryCardProps {
  overallQuality: ReportOverallQuality;
  disclaimer: string;
  generatedReport: GeneratedPdfReportInfo | null;
  analyzedAt: string;
  engineMetadata: {
    system: string;
    version: string;
    analysis_report_id: number;
  };
}

export const ReportSummaryCard: React.FC<ReportSummaryCardProps> = ({
  overallQuality,
  disclaimer,
  generatedReport,
  analyzedAt,
  engineMetadata,
}) => {
  const getRatingBadge = (rating: string) => {
    switch (rating.toUpperCase()) {
      case 'EXCELLENT':
        return <Badge variant="Good" size="md">EXCELLENT</Badge>;
      case 'GOOD':
        return <Badge variant="Good" size="md">GOOD</Badge>;
      case 'FAIR':
        return <Badge variant="Attention" size="md">FAIR</Badge>;
      case 'NEEDS_REVIEW':
        return <Badge variant="Attention" size="md">NEEDS REVIEW</Badge>;
      default:
        return <Badge variant="Critical" size="md">REQUIRES ATTENTION</Badge>;
    }
  };

  return (
    <div className="space-y-4 mb-6">
      {/* Academic Advisory Disclaimer */}
      <div className="bg-[#F8FAFC] border-l-4 border-blue-600 p-4 rounded-r-lg text-xs text-[#334155] leading-relaxed flex items-start gap-3">
        <Info className="w-5 h-5 text-blue-600 shrink-0 mt-0.5" />
        <div>
          <strong className="font-semibold text-[#0F172A]">Academic Decision-Support Advisory:</strong>{' '}
          {disclaimer}
        </div>
      </div>

      {/* Executive Summary Card */}
      <div className="bg-white border border-[#E5E5E5] rounded-xl p-6 shadow-subtle">
        <div className="flex flex-col md:flex-row md:items-center justify-between gap-6 pb-6 border-b border-[#F0F0F0]">
          <div className="flex items-center gap-6">
            <div className="flex flex-col items-center justify-center w-28 h-28 rounded-2xl bg-[#F7F7F5] border border-[#E5E5E5] p-3 text-center shrink-0">
              <span className="text-3xl font-extrabold text-[#111111] tracking-tight">
                {Math.round(overallQuality.score * 10) / 10}
              </span>
              <span className="text-[10px] font-semibold text-[#737373] uppercase tracking-wider mt-0.5">
                Out of 100
              </span>
            </div>

            <div>
              <div className="flex items-center gap-2 mb-1.5">
                <span className="text-xs font-semibold uppercase tracking-wider text-[#737373]">
                  Overall Assessment Index
                </span>
                {getRatingBadge(overallQuality.rating)}
              </div>
              <h2 className="text-lg font-bold text-[#111111]">
                Academic Quality &amp; Alignment Synthesis
              </h2>
              <p className="text-xs text-[#525252] max-w-2xl mt-1 leading-relaxed">
                This authoritative score synthesizes syllabus topic breadth (25%), learning outcome alignment (25%), Bloom’s Taxonomy cognitive depth (15%), difficulty distribution (15%), question originality (10%), and marks proportionality (10%).
              </p>
            </div>
          </div>

          <div className="flex flex-col gap-2 text-xs text-[#737373] bg-[#FAFAFA] p-3.5 rounded-lg border border-[#EBEBEB] min-w-[220px]">
            <div className="flex items-center justify-between">
              <span className="flex items-center gap-1.5">
                <Calendar className="w-3.5 h-3.5 text-[#525252]" />
                Analyzed Date:
              </span>
              <span className="font-mono text-[#111111]">{analyzedAt.split(' ')[0]}</span>
            </div>
            <div className="flex items-center justify-between">
              <span className="flex items-center gap-1.5">
                <Cpu className="w-3.5 h-3.5 text-[#525252]" />
                System Engine:
              </span>
              <span className="font-mono text-[#111111]">v{engineMetadata.version}</span>
            </div>
            {generatedReport && (
              <div className="flex items-center justify-between pt-1 border-t border-[#E5E5E5]">
                <span className="flex items-center gap-1.5">
                  <Award className="w-3.5 h-3.5 text-emerald-600" />
                  PDF Status:
                </span>
                <span className="font-medium text-emerald-700 uppercase text-[11px]">
                  {generatedReport.generation_status}
                </span>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

