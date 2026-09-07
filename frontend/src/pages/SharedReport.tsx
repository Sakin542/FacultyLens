import React, { useEffect, useState, useCallback } from 'react';
import { useParams } from 'react-router-dom';
import { Download, Printer, Loader2 } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { ReportSummaryCard } from '@/components/reports/ReportSummaryCard';
import { ReportQualityDimensions } from '@/components/reports/ReportQualityDimensions';
import { ReportTopicSection } from '@/components/reports/ReportTopicSection';
import { ReportLOSection } from '@/components/reports/ReportLOSection';
import { ReportBalanceSection } from '@/components/reports/ReportBalanceSection';
import { ReportSimilaritySection } from '@/components/reports/ReportSimilaritySection';
import { ReportFindingsSection } from '@/components/reports/ReportFindingsSection';
import { ReportRecommendationsSection } from '@/components/reports/ReportRecommendationsSection';
import { ReportLoading } from '@/components/reports/ReportLoading';
import { ReportError } from '@/components/reports/ReportError';
import { reportService } from '@/services/reportService';
import { AssessmentReportData } from '@/types/report';

export const SharedReport: React.FC = () => {
  const { token } = useParams<{ token: string }>();

  const [data, setData] = useState<AssessmentReportData | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [isDownloading, setIsDownloading] = useState<boolean>(false);

  const fetchSharedReport = useCallback(async () => {
    if (!token) return;
    setLoading(true);
    setError(null);

    try {
      const reportData = await reportService.getSharedReport(token);
      setData(reportData);
    } catch (err: unknown) {
      const errorObj = err as { message?: string };
      setError(
        errorObj.message || 'The shared assessment report could not be found or the link has expired.'
      );
    } finally {
      setLoading(false);
    }
  }, [token]);

  useEffect(() => {
    fetchSharedReport();
  }, [fetchSharedReport]);

  const handleDownloadSharedPdf = async () => {
    if (!token) return;
    setIsDownloading(true);

    try {
      await reportService.downloadSharedReportPdf(
        token,
        data?.generated_report?.file_name || 'FacultyLens_Academic_Report.pdf'
      );
    } catch (err: unknown) {
      const errorObj = err as { message?: string };
      alert(errorObj.message || 'Failed to download shared PDF report.');
    } finally {
      setIsDownloading(false);
    }
  };

  return (
    <div className="min-h-screen bg-[#FAFAFA] text-[#111111]">
      {/* Top Banner */}
      <header className="bg-white border-b border-[#E5E5E5] px-6 py-4 sticky top-0 z-20 print:hidden shadow-xs">
        <div className="max-w-7xl mx-auto flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <div className="w-8 h-8 rounded-lg bg-[#111111] text-white flex items-center justify-center font-bold text-sm">
              FL
            </div>
            <div>
              <div className="flex items-center gap-2">
                <span className="font-bold text-sm text-[#111111] tracking-tight">FacultyLens</span>
                <span className="text-[10px] font-mono text-[#737373] uppercase border border-[#E5E5E5] px-1.5 py-0.5 rounded">
                  Public Report Viewer
                </span>
              </div>
              <p className="text-[11px] text-[#737373]">
                Academic Decision Support System &bull; Read-Only Shared Access
              </p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => window.print()}
              leftIcon={<Printer className="w-3.5 h-3.5" />}
            >
              Print
            </Button>

            <Button
              variant="primary"
              size="sm"
              onClick={handleDownloadSharedPdf}
              disabled={isDownloading}
              leftIcon={
                isDownloading ? (
                  <Loader2 className="w-3.5 h-3.5 animate-spin" />
                ) : (
                  <Download className="w-3.5 h-3.5" />
                )
              }
            >
              {isDownloading ? 'Downloading PDF...' : 'Download Official PDF'}
            </Button>
          </div>
        </div>
      </header>

      {/* Main Container */}
      <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {loading ? (
          <ReportLoading message="Fetching shared assessment quality report..." />
        ) : error ? (
          <ReportError message={error} onRetry={fetchSharedReport} />
        ) : data ? (
          <div>
            {/* Header info card */}
            <div className="bg-white border border-[#E5E5E5] rounded-xl p-6 mb-6 shadow-subtle">
              <div className="flex flex-wrap items-center gap-2 mb-2">
                <span className="text-xs font-mono font-semibold uppercase px-2 py-0.5 bg-[#F7F7F5] border border-[#E5E5E5] text-[#262626] rounded">
                  {data.assessment.course_code}
                </span>
                <span className="text-xs text-[#737373] font-medium">
                  {data.assessment.course_name}
                </span>
                <Badge variant="neutral" size="sm">
                  {data.assessment.type.toUpperCase()}
                </Badge>
                <Badge variant="Good" size="sm" dot>
                  Verified Academic Evaluation
                </Badge>
              </div>

              <h1 className="text-2xl font-bold tracking-tight text-[#111111]">
                {data.assessment.title} — Assessment Quality Report
              </h1>

              <p className="text-xs text-[#737373] mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                <span>Faculty: <strong className="text-[#262626]">{data.assessment.faculty_name}</strong></span>
                <span>&bull;</span>
                <span>Department: <strong className="text-[#262626]">{data.assessment.department}</strong></span>
                <span>&bull;</span>
                <span>Scope: <strong className="text-[#262626]">{data.assessment.total_questions} Questions</strong> ({data.assessment.total_marks} Marks)</span>
                <span>&bull;</span>
                <span>Evaluation Date: <span className="font-mono text-[#525252]">{data.assessment.analyzed_at}</span></span>
              </p>
            </div>

            <ReportSummaryCard
              overallQuality={data.overall_quality}
              disclaimer={data.disclaimer}
              generatedReport={data.generated_report}
              analyzedAt={data.assessment.analyzed_at}
              engineMetadata={data.engine_metadata}
            />

            <ReportQualityDimensions overallQuality={data.overall_quality} />

            <ReportTopicSection topicCoverage={data.topic_coverage} />

            <ReportLOSection loAlignment={data.learning_outcome_alignment} />

            <ReportBalanceSection
              difficulty={data.difficulty_distribution}
              cognitive={data.cognitive_distribution}
            />

            <ReportSimilaritySection similarQuestions={data.similar_questions} />

            <ReportFindingsSection findings={data.findings} />

            <ReportRecommendationsSection
              recommendations={data.recommendations}
              summary={data.recommendation_summary}
            />

            {/* Print Sign-Off block */}
            <div className="hidden print:block mt-8 pt-6 border-t border-[#CBD5E1]">
              <div className="grid grid-cols-2 gap-8 text-xs">
                <div>
                  <div className="font-bold mb-8">Faculty Member Verification:</div>
                  <div className="border-b border-[#94A3B8] w-3/4 mb-1"></div>
                  <div>{data.assessment.faculty_name}</div>
                  <div className="text-[#64748B]">{data.assessment.department}</div>
                </div>
                <div>
                  <div className="font-bold mb-8">Department Head / QA Committee:</div>
                  <div className="border-b border-[#94A3B8] w-3/4 mb-1"></div>
                  <div>Signature &amp; Date</div>
                  <div className="text-[#64748B]">Academic Quality Assurance Review</div>
                </div>
              </div>
            </div>
          </div>
        ) : null}
      </main>
    </div>
  );
};

export default SharedReport;
