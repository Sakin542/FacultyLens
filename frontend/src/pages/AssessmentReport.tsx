import React, { useEffect, useState, useCallback } from 'react';
import { useParams } from 'react-router-dom';
import { ReportHeader } from '@/components/reports/ReportHeader';
import { ReportSummaryCard } from '@/components/reports/ReportSummaryCard';
import { ReportQualityDimensions } from '@/components/reports/ReportQualityDimensions';
import { ReportTopicSection } from '@/components/reports/ReportTopicSection';
import { ReportLOSection } from '@/components/reports/ReportLOSection';
import { ReportBalanceSection } from '@/components/reports/ReportBalanceSection';
import { ReportSimilaritySection } from '@/components/reports/ReportSimilaritySection';
import { ReportFindingsSection } from '@/components/reports/ReportFindingsSection';
import { ReportRecommendationsSection } from '@/components/reports/ReportRecommendationsSection';
import { ReportShareModal } from '@/components/reports/ReportShareModal';
import { ReportLoading } from '@/components/reports/ReportLoading';
import { ReportEmptyState } from '@/components/reports/ReportEmptyState';
import { ReportError } from '@/components/reports/ReportError';
import { reportService } from '@/services/reportService';
import { AssessmentReportData } from '@/types/report';

export const AssessmentReport: React.FC = () => {
  const params = useParams<{ assessmentId?: string; id?: string }>();
  const assessmentId = params.assessmentId || params.id;

  const [data, setData] = useState<AssessmentReportData | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [isDownloading, setIsDownloading] = useState<boolean>(false);
  const [isGeneratingPdf, setIsGeneratingPdf] = useState<boolean>(false);
  const [isShareModalOpen, setIsShareModalOpen] = useState<boolean>(false);
  const [isSharing, setIsSharing] = useState<boolean>(false);
  const [isRevoking, setIsRevoking] = useState<boolean>(false);
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  const fetchReport = useCallback(async () => {
    if (!assessmentId) return;
    setLoading(true);
    setError(null);

    try {
      const reportData = await reportService.getAssessmentReport(assessmentId);
      setData(reportData);
    } catch (err: unknown) {
      const errorObj = err as { status?: number; message?: string };
      if (errorObj.status === 422 || (errorObj.message && errorObj.message.includes('not completed'))) {
        setError('NOT_COMPLETED');
      } else {
        setError(errorObj.message || 'Failed to load assessment report.');
      }
    } finally {
      setLoading(false);
    }
  }, [assessmentId]);

  useEffect(() => {
    fetchReport();
  }, [fetchReport]);

  const handleDownloadPdf = async () => {
    if (!assessmentId) return;

    try {
      let reportId = data?.generated_report?.id;

      // If no completed PDF exists yet, generate one first
      if (!reportId || data?.generated_report?.generation_status !== 'completed') {
        setIsGeneratingPdf(true);
        const result = await reportService.generatePdfReport(assessmentId);
        reportId = result.report.id;
        setData((prev) => (prev ? { ...prev, generated_report: result.report } : null));
        setIsGeneratingPdf(false);
      }

      setIsDownloading(true);
      await reportService.downloadReportPdf(
        reportId,
        data?.generated_report?.file_name || `FacultyLens_Report_${assessmentId}.pdf`
      );

      setFeedback({ type: 'success', message: 'Authoritative assessment report PDF downloaded.' });
      setTimeout(() => setFeedback(null), 4000);
    } catch (err: unknown) {
      const errorObj = err as { message?: string };
      setFeedback({ type: 'error', message: errorObj.message || 'Failed to download PDF report.' });
      setTimeout(() => setFeedback(null), 5000);
    } finally {
      setIsGeneratingPdf(false);
      setIsDownloading(false);
    }
  };

  const handleShare = async () => {
    if (!assessmentId) return;
    setIsSharing(true);

    try {
      let reportId = data?.generated_report?.id;

      // Generate PDF if needed before sharing
      if (!reportId) {
        const genResult = await reportService.generatePdfReport(assessmentId);
        reportId = genResult.report.id;
      }

      const shareResult = await reportService.shareReport(reportId);

      setData((prev) => {
        if (!prev) return null;
        return {
          ...prev,
          generated_report: prev.generated_report
            ? {
                ...prev.generated_report,
                is_shareable: true,
                share_token: shareResult.share_token,
              }
            : {
                id: reportId,
                uuid: '',
                file_name: '',
                file_size: null,
                generation_status: 'completed',
                is_shareable: true,
                share_token: shareResult.share_token,
                generated_at: new Date().toISOString(),
              },
        };
      });

      setFeedback({ type: 'success', message: 'Report shareable link created.' });
      setTimeout(() => setFeedback(null), 4000);
    } catch (err: unknown) {
      const errorObj = err as { message?: string };
      setFeedback({ type: 'error', message: errorObj.message || 'Failed to share report.' });
      setTimeout(() => setFeedback(null), 5000);
    } finally {
      setIsSharing(false);
    }
  };

  const handleRevokeShare = async () => {
    if (!data?.generated_report?.id) return;
    setIsRevoking(true);

    try {
      await reportService.revokeShareReport(data.generated_report.id);

      setData((prev) => {
        if (!prev || !prev.generated_report) return null;
        return {
          ...prev,
          generated_report: {
            ...prev.generated_report,
            is_shareable: false,
            share_token: null,
          },
        };
      });

      setFeedback({ type: 'success', message: 'Share link revoked. Access is now deactivated.' });
      setTimeout(() => setFeedback(null), 4000);
    } catch (err: unknown) {
      const errorObj = err as { message?: string };
      setFeedback({ type: 'error', message: errorObj.message || 'Failed to revoke share.' });
      setTimeout(() => setFeedback(null), 5000);
    } finally {
      setIsRevoking(false);
    }
  };

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 print:p-0">
      {feedback && (
          <div
            className={`mb-4 p-3 rounded-lg text-xs font-medium border flex items-center justify-between print:hidden ${
              feedback.type === 'success'
                ? 'bg-emerald-50 text-emerald-800 border-emerald-200'
                : 'bg-red-50 text-red-800 border-red-200'
            }`}
          >
            <span>{feedback.message}</span>
            <button
              onClick={() => setFeedback(null)}
              className="text-xs font-bold uppercase ml-3 hover:opacity-75"
            >
              &times;
            </button>
          </div>
        )}

        {loading ? (
          <ReportLoading />
        ) : error === 'NOT_COMPLETED' ? (
          <ReportEmptyState assessmentId={assessmentId || ''} />
        ) : error ? (
          <ReportError
            message={error}
            onRetry={fetchReport}
            backUrl={assessmentId ? `/assessments/${assessmentId}/analysis` : '/assessments'}
          />
        ) : data ? (
          <div className="print:bg-white print:text-black">
            <ReportHeader
              assessment={data.assessment}
              generatedReport={data.generated_report}
              onDownloadPdf={handleDownloadPdf}
              onOpenShareModal={() => setIsShareModalOpen(true)}
              isDownloading={isDownloading}
              isGeneratingPdf={isGeneratingPdf}
            />

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

            <ReportShareModal
              isOpen={isShareModalOpen}
              onClose={() => setIsShareModalOpen(false)}
              reportInfo={data.generated_report}
              onShare={handleShare}
              onRevoke={handleRevokeShare}
              isSharing={isSharing}
              isRevoking={isRevoking}
            />
          </div>
        ) : null}
    </div>
  );
};

export default AssessmentReport;
