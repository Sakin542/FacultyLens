import React from 'react';
import { AnalysisHistoryItem } from '@/types/analysisHistory';
import { AnalysisVersionBadge } from './AnalysisVersionBadge';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { Eye, FileText, Clock } from 'lucide-react';

interface AnalysisHistoryTableProps {
  items: AnalysisHistoryItem[];
  selectedForCompare: number[];
  onToggleCompare: (item: AnalysisHistoryItem) => void;
  onViewSnapshot: (analysisId: number) => void;
  onViewAssessment: (assessmentId: number) => void;
  onViewReport: (assessmentId: number) => void;
}

export const AnalysisHistoryTable: React.FC<AnalysisHistoryTableProps> = ({
  items,
  selectedForCompare,
  onToggleCompare,
  onViewSnapshot,
  onViewAssessment,
  onViewReport,
}) => {
  const getScoreColor = (score: number | null) => {
    if (score === null) return 'text-[#737373]';
    if (score >= 80) return 'text-emerald-600 dark:text-emerald-400';
    if (score >= 60) return 'text-blue-600 dark:text-blue-400';
    if (score >= 40) return 'text-amber-600 dark:text-amber-400';
    return 'text-red-600 dark:text-red-400';
  };

  return (
    <div className="overflow-x-auto bg-white dark:bg-[#1C1C1E] rounded-xl border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-sm">
      <table className="w-full text-left border-collapse text-xs">
        <thead>
          <tr className="border-b border-[#E5E5E5] dark:border-[#2C2C2E] bg-[#F7F7F5] dark:bg-[#2C2C2E]/60 text-[#737373] uppercase text-[10px] tracking-wider font-semibold">
            <th className="py-3 px-4 w-10 text-center">Compare</th>
            <th className="py-3 px-4">Assessment & Course</th>
            <th className="py-3 px-4">Version</th>
            <th className="py-3 px-4">Overall Score</th>
            <th className="py-3 px-4 hidden md:table-cell">Core Indicators</th>
            <th className="py-3 px-4">Analyzed At</th>
            <th className="py-3 px-4 text-right">Actions</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-[#E5E5E5] dark:divide-[#2C2C2E]">
          {items.map((item) => {
            const isSelected = selectedForCompare.includes(item.id);
            const course = item.course || item.assessment?.course;

            return (
              <tr
                key={item.id}
                className={`transition-colors hover:bg-[#F7F7F5]/80 dark:hover:bg-[#2C2C2E]/40 ${
                  isSelected ? 'bg-blue-50/50 dark:bg-blue-950/20' : ''
                }`}
              >
                {/* Compare Checkbox */}
                <td className="py-3.5 px-4 text-center">
                  <input
                    type="checkbox"
                    checked={isSelected}
                    onChange={() => onToggleCompare(item)}
                    className="w-4 h-4 rounded border-[#D4D4D4] dark:border-[#52525B] text-blue-600 focus:ring-blue-500 cursor-pointer"
                    title="Select for comparison (max 2)"
                  />
                </td>

                {/* Assessment & Course */}
                <td className="py-3.5 px-4">
                  <div className="space-y-0.5">
                    <div className="font-bold text-[#111111] dark:text-white flex items-center gap-2">
                      <span
                        onClick={() => onViewAssessment(item.assessment_id)}
                        className="hover:underline cursor-pointer"
                      >
                        {item.assessment?.title || `Assessment #${item.assessment_id}`}
                      </span>
                      {item.assessment?.type && (
                        <Badge
                          variant="neutral"
                          className="text-[10px] uppercase font-mono px-1.5 py-0"
                        >
                          {item.assessment.type}
                        </Badge>
                      )}
                    </div>
                    <div className="text-[11px] text-[#737373]">
                      {course?.course_code} — {course?.course_name}
                      {course?.academic_year && ` (AY ${course.academic_year})`}
                    </div>
                  </div>
                </td>

                {/* Version Badge */}
                <td className="py-3.5 px-4 whitespace-nowrap">
                  <AnalysisVersionBadge
                    version={item.analysis_version}
                    isCurrent={item.is_current}
                    size="sm"
                  />
                </td>

                {/* Overall Score */}
                <td className="py-3.5 px-4 whitespace-nowrap">
                  {item.overall_score !== null ? (
                    <div className="flex items-center gap-1.5">
                      <span
                        className={`text-sm font-bold font-mono ${getScoreColor(
                          item.overall_score
                        )}`}
                      >
                        {item.overall_score.toFixed(1)}
                      </span>
                      <span className="text-[10px] text-[#737373]">/100</span>
                      <span className="text-[10px] font-semibold text-[#737373] bg-[#F7F7F5] dark:bg-[#2C2C2E] px-1.5 py-0.5 rounded">
                        {item.rating}
                      </span>
                    </div>
                  ) : (
                    <span className="text-[#737373] italic">—</span>
                  )}
                </td>

                {/* Core Indicators */}
                <td className="py-3.5 px-4 hidden md:table-cell">
                  <div className="flex items-center gap-2 text-[11px] font-mono">
                    <span
                      className="px-1.5 py-0.5 rounded bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C]"
                      title="Topic Coverage"
                    >
                      T: {item.topic_coverage_score ?? '—'}%
                    </span>
                    <span
                      className="px-1.5 py-0.5 rounded bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C]"
                      title="LO Alignment"
                    >
                      LO: {item.learning_outcome_alignment_score ?? '—'}%
                    </span>
                    <span
                      className="px-1.5 py-0.5 rounded bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C]"
                      title="Difficulty Balance"
                    >
                      D: {item.difficulty_balance_score ?? '—'}%
                    </span>
                  </div>
                </td>

                {/* Analyzed At */}
                <td className="py-3.5 px-4 whitespace-nowrap text-[#737373]">
                  <div className="flex items-center gap-1">
                    <Clock className="w-3 h-3" />
                    <span>
                      {new Date(item.analyzed_at).toLocaleDateString(undefined, {
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric',
                      })}
                    </span>
                  </div>
                </td>

                {/* Actions */}
                <td className="py-3.5 px-4 text-right whitespace-nowrap">
                  <div className="flex items-center justify-end gap-1.5">
                    {/* View Snapshot */}
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => onViewSnapshot(item.id)}
                      className="text-xs h-7 px-2 text-[#111111] dark:text-white hover:bg-[#E5E5E5] dark:hover:bg-[#2C2C2E]"
                      title="View Historical Snapshot"
                    >
                      <Eye className="w-3.5 h-3.5 mr-1 text-[#737373]" />
                      Snapshot
                    </Button>

                    {/* Open Report (if available) */}
                    {item.has_report && (
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => onViewReport(item.assessment_id)}
                        className="text-xs h-7 px-2 text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/30"
                        title="View Published Report"
                      >
                        <FileText className="w-3.5 h-3.5 mr-1" />
                        Report
                      </Button>
                    )}
                  </div>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
};

