import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { Input } from '@/components/common/Input';
import { Course } from '@/types';
import { courseService, CoursePayload } from '@/services/courseService';
import { CourseModal } from '@/components/courses/CourseModal';
import {
  BookOpen,
  Plus,
  Search,
  FileCheck2,
  ListChecks,
  ExternalLink,
  Edit,
  Trash2,
  Layers,
  Loader2,
  AlertCircle,
  CheckCircle2,
} from 'lucide-react';

export const Courses: React.FC = () => {
  const navigate = useNavigate();
  const [courses, setCourses] = useState<Course[]>([]);
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedSemester, setSelectedSemester] = useState('all');
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // Modal states
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [editingCourse, setEditingCourse] = useState<Course | null>(null);
  const [deletingCourseId, setDeletingCourseId] = useState<number | string | null>(null);

  const showNotification = (msg: string) => {
    setSuccessMessage(msg);
    setTimeout(() => setSuccessMessage(null), 4000);
  };

  const fetchCourses = useCallback(async () => {
    try {
      setIsLoading(true);
      setError(null);
      const res = await courseService.getAll();
      setCourses(res.data || []);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to load courses.');
      }
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchCourses();
  }, [fetchCourses]);

  const handleCreateCourse = async (data: CoursePayload) => {
    const res = await courseService.create(data);
    setCourses([res.data, ...courses]);
    showNotification('Course created successfully!');
  };

  const handleUpdateCourse = async (data: CoursePayload) => {
    if (!editingCourse) return;
    const res = await courseService.update(editingCourse.id, data);
    setCourses(courses.map((c) => (c.id === editingCourse.id ? res.data : c)));
    setEditingCourse(null);
    showNotification('Course updated successfully!');
  };

  const handleDeleteCourse = async (course: Course) => {
    const code = course.course_code || course.code;
    const title = course.course_name || course.title;
    if (!window.confirm(`Are you sure you want to delete "${code} - ${title}"? All outcomes and uploaded materials will also be deleted.`)) {
      return;
    }

    try {
      setDeletingCourseId(course.id);
      await courseService.delete(course.id);
      setCourses(courses.filter((c) => c.id !== course.id));
      showNotification('Course deleted successfully!');
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      }
    } finally {
      setDeletingCourseId(null);
    }
  };

  const filteredCourses = courses.filter((c) => {
    const code = (c.course_code || c.code || '').toLowerCase();
    const title = (c.course_name || c.title || '').toLowerCase();
    const query = searchQuery.toLowerCase();
    const matchesSearch = code.includes(query) || title.includes(query);

    if (selectedSemester === 'all') return matchesSearch;
    return matchesSearch && c.semester.toLowerCase() === selectedSemester.toLowerCase();
  });

  const uniqueSemesters = Array.from(new Set(courses.map((c) => c.semester).filter(Boolean)));

  return (
    <div className="space-y-6">
      {/* Toast Notification */}
      {successMessage && (
        <div className="p-4 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-xl flex items-center gap-3 text-emerald-700 dark:text-emerald-300 text-sm font-medium animate-in fade-in slide-in-from-top-2">
          <CheckCircle2 className="w-5 h-5 shrink-0" />
          <span>{successMessage}</span>
        </div>
      )}

      {/* Error alert */}
      {error && (
        <div className="p-4 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 rounded-xl flex items-center justify-between text-red-700 dark:text-red-300 text-sm font-medium">
          <div className="flex items-center gap-3">
            <AlertCircle className="w-5 h-5 shrink-0 text-red-500" />
            <span>{error}</span>
          </div>
          <Button variant="outline" size="sm" onClick={() => fetchCourses()}>
            Retry
          </Button>
        </div>
      )}

      {/* Header Controls */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="flex items-center gap-3 flex-1 max-w-lg">
          <div className="relative flex-1">
            <Input
              placeholder="Search courses by code or title..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              leftIcon={<Search className="w-4 h-4" />}
            />
          </div>
          {uniqueSemesters.length > 0 && (
            <select
              value={selectedSemester}
              onChange={(e) => setSelectedSemester(e.target.value)}
              className="rounded-lg border border-sage-200 dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3 py-2 text-xs text-sage-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-sage-600"
            >
              <option value="all">All Semesters</option>
              {uniqueSemesters.map((sem) => (
                <option key={sem} value={sem}>
                  {sem}
                </option>
              ))}
            </select>
          )}
        </div>

        <div className="flex items-center gap-3">
          <Button
            variant="primary"
            leftIcon={<Plus className="w-4 h-4" />}
            onClick={() => setIsAddModalOpen(true)}
          >
            Add Course
          </Button>
        </div>
      </div>

      {/* Content State */}
      {isLoading ? (
        <div className="flex flex-col items-center justify-center min-h-[300px] space-y-4">
          <Loader2 className="w-8 h-8 animate-spin text-sage-800 dark:text-white" />
          <p className="text-sm text-sage-500">Loading your courses...</p>
        </div>
      ) : filteredCourses.length === 0 ? (
        <Card variant="default" className="p-12 text-center space-y-4">
          <div className="w-12 h-12 rounded-xl bg-sage-100 dark:bg-[#2C2C2E] flex items-center justify-center text-sage-800 dark:text-white mx-auto">
            <BookOpen className="w-6 h-6" />
          </div>
          <div className="space-y-1">
            <h3 className="text-base font-bold text-sage-800 dark:text-white">
              {searchQuery ? 'No matching courses found' : 'No courses registered yet'}
            </h3>
            <p className="text-xs text-sage-500 max-w-sm mx-auto">
              {searchQuery
                ? `No courses found matching "${searchQuery}". Try a different search term.`
                : 'Create your first course to begin managing learning outcomes, uploading syllabus documents, and preparing assessments.'}
            </p>
          </div>
          {!searchQuery && (
            <Button
              variant="primary"
              size="sm"
              leftIcon={<Plus className="w-4 h-4" />}
              onClick={() => setIsAddModalOpen(true)}
            >
              Create First Course
            </Button>
          )}
        </Card>
      ) : (
        /* Courses Grid */
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {filteredCourses.map((course) => {
            const code = course.course_code || course.code;
            const title = course.course_name || course.title;
            const academicYear = course.academic_year || (course.year ? `${course.year}-${course.year + 1}` : '2025-2026');
            const credits = course.credits || course.creditHours || 3;
            const cloCount = course.learning_outcomes_count ?? course.learningOutcomesCount ?? (course.learning_outcomes ? course.learning_outcomes.length : 0);
            const matCount = course.materials_count ?? (course.materials ? course.materials.length : 0);
            const assessCount = course.assessments_count ?? course.assessmentCount ?? (course.assessments ? course.assessments.length : 0);

            return (
              <Card
                key={course.id}
                variant="default"
                className="flex flex-col justify-between hover:border-sage-700 dark:hover:border-white hover:shadow-card transition-all duration-200"
              >
                <div className="space-y-4">
                  <div className="flex items-start justify-between">
                    <div>
                      <Badge variant="outline" className="font-mono font-bold text-xs bg-sage-100 dark:bg-[#2C2C2E]">
                        {code}
                      </Badge>
                      <h3 className="text-base font-bold text-sage-800 dark:text-white mt-2 leading-snug line-clamp-2">
                        {title}
                      </h3>
                    </div>
                    <div className="w-9 h-9 rounded-lg bg-sage-100 dark:bg-[#2C2C2E] border border-sage-200 dark:border-[#3A3A3C] flex items-center justify-center text-sage-800 dark:text-white shrink-0">
                      <BookOpen className="w-4 h-4" />
                    </div>
                  </div>

                  <div className="text-xs text-sage-500 space-y-1">
                    <p className="font-mono">{course.semester} {academicYear} • {credits} Credits</p>
                    {course.description && (
                      <p className="line-clamp-2 text-sage-700 dark:text-sage-300">{course.description}</p>
                    )}
                  </div>

                  {/* Stats pill list */}
                  <div className="grid grid-cols-3 gap-2 py-3 border-y border-sage-200 dark:border-[#2C2C2E] text-center">
                    <div>
                      <span className="text-[10px] uppercase font-semibold text-sage-500 block">Outcomes</span>
                      <span className="text-xs font-bold text-sage-800 dark:text-white flex items-center justify-center gap-1 mt-0.5">
                        <ListChecks className="w-3 h-3 text-sage-500" /> {cloCount} CLOs
                      </span>
                    </div>
                    <div>
                      <span className="text-[10px] uppercase font-semibold text-sage-500 block">Materials</span>
                      <span className="text-xs font-bold text-sage-800 dark:text-white flex items-center justify-center gap-1 mt-0.5">
                        <Layers className="w-3 h-3 text-sage-500" /> {matCount} Files
                      </span>
                    </div>
                    <div>
                      <span className="text-[10px] uppercase font-semibold text-sage-500 block">Assessments</span>
                      <span className="text-xs font-bold text-sage-800 dark:text-white flex items-center justify-center gap-1 mt-0.5">
                        <FileCheck2 className="w-3 h-3 text-sage-500" /> {assessCount}
                      </span>
                    </div>
                  </div>
                </div>

                <div className="flex items-center gap-2 pt-4 mt-2">
                  <Button
                    variant="outline"
                    size="sm"
                    className="flex-1 justify-center"
                    leftIcon={<ExternalLink className="w-3.5 h-3.5" />}
                    onClick={() => navigate(`/courses/${course.id}`)}
                  >
                    View Details
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    leftIcon={<Edit className="w-3.5 h-3.5" />}
                    onClick={() => setEditingCourse(course)}
                    title="Edit course"
                  />
                  <Button
                    variant="ghost"
                    size="sm"
                    className="text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30"
                    leftIcon={deletingCourseId === course.id ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Trash2 className="w-3.5 h-3.5" />}
                    onClick={() => handleDeleteCourse(course)}
                    disabled={deletingCourseId === course.id}
                    title="Delete course"
                  />
                </div>
              </Card>
            );
          })}
        </div>
      )}

      {/* Add Course Modal */}
      <CourseModal
        isOpen={isAddModalOpen}
        onClose={() => setIsAddModalOpen(false)}
        onSubmit={handleCreateCourse}
      />

      {/* Edit Course Modal */}
      <CourseModal
        isOpen={Boolean(editingCourse)}
        onClose={() => setEditingCourse(null)}
        onSubmit={handleUpdateCourse}
        course={editingCourse}
      />
    </div>
  );
};
