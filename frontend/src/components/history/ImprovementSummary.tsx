import React from 'react';
import { ImprovementSummaryData } from '@/types/analysisHistory';
import { Card } from '@/components/common/Card';
import { Sparkles, ArrowUpRight, ArrowDownRight, Minus, Calendar } from 'lucide-react';

interface ImprovementSummaryProps {
  summary: ImprovementSummaryData;
}

export const ImprovementSummary: React.FC<ImprovementSummaryProps> = ({ summary }) => {
  if (!summary.has_sufficient_data || !summary.improvements) {
    return null;
  }

  const { improvements, baseline_date, latest_date } = summary;

  const renderDelta = (delta: number) => {
    if (delta > 0) {
      return (
        <span className="inline-flex items-center text-emerald-600 dark:text-emerald-400 font-bold font-mono">
          <ArrowUpRight className="w-3.5 h-3.5 mr-0.5" />
          +{delta} pts
        </span>
      );
    } else if (delta < 0) {
      return (
        <span className="inline-flex items-center text-amber-600 dark:text-amber-400 font-bold font-mono">
          <ArrowDownRight className="w-3.5 h-3.5 mr-0.5" />
          {delta} pts
        </span>
      );
    }
    return (
      <span className="inline-flex items-center text-[#737373] font-bold font-mono">
        <Minus className="w-3.5 h-3.5 mr-0.5" />
        0.0 pts
      </span>
    );
  };

  const dimensions = [
    { label: 'Topic Coverage', key: 'topic_coverage', val: improvements.topic_coverage },
    { label: 'LO Alignment', key: 'learning_outcome_alignment', val: improvements.learning_outcome_alignment },
    { label: 'Difficulty Balance', key: 'difficulty_balance', val: improvements.difficulty_balance },
    { label: 'Cognitive Diversity', key: 'cognitive_diversity', val: improvements.cognitive_diversity },
  ];

  return (
    <Card className="p-5 bg-gradient-to-r from-blue-50/50 via-white to-indigo-50/50 dark:from-[#1E293B]/30 dark:via-[#1C1C1E] dark:to-[#312E81]/20 border border-blue-100 dark:border-blue-900/40 shadow-sm space-y-4">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-blue-100 dark:border-blue-900/40">
        <div className="flex items-center gap-2.5">
          <div className="w-8 h-8 rounded-lg bg-blue-600 text-white flex items-center justify-center shadow-sm">
            <Sparkles className="w-4 h-4" />
          </div>
          <div>
            <h4 className="text-sm font-bold text-[#111111] dark:text-white">
              Quality Indicator Evolution
            </h4>
            <p className="text-xs text-[#737373] flex items-center gap-1.5">
              <Calendar className="w-3 h-3" />
              Timeline: {baseline_date} → {latest_date}
            </p>
          </div>
        </div>

        {/* Overall Delta Badge */}
        <div className="flex items-center gap-2 bg-white dark:bg-[#2C2C2E] px-3 py-1.5 rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] shadow-xs">
          <span className="text-xs text-[#737373]">Overall Delta:</span>
          <div className="text-sm">
            {renderDelta(improvements.overall_score)}
          </div>
        </div>
      </div>

      {/* Dimensions Grid */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        {dimensions.map((dim) => (
          <div
            key={dim.key}
            className="p-3 bg-white dark:bg-[#1C1C1E] rounded-xl border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-xs space-y-1"
          >
            <div className="text-[11px] font-medium text-[#737373]">
              {dim.label}
            </div>
            <div className="text-xs">
              {renderDelta(dim.val)}
            </div>
          </div>
        ))}
      </div>

      <p className="text-[11px] text-[#737373] italic">
        * Metrics reflect relative point differences between earliest baseline and latest recorded analysis to assist faculty decision-making.
      </p>
    </Card>
  );
};

