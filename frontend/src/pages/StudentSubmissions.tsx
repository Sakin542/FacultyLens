import React, { useCallback, useEffect, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { AlertCircle, ArrowLeft, CheckCircle2, Loader2, UploadCloud, UserPlus, Users } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { assessmentService } from '@/services/assessmentService';
import { studentSubmissionService } from '@/services/studentSubmissionService';
import { ApiError } from '@/services/api';
import { Assessment } from '@/types';
import { GradingStatus, PaginationMeta, StudentSubmission, SubmissionFilterParams, SubmissionStatus } from '@/types/submission';
import { SubmissionTable } from '@/components/submissions/SubmissionTable';
import { SubmissionFilters } from '@/components/submissions/SubmissionFilters';
import { SubmissionEmptyState } from '@/components/submissions/SubmissionEmptyState';
import { CreateSubmissionModal } from '@/components/submissions/CreateSubmissionModal';
import { ImportAnswersModal } from '@/components/submissions/ImportAnswersModal';

const DEFAULT_META: PaginationMeta = { current_page: 1, last_page: 1, per_page: 20, total: 0 };

export function submissionErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.status === 403) return 'You are not authorized to view submissions for this assessment.';
    if (err.status === 404) return 'The assessment could not be found.';
    if (err.status === 401) return 'Your session has expired. Please sign in again.';
  }
  return err instanceof Error ? err.message : 'Failed to load submissions.';
}

/**
 * STEP 26: Paginated list of student submissions for an assessment.
 */
export const StudentSubmissions: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const [searchParams, setSearchParams] = useSearchParams();

  const [assessment, setAssessment] = useState<Assessment | null>(null);
  const [submissions, setSubmissions] = useState<StudentSubmission[]>([]);
  const [meta, setMeta] = useState<PaginationMeta>(DEFAULT_META);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [isImportOpen, setIsImportOpen] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const filters: SubmissionFilterParams = {
    page: Number(searchParams.get('page') || 1),
    status: (searchParams.get('status') || '') as SubmissionStatus | '',
    grading_status: (searchParams.get('grading_status') || '') as GradingStatus | '',
    search: searchParams.get('search') || '',
    submitted_from: searchParams.get('submitted_from') || '',
    submitted_to: searchParams.get('submitted_to') || '',
  };
  const hasFilters = Boolean(filters.status || filters.grading_status || filters.search || filters.submitted_from || filters.submitted_to);

  const updateFilters = (patch: Partial<SubmissionFilterParams>) => {
    const next = new URLSearchParams(searchParams);
    Object.entries({ ...filters, ...patch }).forEach(([k, v]) => {
      if (v === undefined || v === null || v === '' || (k === 'page' && v === 1)) next.delete(k);
      else next.set(k, String(v));
    });
    setSearchParams(next, { replace: true });
  };

  const flash = (msg: string) => {
    setNotice(msg);
    setTimeout(() => setNotice(null), 4000);
  };

  const load = useCallback(async () => {
    if (!id) return;
    try {
      setIsLoading(true);
      setError(null);
      const [a, s] = await Promise.all([
        assessment ? Promise.resolve(null) : assessmentService.getById(id),
        studentSubmissionService.getSubmissions(id, { ...filters, per_page: 20 }),
      ]);
      if (a) setAssessment(a.data);
      setSubmissions(s.data);
      setMeta(s.meta);
    } catch (err) {
      setError(submissionErrorMessage(err));
    } finally {
      setIsLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id, searchParams.toString()]);

  useEffect(() => { load(); }, [load]);

  const handleDelete = async (s: StudentSubmission) => {
    if (!window.confirm(`Delete the submission of ${s.student?.student_identifier ?? 'this student'} and all its answers? This cannot be undone.`)) return;
    try {
      setDeletingId(s.id);
      await studentSubmissionService.deleteSubmission(s.id);
      flash('Submission deleted.');
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The submission could not be deleted.');
    } finally {
      setDeletingId(null);
    }
  };

  return (
    <div className="space-y-6" data-testid="student-submissions-page">
      {notice && (
        <div className="p-4 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-xl flex items-center gap-3 text-emerald-700 dark:text-emerald-300 text-sm font-medium">
          <CheckCircle2 className="w-5 h-5 shrink-0" /> <span>{notice}</span>
        </div>
      )}

      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="space-y-1">
          <div className="flex items-center gap-2 text-xs text-[#737373]">
            <Link to="/assessments" className="hover:text-[#111111] dark:hover:text-white">Assessments</Link>
            <span>/</span>
            <Link to={`/assessments/${id}`} className="hover:text-[#111111] dark:hover:text-white font-semibold text-[#111111] dark:text-white">
              {assessment?.title ?? `Assessment #${id}`}
            </Link>
            <span>/</span>
            <span>Submissions</span>
          </div>
          <h1 className="text-2xl font-bold text-[#111111] dark:text-white flex items-center gap-2">
            <Users className="w-6 h-6" /> Student Submissions
          </h1>
          {assessment?.course && (
            <p className="text-xs text-[#737373] font-mono">{assessment.course.course_code} · {assessment.title}</p>
          )}
        </div>
        <div className="flex items-center gap-2">
          <Link to={`/assessments/${id}`}>
            <Button variant="ghost" size="sm" leftIcon={<ArrowLeft className="w-3.5 h-3.5" />}>Back</Button>
          </Link>
          <Button variant="outline" size="sm" leftIcon={<UploadCloud className="w-3.5 h-3.5" />} onClick={() => setIsImportOpen(true)}>Import Answers</Button>
          <Button variant="primary" size="sm" leftIcon={<UserPlus className="w-3.5 h-3.5" />} onClick={() => setIsCreateOpen(true)}>Add Submission</Button>
        </div>
      </div>

      <SubmissionFilters
        filters={filters}
        onChange={updateFilters}
        onClear={() => setSearchParams(new URLSearchParams(), { replace: true })}
        disabled={isLoading}
      />

      {error ? (
        <div className="p-6 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 rounded-xl space-y-3" role="alert">
          <div className="flex items-center gap-2 text-red-600 dark:text-red-400 font-bold"><AlertCircle className="w-5 h-5" /> Unable to load submissions</div>
          <p className="text-sm text-red-700 dark:text-red-300">{error}</p>
          <Button variant="outline" size="sm" onClick={load}>Try Again</Button>
        </div>
      ) : isLoading ? (
        <div className="flex flex-col items-center justify-center min-h-[300px] space-y-3" role="status">
          <Loader2 className="w-7 h-7 animate-spin text-[#111111] dark:text-white" />
          <p className="text-sm text-[#737373]">Loading submissions…</p>
        </div>
      ) : submissions.length === 0 ? (
        <SubmissionEmptyState
          hasFilters={hasFilters}
          onAdd={() => setIsCreateOpen(true)}
          onImport={() => setIsImportOpen(true)}
          onClearFilters={() => setSearchParams(new URLSearchParams(), { replace: true })}
        />
      ) : (
        <SubmissionTable
          submissions={submissions}
          meta={meta}
          onPageChange={(page) => updateFilters({ page })}
          onDelete={handleDelete}
          deletingId={deletingId}
        />
      )}

      {id && (
        <>
          <CreateSubmissionModal
            isOpen={isCreateOpen}
            onClose={() => setIsCreateOpen(false)}
            assessmentId={id}
            onCreated={(s) => { flash(`Submission created for ${s.student?.student_identifier ?? 'student'}.`); load(); }}
          />
          <ImportAnswersModal
            isOpen={isImportOpen}
            onClose={() => setIsImportOpen(false)}
            assessmentId={id}
            onImported={(r) => { flash(`${r.answers_created} answers imported across ${r.submissions_created} new submissions.`); load(); }}
          />
        </>
      )}
    </div>
  );
};
