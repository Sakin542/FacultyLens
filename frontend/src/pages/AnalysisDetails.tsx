import React, { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { HistoricalAnalysisDetails } from '@/types/analysisHistory';
import { analysisHistoryService } from '@/services/analysisHistoryService';
import { AnalysisVersionBadge } from '@/components/history/AnalysisVersionBadge';
import { AnalysisHistoryLoading } from '@/components/history/AnalysisHistoryLoading';
import { AnalysisHistoryError } from '@/components/history/AnalysisHistoryError';
import { Card } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import { Button } from '@/components/common/Button';
import {
  ArrowLeft,
  FileText,
  GitCompare,
  Clock,
  Sparkles,
  Lightbulb,
} from 'lucide-react';

export const AnalysisDetails: React.FC = () => {
  const { analysisId } = useParams<{ analysisId: string }>();
  const navigate = useNavigate();

  const [details, setDetails] = useState<HistoricalAnalysisDetails | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!analysisId) return;

    const fetchSnapshot = async () => {
      try {
        setIsLoading(true);
        setError(null);
        const data = await analysisHistoryService.getAnalysisDetails(analysisId);
        setDetails(data);
      } catch (err: unknown) {
        if (err instanceof Error) {
          setError(err.message);
        } else {
          setError('Failed to load historical analysis snapshot.');
        }
      } finally {
        setIsLoading(false);
      }
    };

    fetchSnapshot();
  }, [analysisId]);

  if (isLoading) {
    return <AnalysisHistoryLoading message="Loading historical analysis snapshot..." />;
  }

  if (error || !details) {
    return (
      <div className="max-w-4xl mx-auto py-8">
        <AnalysisHistoryError
          message={error || 'Analysis record not found.'}
          onRetry={() => window.location.reload()}
        />
      </div>
    );
  }

  const { assessment, overall_quality, findings, recommendations } = details;
  const dimensions = overall_quality?.dimensions || {};

  return (
    <div className="space-y-6 max-w-5xl mx-auto pb-20">
      {/* Top Navigation */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <button
            type="button"
            onClick={() => navigate('/history')}
            className="inline-flex items-center gap-1.5 text-xs text-sage-500 hover:text-sage-800 dark:hover:text-white mb-2 transition-colors"
          >
            <ArrowLeft className="w-3.5 h-3.5" />
            Back to Analysis History
          </button>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-bold tracking-tight text-sage-800 dark:text-white">
              {assessment.title}
            </h1>
            <AnalysisVersionBadge
              version={details.analysis_version}
              isCurrent={details.is_current}
              size="lg"
            />
          </div>
          <p className="text-xs text-sage-500 mt-1">
            {assessment.course_code} — {assessment.course_name} •{' '}
            <span className="uppercase font-mono">{assessment.type}</span> • Total Marks: {assessment.total_marks}
          </p>
        </div>

        {/* Action Buttons */}
        <div className="flex items-center gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => navigate(`/analysis/compare?left=${details.analysis_id}`)}
            className="text-xs"
          >
            <GitCompare className="w-3.5 h-3.5 mr-1.5" />
            Compare Version
          </Button>

          {details.has_report && (
            <Button
              variant="primary"
              size="sm"
              onClick={() => navigate(`/assessments/${assessment.id}/report`)}
              className="bg-blue-600 hover:bg-blue-700 text-white text-xs"
            >
              <FileText className="w-3.5 h-3.5 mr-1.5" />
              View Full Report
            </Button>
          )}
        </div>
      </div>

      {/* Snapshot Information Banner */}
      <div className="p-3.5 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl border border-sage-200 dark:border-[#3A3A3C] flex items-center justify-between text-xs text-sage-500">
        <div className="flex items-center gap-2">
          <Clock className="w-4 h-4 text-sage-500" />
          <span>
            Archived Snapshot: Analyzed on{' '}
            <span className="font-semibold text-sage-800 dark:text-white">
              {new Date(details.analyzed_at).toLocaleString()}
            </span>
          </span>
        </div>
        <span className="italic">
          {details.is_current ? 'Active Assessment Analysis' : 'Archived Historical Snapshot (Read-Only)'}
        </span>
      </div>

      {/* Overall Quality Summary Card */}
      <Card className="p-6 bg-white dark:bg-[#1C1C1E] border border-sage-200 dark:border-[#2C2C2E] shadow-sm space-y-4">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-sage-200 dark:border-[#2C2C2E]">
          <div className="flex items-center gap-3">
            <div className="w-12 h-12 rounded-xl bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 flex items-center justify-center text-blue-600 dark:text-blue-400 font-bold text-lg">
              {Math.round(overall_quality.score)}
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h3 className="text-base font-bold text-sage-800 dark:text-white">
                  Overall Quality Score
                </h3>
                <Badge variant="Good" className="text-xs font-semibold uppercase">
                  {overall_quality.rating}
                </Badge>
              </div>
              <p className="text-xs text-sage-500">
                Weighted synthetic evaluation across 6 core academic assessment indicators
              </p>
            </div>
          </div>
        </div>

        {/* Quality Dimensions Grid */}
        <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 pt-2">
          {Object.entries(dimensions).map(([key, dim]) => (
            <div
              key={key}
              className="p-3.5 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl border border-sage-200 dark:border-[#3A3A3C] space-y-1"
            >
              <div className="flex items-center justify-between text-[11px] text-sage-500">
                <span>{dim.name}</span>
                <span className="font-mono text-[10px]">{dim.weight}</span>
              </div>
              <div className="flex items-baseline justify-between">
                <span className="text-base font-bold font-mono text-sage-800 dark:text-white">
                  {Math.round(dim.score)}%
                </span>
                <span className="text-[10px] font-semibold text-sage-500">
                  {dim.rating}
                </span>
              </div>
            </div>
          ))}
        </div>
      </Card>

      {/* AI Findings */}
      {findings && findings.length > 0 && (
        <Card className="p-6 bg-white dark:bg-[#1C1C1E] border border-sage-200 dark:border-[#2C2C2E] shadow-sm space-y-4">
          <div className="flex items-center gap-2 pb-2 border-b border-sage-200 dark:border-[#2C2C2E]">
            <Sparkles className="w-4 h-4 text-amber-500" />
            <h3 className="text-sm font-bold text-sage-800 dark:text-white">
              AI Diagnostic Findings ({findings.length})
            </h3>
          </div>

          <div className="space-y-3">
            {findings.map((finding, idx) => (
              <div
                key={idx}
                className="p-4 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl border border-sage-200 dark:border-[#3A3A3C] space-y-1.5 text-xs"
              >
                <div className="flex items-center justify-between">
                  <span className="font-bold text-sage-800 dark:text-white">
                    {finding.problem || 'Quality Observation'}
                  </span>
                  {finding.category && (
                    <Badge variant="neutral" className="text-[10px]">
                      {finding.category}
                    </Badge>
                  )}
                </div>
                {finding.evidence && (
                  <p className="text-sage-500">
                    <span className="font-semibold">Evidence: </span>
                    {finding.evidence}
                  </p>
                )}
                {finding.explanation && (
                  <p className="text-sage-500">
                    <span className="font-semibold">Explanation: </span>
                    {finding.explanation}
                  </p>
                )}
              </div>
            ))}
          </div>
        </Card>
      )}

      {/* AI Recommendations */}
      {recommendations && recommendations.length > 0 && (
        <Card className="p-6 bg-white dark:bg-[#1C1C1E] border border-sage-200 dark:border-[#2C2C2E] shadow-sm space-y-4">
          <div className="flex items-center gap-2 pb-2 border-b border-sage-200 dark:border-[#2C2C2E]">
            <Lightbulb className="w-4 h-4 text-blue-500" />
            <h3 className="text-sm font-bold text-sage-800 dark:text-white">
              Actionable Recommendations ({recommendations.length})
            </h3>
          </div>

          <div className="space-y-3">
            {recommendations.map((rec) => (
              <div
                key={rec.id}
                className="p-4 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl border border-sage-200 dark:border-[#3A3A3C] space-y-1.5 text-xs"
              >
                <div className="flex items-center justify-between">
                  <span className="font-bold text-sage-800 dark:text-white">
                    {rec.problem}
                  </span>
                  <div className="flex items-center gap-1.5">
                    <Badge
                      variant={rec.priority === 'high' ? 'Critical' : 'neutral'}
                      className="text-[10px] uppercase font-mono"
                    >
                      {rec.priority} Priority
                    </Badge>
                    <Badge variant="outline" className="text-[10px] uppercase font-mono">
                      {rec.status}
                    </Badge>
                  </div>
                </div>
                <p className="text-sage-800 dark:text-white font-medium">
                  {rec.recommendation}
                </p>
                {rec.explanation && (
                  <p className="text-sage-500">
                    {rec.explanation}
                  </p>
                )}
              </div>
            ))}
          </div>
        </Card>
      )}
    </div>
  );
};

