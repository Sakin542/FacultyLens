import React, { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { AlertCircle, ArrowLeft, Award, Calendar, CheckCircle2, Loader2, Trash2, User } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Card } from '@/components/common/Card';
import { Badge } from '@/components/common/Badge';
import { studentSubmissionService } from '@/services/studentSubmissionService';
import { ApiError } from '@/services/api';
import { AnswerPayload, StudentAnswer, StudentSubmissionDetail, SubmissionStatus } from '@/types/submission';
import { GradingStatusBadge, SubmissionStatusBadge, formatSubmissionStatus } from '@/components/submissions/SubmissionStatusBadge';
import { StudentAnswerList } from '@/components/submissions/StudentAnswerList';
import { formatDate } from '@/components/submissions/SubmissionTable';
import { StudentPerformanceCard } from '@/components/performance/StudentPerformanceCard';

function detailErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.status === 403) return 'You are not authorized to view this submission.';
    if (err.status === 404) return 'The submission could not be found.';
    if (err.status === 401) return 'Your session has expired. Please sign in again.';
  }
  return err instanceof Error ? err.message : 'Failed to load the submission.';
}

/**
 * STEP 26/27: One student's submission with every question, its answer, and AI grading assistance.
 * Faculty final marks are separate from AI suggested marks and always require faculty action.
 */
export const SubmissionDetails: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();

  const [submission, setSubmission] = useState<StudentSubmissionDetail | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [isChangingStatus, setIsChangingStatus] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  const flash = (msg: string) => {
    setNotice(msg);
    setTimeout(() => setNotice(null), 4000);
  };

  const load = useCallback(async () => {
    if (!id) return;
    try {
      setIsLoading(true);
      setError(null);
      const res = await studentSubmissionService.getSubmission(id);
      setSubmission(res.data);
    } catch (err) {
      setError(detailErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
  }, [id]);

  useEffect(() => { load(); }, [load]);

  const refresh = async () => {
    if (!id) return;
    const res = await studentSubmissionService.getSubmission(id);
    setSubmission(res.data);
  };

  const handleAdd = async (questionId: number, data: AnswerPayload, file: File | null) => {
    if (!submission) return;
    if (file) await studentSubmissionService.uploadAnswer(submission.id, questionId, file, data);
    else await studentSubmissionService.addAnswer(submission.id, { ...data, question_id: questionId });
    await refresh();
    flash('Answer saved.');
  };

  const handleUpdate = async (answer: StudentAnswer, data: AnswerPayload, file: File | null) => {
    await studentSubmissionService.updateAnswer(answer.id, data, file);
    await refresh();
    flash('Answer updated.');
  };

  const handleDeleteAnswer = async (answer: StudentAnswer) => {
    await studentSubmissionService.deleteAnswer(answer.id);
    await refresh();
    flash('Answer deleted.');
  };

  const handleDownload = async (answer: StudentAnswer) => {
    try {
      await studentSubmissionService.downloadAnswerFile(answer.id, answer.answer_file_name || 'answer');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Download failed.');
    }
  };

  const handleStatus = async (status: SubmissionStatus) => {
    if (!submission) return;
    try {
      setIsChangingStatus(true);
      await studentSubmissionService.updateSubmissionStatus(submission.id, status);
      await refresh();
      flash(`Submission marked as ${formatSubmissionStatus(status)}.`);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Status could not be changed.');
    } finally {
      setIsChangingStatus(false);
    }
  };

  const handleDeleteSubmission = async () => {
    if (!submission) return;
    if (!window.confirm('Delete this submission and all of its answers? This cannot be undone.')) return;
    try {
      setIsDeleting(true);
      await studentSubmissionService.deleteSubmission(submission.id);
      navigate(`/assessments/${submission.assessment_id}/submissions`);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The submission could not be deleted.');
      setIsDeleting(false);
    }
  };

  if (isLoading) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[400px] space-y-4" role="status">
        <Loader2 className="w-8 h-8 animate-spin text-sage-800 dark:text-white" />
        <p className="text-sm text-sage-500">Loading submission…</p>
      </div>
    );
  }

  if (error && !submission) {
    return (
      <div className="space-y-4">
        <Link to="/assessments" className="inline-flex items-center gap-2 text-xs font-semibold text-sage-500 hover:text-sage-800 dark:hover:text-white">
          <ArrowLeft className="w-4 h-4" /> Back to Assessments
        </Link>
        <div className="p-6 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 rounded-xl space-y-3" role="alert">
          <div className="flex items-center gap-2 text-red-600 dark:text-red-400 font-bold"><AlertCircle className="w-5 h-5" /> Unable to load submission</div>
          <p className="text-sm text-red-700 dark:text-red-300">{error}</p>
          <Button variant="outline" size="sm" onClick={load}>Try Again</Button>
        </div>
      </div>
    );
  }

  if (!submission) return null;

  const answered = submission.questions.filter((q) => q.answer).length;
  const isReadOnly = submission.status === 'RETURNED';

  return (
    <div className="space-y-6" data-testid="submission-details-page">
      {notice && (
        <div className="p-4 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-xl flex items-center gap-3 text-emerald-700 dark:text-emerald-300 text-sm font-medium">
          <CheckCircle2 className="w-5 h-5 shrink-0" /> <span>{notice}</span>
        </div>
      )}
      {error && (
        <div className="p-3 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 rounded-xl flex items-center gap-2 text-red-700 dark:text-red-300 text-xs" role="alert">
          <AlertCircle className="w-4 h-4 shrink-0" /> {error}
          <button type="button" className="ml-auto underline" onClick={() => setError(null)}>Dismiss</button>
        </div>
      )}

      {/* Breadcrumb + actions */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="space-y-1">
          <div className="flex items-center gap-2 text-xs text-sage-500 flex-wrap">
            <Link to="/assessments" className="hover:text-sage-800 dark:hover:text-white">Assessments</Link>
            <span>/</span>
            <Link to={`/assessments/${submission.assessment_id}`} className="hover:text-sage-800 dark:hover:text-white">{submission.assessment.title}</Link>
            <span>/</span>
            <Link to={`/assessments/${submission.assessment_id}/submissions`} className="hover:text-sage-800 dark:hover:text-white">Submissions</Link>
            <span>/</span>
            <span className="font-semibold text-sage-800 dark:text-white font-mono">{submission.submission_identifier || `#${submission.id}`}</span>
          </div>
          <h1 className="text-2xl font-bold text-sage-800 dark:text-white">Student Submission</h1>
        </div>
        <div className="flex items-center gap-2">
          <Link to={`/assessments/${submission.assessment_id}/submissions`}>
            <Button variant="ghost" size="sm" leftIcon={<ArrowLeft className="w-3.5 h-3.5" />}>Back to submissions</Button>
          </Link>
          <Button
            variant="outline"
            size="sm"
            className="text-red-600 border-red-200 dark:border-red-900/50 hover:bg-red-50 dark:hover:bg-red-950/30"
            leftIcon={<Trash2 className="w-3.5 h-3.5" />}
            onClick={handleDeleteSubmission}
            isLoading={isDeleting}
          >
            Delete
          </Button>
        </div>
      </div>

      {/* Overview */}
      <Card variant="default" className="p-6 space-y-5">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-start gap-3">
            <div className="w-10 h-10 rounded-xl bg-sage-100 dark:bg-[#2C2C2E] border border-sage-200 dark:border-[#3A3A3C] flex items-center justify-center text-sage-800 dark:text-white">
              <User className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-base font-bold text-sage-800 dark:text-white" data-testid="student-name">{submission.student.name}</h2>
              <p className="text-xs font-mono text-sage-500">{submission.student.student_identifier}{submission.student.section ? ` · Section ${submission.student.section}` : ''}{submission.student.program ? ` · ${submission.student.program}` : ''}</p>
              <p className="text-xs text-sage-500 mt-1">
                {submission.assessment.course?.course_code} — {submission.assessment.course?.course_name} · {submission.assessment.title}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2 flex-wrap">
            <SubmissionStatusBadge status={submission.status} />
            <GradingStatusBadge status={submission.grading_status} />
          </div>
        </div>

        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-4 border-t border-sage-200 dark:border-[#2C2C2E] text-center">
          <div className="p-3 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl">
            <span className="text-[10px] uppercase font-semibold text-sage-500 block">Submitted</span>
            <span className="text-sm font-bold text-sage-800 dark:text-white flex items-center justify-center gap-1.5 mt-2">
              <Calendar className="w-3.5 h-3.5 text-sage-500" /> {formatDate(submission.submitted_at)}
            </span>
          </div>
          <div className="p-3 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl">
            <span className="text-[10px] uppercase font-semibold text-sage-500 block">Answers</span>
            <span className="text-lg font-bold text-sage-800 dark:text-white block mt-1">{answered} / {submission.questions.length}</span>
          </div>
          <div className="p-3 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl">
            <span className="text-[10px] uppercase font-semibold text-sage-500 block">Marks</span>
            <span className="text-lg font-bold text-sage-800 dark:text-white flex items-center justify-center gap-1.5 mt-1" data-testid="submission-marks">
              <Award className="w-4 h-4 text-sage-500" />
              {submission.awarded_marks !== null && submission.awarded_marks !== undefined ? submission.awarded_marks : 'Not graded'}
              <span className="text-xs text-sage-500 font-normal">/ {submission.total_marks ?? submission.assessment.total_marks ?? '—'}</span>
            </span>
          </div>
          <div className="p-3 bg-sage-100 dark:bg-[#2C2C2E] rounded-xl">
            <span className="text-[10px] uppercase font-semibold text-sage-500 block">Grading</span>
            <span className="text-sm font-bold text-sage-800 dark:text-white block mt-2">{submission.grading_status.replace(/_/g, ' ')}</span>
          </div>
        </div>

        {submission.allowed_transitions.length > 0 && (
          <div className="flex flex-wrap items-center gap-2 pt-4 border-t border-sage-200 dark:border-[#2C2C2E]">
            <span className="text-[10px] uppercase tracking-wider text-sage-500 mr-1">Move to</span>
            {submission.allowed_transitions.map((s) => (
              <Button key={s} variant="outline" size="sm" onClick={() => handleStatus(s)} disabled={isChangingStatus} data-testid={`transition-${s}`}>
                {formatSubmissionStatus(s)}
              </Button>
            ))}
          </div>
        )}
        {isReadOnly && (
          <Badge variant="neutral" className="text-[10px]">Returned submissions are read-only</Badge>
        )}
      </Card>

      {/* Answers */}
      <div className="space-y-3">
        <h2 className="text-base font-bold text-sage-800 dark:text-white">Answers by Question</h2>
        <StudentAnswerList
          questions={submission.questions}
          readOnly={isReadOnly}
          onAdd={handleAdd}
          onUpdate={handleUpdate}
          onDelete={handleDeleteAnswer}
          onDownload={handleDownload}
          onViewRubric={() => navigate(`/assessments/${submission.assessment_id}`)}
          onGradeSaved={async () => { await refresh(); flash('Final marks saved.'); }}
        />
        <p className="text-[11px] text-sage-500 italic">
          AI grading assistance is a decision-support feature. Faculty review and final judgment are required. Student records are private academic data.
        </p>
      </div>

      {/* STEP 30: authorized per-student performance view (finalized marks only) */}
      <StudentPerformanceCard key={`${submission.id}-${submission.updated_at ?? ''}`} studentId={submission.student_id} assessmentId={submission.assessment_id} />
    </div>
  );
};
