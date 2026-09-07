import React from 'react';
import { Card } from '@/components/common/Card';
import { BarChart3 } from 'lucide-react';
import { DifficultyAnalysisData } from '@/types';

interface DifficultyChartProps {
  difficultyAnalysis?: DifficultyAnalysisData | null;
}

export const DifficultyChart: React.FC<DifficultyChartProps> = ({ difficultyAnalysis }) => {
  const distribution = difficultyAnalysis?.distribution || [];

  // Default target profile values (configurable targets)
  const fallbackDistribution: Array<{
    level: string;
    question_percentage: number;
    marks_percentage: number;
    target_percentage: number;
    question_count?: number;
  }> = [
    { level: 'Easy', question_percentage: 0, marks_percentage: 0, target_percentage: 30 },
    { level: 'Medium', question_percentage: 0, marks_percentage: 0, target_percentage: 50 },
    { level: 'Hard', question_percentage: 0, marks_percentage: 0, target_percentage: 20 },
  ];

  const displayData = distribution.length > 0 ? distribution : fallbackDistribution;

  const getDifficultyColor = (level: string) => {
    const l = level.toLowerCase();
    if (l === 'easy') return 'bg-[#16A34A]';
    if (l === 'hard') return 'bg-[#DC2626]';
    return 'bg-[#111111] dark:bg-white';
  };

  return (
    <Card className="p-5 bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-sm space-y-4">
      <div className="flex items-center justify-between pb-3 border-b border-[#E5E5E5] dark:border-[#2C2C2E]">
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center text-[#111111] dark:text-white">
            <BarChart3 className="w-4 h-4" />
          </div>
          <div>
            <h3 className="text-sm font-bold text-[#111111] dark:text-white">Difficulty Distribution</h3>
            <p className="text-xs text-[#737373]">Distribution compared with configured FacultyLens target</p>
          </div>
        </div>

        {difficultyAnalysis?.score !== null && difficultyAnalysis?.score !== undefined && (
          <span className="font-mono text-xs font-bold text-[#111111] dark:text-white">
            Balance: {Math.round(difficultyAnalysis.score)}%
          </span>
        )}
      </div>

      <div className="space-y-4">
        {displayData.map((item, idx) => {
          const actualPct = Math.round(item.marks_percentage ?? item.question_percentage ?? 0);
          const targetPct = item.target_percentage ?? (item.level === 'Easy' ? 30 : item.level === 'Medium' ? 50 : 20);

          return (
            <div key={idx} className="space-y-1.5">
              <div className="flex items-center justify-between text-xs">
                <div className="flex items-center gap-2">
                  <span className="font-semibold text-[#111111] dark:text-white w-16">
                    {item.level}
                  </span>
                  <span className="text-[#737373] text-[11px]">
                    Target: {targetPct}%
                  </span>
                </div>

                <div className="flex items-center gap-3">
                  <span className="font-mono font-bold text-[#111111] dark:text-white">
                    {actualPct}%
                  </span>
                  {item.question_count !== undefined && (
                    <span className="text-[11px] text-[#737373]">
                      ({item.question_count} {item.question_count === 1 ? 'Q' : 'Qs'})
                    </span>
                  )}
                </div>
              </div>

              {/* Stacked comparison bar */}
              <div className="w-full bg-[#E5E5E5] dark:bg-[#2C2C2E] h-3 rounded-full overflow-hidden relative">
                {/* Target marker */}
                <div
                  className="absolute top-0 bottom-0 w-0.5 bg-[#737373] z-10"
                  style={{ left: `${Math.min(targetPct, 100)}%` }}
                  title={`Target: ${targetPct}%`}
                />
                {/* Actual Bar */}
                <div
                  className={`h-full rounded-full transition-all duration-500 ${getDifficultyColor(item.level)}`}
                  style={{ width: `${Math.min(actualPct, 100)}%` }}
                />
              </div>
            </div>
          );
        })}
      </div>

      <div className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] text-[11px] text-[#737373] leading-relaxed">
        Configured initial target benchmark (30% Easy, 50% Medium, 20% Hard) serves as guidance. Ideal difficulty balance depends on course objectives and faculty assessment design.
      </div>
    </Card>
  );
};
