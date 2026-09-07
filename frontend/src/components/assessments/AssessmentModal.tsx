import React, { useState, useEffect } from 'react';
import { Assessment, Course, AssessmentType, AssessmentStatus } from '@/types';
import { AssessmentPayload } from '@/services/assessmentService';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { X, FileCheck2, AlertCircle, Loader2 } from 'lucide-react';

interface AssessmentModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (courseId: number | string, data: AssessmentPayload) => Promise<void>;
  assessment?: Assessment | null;
  courses?: Course[];
  preselectedCourseId?: number | string;
}

const ASSESSMENT_TYPES: { value: AssessmentType; label: string }[] = [
  { value: 'quiz', label: 'Quiz' },
  { value: 'class_test', label: 'Class Test' },
  { value: 'midterm', label: 'Midterm Exam' },
  { value: 'final', label: 'Final Exam' },
  { value: 'assignment', label: 'Assignment' },
  { value: 'project', label: 'Project' },
  { value: 'other', label: 'Other' },
];

const ASSESSMENT_STATUSES: { value: AssessmentStatus; label: string }[] = [
  { value: 'draft', label: 'Draft' },
  { value: 'published', label: 'Published' },
  { value: 'completed', label: 'Completed' },
];

export const AssessmentModal: React.FC<AssessmentModalProps> = ({
  isOpen,
  onClose,
  onSubmit,
  assessment,
  courses = [],
  preselectedCourseId,
}) => {
  const [selectedCourseId, setSelectedCourseId] = useState<string | number>(
    preselectedCourseId || (courses.length > 0 ? courses[0].id : '')
  );
  const [formData, setFormData] = useState<AssessmentPayload>({
    title: '',
    type: 'midterm',
    description: '',
    assessment_date: new Date().toISOString().split('T')[0],
    total_marks: 50,
    duration_minutes: 90,
    status: 'draft',
  });

  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  useEffect(() => {
    if (assessment) {
      setSelectedCourseId(assessment.course_id || assessment.courseId || preselectedCourseId || '');
      setFormData({
        title: assessment.title || '',
        type: (assessment.type?.toLowerCase() as AssessmentType) || 'midterm',
        description: assessment.description || '',
        assessment_date: assessment.assessment_date ? assessment.assessment_date.split('T')[0] : '',
        total_marks: Number(assessment.total_marks || assessment.totalMarks || 50),
        duration_minutes: assessment.duration_minutes ? Number(assessment.duration_minutes) : 90,
        status: (assessment.status?.toLowerCase() as AssessmentStatus) || 'draft',
      });
    } else {
      setSelectedCourseId(preselectedCourseId || (courses.length > 0 ? courses[0].id : ''));
      setFormData({
        title: '',
        type: 'midterm',
        description: '',
        assessment_date: new Date().toISOString().split('T')[0],
        total_marks: 50,
        duration_minutes: 90,
        status: 'draft',
      });
    }
    setError(null);
  }, [assessment, preselectedCourseId, courses, isOpen]);

  if (!isOpen) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    const activeCourseId = assessment ? assessment.course_id : (selectedCourseId || preselectedCourseId);

    if (!activeCourseId) {
      setError('Please select a course for this assessment');
      return;
    }

    if (!formData.title.trim()) {
      setError('Assessment title is required');
      return;
    }

    if (Number(formData.total_marks) <= 0) {
      setError('Total marks must be greater than 0');
      return;
    }

    try {
      setIsSubmitting(true);
      await onSubmit(activeCourseId, {
        ...formData,
        title: formData.title.trim(),
        total_marks: Number(formData.total_marks),
        duration_minutes: formData.duration_minutes ? Number(formData.duration_minutes) : undefined,
      });
      onClose();
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to save assessment. Please try again.');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
      <div className="bg-white dark:bg-[#1C1C1E] rounded-2xl border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-xl max-w-lg w-full p-6 space-y-5 animate-in fade-in zoom-in duration-150">
        <div className="flex items-center justify-between pb-3 border-b border-[#E5E5E5] dark:border-[#2C2C2E]">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center text-[#111111] dark:text-white">
              <FileCheck2 className="w-4 h-4" />
            </div>
            <h3 className="text-lg font-bold text-[#111111] dark:text-white">
              {assessment ? 'Edit Assessment' : 'Create New Assessment'}
            </h3>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-1 rounded-lg text-[#737373] hover:text-[#111111] dark:hover:text-white hover:bg-[#F7F7F5] dark:hover:bg-[#2C2C2E] transition-colors"
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
          {/* Course Selection if not locked / editing */}
          {!preselectedCourseId && !assessment && courses.length > 0 && (
            <div>
              <label className="block text-xs font-medium uppercase tracking-wider text-[#262626] dark:text-[#E5E5E5] mb-1.5">
                Target Course <span className="text-[#DC2626]">*</span>
              </label>
              <select
                value={selectedCourseId}
                onChange={(e) => setSelectedCourseId(e.target.value)}
                disabled={isSubmitting}
                className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-sm text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#111111] dark:focus:ring-white"
              >
                {courses.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.course_code || c.code} — {c.course_name || c.title}
                  </option>
                ))}
              </select>
            </div>
          )}

          <Input
            label="Assessment Title"
            placeholder="e.g. Midterm Examination — Spring 2026"
            value={formData.title}
            onChange={(e) => setFormData({ ...formData, title: e.target.value })}
            required
            disabled={isSubmitting}
          />

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium uppercase tracking-wider text-[#262626] dark:text-[#E5E5E5] mb-1.5">
                Assessment Type <span className="text-[#DC2626]">*</span>
              </label>
              <select
                value={formData.type}
                onChange={(e) => setFormData({ ...formData, type: e.target.value as AssessmentType })}
                disabled={isSubmitting}
                className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-sm text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#111111] dark:focus:ring-white"
              >
                {ASSESSMENT_TYPES.map((t) => (
                  <option key={t.value} value={t.value}>
                    {t.label}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-xs font-medium uppercase tracking-wider text-[#262626] dark:text-[#E5E5E5] mb-1.5">
                Status <span className="text-[#DC2626]">*</span>
              </label>
              <select
                value={formData.status}
                onChange={(e) => setFormData({ ...formData, status: e.target.value as AssessmentStatus })}
                disabled={isSubmitting}
                className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-sm text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#111111] dark:focus:ring-white"
              >
                {ASSESSMENT_STATUSES.map((s) => (
                  <option key={s.value} value={s.value}>
                    {s.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <Input
              label="Date"
              type="date"
              value={formData.assessment_date || ''}
              onChange={(e) => setFormData({ ...formData, assessment_date: e.target.value })}
              disabled={isSubmitting}
            />
            <Input
              label="Total Marks"
              type="number"
              min={1}
              value={formData.total_marks}
              onChange={(e) => setFormData({ ...formData, total_marks: Number(e.target.value) })}
              required
              disabled={isSubmitting}
            />
            <Input
              label="Duration (mins)"
              type="number"
              min={1}
              placeholder="e.g. 90"
              value={formData.duration_minutes || ''}
              onChange={(e) => setFormData({ ...formData, duration_minutes: Number(e.target.value) || undefined })}
              disabled={isSubmitting}
            />
          </div>

          <div>
            <label className="block text-xs font-medium uppercase tracking-wider text-[#262626] dark:text-[#E5E5E5] mb-1.5">
              Description / Instructions
            </label>
            <textarea
              rows={3}
              placeholder="Assessment scope, exam instructions, calculators allowed, etc..."
              value={formData.description || ''}
              onChange={(e) => setFormData({ ...formData, description: e.target.value })}
              disabled={isSubmitting}
              className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#2C2C2E] bg-white dark:bg-[#2C2C2E] px-3.5 py-2 text-sm text-[#111111] dark:text-white placeholder:text-[#A3A3A3] focus:outline-none focus:ring-2 focus:ring-[#111111] dark:focus:ring-white resize-none"
            />
          </div>

          <div className="flex items-center justify-end gap-3 pt-4 border-t border-[#E5E5E5] dark:border-[#2C2C2E]">
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
              {isSubmitting ? 'Saving...' : assessment ? 'Update Assessment' : 'Create Assessment'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
};

