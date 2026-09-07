import React from 'react';
import { useNavigate } from 'react-router-dom';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import {
  Sparkles,
  ExternalLink,
  Calendar,
  Layers,
  Award,
  Loader2,
} from 'lucide-react';

interface AnalysisHeaderProps {
  assessment: {
    id: number | string;
    title: string;
    type: string;
    total_marks: number;
    total_questions: number;
    duration_minutes?: number;
    assessment_date?: string | null;
    course_id: number | string;
    course_code: string;
    course_name: string;
  };
  analyzedAt?: string | null;
  isRunningAnalysis?: boolean;
  onRunAnalysis: () => void;
  analysisStatus?: string;
}

export const AnalysisHeader: React.FC<AnalysisHeaderProps> = ({
  assessment,
  analyzedAt,
  isRunningAnalysis = false,
  onRunAnalysis,
  analysisStatus,
}) => {
  const navigate = useNavigate();

  const formattedDate = analyzedAt
    ? new Date(analyzedAt).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })
    : null;

  return (
    <div className="bg-white dark:bg-[#1C1C1E] rounded-2xl border border-[#E5E5E5] dark:border-[#2C2C2E] p-6 shadow-sm space-y-5">
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        {/* Course & Assessment Identification */}
        <div className="space-y-1.5">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs font-mono font-bold px-2.5 py-0.5 rounded-md bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] text-[#111111] dark:text-white">
              {assessment.course_code}
            </span>
            <span className="text-xs text-[#737373]">•</span>
            <span className="text-xs font-medium text-[#737373]">{assessment.course_name}</span>
            {analysisStatus && (
              <Badge
                variant={
                  analysisStatus === 'completed'
                    ? 'Good'
                    : analysisStatus === 'failed'
                    ? 'Critical'
                    : analysisStatus === 'processing'
                    ? 'Attention'
                    : 'neutral'
                }
                className="text-[11px] capitalize ml-1"
                dot={analysisStatus === 'completed' || analysisStatus === 'processing'}
              >
                {analysisStatus === 'completed' ? 'Audit Complete' : analysisStatus.replace('_', ' ')}
              </Badge>
            )}
          </div>

          <h1 className="text-2xl font-extrabold text-[#111111] dark:text-white tracking-tight">
            {assessment.title}
          </h1>

          <div className="flex flex-wrap items-center gap-3 text-xs text-[#737373] pt-1">
            <span className="capitalize font-semibold text-[#111111] dark:text-white">
              {assessment.type} Assessment
            </span>
            <span>•</span>
            <span className="flex items-center gap-1">
              <Award className="w-3.5 h-3.5" />
              <strong>{assessment.total_marks}</strong> Marks
            </span>
            <span>•</span>
            <span className="flex items-center gap-1">
              <Layers className="w-3.5 h-3.5" />
              <strong>{assessment.total_questions}</strong> Questions
            </span>
            {assessment.duration_minutes && (
              <>
                <span>•</span>
                <span>{assessment.duration_minutes} Mins</span>
              </>
            )}
            {formattedDate && (
              <>
                <span>•</span>
                <span className="flex items-center gap-1 font-mono text-[11px]">
                  <Calendar className="w-3 h-3" />
                  Analyzed {formattedDate}
                </span>
              </>
            )}
          </div>
        </div>

        {/* Header Actions */}
        <div className="flex items-center gap-2.5 shrink-0">
          <Button
            variant="outline"
            size="sm"
            leftIcon={<ExternalLink className="w-3.5 h-3.5" />}
            onClick={() => navigate(`/assessments/${assessment.id}`)}
          >
            View Assessment
          </Button>

          <Button
            variant="primary"
            size="sm"
            className="bg-[#111111] text-white hover:bg-black dark:bg-white dark:text-[#111111] dark:hover:bg-neutral-200 shadow-sm"
            leftIcon={
              isRunningAnalysis ? (
                <Loader2 className="w-3.5 h-3.5 animate-spin" />
              ) : (
                <Sparkles className="w-3.5 h-3.5 text-amber-400" />
              )
            }
            onClick={onRunAnalysis}
            disabled={isRunningAnalysis || assessment.total_questions === 0}
          >
            {isRunningAnalysis ? 'Analyzing Complete Assessment...' : 'Run AI Analysis'}
          </Button>
        </div>
      </div>
    </div>
  );
};

