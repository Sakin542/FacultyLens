import React, { useState, useEffect, useCallback } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { Assessment } from '@/types';
import { assessmentService, AssessmentPayload } from '@/services/assessmentService';
import { questionPaperService } from '@/services/questionPaperService';
import { Card } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Badge } from '@/components/common/Badge';
import { AssessmentModal } from '@/components/assessments/AssessmentModal';
import { QuestionPaperUploadModal } from '@/components/assessments/QuestionPaperUploadModal';
import {
  ArrowLeft,
  Edit,
  Trash2,
  UploadCloud,
  FileText,
  Download,
  Calendar,
  Layers,
  Clock,
  Award,
  AlertCircle,
  Loader2,
  CheckCircle2,
  HelpCircle,
  ExternalLink,
} from 'lucide-react';

export const AssessmentDetails: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const [assessment, setAssessment] = useState<Assessment | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  // Modals
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [isPaperUploadOpen, setIsPaperUploadOpen] = useState(false);

  // Action states
  const [isDeletingAssessment, setIsDeletingAssessment] = useState(false);
  const [isDeletingPaper, setIsDeletingPaper] = useState(false);
  const [isDownloadingPaper, setIsDownloadingPaper] = useState(false);

  const showNotification = (msg: string) => {
    setSuccessMessage(msg);
    setTimeout(() => setSuccessMessage(null), 4000);
  };

  const loadAssessment = useCallback(async () => {
    if (!id) return;
    try {
      setIsLoading(true);
      setError(null);
      const res = await assessmentService.getById(id);
      setAssessment(res.data);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to load assessment details.');
      }
    } finally {
      setIsLoading(false);
    }
  }, [id]);

  useEffect(() => {
    loadAssessment();
  }, [loadAssessment]);

  const handleUpdateAssessment = async (_courseId: number | string, data: AssessmentPayload) => {
    if (!id) return;
    const res = await assessmentService.update(id, data);
    setAssessment(res.data);
    showNotification('Assessment updated successfully!');
  };

  const handleDeleteAssessment = async () => {
    if (!id || !assessment) return;
    if (!window.confirm(`Are you sure you want to delete "${assessment.title}"? This cannot be undone.`)) {
      return;
    }

    try {
      setIsDeletingAssessment(true);
      await assessmentService.delete(id);
      navigate('/assessments');
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      }
    } finally {
      setIsDeletingAssessment(false);
    }
  };

  const handleUploadPaper = async (formData: FormData) => {
    if (!id) return;
    const res = await questionPaperService.upload(id, formData);
    if (assessment) {
      setAssessment({
        ...assessment,
        questionPaper: res.data,
        question_paper: res.data,
      });
    }
    showNotification('Question paper uploaded successfully!');
  };

  const handleDownloadPaper = async () => {
    if (!id || !assessment) return;
    const paper = assessment.questionPaper || assessment.question_paper;
    if (!paper) return;

    try {
      setIsDownloadingPaper(true);
      await questionPaperService.download(id, paper.file_name);
    } catch (err: unknown) {
      if (err instanceof Error) setError(err.message);
    } finally {
      setIsDownloadingPaper(false);
    }
  };

  const handleDeletePaper = async () => {
    if (!id) return;
    if (!window.confirm('Are you sure you want to remove the uploaded question paper?')) return;

    try {
      setIsDeletingPaper(true);
      await questionPaperService.delete(id);
      if (assessment) {
        setAssessment({
          ...assessment,
          questionPaper: null,
          question_paper: null,
        });
      }
      showNotification('Question paper removed successfully!');
    } catch (err: unknown) {
      if (err instanceof Error) setError(err.message);
    } finally {
      setIsDeletingPaper(false);
    }
  };

  const formatFileSize = (bytes?: number) => {
    if (!bytes) return '0 B';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
  };

  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[400px] space-y-4">
        <Loader2 className="w-8 h-8 animate-spin text-[#111111] dark:text-white" />
        <p className="text-sm text-[#737373]">Loading assessment information...</p>
      </div>
    );
  }

  if (error || !assessment) {
    return (
      <div className="space-y-4">
        <Link to="/assessments" className="inline-flex items-center gap-2 text-xs font-semibold text-[#737373] hover:text-[#111111] dark:hover:text-white">
          <ArrowLeft className="w-4 h-4" /> Back to Assessments
        </Link>
        <div className="p-6 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 rounded-xl space-y-3">
          <div className="flex items-center gap-2 text-red-600 dark:text-red-400 font-bold">
            <AlertCircle className="w-5 h-5" />
            <span>Unable to load assessment</span>
          </div>
          <p className="text-sm text-red-700 dark:text-red-300">
            {error || 'Assessment not found or you do not have permission to view it.'}
          </p>
          <Button variant="outline" size="sm" onClick={() => loadAssessment()}>
            Try Again
          </Button>
        </div>
      </div>
    );
  }

  const course = assessment.course;
  const paper = assessment.questionPaper || assessment.question_paper;
  const questions = assessment.questions || [];
  const qCount = assessment.questions_count ?? questions.length;

  return (
    <div className="space-y-6">
      {/* Toast Notification */}
      {successMessage && (
        <div className="p-4 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-xl flex items-center gap-3 text-emerald-700 dark:text-emerald-300 text-sm font-medium animate-in fade-in slide-in-from-top-2">
          <CheckCircle2 className="w-5 h-5 shrink-0" />
          <span>{successMessage}</span>
        </div>
      )}

      {/* Breadcrumbs & Header Actions */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="space-y-1">
          <div className="flex items-center gap-2 text-xs text-[#737373]">
            <Link to="/assessments" className="hover:text-[#111111] dark:hover:text-white transition-colors">
              Assessments
            </Link>
            <span>/</span>
            {course && (
              <>
                <Link to={`/courses/${course.id}`} className="font-mono hover:text-[#111111] dark:hover:text-white transition-colors">
                  {course.course_code || course.code}
                </Link>
                <span>/</span>
              </>
            )}
            <span className="font-semibold text-[#111111] dark:text-white">{assessment.title}</span>
          </div>
          <h1 className="text-2xl font-bold text-[#111111] dark:text-white">{assessment.title}</h1>
        </div>

        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            leftIcon={<Edit className="w-3.5 h-3.5" />}
            onClick={() => setIsEditModalOpen(true)}
          >
            Edit
          </Button>
          <Button
            variant="outline"
            size="sm"
            className="text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30 border-red-200 dark:border-red-900/50"
            leftIcon={isDeletingAssessment ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Trash2 className="w-3.5 h-3.5" />}
            onClick={handleDeleteAssessment}
            disabled={isDeletingAssessment}
          >
            Delete
          </Button>
        </div>
      </div>

      {/* Assessment Overview Card */}
      <Card variant="default" className="p-6 space-y-6">
        <div className="flex flex-wrap items-center gap-3">
          {course && (
            <Badge variant="outline" className="font-mono font-bold text-sm bg-[#F7F7F5] dark:bg-[#2C2C2E]">
              {course.course_code || course.code} — {course.course_name || course.title}
            </Badge>
          )}
          <Badge variant="neutral" className="text-xs uppercase font-semibold">
            {assessment.type}
          </Badge>
          <Badge
            variant={assessment.status === 'completed' || assessment.status === 'Analyzed' ? 'default' : 'neutral'}
            className="text-xs capitalize"
          >
            Status: {assessment.status}
          </Badge>
        </div>

        {assessment.description && (
          <div className="space-y-1">
            <h3 className="text-xs font-bold uppercase tracking-wider text-[#737373]">Description / Scope</h3>
            <p className="text-sm text-[#262626] dark:text-[#E5E5E5] leading-relaxed whitespace-pre-line">
              {assessment.description}
            </p>
          </div>
        )}

        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-4 border-t border-[#E5E5E5] dark:border-[#2C2C2E] text-center">
          <div className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl">
            <span className="text-[10px] uppercase font-semibold text-[#737373] block">Total Marks</span>
            <span className="text-lg font-bold text-[#111111] dark:text-white flex items-center justify-center gap-1.5 mt-1">
              <Award className="w-4 h-4 text-[#737373]" /> {assessment.total_marks || assessment.totalMarks}
            </span>
          </div>

          <div className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl">
            <span className="text-[10px] uppercase font-semibold text-[#737373] block">Duration</span>
            <span className="text-lg font-bold text-[#111111] dark:text-white flex items-center justify-center gap-1.5 mt-1">
              <Clock className="w-4 h-4 text-[#737373]" /> {assessment.duration_minutes || 90}m
            </span>
          </div>

          <div className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl">
            <span className="text-[10px] uppercase font-semibold text-[#737373] block">Assessment Date</span>
            <span className="text-sm font-bold text-[#111111] dark:text-white flex items-center justify-center gap-1.5 mt-2">
              <Calendar className="w-3.5 h-3.5 text-[#737373]" />
              {assessment.assessment_date ? assessment.assessment_date.split('T')[0] : 'TBD'}
            </span>
          </div>

          <div className="p-3 bg-[#F7F7F5] dark:bg-[#2C2C2E] rounded-xl">
            <span className="text-[10px] uppercase font-semibold text-[#737373] block">Questions Count</span>
            <span className="text-lg font-bold text-[#111111] dark:text-white flex items-center justify-center gap-1.5 mt-1">
              <Layers className="w-4 h-4 text-[#737373]" /> {qCount}
            </span>
          </div>
        </div>
      </Card>

      {/* Two Column Layout: Question Paper & Questions / Question Bank */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Section 1: Question Paper File */}
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <div className="w-7 h-7 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] flex items-center justify-center text-[#111111] dark:text-white">
                <FileText className="w-4 h-4" />
              </div>
              <h2 className="text-base font-bold text-[#111111] dark:text-white">Question Paper Document</h2>
            </div>
            {paper && (
              <Button
                variant="outline"
                size="sm"
                leftIcon={<UploadCloud className="w-3.5 h-3.5" />}
                onClick={() => setIsPaperUploadOpen(true)}
              >
                Replace Paper
              </Button>
            )}
          </div>

          {paper ? (
            <Card variant="default" className="p-5 space-y-4">
              <div className="flex items-start justify-between gap-3">
                <div className="flex items-start gap-3">
                  <div className="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200 dark:border-emerald-800 flex items-center justify-center text-emerald-600 dark:text-emerald-400 shrink-0">
                    <FileText className="w-5 h-5" />
                  </div>
                  <div>
                    <h4 className="text-sm font-bold text-[#111111] dark:text-white">{paper.file_name}</h4>
                    <p className="text-xs text-[#737373] font-mono mt-0.5">
                      {paper.file_type?.toUpperCase() || 'DOCUMENT'} • {formatFileSize(paper.file_size)}
                    </p>
                    <p className="text-[11px] text-[#737373] mt-1">
                      Uploaded on {paper.created_at ? new Date(paper.created_at).toLocaleDateString() : 'recently'}
                    </p>
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    leftIcon={isDownloadingPaper ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Download className="w-3.5 h-3.5" />}
                    onClick={handleDownloadPaper}
                    disabled={isDownloadingPaper}
                  >
                    Download
                  </Button>
                  <button
                    type="button"
                    onClick={handleDeletePaper}
                    disabled={isDeletingPaper}
                    className="p-2 rounded-lg text-red-500 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950/30 transition-colors"
                    title="Delete question paper"
                  >
                    {isDeletingPaper ? (
                      <Loader2 className="w-4 h-4 animate-spin" />
                    ) : (
                      <Trash2 className="w-4 h-4" />
                    )}
                  </button>
                </div>
              </div>
            </Card>
          ) : (
            <Card variant="default" className="p-8 text-center space-y-3">
              <UploadCloud className="w-8 h-8 text-[#737373] mx-auto opacity-50" />
              <div className="space-y-1">
                <p className="text-sm font-bold text-[#111111] dark:text-white">No Question Paper Attached</p>
                <p className="text-xs text-[#737373]">
                  Upload the official question paper (PDF, DOCX, TXT) for this assessment.
                </p>
              </div>
              <Button
                variant="primary"
                size="sm"
                leftIcon={<UploadCloud className="w-3.5 h-3.5" />}
                onClick={() => setIsPaperUploadOpen(true)}
              >
                Upload Question Paper
              </Button>
            </Card>
          )}
        </div>

        {/* Section 2: Questions & Question Bank Context */}
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <div className="w-7 h-7 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] flex items-center justify-center text-[#111111] dark:text-white">
                <HelpCircle className="w-4 h-4" />
              </div>
              <h2 className="text-base font-bold text-[#111111] dark:text-white">Questions & Question Bank</h2>
            </div>
            {course && (
              <Link to={`/courses/${course.id}/question-bank`}>
                <Button variant="outline" size="sm" leftIcon={<ExternalLink className="w-3.5 h-3.5" />}>
                  Course Question Bank
                </Button>
              </Link>
            )}
          </div>

          {questions.length === 0 ? (
            <Card variant="default" className="p-8 text-center space-y-3">
              <Layers className="w-8 h-8 text-[#737373] mx-auto opacity-50" />
              <div className="space-y-1">
                <p className="text-sm font-bold text-[#111111] dark:text-white">Question Paper Overview</p>
                <p className="text-xs text-[#737373]">
                  Manage course questions in the Question Bank or review assessment history.
                </p>
              </div>
              {course && (
                <Link to={`/courses/${course.id}/question-bank`}>
                  <Button variant="outline" size="sm" leftIcon={<HelpCircle className="w-3.5 h-3.5" />}>
                    Open Question Bank
                  </Button>
                </Link>
              )}
            </Card>
          ) : (
            <div className="space-y-3">
              {questions.map((q, idx) => (
                <Card key={q.id || idx} variant="default" className="p-4 space-y-2">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-bold text-[#111111] dark:text-white font-mono">
                      Q{q.question_number || q.questionNumber || idx + 1}
                    </span>
                    <div className="flex items-center gap-2">
                      {q.difficulty_level && (
                        <Badge variant="neutral" className="text-[10px] capitalize">
                          {q.difficulty_level}
                        </Badge>
                      )}
                      <span className="text-xs font-mono font-bold text-[#111111] dark:text-white">
                        {q.marks || q.maxMarks} Marks
                      </span>
                    </div>
                  </div>
                  <p className="text-xs text-[#262626] dark:text-[#E5E5E5] leading-relaxed">
                    {q.question_text || q.text}
                  </p>
                </Card>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Edit Modal */}
      <AssessmentModal
        isOpen={isEditModalOpen}
        onClose={() => setIsEditModalOpen(false)}
        onSubmit={handleUpdateAssessment}
        assessment={assessment}
      />

      {/* Upload Question Paper Modal */}
      <QuestionPaperUploadModal
        isOpen={isPaperUploadOpen}
        onClose={() => setIsPaperUploadOpen(false)}
        onSubmit={handleUploadPaper}
        currentFileName={paper?.file_name}
      />
    </div>
  );
};
