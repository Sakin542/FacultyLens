import React from 'react';
import { Badge } from '@/components/common/Badge';
import { ReportOverallQuality } from '@/types/report';

interface ReportQualityDimensionsProps {
  overallQuality: ReportOverallQuality;
}

export const ReportQualityDimensions: React.FC<ReportQualityDimensionsProps> = ({ overallQuality }) => {
  const dimensions = Object.values(overallQuality.dimensions);

  const getRatingBadge = (rating: string) => {
    switch (rating.toUpperCase()) {
      case 'EXCELLENT':
        return <Badge variant="Good" size="sm">EXCELLENT</Badge>;
      case 'GOOD':
        return <Badge variant="Good" size="sm">GOOD</Badge>;
      case 'FAIR':
        return <Badge variant="Attention" size="sm">FAIR</Badge>;
      case 'NEEDS_REVIEW':
        return <Badge variant="Attention" size="sm">NEEDS REVIEW</Badge>;
      default:
        return <Badge variant="Critical" size="sm">REQUIRES ATTENTION</Badge>;
    }
  };

  return (
    <div className="bg-white border border-[#E5E5E5] rounded-xl p-6 mb-6 shadow-subtle">
      <div className="flex items-center justify-between mb-4">
        <div>
          <h3 className="text-sm font-bold uppercase tracking-wider text-[#111111]">
            Quality Dimensions Breakdown
          </h3>
          <p className="text-xs text-[#737373] mt-0.5">
            Six weighted academic dimensions calibrated against university curriculum benchmarks.
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {dimensions.map((dim, idx) => (
          <div
            key={idx}
            className="p-4 rounded-lg bg-[#FAFAFA] border border-[#EBEBEB] flex flex-col justify-between"
          >
            <div>
              <div className="flex items-center justify-between gap-2 mb-2">
                <span className="text-xs font-bold text-[#111111]">
                  {dim.name}
                </span>
                <span className="text-[10px] font-mono text-[#737373] bg-[#EEEEEE] px-1.5 py-0.5 rounded">
                  {dim.weight}
                </span>
              </div>

              <div className="flex items-baseline justify-between mb-2">
                <span className="text-2xl font-extrabold text-[#111111]">
                  {Math.round(dim.score * 10) / 10}%
                </span>
                {getRatingBadge(dim.rating)}
              </div>

              {/* Progress bar */}
              <div className="w-full bg-[#E5E5E5] h-1.5 rounded-full overflow-hidden mb-2.5">
                <div
                  className="bg-[#111111] h-1.5 rounded-full transition-all duration-300"
                  style={{ width: `${Math.min(100, Math.max(0, dim.score))}%` }}
                />
              </div>

              <p className="text-[11px] text-[#525252] leading-relaxed">
                {dim.description}
              </p>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

