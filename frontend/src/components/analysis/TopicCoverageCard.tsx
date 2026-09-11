import React from 'react';
import { Card } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import { BookOpen } from 'lucide-react';
import { TopicAnalysisData } from '@/types';

interface TopicCoverageCardProps {
  topicAnalysis?: TopicAnalysisData | null;
  overallScore?: number | null;
}

export const TopicCoverageCard: React.FC<TopicCoverageCardProps> = ({
  topicAnalysis,
  overallScore,
}) => {
  const topics = topicAnalysis?.topics || [];
  const score = overallScore ?? topicAnalysis?.score;

  const getStatusBadge = (status: string) => {
    const s = (status || '').toUpperCase();
    if (s === 'COVERED' || s === 'ADEQUATE') {
      return <Badge variant="Good" size="sm">Covered</Badge>;
    }
    if (s === 'LOW' || s === 'LOW_COVERAGE' || s === 'WEAK') {
      return <Badge variant="Attention" size="sm">Weak</Badge>;
    }
    return <Badge variant="Critical" size="sm">Not Covered</Badge>;
  };

  return (
    <Card className="p-5 bg-white dark:bg-[#1C1C1E] border border-sage-200 dark:border-[#2C2C2E] shadow-sm space-y-4">
      <div className="flex items-center justify-between pb-3 border-b border-sage-200 dark:border-[#2C2C2E]">
        <div className="flex items-center gap-2">
          <div className="w-8 h-8 rounded-lg bg-sage-100 dark:bg-[#2C2C2E] border border-sage-200 dark:border-[#3A3A3C] flex items-center justify-center text-sage-800 dark:text-white">
            <BookOpen className="w-4 h-4" />
          </div>
          <div>
            <h3 className="text-sm font-bold text-sage-800 dark:text-white">Topic Coverage</h3>
            <p className="text-xs text-sage-500">Identified course topics evaluated in questions</p>
          </div>
        </div>

        <div className="text-right">
          <span className="text-xs text-sage-500 block">Coverage</span>
          <span className="text-lg font-extrabold font-mono text-sage-800 dark:text-white">
            {score !== null && score !== undefined ? `${Math.round(score)}%` : '—'}
          </span>
        </div>
      </div>

      {topics.length === 0 ? (
        <div className="p-4 text-center text-xs text-sage-500 italic">
          Topic analysis unavailable. Add course syllabus topics or upload course materials to enable full topic coverage extraction.
        </div>
      ) : (
        <div className="space-y-2.5">
          {topics.map((item, idx) => (
            <div
              key={idx}
              className="p-3 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl border border-sage-200 dark:border-[#3A3A3C] flex items-center justify-between gap-3 text-xs"
            >
              <div className="min-w-0 flex-1">
                <span className="font-semibold text-sage-800 dark:text-white block truncate">
                  {item.topic}
                </span>
                <span className="text-[11px] text-sage-500">
                  {item.question_count} {item.question_count === 1 ? 'question' : 'questions'}
                  {item.marks ? ` • ${item.marks} marks` : ''}
                </span>
              </div>

              <div className="shrink-0 flex items-center gap-2">
                {item.coverage_percentage !== undefined && (
                  <span className="font-mono text-[11px] text-sage-500">
                    {Math.round(item.coverage_percentage)}%
                  </span>
                )}
                {getStatusBadge(item.coverage_status)}
              </div>
            </div>
          ))}
        </div>
      )}
    </Card>
  );
};

