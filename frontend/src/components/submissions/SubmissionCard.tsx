import React from 'react';
import { Link } from 'react-router-dom';
import { Users, UploadCloud, ClipboardList } from 'lucide-react';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { SubmissionSummaryStats } from '@/types/submission';

interface SubmissionCardProps {
  assessmentId: number | string;
  stats: SubmissionSummaryStats | null;
  isLoading?: boolean;
  error?: string | null;
  onImport?: () => void;
}

/**
 * Assessment-page summary of student submissions. All numbers come from the API.
 */
export const SubmissionCard: React.FC<SubmissionCardProps> = ({ assessmentId, stats, isLoading = false, error, onImport }) => {
  const stat = (label: string, value: number | string) => (
    <div className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl text-center">
      <span className="text-[10px] uppercase font-semibold text-[#737373] block">{label}</span>
      <span className="text-lg font-bold text-[#111111] dark:text-white block mt-1">{value}</span>
    </div>
  );

  return (
    <Card variant="default" className="p-5 space-y-4" data-testid="submission-card-summary">
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <div className="w-7 h-7 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] flex items-center justify-center text-[#111111] dark:text-white">
            <Users className="w-4 h-4" />
          </div>
          <h2 className="text-base font-bold text-[#111111] dark:text-white">Student Submissions</h2>
        </div>
        <div className="flex items-center gap-2">
          {onImport && (
            <Button variant="outline" size="sm" leftIcon={<UploadCloud className="w-3.5 h-3.5" />} onClick={onImport}>
              Import Answers
            </Button>
          )}
          <Link to={`/assessments/${assessmentId}/submissions`}>
            <Button variant="primary" size="sm" leftIcon={<ClipboardList className="w-3.5 h-3.5" />}>
              View Submissions
            </Button>
          </Link>
        </div>
      </div>

      {error ? (
        <p className="text-xs text-red-600" role="alert">{error}</p>
      ) : isLoading || !stats ? (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 animate-pulse">
          {[1, 2, 3, 4].map((i) => <div key={i} className="h-16 bg-[#E5E5E5] dark:bg-[#2C2C2E] rounded-xl" />)}
        </div>
      ) : (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          {stat('Total Submissions', stats.total_submissions)}
          {stat('Not Reviewed', stats.answers_by_status.NOT_REVIEWED)}
          {stat('Under Review', stats.answers_by_status.UNDER_REVIEW)}
          {stat('Reviewed', stats.answers_by_status.REVIEWED)}
        </div>
      )}
      {stats && !error && (
        <p className="text-[11px] text-[#737373]">
          {stats.total_answers} answers across {stats.questions_count} questions · {stats.by_grading_status.NOT_STARTED} submissions not started
        </p>
      )}
    </Card>
  );
};
