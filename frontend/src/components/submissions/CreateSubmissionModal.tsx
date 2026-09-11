import React, { useEffect, useState } from 'react';
import { AlertCircle, Loader2, UserPlus, X } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { studentService, studentSubmissionService } from '@/services/studentSubmissionService';
import { CreateSubmissionPayload, Student, StudentSubmission } from '@/types/submission';

interface CreateSubmissionModalProps {
  isOpen: boolean;
  onClose: () => void;
  assessmentId: number | string;
  onCreated: (submission: StudentSubmission) => void;
}

const selectClass =
  'w-full rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] px-3 py-2 text-xs text-sage-800 dark:text-white focus:outline-none focus:ring-2 focus:ring-sage-600 dark:focus:ring-white';

/**
 * Create a student submission. Students can be picked from the faculty's registered list
 * or registered inline (identifier + name only; no login account is created).
 */
export const CreateSubmissionModal: React.FC<CreateSubmissionModalProps> = ({ isOpen, onClose, assessmentId, onCreated }) => {
  const [students, setStudents] = useState<Student[]>([]);
  const [loadingStudents, setLoadingStudents] = useState(false);
  const [mode, setMode] = useState<'existing' | 'new'>('existing');
  const [studentId, setStudentId] = useState<string>('');
  const [newIdentifier, setNewIdentifier] = useState('');
  const [newName, setNewName] = useState('');
  const [newSection, setNewSection] = useState('');
  const [submissionIdentifier, setSubmissionIdentifier] = useState('');
  const [submittedAt, setSubmittedAt] = useState('');
  const [status, setStatus] = useState<'DRAFT' | 'SUBMITTED'>('SUBMITTED');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!isOpen) return;
    setError(null);
    setSubmissionIdentifier('');
    setSubmittedAt('');
    setStatus('SUBMITTED');
    setNewIdentifier('');
    setNewName('');
    setNewSection('');
    setLoadingStudents(true);
    studentService
      .getAll({ per_page: 200 })
      .then((res) => {
        setStudents(res.data);
        setMode(res.data.length > 0 ? 'existing' : 'new');
        setStudentId(res.data[0] ? String(res.data[0].id) : '');
      })
      .catch((err) => setError(err instanceof Error ? err.message : 'Could not load students.'))
      .finally(() => setLoadingStudents(false));
  }, [isOpen]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && isOpen && !busy) onClose(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [isOpen, busy, onClose]);

  if (!isOpen) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    let resolvedStudentId: number | null = studentId ? Number(studentId) : null;
    try {
      setBusy(true);
      if (mode === 'new') {
        if (!newIdentifier.trim() || !newName.trim()) {
          setError('Student identifier and name are required.');
          return;
        }
        const created = await studentService.create({
          student_identifier: newIdentifier.trim(),
          name: newName.trim(),
          section: newSection.trim() || null,
        });
        resolvedStudentId = created.data.id;
      }
      if (!resolvedStudentId) {
        setError('Select or register a student.');
        return;
      }
      const payload: CreateSubmissionPayload = {
        student_id: resolvedStudentId,
        submission_identifier: submissionIdentifier.trim() || null,
        submitted_at: submittedAt ? new Date(submittedAt).toISOString() : null,
        status,
      };
      const res = await studentSubmissionService.createSubmission(assessmentId, payload);
      onCreated(res.data);
      onClose();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The submission could not be created.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
      <div className="w-full max-w-lg bg-white dark:bg-[#1C1C1E] rounded-2xl shadow-2xl border border-sage-200 dark:border-[#2C2C2E] overflow-hidden" role="dialog" aria-modal="true" aria-labelledby="create-submission-title">
        <div className="flex items-center justify-between p-4 border-b border-sage-200 dark:border-[#2C2C2E]">
          <h3 id="create-submission-title" className="text-sm font-bold text-sage-800 dark:text-white flex items-center gap-2">
            <UserPlus className="w-4 h-4" /> Add Student Submission
          </h3>
          <button type="button" onClick={onClose} disabled={busy} aria-label="Close" className="p-1 rounded-lg text-sage-500 hover:text-sage-800 dark:hover:text-white">
            <X className="w-4 h-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-5 space-y-4 text-xs" noValidate>
          <div className="flex items-center gap-2">
            <button type="button" onClick={() => setMode('existing')} className={`px-3 py-1.5 rounded-lg border text-xs ${mode === 'existing' ? 'border-sage-700 dark:border-white bg-sage-100 dark:bg-[#2C2C2E]' : 'border-sage-200 dark:border-[#3A3A3C]'}`} disabled={busy}>
              Registered student
            </button>
            <button type="button" onClick={() => setMode('new')} className={`px-3 py-1.5 rounded-lg border text-xs ${mode === 'new' ? 'border-sage-700 dark:border-white bg-sage-100 dark:bg-[#2C2C2E]' : 'border-sage-200 dark:border-[#3A3A3C]'}`} disabled={busy}>
              New student
            </button>
          </div>

          {mode === 'existing' ? (
            <div className="space-y-1.5">
              <label htmlFor="student-select" className="block text-[10px] font-medium uppercase tracking-wider text-sage-700 dark:text-sage-200">Student</label>
              {loadingStudents ? (
                <div className="flex items-center gap-2 text-sage-500"><Loader2 className="w-3.5 h-3.5 animate-spin" /> Loading students…</div>
              ) : students.length === 0 ? (
                <p className="text-sage-500">No students registered yet. Switch to “New student”.</p>
              ) : (
                <select id="student-select" value={studentId} onChange={(e) => setStudentId(e.target.value)} className={selectClass} disabled={busy} required>
                  {students.map((s) => (
                    <option key={s.id} value={s.id}>{s.student_identifier} — {s.name}{s.section ? ` (${s.section})` : ''}</option>
                  ))}
                </select>
              )}
            </div>
          ) : (
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <Input id="new-student-identifier" label="Student identifier" value={newIdentifier} onChange={(e) => setNewIdentifier(e.target.value)} placeholder="e.g. STU001" maxLength={64} required disabled={busy} />
              <Input id="new-student-name" label="Student name" value={newName} onChange={(e) => setNewName(e.target.value)} placeholder="Full name" maxLength={255} required disabled={busy} />
              <Input id="new-student-section" label="Section (optional)" value={newSection} onChange={(e) => setNewSection(e.target.value)} maxLength={20} disabled={busy} />
            </div>
          )}

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Input id="submission-identifier" label="Submission ID (optional)" value={submissionIdentifier} onChange={(e) => setSubmissionIdentifier(e.target.value)} placeholder="e.g. MIDTERM-001" maxLength={64} disabled={busy} />
            <Input id="submitted-at" label="Submitted at (optional)" type="datetime-local" value={submittedAt} onChange={(e) => setSubmittedAt(e.target.value)} disabled={busy} />
          </div>

          <div className="space-y-1.5">
            <label htmlFor="submission-status" className="block text-[10px] font-medium uppercase tracking-wider text-sage-700 dark:text-sage-200">Initial status</label>
            <select id="submission-status" value={status} onChange={(e) => setStatus(e.target.value as 'DRAFT' | 'SUBMITTED')} className={selectClass} disabled={busy}>
              <option value="SUBMITTED">Submitted</option>
              <option value="DRAFT">Draft</option>
            </select>
          </div>

          {error && (
            <div className="p-3 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 rounded-xl flex items-center gap-2 text-red-700 dark:text-red-300" role="alert">
              <AlertCircle className="w-4 h-4 shrink-0" /> <span>{error}</span>
            </div>
          )}

          <p className="text-[11px] text-sage-500 italic">Student records are private academic data visible only to you.</p>

          <div className="flex items-center justify-end gap-2 pt-2 border-t border-sage-200 dark:border-[#2C2C2E]">
            <Button type="button" variant="ghost" size="sm" onClick={onClose} disabled={busy}>Cancel</Button>
            <Button type="submit" variant="primary" size="sm" isLoading={busy} disabled={busy || (mode === 'existing' && !studentId)}>
              Create Submission
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
};
