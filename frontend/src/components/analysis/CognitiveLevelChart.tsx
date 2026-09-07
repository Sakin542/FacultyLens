import React from 'react';
import { Card } from '@/components/common/Card';
import { BrainCircuit } from 'lucide-react';
import { CognitiveAnalysisData } from '@/types';

interface CognitiveLevelChartProps {
  cognitiveAnalysis?: CognitiveAnalysisData | null;
}

export const CognitiveLevelChart: React.FC<CognitiveLevelChartProps> = ({ cognitiveAnalysis }) => {
  const distribution = cognitiveAnalysis?.distribution || [];

  const defaultTiers: Array<{
    level: string;
    question_percentage: number;
    marks_percentage: number;
    question_count?: number;
  }> = [
    { level: 'Remember', question_percentage: 0, marks_percentage: 0 },
    { level: 'Understand', question_percentage: 0, marks_percentage: 0 },
    { level: 'Apply', question_percentage: 0, marks_percentage: 0 },
    { level: 'Analyze', question_percentage: 0, marks_percentage: 0 },
    { level: 'Evaluate', question_percentage: 0, marks_percentage: 0 },
    { level: 'Create', question_percentage: 0, marks_percentage: 0 },
  ];

  const displayData = distribution.length > 0 ? distribution : defaultTiers;

  return (
    <Card className="p-5 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-sm space-y-4">
      <div className="flex items-center justify-between pb-3 border-b border-[#E5E5E5] dark:border-[#2C2C2E]">
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center text-[#111111] dark:text-white">
            <BrainCircuit className="w-4 h-4" />
          </div>
          <div>
            <h3 className="text-sm font-bold text-[#111111] dark:text-white">Cognitive-Level Distribution</h3>
            <p className="text-xs text-[#737373]">Bloom's Revised Taxonomy classification</p>
          </div>
        </div>

        {cognitiveAnalysis?.dominant_level && (
          <span className="font-mono text-xs font-bold text-[#111111] dark:text-white">
            Dominant: {cognitiveAnalysis.dominant_level} ({Math.round(cognitiveAnalysis.dominant_percentage)}%)
          </span>
        )}
      </div>

      <div className="space-y-3.5">
        {displayData.map((item, idx) => {
          const pct = Math.round(item.marks_percentage ?? item.question_percentage ?? 0);

          return (
            <div key={idx} className="space-y-1">
              <div className="flex items-center justify-between text-xs">
                <span className="font-semibold text-[#111111] dark:text-white w-24">
                  {item.level}
                </span>

                <div className="flex items-center gap-2">
                  <span className="font-mono font-bold text-[#111111] dark:text-white">
                    {pct}%
                  </span>
                  {item.question_count !== undefined && (
                    <span className="text-[11px] text-[#737373]">
                      ({item.question_count} {item.question_count === 1 ? 'Q' : 'Qs'})
                    </span>
                  )}
                </div>
              </div>

              <div className="w-full bg-[#E5E5E5] dark:bg-[#2C2C2E] h-2.5 rounded-full overflow-hidden">
                <div
                  className="h-full bg-[#111111] dark:bg-white rounded-full transition-all duration-500"
                  style={{ width: `${Math.min(pct, 100)}%` }}
                />
              </div>
            </div>
          );
        })}
      </div>

      <div className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] text-[11px] text-[#737373] leading-relaxed">
        Cognitive distribution should be interpreted in relation to the course learning outcomes and assessment purpose rather than assuming higher Bloom tiers are universally preferable.
      </div>
    </Card>
  );
};
