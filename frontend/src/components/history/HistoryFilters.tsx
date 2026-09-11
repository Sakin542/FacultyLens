import React from 'react';
import { Course } from '@/types';
import { HistoryFilterParams } from '@/types/analysisHistory';
import { Input } from '@/components/common/Input';
import { Button } from '@/components/common/Button';
import { Search, RotateCcw, Filter } from 'lucide-react';

interface HistoryFiltersProps {
  filters: HistoryFilterParams;
  courses: Course[];
  onFilterChange: (key: keyof HistoryFilterParams, value: string | number | undefined) => void;
  onReset: () => void;
  isLoading?: boolean;
}

export const HistoryFilters: React.FC<HistoryFiltersProps> = ({
  filters,
  courses,
  onFilterChange,
  onReset,
  isLoading,
}) => {
  // Extract unique academic years and semesters from courses list
  const academicYears = Array.from(
    new Set(courses.map((c) => c.academic_year).filter(Boolean))
  ) as string[];

  const semesters = Array.from(
    new Set(courses.map((c) => c.semester).filter(Boolean))
  ) as string[];

  return (
    <div className="space-y-3 bg-white dark:bg-[#1C1C1E] p-4 rounded-xl border border-sage-200 dark:border-[#2C2C2E] shadow-sm">
      {/* Search and Sort Row */}
      <div className="flex flex-col sm:flex-row items-center justify-between gap-3">
        <div className="relative flex-1 w-full max-w-md">
          <Input
            placeholder="Search by assessment title, course code, or name..."
            value={filters.search || ''}
            onChange={(e) => onFilterChange('search', e.target.value)}
            leftIcon={<Search className="w-4 h-4 text-sage-500" />}
            className="w-full text-xs"
          />
        </div>

        <div className="flex items-center gap-2 w-full sm:w-auto justify-end">
          <label className="text-xs font-medium text-sage-500 whitespace-nowrap">
            Sort:
          </label>
          <select
            value={filters.sort || 'newest'}
            onChange={(e) => onFilterChange('sort', e.target.value as any)}
            className="rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] px-3 py-1.5 text-xs text-sage-800 dark:text-white focus:outline-none focus:ring-1 focus:ring-black dark:focus:ring-white"
          >
            <option value="newest">Newest First</option>
            <option value="oldest">Oldest First</option>
            <option value="highest_score">Highest Score</option>
            <option value="lowest_score">Lowest Score</option>
          </select>

          <Button
            variant="ghost"
            size="sm"
            onClick={onReset}
            disabled={isLoading}
            className="text-xs text-sage-500 hover:text-sage-800 dark:hover:text-white"
            title="Reset Filters"
          >
            <RotateCcw className="w-3.5 h-3.5 mr-1" />
            Reset
          </Button>
        </div>
      </div>

      {/* Filter Options Row */}
      <div className="flex flex-wrap items-center gap-3 pt-3 border-t border-sage-200 dark:border-[#2C2C2E] text-xs">
        <div className="flex items-center gap-1.5 text-sage-500">
          <Filter className="w-3.5 h-3.5" />
          <span className="font-semibold">Filters:</span>
        </div>

        {/* Course Filter */}
        <div className="flex items-center gap-1">
          <select
            value={filters.course_id || 'all'}
            onChange={(e) => onFilterChange('course_id', e.target.value)}
            className="rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-sage-100 dark:bg-[#2C2C2E] px-2.5 py-1 text-xs text-sage-800 dark:text-white focus:outline-none"
          >
            <option value="all">All Courses</option>
            {courses.map((c) => (
              <option key={c.id} value={c.id}>
                {c.course_code || c.code} — {c.course_name || c.title}
              </option>
            ))}
          </select>
        </div>

        {/* Assessment Type Filter */}
        <div className="flex items-center gap-1">
          <select
            value={filters.assessment_type || 'all'}
            onChange={(e) => onFilterChange('assessment_type', e.target.value)}
            className="rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-sage-100 dark:bg-[#2C2C2E] px-2.5 py-1 text-xs text-sage-800 dark:text-white focus:outline-none"
          >
            <option value="all">All Assessment Types</option>
            <option value="midterm">Midterm</option>
            <option value="final">Final Exam</option>
            <option value="quiz">Quiz</option>
            <option value="assignment">Assignment</option>
          </select>
        </div>

        {/* Academic Year Filter */}
        {academicYears.length > 0 && (
          <div className="flex items-center gap-1">
            <select
              value={filters.academic_year || 'all'}
              onChange={(e) => onFilterChange('academic_year', e.target.value)}
              className="rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-sage-100 dark:bg-[#2C2C2E] px-2.5 py-1 text-xs text-sage-800 dark:text-white focus:outline-none"
            >
              <option value="all">All Academic Years</option>
              {academicYears.map((yr) => (
                <option key={yr} value={yr}>
                  AY {yr}
                </option>
              ))}
            </select>
          </div>
        )}

        {/* Semester Filter */}
        {semesters.length > 0 && (
          <div className="flex items-center gap-1">
            <select
              value={filters.semester || 'all'}
              onChange={(e) => onFilterChange('semester', e.target.value)}
              className="rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-sage-100 dark:bg-[#2C2C2E] px-2.5 py-1 text-xs text-sage-800 dark:text-white focus:outline-none"
            >
              <option value="all">All Semesters</option>
              {semesters.map((sem) => (
                <option key={sem} value={sem}>
                  {sem}
                </option>
              ))}
            </select>
          </div>
        )}
      </div>
    </div>
  );
};

