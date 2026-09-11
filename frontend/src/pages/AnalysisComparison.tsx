import React, { useState, useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { AnalysisComparisonData, AnalysisHistoryItem } from '@/types/analysisHistory';
import { analysisHistoryService } from '@/services/analysisHistoryService';
import { AnalysisComparisonTable } from '@/components/history/AnalysisComparisonTable';
import { AnalysisHistoryLoading } from '@/components/history/AnalysisHistoryLoading';
import { AnalysisHistoryError } from '@/components/history/AnalysisHistoryError';
import { Button } from '@/components/common/Button';
import { Card } from '@/components/common/Card';
import { GitCompare, ArrowLeft } from 'lucide-react';

export const AnalysisComparison: React.FC = () => {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();

  const leftId = searchParams.get('left') || searchParams.get('left_analysis_id');
  const rightId = searchParams.get('right') || searchParams.get('right_analysis_id');

  const [comparisonData, setComparisonData] = useState<AnalysisComparisonData | null>(null);
  const [availableAnalyses, setAvailableAnalyses] = useState<AnalysisHistoryItem[]>([]);
  const [selectedLeft, setSelectedLeft] = useState<string>(leftId || '');
  const [selectedRight, setSelectedRight] = useState<string>(rightId || '');

  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Load available analyses for the quick selection dropdown
  useEffect(() => {
    const loadList = async () => {
      try {
        const res = await analysisHistoryService.getHistory({ per_page: 50 });
        setAvailableAnalyses(res.data);
      } catch (e) {
        console.error('Failed to load history list for comparison', e);
      }
    };
    loadList();
  }, []);

  // Fetch comparison data when IDs are set
  useEffect(() => {
    if (!leftId || !rightId) {
      setComparisonData(null);
      return;
    }

    const fetchComparison = async () => {
      try {
        setIsLoading(true);
        setError(null);
        const data = await analysisHistoryService.compareAnalyses(leftId, rightId);
        setComparisonData(data);
        setSelectedLeft(leftId);
        setSelectedRight(rightId);
      } catch (err: unknown) {
        if (err instanceof Error) {
          setError(err.message);
        } else {
          setError('Failed to compute assessment comparison.');
        }
      } finally {
        setIsLoading(false);
      }
    };

    fetchComparison();
  }, [leftId, rightId]);

  const handleApplyComparison = () => {
    if (!selectedLeft || !selectedRight) return;
    setSearchParams({ left: selectedLeft, right: selectedRight });
  };

  return (
    <div className="space-y-6 max-w-6xl mx-auto pb-20">
      {/* Top Header */}
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
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 flex items-center justify-center text-blue-600 dark:text-blue-400">
              <GitCompare className="w-4 h-4" />
            </div>
            <div>
              <h1 className="text-2xl font-bold tracking-tight text-sage-800 dark:text-white">
                Compare Assessment Analyses
              </h1>
              <p className="text-xs text-sage-500">
                Side-by-side comparative inspection of assessment quality indicator shifts.
              </p>
            </div>
          </div>
        </div>
      </div>

      {/* Comparison Selector Bar */}
      <Card className="p-4 bg-white dark:bg-[#1C1C1E] border border-sage-200 dark:border-[#2C2C2E] shadow-sm space-y-3">
        <h4 className="text-xs font-bold text-sage-800 dark:text-white uppercase tracking-wider">
          Select Analyses to Compare
        </h4>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
          {/* Left Selection */}
          <div className="space-y-1">
            <label className="font-semibold text-sage-500">
              Baseline Analysis (A):
            </label>
            <select
              value={selectedLeft}
              onChange={(e) => setSelectedLeft(e.target.value)}
              className="w-full rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-sage-100 dark:bg-[#2C2C2E] p-2 text-xs text-sage-800 dark:text-white focus:outline-none"
            >
              <option value="">-- Choose Baseline --</option>
              {availableAnalyses.map((item) => (
                <option
                  key={`left-${item.id}`}
                  value={item.id}
                  disabled={String(item.id) === selectedRight}
                >
                  {item.assessment?.title} (v{item.analysis_version}) — Score: {item.overall_score ?? '—'} (
                  {new Date(item.analyzed_at).toLocaleDateString()})
                </option>
              ))}
            </select>
          </div>

          {/* Right Selection */}
          <div className="space-y-1">
            <label className="font-semibold text-sage-500">
              Comparison Target (B):
            </label>
            <select
              value={selectedRight}
              onChange={(e) => setSelectedRight(e.target.value)}
              className="w-full rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-sage-100 dark:bg-[#2C2C2E] p-2 text-xs text-sage-800 dark:text-white focus:outline-none"
            >
              <option value="">-- Choose Target --</option>
              {availableAnalyses.map((item) => (
                <option
                  key={`right-${item.id}`}
                  value={item.id}
                  disabled={String(item.id) === selectedLeft}
                >
                  {item.assessment?.title} (v{item.analysis_version}) — Score: {item.overall_score ?? '—'} (
                  {new Date(item.analyzed_at).toLocaleDateString()})
                </option>
              ))}
            </select>
          </div>
        </div>

        <div className="flex justify-end pt-2">
          <Button
            variant="primary"
            size="sm"
            disabled={!selectedLeft || !selectedRight || selectedLeft === selectedRight}
            onClick={handleApplyComparison}
            className="bg-blue-600 hover:bg-blue-700 text-white text-xs px-4"
          >
            Update Comparison Matrix
          </Button>
        </div>
      </Card>

      {/* Content Area */}
      {isLoading ? (
        <AnalysisHistoryLoading message="Computing comparative assessment matrix..." />
      ) : error ? (
        <AnalysisHistoryError
          message={error}
          onRetry={() => {
            if (leftId && rightId) {
              setSearchParams({ left: leftId, right: rightId });
            }
          }}
        />
      ) : comparisonData ? (
        <AnalysisComparisonTable
          comparisonData={comparisonData}
          onBack={() => navigate('/history')}
        />
      ) : (
        <Card className="p-12 text-center bg-white dark:bg-[#1C1C1E] border border-sage-200 dark:border-[#2C2C2E] text-xs text-sage-500 space-y-2">
          <GitCompare className="w-8 h-8 text-sage-500 mx-auto opacity-50" />
          <p className="font-semibold text-sage-800 dark:text-white">
            Select two analyses above to run comparative evaluation
          </p>
          <p>
            Choose a baseline analysis and a target analysis to inspect metric differentials.
          </p>
        </Card>
      )}
    </div>
  );
};

