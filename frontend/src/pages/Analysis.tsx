import React, { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { AnalysisHeader } from '@/components/analysis/AnalysisHeader';
import { OverallQualityCard } from '@/components/analysis/OverallQualityCard';
import { QualityComponentCards } from '@/components/analysis/QualityComponentCards';
import { TopicCoverageCard } from '@/components/analysis/TopicCoverageCard';
import { LearningOutcomeAlignmentCard } from '@/components/analysis/LearningOutcomeAlignmentCard';
import { DifficultyChart } from '@/components/analysis/DifficultyChart';
import { CognitiveLevelChart } from '@/components/analysis/CognitiveLevelChart';
import { SimilarQuestionsTable } from '@/components/analysis/SimilarQuestionsTable';
import { AIFindingsCard } from '@/components/analysis/AIFindingsCard';
import { RecommendationsSection } from '@/components/analysis/RecommendationsSection';
import { AnalysisLoading } from '@/components/analysis/AnalysisLoading';
import { AnalysisEmptyState } from '@/components/analysis/AnalysisEmptyState';
import { AnalysisError } from '@/components/analysis/AnalysisError';
import { PerformanceOverview } from '@/components/performance/PerformanceOverview';
import { aiAnalysisService, FullAssessmentAnalysisData } from '@/services/aiAnalysisService';
import { assessmentService } from '@/services/assessmentService';
import { Assessment, RecommendationStatus } from '@/types';
import {
  FileCheck2,
  CheckCircle2,
} from 'lucide-react';

export const Analysis: React.FC = () => {
  const { id: paramAssessmentId } = useParams<{ id?: string }>();
  const navigate = useNavigate();

  // State management
  const [assessmentsList, setAssessmentsList] = useState<Assessment[]>([]);
  const [selectedAssessmentId, setSelectedAssessmentId] = useState<string | number | null>(
    paramAssessmentId || null
  );
  const [analysisData, setAnalysisData] = useState<FullAssessmentAnalysisData | null>(null);
  const [isLoading, setIsLoading] = useState<boolean>(true);
  const [isRunningAnalysis, setIsRunningAnalysis] = useState<boolean>(false);
  const [isUpdatingStatus, setIsUpdatingStatus] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const [errorStatus, setErrorStatus] = useState<number | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const showNotification = (msg: string) => {
    setSuccessMessage(msg);
    setTimeout(() => setSuccessMessage(null), 4000);
  };

  // Sync route param changes
  useEffect(() => {
    if (paramAssessmentId) {
      setSelectedAssessmentId(paramAssessmentId);
    }
  }, [paramAssessmentId]);

  // Load faculty's assessments for dropdown selector if on /analysis
  useEffect(() => {
    const fetchAssessments = async () => {
      try {
        const res = await assessmentService.getAll();
        const list = res.data || [];
        setAssessmentsList(list);

        // If no assessment is currently selected in route, pick the first available
        if (!paramAssessmentId && list.length > 0) {
          setSelectedAssessmentId(list[0].id);
        }
      } catch (err: unknown) {
        console.error('Failed to load assessments list for selector:', err);
      }
    };

    fetchAssessments();
  }, [paramAssessmentId]);

  // Load single assessment analysis dashboard
  const loadAnalysis = useCallback(async (asmId: string | number) => {
    try {
      setIsLoading(true);
      setError(null);
      setErrorStatus(null);
      const res = await aiAnalysisService.getAssessmentAnalysis(asmId);
      setAnalysisData(res.data);
    } catch (err: any) {
      console.error('Failed to load assessment analysis:', err);
      const status = err?.status || err?.response?.status;
      setErrorStatus(status);
      setError(err?.message || 'Failed to load assessment analysis data.');
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (selectedAssessmentId) {
      loadAnalysis(selectedAssessmentId);
    } else {
      setIsLoading(false);
    }
  }, [selectedAssessmentId, loadAnalysis]);

  // Handle running AI Analysis
  const handleRunAnalysis = async () => {
    if (!selectedAssessmentId) return;

    try {
      setIsRunningAnalysis(true);
      setError(null);
      await aiAnalysisService.analyzeAssessment(selectedAssessmentId);
      await loadAnalysis(selectedAssessmentId);
      showNotification('AI Analysis completed successfully! Dashboard metrics refreshed.');
    } catch (err: any) {
      console.error('Unified analysis error:', err);
      const status = err?.status || err?.response?.status;
      setErrorStatus(status);
      setError(err?.message || 'AI analysis could not be completed.');
    } finally {
      setIsRunningAnalysis(false);
    }
  };

  // Handle recommendation decision update
  const handleRecommendationStatusChange = async (
    recId: number | string,
    newStatus: RecommendationStatus,
    notes?: string
  ) => {
    try {
      setIsUpdatingStatus(true);
      await aiAnalysisService.updateRecommendationStatus(recId, newStatus, notes);

      // Optimistically update local state
      if (analysisData) {
        const updatedRecs = analysisData.recommendations.map((r) =>
          r.id === recId
            ? {
                ...r,
                status: newStatus,
                faculty_notes: notes !== undefined ? notes : r.faculty_notes,
              }
            : r
        );
        setAnalysisData({
          ...analysisData,
          recommendations: updatedRecs,
        });
      }
      showNotification(`Recommendation marked as ${newStatus}.`);
    } catch (err: any) {
      console.error('Failed to update recommendation status:', err);
      showNotification('Failed to update recommendation status.');
    } finally {
      setIsUpdatingStatus(false);
    }
  };

  // If loading initially
  if (isLoading) {
    return <AnalysisLoading isProcessing={false} />;
  }

  // If error occurred fetching dashboard
  if (error && !analysisData) {
    return (
      <AnalysisError
        error={error}
        status={errorStatus}
        onRetry={() => selectedAssessmentId && loadAnalysis(selectedAssessmentId)}
        isRetrying={isLoading}
      />
    );
  }

  // If faculty has zero assessments created
  if (!selectedAssessmentId || !analysisData) {
    return (
      <Card className="p-12 text-center bg-white dark:bg-[#1C1C1E] border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-sm space-y-4 max-w-lg mx-auto">
        <div className="w-12 h-12 rounded-xl bg-[#F7F7F5] dark:bg-[#2C2C2E] flex items-center justify-center text-[#111111] dark:text-white mx-auto">
          <FileCheck2 className="w-6 h-6" />
        </div>
        <div className="space-y-1">
          <h3 className="text-base font-bold text-[#111111] dark:text-white">
            No Assessments Found
          </h3>
          <p className="text-xs text-[#737373]">
            Create an examination or quiz assessment first to evaluate questions using AI.
          </p>
        </div>
        <Button
          variant="primary"
          size="sm"
          onClick={() => navigate('/assessments')}
          className="mx-auto"
        >
          Go to Assessments
        </Button>
      </Card>
    );
  }

  const { assessment, report, analysis_status } = analysisData;
  const isNotAnalyzed = analysis_status === 'not_analyzed' || !report;
  const isProcessing = analysis_status === 'processing' || isRunningAnalysis;

  // Aggregate dimension scores for component cards
  const dimensionScores = {
    topicCoverage: report?.topic_coverage_score ?? analysisData.quality_analysis?.components?.topic_coverage,
    learningOutcomeCoverage:
      report?.learning_outcome_alignment_score ?? analysisData.quality_analysis?.components?.learning_outcome_coverage,
    difficultyBalance:
      report?.difficulty_balance_score ?? analysisData.quality_analysis?.components?.difficulty_balance,
    cognitiveDiversity:
      report?.cognitive_level_balance_score ?? analysisData.quality_analysis?.components?.cognitive_diversity,
    questionDiversity: analysisData.quality_analysis?.components?.question_diversity,
    marksDistribution: analysisData.quality_analysis?.components?.marks_distribution,
  };

  return (
    <div className="space-y-6">
      {/* Toast Notification */}
      {successMessage && (
        <div className="p-4 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-xl flex items-center gap-3 text-emerald-700 dark:text-emerald-300 text-sm font-medium animate-in fade-in slide-in-from-top-2">
          <CheckCircle2 className="w-5 h-5 shrink-0" />
          <span>{successMessage}</span>
        </div>
      )}

      {/* Assessment Selector Bar (when multiple assessments available) */}
      {assessmentsList.length > 1 && (
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 bg-white dark:bg-[#1C1C1E] rounded-xl border border-[#E5E5E5] dark:border-[#2C2C2E] text-xs">
          <div className="flex items-center gap-2">
            <span className="font-semibold text-[#737373]">Select Assessment:</span>
            <select
              value={selectedAssessmentId}
              onChange={(e) => {
                const newId = e.target.value;
                setSelectedAssessmentId(newId);
                navigate(`/assessments/${newId}/analysis`);
              }}
              className="rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-[#F7F7F5] dark:bg-[#2C2C2E] px-2.5 py-1.5 font-medium text-[#111111] dark:text-white"
            >
              {assessmentsList.map((asm) => (
                <option key={asm.id} value={asm.id}>
                  {asm.course?.course_code ? `${asm.course.course_code} — ` : ''}
                  {asm.title} ({asm.type})
                </option>
              ))}
            </select>
          </div>

          <div className="text-[11px] text-[#737373] hidden sm:block font-mono">
            ID: {assessment.id} • {assessment.total_questions} Questions
          </div>
        </div>
      )}

      {/* Header */}
      <AnalysisHeader
        assessment={assessment}
        analyzedAt={report?.analyzed_at}
        isRunningAnalysis={isRunningAnalysis}
        onRunAnalysis={handleRunAnalysis}
        analysisStatus={analysis_status}
      />

      {/* Processing State */}
      {isProcessing ? (
        <AnalysisLoading isProcessing={true} />
      ) : isNotAnalyzed ? (
        /* Empty State */
        <AnalysisEmptyState
          assessmentTitle={assessment.title}
          courseCode={assessment.course_code}
          onRunAnalysis={handleRunAnalysis}
          isRunningAnalysis={isRunningAnalysis}
        />
      ) : analysis_status === 'failed' ? (
        /* Failed State */
        <AnalysisError
          error={report?.processing_error || 'AI analysis could not be completed.'}
          onRetry={handleRunAnalysis}
          isRetrying={isRunningAnalysis}
        />
      ) : (
        /* Completed Analysis Dashboard */
        <div className="space-y-6">
          {/* 1. Overall Quality Score Prominent Card */}
          <OverallQualityCard
            score={report?.overall_score ?? null}
            rating={report?.rating}
            totalQuestions={assessment.total_questions}
          />

          {/* 2. Six Quality Component Cards */}
          <QualityComponentCards scores={dimensionScores} />

          {/* 3. Difficulty Chart & Cognitive-Level Chart (2 Columns) */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <DifficultyChart difficultyAnalysis={analysisData.quality_analysis?.difficulty_analysis} />
            <CognitiveLevelChart cognitiveAnalysis={analysisData.quality_analysis?.cognitive_analysis} />
          </div>

          {/* 4. Topic Coverage & Learning Outcome Alignment (2 Columns) */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <TopicCoverageCard
              topicAnalysis={analysisData.quality_analysis?.topic_analysis}
              overallScore={report?.topic_coverage_score}
            />
            <LearningOutcomeAlignmentCard
              alignmentAnalysis={analysisData.alignment_analysis}
              overallScore={report?.learning_outcome_alignment_score}
              alignmentsList={analysisData.learning_outcome_alignments}
            />
          </div>

          {/* 5. Similar Questions */}
          <SimilarQuestionsTable
            similarityAnalysis={analysisData.similarity_analysis}
            similarityMatches={analysisData.similarity_matches}
          />

          {/* 6. AI Findings */}
          <AIFindingsCard findings={analysisData.findings || []} />

          {/* 7. AI Recommendations with Filters and Actions */}
          <RecommendationsSection
            recommendations={analysisData.recommendations || []}
            onStatusUpdate={handleRecommendationStatusChange}
            isUpdatingStatus={isUpdatingStatus}
          />
        </div>
      )}

      {/* STEP 30: Student Performance / Gap Analysis — a separate analytic from assessment quality */}
      <PerformanceOverview assessmentId={selectedAssessmentId} />
    </div>
  );
};
