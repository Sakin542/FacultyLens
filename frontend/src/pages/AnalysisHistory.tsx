import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { Course } from '@/types';
import {
  AnalysisHistoryItem,
  HistoryFilterParams,
  HistoryPaginationMeta,
  CourseTrendData,
  ImprovementSummaryData,
} from '@/types/analysisHistory';
import { analysisHistoryService } from '@/services/analysisHistoryService';
import { courseService } from '@/services/courseService';
import { HistoryFilters } from '@/components/history/HistoryFilters';
import { AnalysisHistoryTable } from '@/components/history/AnalysisHistoryTable';
import { ComparisonSelector } from '@/components/history/ComparisonSelector';
import { QualityTrendChart } from '@/components/history/QualityTrendChart';
import { ImprovementSummary } from '@/components/history/ImprovementSummary';
import { AnalysisHistoryEmptyState } from '@/components/history/AnalysisHistoryEmptyState';
import { AnalysisHistoryLoading } from '@/components/history/AnalysisHistoryLoading';
import { AnalysisHistoryError } from '@/components/history/AnalysisHistoryError';
import { Button } from '@/components/common/Button';
import {
  History as HistoryIcon,
  TrendingUp,
  Layers,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react';

export const AnalysisHistory: React.FC = () => {
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();

  // State
  const [items, setItems] = useState<AnalysisHistoryItem[]>([]);
  const [meta, setMeta] = useState<HistoryPaginationMeta>({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
  });
  const [courses, setCourses] = useState<Course[]>([]);
  const [selectedForCompare, setSelectedForCompare] = useState<AnalysisHistoryItem[]>([]);
  const [activeTab, setActiveTab] = useState<'all' | 'trends'>('all');

  // Trend Data for Selected Course
  const [selectedCourseForTrend, setSelectedCourseForTrend] = useState<string>('');
  const [trendData, setTrendData] = useState<CourseTrendData | null>(null);
  const [improvementSummary, setImprovementSummary] = useState<ImprovementSummaryData | null>(null);
  const [isTrendLoading, setIsTrendLoading] = useState(false);

  // Filters State from URL or defaults
  const [filters, setFilters] = useState<HistoryFilterParams>({
    course_id: searchParams.get('course_id') || 'all',
    assessment_type: searchParams.get('assessment_type') || 'all',
    academic_year: searchParams.get('academic_year') || 'all',
    semester: searchParams.get('semester') || 'all',
    search: searchParams.get('search') || '',
    sort: (searchParams.get('sort') as any) || 'newest',
    page: parseInt(searchParams.get('page') || '1', 10),
  });

  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Load initial courses list
  useEffect(() => {
    const fetchCourses = async () => {
      try {
        const res = await courseService.getAll();
        const list = res.data || [];
        setCourses(list);
        if (list.length > 0 && !selectedCourseForTrend) {
          setSelectedCourseForTrend(String(list[0].id));
        }
      } catch (e) {
        console.error('Failed to load courses', e);
      }
    };
    fetchCourses();
  }, []);

  // Fetch History List
  const fetchHistory = useCallback(async () => {
    try {
      setIsLoading(true);
      setError(null);
      const res = await analysisHistoryService.getHistory(filters);
      setItems(res.data);
      setMeta(res.meta);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to fetch analysis history.');
      }
    } finally {
      setIsLoading(false);
    }
  }, [filters]);

  useEffect(() => {
    fetchHistory();
  }, [fetchHistory]);

  // Fetch Trend Data when course or tab changes
  useEffect(() => {
    if (!selectedCourseForTrend || selectedCourseForTrend === 'all') return;

    const fetchTrends = async () => {
      try {
        setIsTrendLoading(true);
        const [trendRes, summaryRes] = await Promise.all([
          analysisHistoryService.getTrendData(selectedCourseForTrend),
          analysisHistoryService.getImprovementSummary(selectedCourseForTrend),
        ]);
        setTrendData(trendRes);
        setImprovementSummary(summaryRes);
      } catch (err) {
        console.error('Failed to fetch course trends', err);
        setTrendData(null);
        setImprovementSummary(null);
      } finally {
        setIsTrendLoading(false);
      }
    };

    fetchTrends();
  }, [selectedCourseForTrend]);

  // Handlers
  const handleFilterChange = (
    key: keyof HistoryFilterParams,
    value: string | number | undefined
  ) => {
    setFilters((prev) => {
      const updated = { ...prev, [key]: value, page: 1 };
      // Sync with URL query params
      const newParams = new URLSearchParams();
      Object.entries(updated).forEach(([k, v]) => {
        if (v !== undefined && v !== '' && v !== 'all') {
          newParams.set(k, String(v));
        }
      });
      setSearchParams(newParams);
      return updated;
    });
  };

  const handleResetFilters = () => {
    const empty: HistoryFilterParams = {
      course_id: 'all',
      assessment_type: 'all',
      academic_year: 'all',
      semester: 'all',
      search: '',
      sort: 'newest',
      page: 1,
    };
    setFilters(empty);
    setSearchParams(new URLSearchParams());
  };

  const handlePageChange = (newPage: number) => {
    if (newPage < 1 || newPage > meta.last_page) return;
    handleFilterChange('page', newPage);
  };

  const handleToggleCompare = (item: AnalysisHistoryItem) => {
    setSelectedForCompare((prev) => {
      const exists = prev.find((p) => p.id === item.id);
      if (exists) {
        return prev.filter((p) => p.id !== item.id);
      }
      if (prev.length >= 2) {
        // Replace oldest selection
        return [prev[1], item];
      }
      return [...prev, item];
    });
  };

  const handleExecuteCompare = () => {
    if (selectedForCompare.length !== 2) return;
    const [first, second] = selectedForCompare;
    navigate(`/analysis/compare?left=${first.id}&right=${second.id}`);
  };

  const hasActiveFilters =
    filters.course_id !== 'all' ||
    filters.assessment_type !== 'all' ||
    filters.academic_year !== 'all' ||
    filters.semester !== 'all' ||
    Boolean(filters.search);

  return (
    <div className="space-y-6 max-w-7xl mx-auto pb-20">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 text-xs text-[#737373] mb-1">
            <HistoryIcon className="w-3.5 h-3.5" />
            <span>Academic Decision Support</span>
            <span>•</span>
            <span className="font-semibold text-[#111111] dark:text-white">
              Analysis History & Versioning
            </span>
          </div>
          <h1 className="text-2xl font-bold tracking-tight text-[#111111] dark:text-white">
            Analysis History
          </h1>
          <p className="text-xs text-[#737373] mt-0.5">
            Audit historical assessment evaluations, monitor quality evolution, and compare analysis versions.
          </p>
        </div>

        {/* View Toggle */}
        <div className="flex items-center gap-1.5 p-1 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] self-start sm:self-auto text-xs">
          <button
            type="button"
            onClick={() => setActiveTab('all')}
            className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg font-semibold transition-all ${
              activeTab === 'all'
                ? 'bg-white dark:bg-[#1C1C1E] text-[#111111] dark:text-white shadow-xs'
                : 'text-[#737373] hover:text-[#111111] dark:hover:text-white'
            }`}
          >
            <Layers className="w-3.5 h-3.5" />
            All Historical Runs ({meta.total})
          </button>
          <button
            type="button"
            onClick={() => setActiveTab('trends')}
            className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg font-semibold transition-all ${
              activeTab === 'trends'
                ? 'bg-white dark:bg-[#1C1C1E] text-[#111111] dark:text-white shadow-xs'
                : 'text-[#737373] hover:text-[#111111] dark:hover:text-white'
            }`}
          >
            <TrendingUp className="w-3.5 h-3.5 text-blue-500" />
            Quality Trajectory
          </button>
        </div>
      </div>

      {/* Tab: Quality Trajectory View */}
      {activeTab === 'trends' && (
        <div className="space-y-4 animate-in fade-in duration-200">
          {/* Course Selector for Trend View */}
          <div className="flex items-center gap-3 p-3 bg-white dark:bg-[#1C1C1E] rounded-xl border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-sm text-xs">
            <span className="font-semibold text-[#737373] whitespace-nowrap">
              Select Course to Inspect:
            </span>
            <select
              value={selectedCourseForTrend}
              onChange={(e) => setSelectedCourseForTrend(e.target.value)}
              className="rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-[#F7F7F5] dark:bg-[#2C2C2E] px-3 py-1.5 text-xs text-[#111111] dark:text-white font-medium focus:outline-none"
            >
              {courses.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.course_code || c.code} — {c.course_name || c.title}
                </option>
              ))}
            </select>
          </div>

          {isTrendLoading ? (
            <AnalysisHistoryLoading message="Calculating trajectory and indicator changes..." />
          ) : (
            <>
              {improvementSummary && (
                <ImprovementSummary summary={improvementSummary} />
              )}
              {trendData ? (
                <QualityTrendChart trendData={trendData} />
              ) : (
                <div className="p-8 text-center bg-white dark:bg-[#1C1C1E] rounded-xl border text-xs text-[#737373]">
                  Select a course with completed analyses to view quality trend data.
                </div>
              )}
            </>
          )}
        </div>
      )}

      {/* Tab: All Analyses List View */}
      {activeTab === 'all' && (
        <div className="space-y-4 animate-in fade-in duration-200">
          {/* Filters Toolbar */}
          <HistoryFilters
            filters={filters}
            courses={courses}
            onFilterChange={handleFilterChange}
            onReset={handleResetFilters}
            isLoading={isLoading}
          />

          {/* Content States */}
          {isLoading ? (
            <AnalysisHistoryLoading />
          ) : error ? (
            <AnalysisHistoryError message={error} onRetry={fetchHistory} />
          ) : items.length === 0 ? (
            <AnalysisHistoryEmptyState
              hasFilters={hasActiveFilters}
              onClearFilters={handleResetFilters}
            />
          ) : (
            <>
              {/* History Table */}
              <AnalysisHistoryTable
                items={items}
                selectedForCompare={selectedForCompare.map((i) => i.id)}
                onToggleCompare={handleToggleCompare}
                onViewSnapshot={(id) => navigate(`/analysis/${id}`)}
                onViewAssessment={(id) => navigate(`/assessments/${id}`)}
                onViewReport={(id) => navigate(`/assessments/${id}/report`)}
              />

              {/* Pagination Bar */}
              {meta.last_page > 1 && (
                <div className="flex items-center justify-between p-3 bg-white dark:bg-[#1C1C1E] rounded-xl border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-sm text-xs">
                  <div className="text-[#737373]">
                    Showing Page <span className="font-semibold text-[#111111] dark:text-white">{meta.current_page}</span> of{' '}
                    <span className="font-semibold text-[#111111] dark:text-white">{meta.last_page}</span> ({meta.total} total)
                  </div>

                  <div className="flex items-center gap-2">
                    <Button
                      variant="secondary"
                      size="sm"
                      disabled={meta.current_page <= 1}
                      onClick={() => handlePageChange(meta.current_page - 1)}
                      className="text-xs h-8 px-2.5"
                    >
                      <ChevronLeft className="w-3.5 h-3.5 mr-1" />
                      Previous
                    </Button>
                    <Button
                      variant="secondary"
                      size="sm"
                      disabled={meta.current_page >= meta.last_page}
                      onClick={() => handlePageChange(meta.current_page + 1)}
                      className="text-xs h-8 px-2.5"
                    >
                      Next
                      <ChevronRight className="w-3.5 h-3.5 ml-1" />
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      )}

      {/* Floating Comparison Action Bar */}
      <ComparisonSelector
        selectedItems={selectedForCompare}
        onRemoveItem={(id) =>
          setSelectedForCompare((prev) => prev.filter((i) => i.id !== id))
        }
        onClear={() => setSelectedForCompare([])}
        onCompare={handleExecuteCompare}
      />
    </div>
  );
};

