import React, { useEffect, useState } from 'react';
import { Loader2, UserCheck } from 'lucide-react';
import { Card } from '@/components/common/Card';
import { performanceService } from '@/services/performanceService';
import { StudentPerformance } from '@/types/performance';
import { formatPct } from './PerformanceGapBadge';
import { PerformanceError } from './PerformanceError';

interface StudentPerformanceCardProps {
  studentId: number | string;
  assessmentId: number | string;
}

/**
 * Authorized per-student view for one assessment. Uses "areas for review" wording; never labels
 * ability and never shows unfinalized marks as final.
 */
export const StudentPerformanceCard: React.FC<StudentPerformanceCardProps> = ({ studentId, assessmentId }) => {
  const [data, setData] = useState<StudentPerformance | null>(null);
  const [error, setError] = useState<Error | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    let active = true;
    setIsLoading(true);
    performanceService.getStudentPerformance(studentId, assessmentId)
      .then((res) => { if (active) { setData(res.data); setError(null); } })
      .catch((err) => { if (active) setError(err instanceof Error ? err : new Error('Failed to load.')); })
      .finally(() => { if (active) setIsLoading(false); });
    return () => { active = false; };
  }, [studentId, assessmentId]);

  return (
    <Card variant="default" className="p-5 space-y-3" data-testid="student-performance-card">
      <h3 className="text-sm font-bold text-[#111111] dark:text-white flex items-center gap-2">
        <UserCheck className="w-4 h-4 text-[#737373]" /> Performance in this assessment
      </h3>
      {isLoading ? (
        <p className="text-xs text-[#737373] flex items-center gap-2" role="status"><Loader2 className="w-3.5 h-3.5 animate-spin" /> Loading…</p>
      ) : error ? (
        <PerformanceError error={error} />
      ) : data && (
        <>
          <div className="grid grid-cols-3 gap-3 text-center">
            <div className="p-3 rounded-xl bg-[#F7F7F5] dark:bg-[#2C2C2E]">
              <span className="block text-[10px] uppercase font-semibold text-[#737373]">Overall</span>
              <span className="block text-lg font-bold font-mono text-[#111111] dark:text-white" data-testid="student-overall">{data.has_finalized_grades ? formatPct(data.overall_percentage) : 'Not finalized'}</span>
              {data.has_finalized_grades && <span className="block text-[10px] text-[#737373]">{data.total_awarded_marks} / {data.total_maximum_marks} marks</span>}
            </div>
            <div className="p-3 rounded-xl bg-[#F7F7F5] dark:bg-[#2C2C2E]">
              <span className="block text-[10px] uppercase font-semibold text-[#737373]">Finalized</span>
              <span className="block text-lg font-bold font-mono text-[#111111] dark:text-white">{data.finalized_question_count} / {data.question_count}</span>
              <span className="block text-[10px] text-[#737373]">questions</span>
            </div>
            <div className="p-3 rounded-xl bg-[#F7F7F5] dark:bg-[#2C2C2E]">
              <span className="block text-[10px] uppercase font-semibold text-[#737373]">Expected</span>
              <span className="block text-lg font-bold font-mono text-[#111111] dark:text-white">{formatPct(data.expected_performance_percent)}</span>
              <span className="block text-[10px] text-[#737373]">benchmark</span>
            </div>
          </div>

          <ul className="divide-y divide-[#E5E5E5] dark:divide-[#2C2C2E] text-xs" data-testid="student-questions">
            {data.questions.map((q) => (
              <li key={q.question_id} className="py-1.5 flex items-center justify-between gap-2">
                <span className="font-mono font-semibold text-[#111111] dark:text-white">Q{q.question_number ?? q.question_id}</span>
                <span className="text-[#737373] truncate flex-1">{q.question_text_excerpt}</span>
                <span className="font-mono text-[#111111] dark:text-white shrink-0">
                  {!q.answered ? 'No answer' : q.awarded_marks === null ? 'Not graded' : `${q.awarded_marks} / ${q.maximum_marks}`}
                  {q.answered && q.awarded_marks !== null && !q.is_finalized && <span className="text-[10px] text-[#737373] ml-1">(not finalized)</span>}
                </span>
              </li>
            ))}
          </ul>

          <div data-testid="areas-for-review">
            <span className="block text-[10px] uppercase tracking-wider font-semibold text-[#737373] mb-1">Potential areas for review</span>
            {data.areas_for_review.length === 0 ? (
              <p className="text-xs text-[#737373] italic">{data.has_finalized_grades ? 'No areas below the benchmark in the finalized questions.' : 'Available once grades are finalized.'}</p>
            ) : (
              <ul className="flex flex-wrap gap-1.5">
                {data.areas_for_review.map((a) => (
                  <li key={`${a.type}-${a.label}`} className="px-2 py-1 rounded-md bg-[#F7F7F5] dark:bg-[#2C2C2E] text-xs text-[#262626] dark:text-[#E5E5E5]">
                    {a.label} <span className="font-mono text-[#737373]">{formatPct(a.percentage)}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
          <p className="text-[11px] text-[#737373] italic">{data.note}</p>
        </>
      )}
    </Card>
  );
};
