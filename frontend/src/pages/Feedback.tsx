import React, { useState, useEffect, useCallback } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  RecommendationFeedbackItem,
  FeedbackSummaryData,
  ImprovementSignalsResponse,
  AiImprovementSignalItem,
  FeedbackFilterParams,
} from '@/types/feedback';
import { HistoryPaginationMeta } from '@/types/analysisHistory';
import { Course } from '@/types';
import { feedbackService } from '@/services/feedbackService';
import { courseService } from '@/services/courseService';
import { FeedbackSummaryCards } from '@/components/feedback/FeedbackSummaryCards';
import { FeedbackHistoryTable } from '@/components/feedback/FeedbackHistoryTable';
import { ImprovementSignalsView } from '@/components/feedback/ImprovementSignalsView';
import { Button } from '@/components/common/Button';
import {
  MessageSquare,
  Sparkles,
  RefreshCw,
  AlertCircle,
  Layers,
} from 'lucide-react';

export const Feedback: React.FC = () => {
  const [searchParams] = useSearchParams();

  // Active sub-tab: 'history' or 'signals'
  const [activeTab, setActiveTab] = useState<'history' | 'signals'>('history');

  // Courses list for filter
  const [courses, setCourses] = useState<Course[]>([]);
  const [selectedCourseId, setSelectedCourseId] = useState<string>(
    searchParams.get('course_id') || 'all'
  );

  // Summary Metrics
  const [summary, setSummary] = useState<FeedbackSummaryData | null>(null);
  const [isSummaryLoading, setIsSummaryLoading] = useState(true);

  // Feedback History
  const [historyItems, setHistoryItems] = useState<RecommendationFeedbackItem[]>([]);
  const [historyMeta, setHistoryMeta] = useState<HistoryPaginationMeta>({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
  });
  const [isHistoryLoading, setIsHistoryLoading] = useState(true);

  // History Filters
  const [decisionFilter, setDecisionFilter] = useState<string>('all');
  const [ratingFilter, setRatingFilter] = useState<string>('all');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [page, setPage] = useState<number>(1);

  // Improvement Signals
  const [signalsSummary, setSignalsSummary] = useState<ImprovementSignalsResponse['summary'] | null>(null);
  const [signals, setSignals] = useState<AiImprovementSignalItem[]>([]);
  const [isSignalsLoading, setIsSignalsLoading] = useState(false);

  // Global Error
  const [error, setError] = useState<string | null>(null);

  // Fetch courses list
  useEffect(() => {
    const fetchCourses = async () => {
      try {
        const res = await courseService.getAll();
        setCourses(res.data || []);
      } catch (err) {
        console.error('Failed to load courses', err);
      }
    };
    fetchCourses();
  }, []);

  // Fetch Summary Metrics
  const fetchSummary = useCallback(async () => {
    try {
      setIsSummaryLoading(true);
      const res = await feedbackService.getFeedbackSummary(
        selectedCourseId !== 'all' ? selectedCourseId : undefined
      );
      setSummary(res);
    } catch (err: unknown) {
      console.error('Failed to fetch feedback summary', err);
    } finally {
      setIsSummaryLoading(false);
    }
  }, [selectedCourseId]);

  // Fetch Feedback History
  const fetchHistory = useCallback(async () => {
    try {
      setIsHistoryLoading(true);
      setError(null);

      const params: FeedbackFilterParams = {
        page,
        per_page: 15,
      };

      if (selectedCourseId !== 'all') params.course_id = selectedCourseId;
      if (decisionFilter !== 'all') params.decision = decisionFilter;
      if (ratingFilter !== 'all') params.rating = ratingFilter;
      if (searchQuery.trim()) params.search = searchQuery.trim();

      const res = await feedbackService.getFeedbackHistory(params);
      setHistoryItems(res.data);
      setHistoryMeta(res.meta);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to fetch feedback history.');
      }
    } finally {
      setIsHistoryLoading(false);
    }
  }, [selectedCourseId, decisionFilter, ratingFilter, searchQuery, page]);

  // Fetch Improvement Signals
  const fetchSignals = useCallback(async () => {
    try {
      setIsSignalsLoading(true);
      const res = await feedbackService.getImprovementSignals();
      setSignalsSummary(res.summary);
      setSignals(res.signals);
    } catch (err: unknown) {
      console.error('Failed to fetch improvement signals', err);
    } finally {
      setIsSignalsLoading(false);
    }
  }, []);

  // Initial and reactive loads
  useEffect(() => {
    fetchSummary();
  }, [fetchSummary]);

  useEffect(() => {
    fetchHistory();
  }, [fetchHistory]);

  useEffect(() => {
    if (activeTab === 'signals') {
      fetchSignals();
    }
  }, [activeTab, fetchSignals]);

  const handleRefresh = () => {
    fetchSummary();
    fetchHistory();
    if (activeTab === 'signals') {
      fetchSignals();
    }
  };

  return (
    <div className="max-w-7xl mx-auto space-y-6 pb-12">
      {/* Page Header */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2.5">
            <div className="w-9 h-9 rounded-xl bg-sage-700 dark:bg-white text-white dark:text-sage-800 flex items-center justify-center shadow-xs">
              <MessageSquare className="w-5 h-5" />
            </div>
            <div>
              <h1 className="text-xl font-bold tracking-tight text-sage-800 dark:text-white">
                Faculty Feedback & Decisions
              </h1>
              <p className="text-xs text-sage-500">
                Audit trail of recommendation reviews, usefulness ratings, and AI calibration telemetry
              </p>
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2 w-full sm:w-auto">
          {/* Course filter */}
          <select
            value={selectedCourseId}
            onChange={(e) => {
              setSelectedCourseId(e.target.value);
              setPage(1);
            }}
            className="px-3 py-1.5 rounded-xl border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] text-xs text-sage-800 dark:text-white"
          >
            <option value="all">All Courses</option>
            {courses.map((c) => (
              <option key={c.id} value={c.id}>
                {c.course_code || c.code} — {c.course_name || c.title || ''}
              </option>
            ))}
          </select>

          <Button
            variant="outline"
            size="sm"
            onClick={handleRefresh}
            leftIcon={<RefreshCw className="w-3.5 h-3.5" />}
          >
            Refresh
          </Button>
        </div>
      </div>

      {/* Error Alert */}
      {error && (
        <div className="p-4 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-900 rounded-xl flex items-center gap-3 text-xs text-red-600 dark:text-red-400">
          <AlertCircle className="w-4 h-4 shrink-0" />
          <span>{error}</span>
        </div>
      )}

      {/* Summary KPI Cards */}
      <FeedbackSummaryCards summary={summary} isLoading={isSummaryLoading} />

      {/* Sub-Tab Switcher */}
      <div className="flex items-center gap-2 border-b border-sage-200 dark:border-[#2C2C2E] pb-2 text-xs">
        <button
          type="button"
          onClick={() => setActiveTab('history')}
          className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg font-medium transition-colors ${
            activeTab === 'history'
              ? 'bg-sage-700 text-white dark:bg-white dark:text-sage-800'
              : 'text-sage-500 hover:bg-neutral-100 dark:hover:bg-neutral-800'
          }`}
        >
          <Layers className="w-3.5 h-3.5" />
          Feedback & Decision History
        </button>

        <button
          type="button"
          onClick={() => setActiveTab('signals')}
          className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg font-medium transition-colors ${
            activeTab === 'signals'
              ? 'bg-sage-700 text-white dark:bg-white dark:text-sage-800'
              : 'text-sage-500 hover:bg-neutral-100 dark:hover:bg-neutral-800'
          }`}
        >
          <Sparkles className="w-3.5 h-3.5" />
          AI Improvement Signals
        </button>
      </div>

      {/* Tab Panels */}
      {activeTab === 'history' ? (
        <FeedbackHistoryTable
          items={historyItems}
          meta={historyMeta}
          isLoading={isHistoryLoading}
          onPageChange={setPage}
          decisionFilter={decisionFilter}
          onDecisionFilterChange={(val) => {
            setDecisionFilter(val);
            setPage(1);
          }}
          ratingFilter={ratingFilter}
          onRatingFilterChange={(val) => {
            setRatingFilter(val);
            setPage(1);
          }}
          searchQuery={searchQuery}
          onSearchChange={(val) => {
            setSearchQuery(val);
            setPage(1);
          }}
        />
      ) : (
        <ImprovementSignalsView
          summary={signalsSummary}
          signals={signals}
          isLoading={isSignalsLoading}
        />
      )}
    </div>
  );
};
