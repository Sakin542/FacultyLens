import React, { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { Input } from '@/components/common/Input';
import { Assessment, Course } from '@/types';
import { assessmentService, AssessmentPayload } from '@/services/assessmentService';
import { courseService } from '@/services/courseService';
import { AssessmentModal } from '@/components/assessments/AssessmentModal';
import {
  Plus,
  Search,
  FileCheck2,
  Calendar,
  Layers,
  Edit,
  Trash2,
  ExternalLink,
  Loader2,
  AlertCircle,
  CheckCircle2,
  FileText,
} from 'lucide-react';

export const Assessments: React.FC = () => {
  const navigate = useNavigate();
  const [assessments, setAssessments] = useState<Assessment[]>([]);
  const [courses, setCourses] = useState<Course[]>([]);
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCourseId, setSelectedCourseId] = useState<string>('all');
  const [selectedType, setSelectedType] = useState<string>('all');
  const [selectedStatus, setSelectedStatus] = useState<string>('all');
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // Modal states
  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [editingAssessment, setEditingAssessment] = useState<Assessment | null>(null);
  const [deletingId, setDeletingId] = useState<number | string | null>(null);

  const showNotification = (msg: string) => {
    setSuccessMessage(msg);
    setTimeout(() => setSuccessMessage(null), 4000);
  };

  const loadData = useCallback(async () => {
    try {
      setIsLoading(true);
      setError(null);
      const [asmRes, coursesRes] = await Promise.all([
        assessmentService.getAll(),
        courseService.getAll(),
      ]);
      setAssessments(asmRes.data || []);
      setCourses(coursesRes.data || []);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to load assessments.');
      }
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const handleCreateAssessment = async (courseId: number | string, data: AssessmentPayload) => {
    const res = await assessmentService.create(courseId, data);
    setAssessments([res.data, ...assessments]);
    showNotification('Assessment created successfully!');
  };

  const handleUpdateAssessment = async (_courseId: number | string, data: AssessmentPayload) => {
    if (!editingAssessment) return;
    const res = await assessmentService.update(editingAssessment.id, data);
    setAssessments(assessments.map((a) => (a.id === editingAssessment.id ? res.data : a)));
    setEditingAssessment(null);
    showNotification('Assessment updated successfully!');
  };

  const handleDeleteAssessment = async (asm: Assessment) => {
    if (!window.confirm(`Are you sure you want to delete "${asm.title}"? Any attached question paper and question records will also be deleted.`)) {
      return;
    }

    try {
      setDeletingId(asm.id);
      await assessmentService.delete(asm.id);
      setAssessments(assessments.filter((a) => a.id !== asm.id));
      showNotification('Assessment deleted successfully!');
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      }
    } finally {
      setDeletingId(null);
    }
  };

  const filteredAssessments = assessments.filter((asm) => {
    const title = asm.title.toLowerCase();
    const courseCode = (asm.course?.course_code || asm.courseCode || '').toLowerCase();
    const courseName = (asm.course?.course_name || asm.courseTitle || '').toLowerCase();
    const query = searchQuery.toLowerCase();

    const matchesSearch = title.includes(query) || courseCode.includes(query) || courseName.includes(query);
    const matchesCourse = selectedCourseId === 'all' || String(asm.course_id) === String(selectedCourseId);
    const matchesType = selectedType === 'all' || asm.type?.toLowerCase() === selectedType.toLowerCase();
    const matchesStatus = selectedStatus === 'all' || asm.status?.toLowerCase() === selectedStatus.toLowerCase();

    return matchesSearch && matchesCourse && matchesType && matchesStatus;
  });

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
          <Button variant="outline" size="sm" onClick={() => loadData()}>
            Retry
          </Button>
        </div>
      )}

      {/* Header Controls */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="relative flex-1 max-w-md">
          <Input
            placeholder="Search assessments by title or course..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            leftIcon={<Search className="w-4 h-4" />}
          />
        </div>

        <div className="flex items-center gap-3">
          <Button
            variant="primary"
            leftIcon={<Plus className="w-4 h-4" />}
            onClick={() => setIsCreateModalOpen(true)}
            disabled={courses.length === 0}
          >
            Create Assessment
          </Button>
        </div>
      </div>

      {/* Filter Toolbar */}
      <div className="flex flex-wrap items-center gap-3 p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] text-xs">
        <div className="flex items-center gap-1.5">
          <span className="font-semibold text-[#737373]">Course:</span>
          <select
            value={selectedCourseId}
            onChange={(e) => setSelectedCourseId(e.target.value)}
            className="rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] px-2.5 py-1 text-xs text-[#111111] dark:text-white"
          >
            <option value="all">All Courses ({courses.length})</option>
            {courses.map((c) => (
              <option key={c.id} value={c.id}>
                {c.course_code || c.code} — {c.course_name || c.title}
              </option>
            ))}
          </select>
        </div>

        <div className="flex items-center gap-1.5">
          <span className="font-semibold text-[#737373]">Type:</span>
          <select
            value={selectedType}
            onChange={(e) => setSelectedType(e.target.value)}
            className="rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] px-2.5 py-1 text-xs text-[#111111] dark:text-white capitalize"
          >
            <option value="all">All Types</option>
            <option value="quiz">Quiz</option>
            <option value="class_test">Class Test</option>
            <option value="midterm">Midterm</option>
            <option value="final">Final</option>
            <option value="assignment">Assignment</option>
            <option value="project">Project</option>
          </select>
        </div>

        <div className="flex items-center gap-1.5">
          <span className="font-semibold text-[#737373]">Status:</span>
          <select
            value={selectedStatus}
            onChange={(e) => setSelectedStatus(e.target.value)}
            className="rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] px-2.5 py-1 text-xs text-[#111111] dark:text-white capitalize"
          >
            <option value="all">All Statuses</option>
            <option value="draft">Draft</option>
            <option value="published">Published</option>
            <option value="completed">Completed</option>
          </select>
        </div>
      </div>

      {/* Content State */}
      {isLoading ? (
        <div className="flex flex-col items-center justify-center min-h-[300px] space-y-4">
          <Loader2 className="w-8 h-8 animate-spin text-[#111111] dark:text-white" />
          <p className="text-sm text-[#737373]">Loading assessments...</p>
        </div>
      ) : filteredAssessments.length === 0 ? (
        <Card variant="default" className="p-12 text-center space-y-4">
          <div className="w-12 h-12 rounded-xl bg-[#F7F7F5] dark:bg-[#2C2C2E] flex items-center justify-center text-[#111111] dark:text-white mx-auto">
            <FileCheck2 className="w-6 h-6" />
          </div>
          <div className="space-y-1">
            <h3 className="text-base font-bold text-[#111111] dark:text-white">
              {searchQuery || selectedCourseId !== 'all' || selectedType !== 'all'
                ? 'No matching assessments found'
                : 'No assessments created yet'}
            </h3>
            <p className="text-xs text-[#737373] max-w-sm mx-auto">
              {searchQuery || selectedCourseId !== 'all' || selectedType !== 'all'
                ? 'Try adjusting your search or filters.'
                : courses.length === 0
                ? 'Create a course first before preparing assessments.'
                : 'Create your first assessment to begin uploading question papers and managing questions.'}
            </p>
          </div>
          {courses.length > 0 && (
            <Button
              variant="primary"
              size="sm"
              leftIcon={<Plus className="w-4 h-4" />}
              onClick={() => setIsCreateModalOpen(true)}
            >
              Create First Assessment
            </Button>
          )}
        </Card>
      ) : (
        /* Assessments Grid */
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          {filteredAssessments.map((asm) => {
            const courseCode = asm.course?.course_code || asm.courseCode || 'N/A';
            const courseTitle = asm.course?.course_name || asm.courseTitle || '';
            const paper = asm.questionPaper || asm.question_paper;
            const qCount = asm.questions_count ?? (asm.questions ? asm.questions.length : 0);

            return (
              <Card
                key={asm.id}
                variant="default"
                className="flex flex-col justify-between hover:border-[#111111] dark:hover:border-white hover:shadow-card transition-all duration-200"
              >
                <div className="space-y-4">
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <div className="flex items-center gap-2">
                        <Badge variant="outline" className="font-mono text-xs bg-[#F7F7F5] dark:bg-[#2C2C2E]">
                          {courseCode}
                        </Badge>
                        <Badge
                          variant={asm.status === 'completed' || asm.status === 'Analyzed' ? 'default' : 'neutral'}
                          className="text-xs capitalize"
                        >
                          {asm.status}
                        </Badge>
                      </div>
                      <h3 className="text-base font-bold text-[#111111] dark:text-white mt-2.5 leading-snug">
                        {asm.title}
                      </h3>
                      <p className="text-xs text-[#737373] line-clamp-1">{courseTitle}</p>
                    </div>

                    <div className="w-9 h-9 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center text-[#111111] dark:text-white shrink-0">
                      <FileCheck2 className="w-4 h-4" />
                    </div>
                  </div>

                  {/* Details Pill Box */}
                  <div className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C] space-y-2 text-xs">
                    <div className="flex items-center justify-between text-[#737373]">
                      <span className="capitalize font-semibold text-[#111111] dark:text-white">
                        {asm.type} Exam
                      </span>
                      <span className="font-mono font-bold text-[#111111] dark:text-white">
                        {asm.total_marks || asm.totalMarks} Marks
                      </span>
                    </div>

                    <div className="flex items-center justify-between text-[11px] text-[#737373] pt-1 border-t border-[#E5E5E5] dark:border-[#3A3A3C]">
                      <span className="flex items-center gap-1">
                        <Calendar className="w-3 h-3" />
                        {asm.assessment_date ? asm.assessment_date.split('T')[0] : 'Date TBD'}
                      </span>
                      <span className="flex items-center gap-1">
                        <Layers className="w-3 h-3" />
                        {qCount} {qCount === 1 ? 'Question' : 'Questions'}
                      </span>
                    </div>

                    {paper ? (
                      <div className="pt-1 flex items-center gap-1.5 text-[11px] text-emerald-600 dark:text-emerald-400 font-medium">
                        <FileText className="w-3.5 h-3.5" />
                        <span className="truncate max-w-[200px]">{paper.file_name}</span>
                      </div>
                    ) : (
                      <div className="pt-1 text-[11px] text-amber-600 dark:text-amber-400">
                        No question paper attached
                      </div>
                    )}
                  </div>
                </div>

                {/* Actions */}
                <div className="flex items-center gap-2 pt-4 mt-2 border-t border-[#E5E5E5] dark:border-[#2C2C2E]">
                  <Button
                    variant="outline"
                    size="sm"
                    className="flex-1 justify-center"
                    leftIcon={<ExternalLink className="w-3.5 h-3.5" />}
                    onClick={() => navigate(`/assessments/${asm.id}`)}
                  >
                    View Details
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    leftIcon={<Edit className="w-3.5 h-3.5" />}
                    onClick={() => setEditingAssessment(asm)}
                    title="Edit assessment"
                  />
                  <Button
                    variant="ghost"
                    size="sm"
                    className="text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30"
                    leftIcon={deletingId === asm.id ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Trash2 className="w-3.5 h-3.5" />}
                    onClick={() => handleDeleteAssessment(asm)}
                    disabled={deletingId === asm.id}
                    title="Delete assessment"
                  />
                </div>
              </Card>
            );
          })}
        </div>
      )}

      {/* Create Assessment Modal */}
      <AssessmentModal
        isOpen={isCreateModalOpen}
        onClose={() => setIsCreateModalOpen(false)}
        onSubmit={handleCreateAssessment}
        courses={courses}
      />

      {/* Edit Assessment Modal */}
      <AssessmentModal
        isOpen={Boolean(editingAssessment)}
        onClose={() => setEditingAssessment(null)}
        onSubmit={handleUpdateAssessment}
        assessment={editingAssessment}
        courses={courses}
      />
    </div>
  );
};
