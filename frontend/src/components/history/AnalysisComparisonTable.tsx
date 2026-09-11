import React from 'react';
import { AnalysisComparisonData } from '@/types/analysisHistory';
import { Card } from '@/components/common/Card';
import { Badge, BadgeVariant } from '@/components/common/Badge';
import { AnalysisVersionBadge } from './AnalysisVersionBadge';
import {
  GitCompare,
  ArrowUpRight,
  ArrowDownRight,
  Minus,
  Info,
  Calendar,
} from 'lucide-react';

interface AnalysisComparisonTableProps {
  comparisonData: AnalysisComparisonData;
  onBack?: () => void;
}

export const AnalysisComparisonTable: React.FC<AnalysisComparisonTableProps> = ({
  comparisonData,
}) => {
  const { is_same_assessment, context_notice, left, right, changes, interpretations } =
    comparisonData;

  const getMetricDifference = (
    key: keyof typeof changes,
    isInverse: boolean = false
  ) => {
    const diff = changes[key];
    if (diff === null || diff === undefined) {
      return {
        text: '—',
        direction: 'neutral',
        icon: Minus,
        color: 'text-sage-500',
        badge: undefined,
        badgeVariant: 'neutral' as BadgeVariant,
      };
    }

    if (diff === 0) {
      return {
        text: '0.0 pts',
        direction: 'neutral',
        icon: Minus,
        color: 'text-sage-500',
        badge: 'Identical',
        badgeVariant: 'neutral' as BadgeVariant,
      };
    }

    // For similarity, an increase is considered higher overlap (so positive diff is higher similarity)
    const isPositive = isInverse ? diff < 0 : diff > 0;

    return {
      text: `${diff > 0 ? '+' : ''}${diff.toFixed(1)} pts`,
      direction: isPositive ? 'improved' : 'declined',
      icon: diff > 0 ? ArrowUpRight : ArrowDownRight,
      color: isPositive
        ? 'text-emerald-600 dark:text-emerald-400'
        : 'text-amber-600 dark:text-amber-400',
      badge: isPositive ? 'Improved' : 'Declined',
      badgeVariant: (isPositive ? 'Good' : 'Attention') as BadgeVariant,
    };
  };

  const metricRows = [
    {
      key: 'overall_score' as const,
      label: 'Overall Quality Score',
      leftVal: left.overall_score,
      rightVal: right.overall_score,
      weight: 'Primary Indicator',
      isInverse: false,
    },
    {
      key: 'topic_coverage_score' as const,
      label: 'Topic Coverage Score',
      leftVal: left.topic_coverage_score,
      rightVal: right.topic_coverage_score,
      weight: '25% Weight',
      isInverse: false,
    },
    {
      key: 'learning_outcome_alignment_score' as const,
      label: 'LO Alignment Score',
      leftVal: left.learning_outcome_alignment_score,
      rightVal: right.learning_outcome_alignment_score,
      weight: '25% Weight',
      isInverse: false,
    },
    {
      key: 'difficulty_balance_score' as const,
      label: 'Difficulty Balance Score',
      leftVal: left.difficulty_balance_score,
      rightVal: right.difficulty_balance_score,
      weight: '15% Weight',
      isInverse: false,
    },
    {
      key: 'cognitive_level_balance_score' as const,
      label: 'Cognitive Diversity Score',
      leftVal: left.cognitive_level_balance_score,
      rightVal: right.cognitive_level_balance_score,
      weight: '15% Weight',
      isInverse: false,
    },
    {
      key: 'question_diversity_score' as const,
      label: 'Question Diversity Score',
      leftVal: left.question_diversity_score,
      rightVal: right.question_diversity_score,
      weight: '10% Weight',
      isInverse: false,
    },
    {
      key: 'similarity_score' as const,
      label: 'Historical Overlap / Similarity',
      leftVal: left.similarity_score,
      rightVal: right.similarity_score,
      weight: 'Informational',
      isInverse: true, // Lower similarity is preferred
    },
  ];

  return (
    <div className="space-y-6">
      {/* Context Notice Banner */}
      <div
        className={`p-4 rounded-xl border flex items-start gap-3 text-xs ${
          is_same_assessment
            ? 'bg-blue-50/70 border-blue-200 text-blue-900 dark:bg-blue-950/30 dark:border-blue-900 dark:text-blue-200'
            : 'bg-amber-50/70 border-amber-200 text-amber-900 dark:bg-amber-950/30 dark:border-amber-900 dark:text-amber-200'
        }`}
      >
        <Info className="w-4 h-4 shrink-0 mt-0.5" />
        <div>
          <span className="font-bold">Comparative Context: </span>
          {context_notice}
        </div>
      </div>

      {/* Header Cards: Left vs Right Overview */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {/* Baseline (Left) */}
        <Card className="p-5 bg-white dark:bg-[#1C1C1E] border border-sage-200 dark:border-[#2C2C2E] shadow-sm space-y-3">
          <div className="flex items-center justify-between">
            <span className="text-xs uppercase font-mono tracking-wider font-semibold text-sage-500">
              Baseline Analysis (A)
            </span>
            <AnalysisVersionBadge
              version={left.version}
              isCurrent={false}
              size="sm"
            />
          </div>

          <div>
            <h3 className="text-base font-bold text-sage-800 dark:text-white">
              {left.assessment_title}
            </h3>
            <p className="text-xs text-sage-500">
              {left.course_code} — {left.course_name} ({left.assessment_type})
            </p>
          </div>

          <div className="pt-2 border-t border-sage-200 dark:border-[#2C2C2E] flex items-center justify-between text-xs">
            <span className="text-sage-500 flex items-center gap-1">
              <Calendar className="w-3.5 h-3.5" />
              {left.analyzed_at}
            </span>
            <span className="font-mono text-sm font-bold text-sage-800 dark:text-white">
              Overall: {left.overall_score ?? '—'} / 100
            </span>
          </div>
        </Card>

        {/* Target / Comparison (Right) */}
        <Card className="p-5 bg-white dark:bg-[#1C1C1E] border-2 border-blue-500/30 dark:border-blue-400/30 shadow-sm space-y-3">
          <div className="flex items-center justify-between">
            <span className="text-xs uppercase font-mono tracking-wider font-semibold text-blue-600 dark:text-blue-400">
              Comparison Target (B)
            </span>
            <AnalysisVersionBadge
              version={right.version}
              isCurrent={true}
              size="sm"
            />
          </div>

          <div>
            <h3 className="text-base font-bold text-sage-800 dark:text-white">
              {right.assessment_title}
            </h3>
            <p className="text-xs text-sage-500">
              {right.course_code} — {right.course_name} ({right.assessment_type})
            </p>
          </div>

          <div className="pt-2 border-t border-sage-200 dark:border-[#2C2C2E] flex items-center justify-between text-xs">
            <span className="text-sage-500 flex items-center gap-1">
              <Calendar className="w-3.5 h-3.5" />
              {right.analyzed_at}
            </span>
            <span className="font-mono text-sm font-bold text-blue-600 dark:text-blue-400">
              Overall: {right.overall_score ?? '—'} / 100
            </span>
          </div>
        </Card>
      </div>

      {/* Side-by-Side Comparison Matrix Table */}
      <Card className="overflow-hidden bg-white dark:bg-[#1C1C1E] border border-sage-200 dark:border-[#2C2C2E] shadow-sm">
        <div className="p-4 bg-sage-100 dark:bg-[#2C2C2E] border-b border-sage-200 dark:border-[#3A3A3C] flex items-center justify-between">
          <div className="flex items-center gap-2">
            <GitCompare className="w-4 h-4 text-sage-500" />
            <h4 className="text-xs font-bold text-sage-800 dark:text-white uppercase tracking-wider">
              Metric-by-Metric Delta Matrix
            </h4>
          </div>
          <span className="text-[11px] text-sage-500">
            Calculated as: Target (B) − Baseline (A)
          </span>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left border-collapse text-xs">
            <thead>
              <tr className="border-b border-sage-200 dark:border-[#2C2C2E] bg-white dark:bg-[#1C1C1E] text-sage-500 text-[11px] font-semibold">
                <th className="py-3 px-4">Evaluation Dimension</th>
                <th className="py-3 px-4 text-center">Baseline (A)</th>
                <th className="py-3 px-4 text-center">Target (B)</th>
                <th className="py-3 px-4 text-center">Point Difference</th>
                <th className="py-3 px-4 text-right">Indicator State</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-sage-200 dark:divide-[#2C2C2E]">
              {metricRows.map((row) => {
                const diffInfo = getMetricDifference(row.key, row.isInverse);
                const DiffIcon = diffInfo.icon;

                return (
                  <tr
                    key={row.key}
                    className="hover:bg-sage-100/60 dark:hover:bg-[#2C2C2E]/40 transition-colors"
                  >
                    <td className="py-3 px-4">
                      <div className="font-semibold text-sage-800 dark:text-white">
                        {row.label}
                      </div>
                      <div className="text-[10px] text-sage-500">{row.weight}</div>
                    </td>

                    <td className="py-3 px-4 text-center font-mono font-medium">
                      {row.leftVal !== null ? `${row.leftVal}%` : '—'}
                    </td>

                    <td className="py-3 px-4 text-center font-mono font-medium">
                      {row.rightVal !== null ? `${row.rightVal}%` : '—'}
                    </td>

                    <td className="py-3 px-4 text-center font-mono font-bold">
                      <div className="flex items-center justify-center gap-1">
                        <DiffIcon className={`w-3.5 h-3.5 ${diffInfo.color}`} />
                        <span className={diffInfo.color}>{diffInfo.text}</span>
                      </div>
                    </td>

                    <td className="py-3 px-4 text-right">
                      {diffInfo.badge && (
                        <Badge
                          variant={diffInfo.badgeVariant}
                          className="text-[10px] font-semibold"
                        >
                          {diffInfo.badge}
                        </Badge>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </Card>

      {/* Semantic Decision-Support Interpretations */}
      {interpretations && interpretations.length > 0 && (
        <Card className="p-5 bg-white dark:bg-[#1C1C1E] border border-sage-200 dark:border-[#2C2C2E] shadow-sm space-y-3">
          <h4 className="text-xs font-bold uppercase tracking-wider text-sage-800 dark:text-white flex items-center gap-2">
            <Info className="w-4 h-4 text-blue-500" />
            Decision-Support Interpretations
          </h4>

          <div className="space-y-2">
            {interpretations.map((interp, idx) => (
              <div
                key={idx}
                className="p-3 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl border border-sage-200 dark:border-[#3A3A3C] text-xs text-sage-800 dark:text-white"
              >
                {interp}
              </div>
            ))}
          </div>

          <p className="text-[11px] text-sage-500 italic pt-2">
            * Comparative analysis provides quantitative structural comparisons across assessment versions. Pedagogical choices remain under faculty purview.
          </p>
        </Card>
      )}
    </div>
  );
};

