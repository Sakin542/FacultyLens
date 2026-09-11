import React from 'react';
import { useNavigate } from 'react-router-dom';
import { ArrowLeft, Download, Share2, Printer, ShieldCheck, Loader2 } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { ReportAssessmentInfo, GeneratedPdfReportInfo } from '@/types/report';

interface ReportHeaderProps {
  assessment: ReportAssessmentInfo;
  generatedReport: GeneratedPdfReportInfo | null;
  onDownloadPdf: () => void;
  onOpenShareModal?: () => void;
  isDownloading?: boolean;
  isGeneratingPdf?: boolean;
  isPublicView?: boolean;
}

export const ReportHeader: React.FC<ReportHeaderProps> = ({
  assessment,
  generatedReport,
  onDownloadPdf,
  onOpenShareModal,
  isDownloading = false,
  isGeneratingPdf = false,
  isPublicView = false,
}) => {
  const navigate = useNavigate();

  return (
    <div className="bg-white border-b border-sage-200 px-6 py-5 mb-6 print:border-none print:p-0 print:mb-4">
      {/* Top navigation row */}
      {!isPublicView && (
        <div className="flex items-center justify-between mb-4 print:hidden">
          <button
            onClick={() => navigate(`/assessments/${assessment.id}/analysis`)}
            className="inline-flex items-center gap-1.5 text-xs font-medium text-sage-500 hover:text-sage-800 transition-colors"
          >
            <ArrowLeft className="w-3.5 h-3.5" />
            Back to AI Analysis Dashboard
          </button>

          <div className="flex items-center gap-2">
            <span className="text-xs text-sage-500 flex items-center gap-1">
              <ShieldCheck className="w-3.5 h-3.5 text-emerald-600" />
              Verified Decision-Support Document
            </span>
          </div>
        </div>
      )}

      {/* Main Header Content */}
      <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
          <div className="flex flex-wrap items-center gap-2 mb-1.5">
            <span className="text-xs font-mono font-semibold uppercase px-2 py-0.5 bg-sage-100 border border-sage-200 text-sage-700 rounded">
              {assessment.course_code}
            </span>
            <span className="text-xs text-sage-500 font-medium">
              {assessment.course_name}
            </span>
            <Badge variant="neutral" size="sm">
              {assessment.type.toUpperCase()}
            </Badge>
            {generatedReport?.is_shareable && (
              <Badge variant="Good" size="sm" dot>
                Shared
              </Badge>
            )}
          </div>

          <h1 className="text-2xl font-bold tracking-tight text-sage-800">
            {assessment.title} — Assessment Quality Report
          </h1>

          <p className="text-xs text-sage-500 mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
            <span>Faculty: <strong className="text-sage-700">{assessment.faculty_name}</strong></span>
            <span>&bull;</span>
            <span>Department: <strong className="text-sage-700">{assessment.department}</strong></span>
            <span>&bull;</span>
            <span>Total Scope: <strong className="text-sage-700">{assessment.total_questions} Questions</strong> ({assessment.total_marks} Marks)</span>
            <span>&bull;</span>
            <span>Analyzed: <span className="font-mono text-sage-600">{assessment.analyzed_at}</span></span>
          </p>
        </div>

        {/* Action Buttons */}
        <div className="flex items-center gap-2.5 print:hidden">
          <Button
            variant="outline"
            size="sm"
            onClick={() => window.print()}
            leftIcon={<Printer className="w-4 h-4" />}
          >
            Print
          </Button>

          {!isPublicView && onOpenShareModal && (
            <Button
              variant="outline"
              size="sm"
              onClick={onOpenShareModal}
              leftIcon={<Share2 className="w-4 h-4" />}
            >
              Share Report
            </Button>
          )}

          <Button
            variant="primary"
            size="sm"
            onClick={onDownloadPdf}
            disabled={isDownloading || isGeneratingPdf}
            leftIcon={
              isDownloading || isGeneratingPdf ? (
                <Loader2 className="w-4 h-4 animate-spin" />
              ) : (
                <Download className="w-4 h-4" />
              )
            }
          >
            {isGeneratingPdf
              ? 'Generating PDF...'
              : isDownloading
              ? 'Downloading...'
              : 'Download Official PDF'}
          </Button>
        </div>
      </div>
    </div>
  );
};
