import React from 'react';
import { ReportDifficultyDistribution, ReportCognitiveDistribution } from '@/types/report';

interface ReportBalanceSectionProps {
  difficulty: ReportDifficultyDistribution;
  cognitive: ReportCognitiveDistribution;
}

export const ReportBalanceSection: React.FC<ReportBalanceSectionProps> = ({
  difficulty,
  cognitive,
}) => {
  const diffLevels = Object.values(difficulty.levels);
  const bloomLevels = Object.values(cognitive.levels);

  return (
    <div className="bg-white border border-sage-200 rounded-xl p-6 mb-6 shadow-subtle">
      <div className="mb-5">
        <h3 className="text-base font-bold text-sage-800">
          3. Difficulty &amp; Cognitive (Bloom’s Taxonomy) Balance
        </h3>
        <p className="text-xs text-sage-500 mt-0.5">
          Proportionality analysis of assessment items across foundational to advanced difficulty tiers and cognitive processing levels.
        </p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Difficulty Breakdown */}
        <div className="border border-[#EBEBEB] rounded-lg p-4 bg-sage-50">
          <div className="flex items-center justify-between mb-3">
            <h4 className="text-xs font-bold uppercase tracking-wider text-sage-800">
              Difficulty Distribution (Marks Basis)
            </h4>
            <span className="text-xs font-semibold text-sage-800 bg-white px-2 py-0.5 rounded border border-sage-200">
              Score: {Math.round(difficulty.score * 10) / 10}%
            </span>
          </div>

          <table className="w-full text-left text-xs border-collapse">
            <thead className="border-b border-sage-200 text-sage-600 font-medium">
              <tr>
                <th className="py-2">Difficulty Tier</th>
                <th className="py-2 text-center">Items</th>
                <th className="py-2 text-center">Marks</th>
                <th className="py-2 text-center">Actual %</th>
                <th className="py-2 text-center">Target</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#EEEEEE]">
              {diffLevels.map((tier, idx) => (
                <tr key={idx}>
                  <td className="py-2.5 font-semibold text-sage-800">
                    {tier.label}
                  </td>
                  <td className="py-2.5 text-center font-mono text-sage-600">
                    {tier.question_count}
                  </td>
                  <td className="py-2.5 text-center font-mono text-sage-800">
                    {tier.marks}
                  </td>
                  <td className="py-2.5 text-center font-bold text-sage-800">
                    {tier.actual_percentage}%
                  </td>
                  <td className="py-2.5 text-center text-sage-500 font-mono">
                    {tier.target_percentage}%
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {/* Comparative Stacked Bar */}
          <div className="mt-4 pt-3 border-t border-[#EBEBEB]">
            <div className="text-[11px] text-sage-500 mb-1.5 flex justify-between">
              <span>Actual Proportion</span>
              <span>100% Total Marks</span>
            </div>
            <div className="h-3 w-full bg-sage-200 rounded-full overflow-hidden flex">
              <div
                className="bg-emerald-600 h-full"
                style={{ width: `${difficulty.levels.easy.actual_percentage}%` }}
                title={`Easy: ${difficulty.levels.easy.actual_percentage}%`}
              />
              <div
                className="bg-blue-600 h-full"
                style={{ width: `${difficulty.levels.medium.actual_percentage}%` }}
                title={`Medium: ${difficulty.levels.medium.actual_percentage}%`}
              />
              <div
                className="bg-amber-600 h-full"
                style={{ width: `${difficulty.levels.hard.actual_percentage}%` }}
                title={`Hard: ${difficulty.levels.hard.actual_percentage}%`}
              />
            </div>
            <div className="flex items-center justify-between text-[10px] text-sage-500 mt-1.5">
              <span className="flex items-center gap-1">
                <span className="w-2 h-2 rounded-full bg-emerald-600 inline-block" /> Easy
              </span>
              <span className="flex items-center gap-1">
                <span className="w-2 h-2 rounded-full bg-blue-600 inline-block" /> Medium
              </span>
              <span className="flex items-center gap-1">
                <span className="w-2 h-2 rounded-full bg-amber-600 inline-block" /> Hard
              </span>
            </div>
          </div>
        </div>

        {/* Cognitive Breakdown */}
        <div className="border border-[#EBEBEB] rounded-lg p-4 bg-sage-50">
          <div className="flex items-center justify-between mb-3">
            <h4 className="text-xs font-bold uppercase tracking-wider text-sage-800">
              Bloom’s Taxonomy Distribution
            </h4>
            <span className="text-xs font-semibold text-sage-800 bg-white px-2 py-0.5 rounded border border-sage-200">
              Score: {Math.round(cognitive.score * 10) / 10}%
            </span>
          </div>

          <table className="w-full text-left text-xs border-collapse">
            <thead className="border-b border-sage-200 text-sage-600 font-medium">
              <tr>
                <th className="py-2">Cognitive Level</th>
                <th className="py-2 text-center">Items</th>
                <th className="py-2 text-center">Marks</th>
                <th className="py-2 text-center">Actual %</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#EEEEEE]">
              {bloomLevels.map((bloom, bIdx) => (
                <tr key={bIdx}>
                  <td className="py-2 font-semibold text-sage-800">
                    {bloom.label}
                  </td>
                  <td className="py-2 text-center font-mono text-sage-600">
                    {bloom.count}
                  </td>
                  <td className="py-2 text-center font-mono text-sage-800">
                    {bloom.marks}
                  </td>
                  <td className="py-2 text-center font-bold text-sage-800">
                    {bloom.percentage}%
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          <p className="text-[11px] text-sage-500 mt-4 pt-3 border-t border-[#EBEBEB] leading-relaxed">
            Standard university accreditation guidelines advise maintaining higher-order cognitive questions (Apply, Analyze, Evaluate, Create) for advanced undergraduate assessments.
          </p>
        </div>
      </div>
    </div>
  );
};

