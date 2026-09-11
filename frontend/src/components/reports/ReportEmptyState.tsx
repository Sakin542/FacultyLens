import React from 'react';
import { useNavigate } from 'react-router-dom';
import { FileText, Sparkles, ArrowRight } from 'lucide-react';
import { Button } from '@/components/common/Button';

interface ReportEmptyStateProps {
  assessmentId: number | string;
  message?: string;
}

export const ReportEmptyState: React.FC<ReportEmptyStateProps> = ({
  assessmentId,
  message = 'Academic analysis must be completed before generating a report.',
}) => {
  const navigate = useNavigate();

  return (
    <div className="min-h-[450px] flex flex-col items-center justify-center p-8 bg-white border border-sage-200 rounded-xl shadow-subtle text-center">
      <div className="w-14 h-14 rounded-2xl bg-sage-100 border border-sage-200 flex items-center justify-center mb-4 text-sage-800">
        <FileText className="w-7 h-7" />
      </div>

      <h3 className="text-lg font-bold text-sage-800">
        Report Not Yet Available
      </h3>

      <p className="text-xs text-sage-500 max-w-md mt-1.5 mb-6 leading-relaxed">
        {message} Run AI Analysis on this assessment to compile syllabus topic coverage, learning outcome alignment, difficulty balance, and actionable recommendations.
      </p>

      <Button
        variant="primary"
        onClick={() => navigate(`/assessments/${assessmentId}/analysis`)}
        rightIcon={<ArrowRight className="w-4 h-4" />}
        leftIcon={<Sparkles className="w-4 h-4" />}
      >
        Go to AI Analysis Dashboard
      </Button>
    </div>
  );
};

