import React, { useState, useEffect, useCallback } from 'react';
import { useParams, Link } from 'react-router-dom';
import { Course, PreviousQuestion, PaginatedResponse } from '@/types';
import { courseService } from '@/services/courseService';
import {
  previousQuestionService,
  PreviousQuestionPayload,
  PreviousQuestionQueryParams,
} from '@/services/previousQuestionService';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { Input } from '@/components/common/Input';
import { PreviousQuestionModal } from '@/components/assessments/PreviousQuestionModal';
import { PreviousQuestionUploadModal } from '@/components/assessments/PreviousQuestionUploadModal';
import {
  HelpCircle,
  Plus,
  UploadCloud,
  Search,
  Download,
  Edit,
  Trash2,
  FileText,
  Loader2,
  AlertCircle,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  BookOpen,
} from 'lucide-react';

export const QuestionBank: React.FC = () => {
  const { courseId } = useParams<{ courseId?: string }>();

  const [courses, setCourses] = useState<Course[]>([]);
  const [selectedCourseId, setSelectedCourseId] = useState<string | number>(courseId || '');
  const [activeCourse, setActiveCourse] = useState<Course | null>(null);

  const [paginatedData, setPaginatedData] = useState<PaginatedResponse<PreviousQuestion>>({
    data: [],
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
    from: null,
    to: null,
  });

  const [searchQuery, setSearchQuery] = useState('');
  const [selectedType, setSelectedType] = useState('all');
  const [selectedDifficulty, setSelectedDifficulty] = useState('all');
  const [selectedCognitive, setSelectedCognitive] = useState('all');
  const [selectedYear, setSelectedYear] = useState('all');
  const [sortBy, setSortBy] = useState('newest');
  const [currentPage, setCurrentPage] = useState(1);

  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // Modals
  const [isAddModalOpen, setIsAddModalOpen] = useState(false);
  const [isUploadModalOpen, setIsUploadModalOpen] = useState(false);
  const [editingQuestion, setEditingQuestion] = useState<PreviousQuestion | null>(null);
  const [deletingId, setDeletingId] = useState<number | string | null>(null);

  const showNotification = (msg: string) => {
    setSuccessMessage(msg);
    setTimeout(() => setSuccessMessage(null), 4000);
  };

  // 1. Load faculty courses
  useEffect(() => {
    const fetchCourses = async () => {
      try {
        const res = await courseService.getAll();
        setCourses(res.data || []);
        if (!selectedCourseId && res.data && res.data.length > 0) {
          setSelectedCourseId(res.data[0].id);
        }
      } catch (err: unknown) {
        console.error('Failed to load courses:', err);
      }
    };
    fetchCourses();
  }, [selectedCourseId]);

  // 2. Sync activeCourse
  useEffect(() => {
    if (selectedCourseId && courses.length > 0) {
      const found = courses.find((c) => String(c.id) === String(selectedCourseId)) || null;
      setActiveCourse(found);
    }
  }, [selectedCourseId, courses]);

  // 3. Load Questions for selected course
  const loadQuestions = useCallback(async () => {
    if (!selectedCourseId) return;
    try {
      setIsLoading(true);
      setError(null);
      const params: PreviousQuestionQueryParams = {
        page: currentPage,
        per_page: 10,
        search: searchQuery,
        question_type: selectedType,
        difficulty_level: selectedDifficulty,
        cognitive_level: selectedCognitive,
        source_year: selectedYear,
        sort_by: sortBy,
      };

      const res = await previousQuestionService.getByCourse(selectedCourseId, params);
      setPaginatedData(res);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to load questions from bank.');
      }
    } finally {
      setIsLoading(false);
    }
  }, [selectedCourseId, currentPage, searchQuery, selectedType, selectedDifficulty, selectedCognitive, selectedYear, sortBy]);

  useEffect(() => {
    loadQuestions();
  }, [loadQuestions]);

  const handleAddQuestion = async (data: PreviousQuestionPayload) => {
    if (!selectedCourseId) return;
    await previousQuestionService.create(selectedCourseId, data);
    showNotification('Question added to Question Bank!');
    loadQuestions();
  };

  const handleUploadDocument = async (formData: FormData) => {
    if (!selectedCourseId) return;
    await previousQuestionService.create(selectedCourseId, formData);
    showNotification('Previous question paper uploaded successfully!');
    loadQuestions();
  };

  const handleUpdateQuestion = async (data: PreviousQuestionPayload) => {
    if (!editingQuestion) return;
    await previousQuestionService.update(editingQuestion.id, data);
    showNotification('Question updated successfully!');
    setEditingQuestion(null);
    loadQuestions();
  };

  const handleDeleteQuestion = async (q: PreviousQuestion) => {
    if (!window.confirm('Are you sure you want to delete this question from the bank?')) return;
    try {
      setDeletingId(q.id);
      await previousQuestionService.delete(q.id);
      showNotification('Question removed from bank.');
      loadQuestions();
    } catch (err: unknown) {
      if (err instanceof Error) setError(err.message);
    } finally {
      setDeletingId(null);
    }
  };

  const handleDownloadFile = async (q: PreviousQuestion) => {
    if (!q.file_name) return;
    try {
      await previousQuestionService.download(q.id, q.file_name);
    } catch (err: unknown) {
      if (err instanceof Error) setError(err.message);
    }
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

      {/* Header & Course Switcher */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="space-y-1">
          <div className="flex items-center gap-2 text-xs text-[#737373]">
            <Link to="/courses" className="hover:text-[#111111] dark:hover:text-white transition-colors">
              Courses
            </Link>
            <span>/</span>
            {activeCourse && (
              <>
                <Link to={`/courses/${activeCourse.id}`} className="font-mono hover:text-[#111111] dark:hover:text-white transition-colors">
                  {activeCourse.course_code || activeCourse.code}
                </Link>
                <span>/</span>
              </>
            )}
            <span className="font-semibold text-[#111111] dark:text-white">Question Bank</span>
          </div>
          <h1 className="text-2xl font-bold text-[#111111] dark:text-white flex items-center gap-2">
            <HelpCircle className="w-6 h-6" /> Course Question Bank
          </h1>
        </div>

        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            leftIcon={<UploadCloud className="w-3.5 h-3.5" />}
            onClick={() => setIsUploadModalOpen(true)}
            disabled={!selectedCourseId}
          >
            Upload Past Paper
          </Button>
          <Button
            variant="primary"
            size="sm"
            leftIcon={<Plus className="w-3.5 h-3.5" />}
            onClick={() => setIsAddModalOpen(true)}
            disabled={!selectedCourseId}
          >
            Add Question
          </Button>
        </div>
      </div>

      {/* Course Selector Bar */}
      <div className="p-4 bg-white dark:bg-[#1C1C1E] rounded-xl border border-[#E5E5E5] dark:border-[#2C2C2E] flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <BookOpen className="w-4 h-4 text-[#737373]" />
          <span className="text-xs font-semibold text-[#737373]">Selected Course:</span>
          <select
            value={selectedCourseId}
            onChange={(e) => {
              setSelectedCourseId(e.target.value);
              setCurrentPage(1);
            }}
            className="rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] px-3 py-1.5 text-xs font-bold text-[#111111] dark:text-white focus:outline-none focus:ring-2 focus:ring-[#111111]"
          >
            {courses.map((c) => (
              <option key={c.id} value={c.id}>
                {c.course_code || c.code} — {c.course_name || c.title}
              </option>
            ))}
          </select>
        </div>

        <div className="text-xs text-[#737373]">
          Total in Bank: <span className="font-bold text-[#111111] dark:text-white font-mono">{paginatedData.total}</span> Questions
        </div>
      </div>

      {/* Filter and Search Bar */}
      <div className="space-y-3">
        <div className="flex flex-col sm:flex-row gap-3">
          <div className="relative flex-1">
            <Input
              placeholder="Search questions by text or exam source..."
              value={searchQuery}
              onChange={(e) => {
                setSearchQuery(e.target.value);
                setCurrentPage(1);
              }}
              leftIcon={<Search className="w-4 h-4" />}
            />
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2 text-xs p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl border border-[#E5E5E5] dark:border-[#3A3A3C]">
          <div className="flex items-center gap-1">
            <span className="font-semibold text-[#737373]">Type:</span>
            <select
              value={selectedType}
              onChange={(e) => {
                setSelectedType(e.target.value);
                setCurrentPage(1);
              }}
              className="rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] px-2.5 py-1 text-xs text-[#111111] dark:text-white"
            >
              <option value="all">All Types</option>
              <option value="descriptive">Descriptive</option>
              <option value="problem_solving">Problem Solving</option>
              <option value="short_answer">Short Answer</option>
              <option value="mcq">MCQ</option>
              <option value="true_false">True / False</option>
            </select>
          </div>

          <div className="flex items-center gap-1">
            <span className="font-semibold text-[#737373]">Difficulty:</span>
            <select
              value={selectedDifficulty}
              onChange={(e) => {
                setSelectedDifficulty(e.target.value);
                setCurrentPage(1);
              }}
              className="rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] px-2.5 py-1 text-xs text-[#111111] dark:text-white"
            >
              <option value="all">All Difficulties</option>
              <option value="easy">Easy</option>
              <option value="medium">Medium</option>
              <option value="hard">Hard</option>
            </select>
          </div>

          <div className="flex items-center gap-1">
            <span className="font-semibold text-[#737373]">Bloom Level:</span>
            <select
              value={selectedCognitive}
              onChange={(e) => {
                setSelectedCognitive(e.target.value);
                setCurrentPage(1);
              }}
              className="rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] px-2.5 py-1 text-xs text-[#111111] dark:text-white"
            >
              <option value="all">All Levels</option>
              <option value="Remember">Remember</option>
              <option value="Understand">Understand</option>
              <option value="Apply">Apply</option>
              <option value="Analyze">Analyze</option>
              <option value="Evaluate">Evaluate</option>
              <option value="Create">Create</option>
            </select>
          </div>

          <div className="flex items-center gap-1">
            <span className="font-semibold text-[#737373]">Year:</span>
            <select
              value={selectedYear}
              onChange={(e) => {
                setSelectedYear(e.target.value);
                setCurrentPage(1);
              }}
              className="rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] px-2.5 py-1 text-xs text-[#111111] dark:text-white"
            >
              <option value="all">All Years</option>
              <option value="2026">2026</option>
              <option value="2025">2025</option>
              <option value="2024">2024</option>
              <option value="2023">2023</option>
              <option value="2022">2022</option>
            </select>
          </div>

          <div className="flex items-center gap-1">
            <span className="font-semibold text-[#737373]">Sort By:</span>
            <select
              value={sortBy}
              onChange={(e) => {
                setSortBy(e.target.value);
                setCurrentPage(1);
              }}
              className="rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#1C1C1E] px-2.5 py-1 text-xs text-[#111111] dark:text-white"
            >
              <option value="newest">Newest</option>
              <option value="oldest">Oldest</option>
              <option value="marks_asc">Marks: Low to High</option>
              <option value="marks_desc">Marks: High to Low</option>
              <option value="difficulty">Difficulty</option>
            </select>
          </div>
        </div>
      </div>

      {/* Error Feedback */}
      {error && (
        <div className="p-4 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 rounded-xl flex items-center justify-between text-red-700 dark:text-red-300 text-sm font-medium">
          <div className="flex items-center gap-3">
            <AlertCircle className="w-5 h-5 shrink-0 text-red-500" />
            <span>{error}</span>
          </div>
          <Button variant="outline" size="sm" onClick={() => loadQuestions()}>
            Retry
          </Button>
        </div>
      )}

      {/* Content List */}
      {isLoading ? (
        <div className="flex flex-col items-center justify-center min-h-[300px] space-y-4">
          <Loader2 className="w-8 h-8 animate-spin text-[#111111] dark:text-white" />
          <p className="text-sm text-[#737373]">Loading questions from repository...</p>
        </div>
      ) : paginatedData.data.length === 0 ? (
        <Card variant="default" className="p-12 text-center space-y-4">
          <HelpCircle className="w-12 h-12 text-[#737373] mx-auto opacity-50" />
          <div className="space-y-1">
            <h3 className="text-base font-bold text-[#111111] dark:text-white">
              {searchQuery || selectedType !== 'all' || selectedDifficulty !== 'all'
                ? 'No questions match the filter criteria'
                : 'Question Bank is empty for this course'}
            </h3>
            <p className="text-xs text-[#737373] max-w-sm mx-auto">
              Add individual questions manually or upload historical question papers to build your course repository.
            </p>
          </div>
          <div className="flex items-center justify-center gap-3 pt-2">
            <Button
              variant="outline"
              size="sm"
              leftIcon={<UploadCloud className="w-3.5 h-3.5" />}
              onClick={() => setIsUploadModalOpen(true)}
            >
              Upload Past Paper
            </Button>
            <Button
              variant="primary"
              size="sm"
              leftIcon={<Plus className="w-3.5 h-3.5" />}
              onClick={() => setIsAddModalOpen(true)}
            >
              Add Question
            </Button>
          </div>
        </Card>
      ) : (
        <div className="space-y-4">
          <div className="space-y-3">
            {paginatedData.data.map((q, idx) => {
              const itemNumber = (paginatedData.current_page - 1) * paginatedData.per_page + idx + 1;

              return (
                <Card
                  key={q.id}
                  variant="default"
                  className="p-5 space-y-3 hover:border-[#111111] dark:hover:border-white transition-colors"
                >
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="font-mono font-bold text-xs text-[#737373]">#{itemNumber}</span>
                      {q.difficulty_level && (
                        <Badge variant="neutral" className="text-[10px] capitalize">
                          {q.difficulty_level}
                        </Badge>
                      )}
                      {q.cognitive_level && (
                        <Badge variant="outline" className="text-[10px]">
                          Bloom: {q.cognitive_level}
                        </Badge>
                      )}
                      {q.question_type && (
                        <Badge variant="neutral" className="text-[10px] capitalize">
                          {q.question_type.replace('_', ' ')}
                        </Badge>
                      )}
                      {q.marks && (
                        <span className="text-xs font-mono font-bold text-[#111111] dark:text-white">
                          {q.marks} Marks
                        </span>
                      )}
                    </div>

                    <div className="flex items-center gap-1 shrink-0">
                      {q.file_name && (
                        <Button
                          variant="ghost"
                          size="sm"
                          leftIcon={<Download className="w-3.5 h-3.5" />}
                          onClick={() => handleDownloadFile(q)}
                          title="Download attached paper"
                        />
                      )}
                      <Button
                        variant="ghost"
                        size="sm"
                        leftIcon={<Edit className="w-3.5 h-3.5" />}
                        onClick={() => setEditingQuestion(q)}
                        title="Edit question"
                      />
                      <Button
                        variant="ghost"
                        size="sm"
                        className="text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30"
                        leftIcon={deletingId === q.id ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Trash2 className="w-3.5 h-3.5" />}
                        onClick={() => handleDeleteQuestion(q)}
                        disabled={deletingId === q.id}
                        title="Delete question"
                      />
                    </div>
                  </div>

                  <p className="text-sm text-[#111111] dark:text-[#E5E5E5] leading-relaxed whitespace-pre-line">
                    {q.question_text}
                  </p>

                  <div className="flex flex-wrap items-center justify-between text-[11px] text-[#737373] pt-2 border-t border-[#E5E5E5] dark:border-[#2C2C2E]">
                    <div className="flex items-center gap-2">
                      {q.source_assessment && <span>Exam: {q.source_assessment}</span>}
                      {q.source_year && <span>• Year: {q.source_year}</span>}
                      {q.source && <span>• Source: {q.source.replace('_', ' ')}</span>}
                    </div>

                    {q.file_name && (
                      <div className="flex items-center gap-1 text-emerald-600 dark:text-emerald-400 font-medium font-mono">
                        <FileText className="w-3 h-3" />
                        <span>{q.file_name}</span>
                      </div>
                    )}
                  </div>
                </Card>
              );
            })}
          </div>

          {/* Pagination Footer */}
          {paginatedData.last_page > 1 && (
            <div className="flex items-center justify-between pt-4 border-t border-[#E5E5E5] dark:border-[#2C2C2E] text-xs">
              <span className="text-[#737373]">
                Showing {paginatedData.from} - {paginatedData.to} of {paginatedData.total} questions
              </span>

              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  leftIcon={<ChevronLeft className="w-3.5 h-3.5" />}
                  onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                  disabled={currentPage <= 1}
                >
                  Previous
                </Button>

                <div className="px-3 py-1 font-mono font-bold bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-lg text-[#111111] dark:text-white">
                  Page {paginatedData.current_page} of {paginatedData.last_page}
                </div>

                <Button
                  variant="outline"
                  size="sm"
                  rightIcon={<ChevronRight className="w-3.5 h-3.5" />}
                  onClick={() => setCurrentPage((p) => Math.min(paginatedData.last_page, p + 1))}
                  disabled={currentPage >= paginatedData.last_page}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Add Modal */}
      <PreviousQuestionModal
        isOpen={isAddModalOpen}
        onClose={() => setIsAddModalOpen(false)}
        onSubmit={handleAddQuestion}
      />

      {/* Edit Modal */}
      <PreviousQuestionModal
        isOpen={Boolean(editingQuestion)}
        onClose={() => setEditingQuestion(null)}
        onSubmit={handleUpdateQuestion}
        question={editingQuestion}
      />

      {/* Upload Modal */}
      <PreviousQuestionUploadModal
        isOpen={isUploadModalOpen}
        onClose={() => setIsUploadModalOpen(false)}
        onSubmit={handleUploadDocument}
      />
    </div>
  );
};
