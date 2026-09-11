import React, { useState, useEffect } from 'react';
import { Course } from '@/types';
import { CoursePayload } from '@/services/courseService';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { X, BookOpen, AlertCircle, Loader2 } from 'lucide-react';

interface CourseModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (data: CoursePayload) => Promise<void>;
  course?: Course | null;
  title?: string;
}

export const CourseModal: React.FC<CourseModalProps> = ({
  isOpen,
  onClose,
  onSubmit,
  course,
  title,
}) => {
  const [formData, setFormData] = useState<CoursePayload>({
    course_code: '',
    course_name: '',
    description: '',
    semester: 'Spring',
    academic_year: '2025-2026',
    credits: 3,
    status: 'active',
  });

  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    if (course) {
      setFormData({
        course_code: course.course_code || course.code || '',
        course_name: course.course_name || course.title || '',
        description: course.description || '',
        semester: course.semester || 'Spring',
        academic_year: course.academic_year || (course.year ? `${course.year}-${course.year + 1}` : '2025-2026'),
        credits: course.credits || course.creditHours || 3,
        status: course.status || 'active',
      });
    } else {
      setFormData({
        course_code: '',
        course_name: '',
        description: '',
        semester: 'Spring',
        academic_year: '2025-2026',
        credits: 3,
        status: 'active',
      });
    }
    setError(null);
  }, [course, isOpen]);

  if (!isOpen) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!formData.course_code.trim()) {
      setError('Course code is required');
      return;
    }
    if (!formData.course_name.trim()) {
      setError('Course name is required');
      return;
    }

    try {
      setIsSubmitting(true);
      await onSubmit({
        ...formData,
        course_code: formData.course_code.trim().toUpperCase(),
        course_name: formData.course_name.trim(),
        credits: Number(formData.credits) || 3,
      });
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to save course. Please try again.');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
      <div className="bg-white dark:bg-[#1C1C1E] rounded-2xl border border-sage-200 dark:border-[#2C2C2E] shadow-xl max-w-lg w-full p-6 space-y-5 animate-in fade-in zoom-in duration-150">
        <div className="flex items-center justify-between pb-3 border-b border-sage-200 dark:border-[#2C2C2E]">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-sage-100 dark:bg-[#2C2C2E] border border-sage-200 dark:border-[#3A3A3C] flex items-center justify-center text-sage-800 dark:text-white">
              <BookOpen className="w-4 h-4" />
            </div>
            <h3 className="text-lg font-bold text-sage-800 dark:text-white">
              {title || (course ? 'Edit Course' : 'Create New Course')}
            </h3>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-1 rounded-lg text-sage-500 hover:text-sage-800 dark:hover:text-white hover:bg-sage-100 dark:hover:bg-[#2C2C2E] transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {error && (
          <div className="p-3 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 rounded-lg text-xs text-red-600 dark:text-red-400 flex items-center gap-2">
            <AlertCircle className="w-4 h-4 shrink-0" />
            <span>{error}</span>
          </div>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Input
              label="Course Code"
              placeholder="e.g. CSE 4201"
              value={formData.course_code}
              onChange={(e) => setFormData({ ...formData, course_code: e.target.value })}
              required
              disabled={isSubmitting}
            />
            <div>
              <label className="block text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-200 mb-1.5">
                Credits <span className="text-[#DC2626]">*</span>
              </label>
              <select
                value={formData.credits}
                onChange={(e) => setFormData({ ...formData, credits: Number(e.target.value) })}
                disabled={isSubmitting}
                className="w-full rounded-lg border border-sage-200 dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-sm text-sage-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-sage-600 dark:focus:ring-white"
              >
                <option value={1}>1 Credit Unit</option>
                <option value={2}>2 Credit Units</option>
                <option value={3}>3 Credit Units</option>
                <option value={4}>4 Credit Units</option>
                <option value={5}>5 Credit Units</option>
              </select>
            </div>
          </div>

          <Input
            label="Course Title / Name"
            placeholder="e.g. Artificial Intelligence & Expert Systems"
            value={formData.course_name}
            onChange={(e) => setFormData({ ...formData, course_name: e.target.value })}
            required
            disabled={isSubmitting}
          />

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-200 mb-1.5">
                Semester
              </label>
              <select
                value={formData.semester}
                onChange={(e) => setFormData({ ...formData, semester: e.target.value })}
                disabled={isSubmitting}
                className="w-full rounded-lg border border-sage-200 dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-sm text-sage-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-sage-600 dark:focus:ring-white"
              >
                <option value="Spring">Spring</option>
                <option value="Summer">Summer</option>
                <option value="Fall">Fall</option>
                <option value="Winter">Winter</option>
              </select>
            </div>
            <Input
              label="Academic Year"
              placeholder="2025-2026"
              value={formData.academic_year}
              onChange={(e) => setFormData({ ...formData, academic_year: e.target.value })}
              required
              disabled={isSubmitting}
            />
          </div>

          <div>
            <label className="block text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-200 mb-1.5">
              Course Description
            </label>
            <textarea
              rows={3}
              placeholder="Brief summary of course topics, prerequisites, and scope..."
              value={formData.description || ''}
              onChange={(e) => setFormData({ ...formData, description: e.target.value })}
              disabled={isSubmitting}
              className="w-full rounded-lg border border-sage-200 dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-sm text-sage-800 dark:text-white placeholder:text-sage-400 focus:outline-none focus:ring-2 focus:ring-sage-600 dark:focus:ring-white resize-none"
            />
          </div>

          <div className="flex items-center justify-end gap-3 pt-4 border-t border-sage-200 dark:border-[#2C2C2E]">
            <Button
              variant="outline"
              size="sm"
              type="button"
              onClick={onClose}
              disabled={isSubmitting}
            >
              Cancel
            </Button>
            <Button
              variant="primary"
              size="sm"
              type="submit"
              disabled={isSubmitting}
              leftIcon={isSubmitting ? <Loader2 className="w-4 h-4 animate-spin" /> : undefined}
            >
              {isSubmitting ? 'Saving...' : course ? 'Update Course' : 'Create Course'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
};

