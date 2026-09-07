import React, { useState } from 'react';
import { CourseTrendData, TrendDataPoint } from '@/types/analysisHistory';
import { Card } from '@/components/common/Card';
import { TrendingUp, Info } from 'lucide-react';

interface QualityTrendChartProps {
  trendData: CourseTrendData;
}

export const QualityTrendChart: React.FC<QualityTrendChartProps> = ({ trendData }) => {
  const [activeMetric, setActiveMetric] = useState<
    | 'overall_score'
    | 'topic_coverage_score'
    | 'learning_outcome_alignment_score'
    | 'difficulty_balance_score'
    | 'cognitive_level_balance_score'
  >('overall_score');

  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null);

  const series = trendData.series || [];

  if (series.length === 0) {
    return (
      <Card className="p-6 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] text-center text-[#737373] text-sm">
        No completed analyses recorded yet for trend visualization.
      </Card>
    );
  }

  const metricLabels: Record<string, { label: string; color: string }> = {
    overall_score: { label: 'Overall Quality', color: '#2563EB' },
    topic_coverage_score: { label: 'Topic Coverage', color: '#10B981' },
    learning_outcome_alignment_score: { label: 'LO Alignment', color: '#8B5CF6' },
    difficulty_balance_score: { label: 'Difficulty Balance', color: '#F59E0B' },
    cognitive_level_balance_score: { label: 'Cognitive Diversity', color: '#EC4899' },
  };

  const selectedColor = metricLabels[activeMetric].color;

  // Chart Dimensions
  const chartHeight = 200;
  const chartWidth = 600;
  const paddingX = 40;
  const paddingY = 25;

  const points = series.map((item: TrendDataPoint, index: number) => {
    const val = item[activeMetric] ?? 0;
    const x =
      series.length === 1
        ? chartWidth / 2
        : paddingX + (index / (series.length - 1)) * (chartWidth - paddingX * 2);
    // scale from 0 to 100
    const y = chartHeight - paddingY - (val / 100) * (chartHeight - paddingY * 2);
    return { x, y, val, item };
  });

  const pathD =
    points.length === 1
      ? ''
      : points.reduce(
          (acc, p, idx) => (idx === 0 ? `M ${p.x} ${p.y}` : `${acc} L ${p.x} ${p.y}`),
          ''
        );

  const areaD =
    points.length === 1
      ? ''
      : `${pathD} L ${points[points.length - 1].x} ${chartHeight - paddingY} L ${points[0].x} ${chartHeight - paddingY} Z`;

  return (
    <Card className="p-5 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-sm space-y-4">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-[#E5E5E5] dark:border-[#2C2C2E]">
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 flex items-center justify-center text-blue-600 dark:text-blue-400">
            <TrendingUp className="w-4 h-4" />
          </div>
          <div>
            <h3 className="text-sm font-bold text-[#111111] dark:text-white">
              Quality Trajectory over Time
            </h3>
            <p className="text-xs text-[#737373]">
              Course: <span className="font-semibold text-[#111111] dark:text-white">{trendData.course?.code} — {trendData.course?.name}</span> ({trendData.total_data_points} analyses)
            </p>
          </div>
        </div>

        {/* Metric Selector */}
        <div className="flex flex-wrap items-center gap-1.5 text-xs">
          {Object.entries(metricLabels).map(([key, config]) => {
            const isActive = activeMetric === key;
            return (
              <button
                key={key}
                type="button"
                onClick={() => setActiveMetric(key as any)}
                className={`px-2.5 py-1 rounded-lg font-medium transition-all text-xs ${
                  isActive
                    ? 'bg-[#111111] text-white dark:bg-white dark:text-[#111111] shadow-sm'
                    : 'bg-[#F7F7F5] dark:bg-[#2C2C2E] text-[#737373] hover:text-[#111111] dark:hover:text-white'
                }`}
              >
                {config.label}
              </button>
            );
          })}
        </div>
      </div>

      {/* SVG Chart */}
      <div className="relative w-full overflow-x-auto">
        <svg
          viewBox={`0 0 ${chartWidth} ${chartHeight}`}
          className="w-full h-48 sm:h-56 select-none"
        >
          {/* Horizontal Grid lines (0%, 25%, 50%, 75%, 100%) */}
          {[0, 25, 50, 75, 100].map((score) => {
            const y = chartHeight - paddingY - (score / 100) * (chartHeight - paddingY * 2);
            return (
              <g key={score}>
                <line
                  x1={paddingX}
                  y1={y}
                  x2={chartWidth - paddingX}
                  y2={y}
                  stroke="currentColor"
                  className="text-[#E5E5E5] dark:text-[#2C2C2E]"
                  strokeDasharray="4 4"
                />
                <text
                  x={paddingX - 6}
                  y={y + 3}
                  textAnchor="end"
                  className="text-[9px] fill-[#737373] font-mono"
                >
                  {score}
                </text>
              </g>
            );
          })}

          {/* Area fill */}
          {areaD && (
            <path
              d={areaD}
              fill={selectedColor}
              fillOpacity="0.08"
            />
          )}

          {/* Line */}
          {pathD && (
            <path
              d={pathD}
              fill="none"
              stroke={selectedColor}
              strokeWidth="2.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          )}

          {/* Data Points */}
          {points.map((p, idx) => {
            const isHovered = hoveredIndex === idx;
            return (
              <g
                key={idx}
                onMouseEnter={() => setHoveredIndex(idx)}
                onMouseLeave={() => setHoveredIndex(null)}
                className="cursor-pointer"
              >
                <circle
                  cx={p.x}
                  cy={p.y}
                  r={isHovered ? 6 : 4}
                  fill={selectedColor}
                  className="transition-all"
                  stroke="#FFFFFF"
                  strokeWidth="2"
                />
                {/* Date Label on bottom */}
                <text
                  x={p.x}
                  y={chartHeight - 6}
                  textAnchor="middle"
                  className="text-[9px] fill-[#737373] font-mono"
                >
                  {p.item.analyzed_at}
                </text>
              </g>
            );
          })}
        </svg>

        {/* Tooltip Overlay */}
        {hoveredIndex !== null && points[hoveredIndex] && (
          <div
            className="absolute z-10 -translate-x-1/2 bg-[#111111] text-white p-2 rounded-lg text-xs shadow-lg pointer-events-none"
            style={{
              left: `${(points[hoveredIndex].x / chartWidth) * 100}%`,
              top: `${Math.max(10, (points[hoveredIndex].y / chartHeight) * 100 - 35)}%`,
            }}
          >
            <div className="font-semibold">{points[hoveredIndex].item.assessment_title}</div>
            <div className="text-[11px] text-[#A1A1AA]">
              v{points[hoveredIndex].item.version} • {points[hoveredIndex].item.analyzed_at}
            </div>
            <div className="font-bold text-emerald-400 mt-0.5">
              {metricLabels[activeMetric].label}: {points[hoveredIndex].val}
            </div>
          </div>
        )}
      </div>

      {/* Accessible Trend Summary */}
      <div className="flex items-start gap-2 p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] text-xs text-[#737373] dark:text-[#A1A1AA]">
        <Info className="w-4 h-4 text-[#737373] shrink-0 mt-0.5" />
        <div>
          <span className="font-semibold text-[#111111] dark:text-white">Summary Trajectory: </span>
          {trendData.summary_text}
        </div>
      </div>
    </Card>
  );
};

