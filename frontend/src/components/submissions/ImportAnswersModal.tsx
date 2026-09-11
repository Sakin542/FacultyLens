import React, { useEffect, useRef, useState } from 'react';
import { AlertCircle, FileSpreadsheet, UploadCloud, X } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { studentSubmissionService } from '@/services/studentSubmissionService';
import { ApiError } from '@/services/api';

interface ImportAnswersModalProps {
  isOpen: boolean;
  onClose: () => void;
  assessmentId: number | string;
  onImported: (result: { submissions_created: number; answers_created: number }) => void;
}

/**
 * CSV import of text answers: student_identifier, question_number, answer_text.
 * Students must already be registered; the server validates every row before writing anything.
 */
export const ImportAnswersModal: React.FC<ImportAnswersModalProps> = ({ isOpen, onClose, assessmentId, onImported }) => {
  const inputRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [rowErrors, setRowErrors] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (isOpen) { setFile(null); setError(null); setRowErrors([]); }
  }, [isOpen]);

  if (!isOpen) return null;

  const pick = (f?: File) => {
    if (!f) return;
    const ext = f.name.split('.').pop()?.toLowerCase();
    if (ext !== 'csv' && ext !== 'txt') { setError('Please select a .csv file.'); setFile(null); return; }
    if (f.size > 5 * 1024 * 1024) { setError('The CSV must not exceed 5MB.'); setFile(null); return; }
    setError(null); setRowErrors([]); setFile(f);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!file) { setError('Select a CSV file first.'); return; }
    try {
      setBusy(true); setError(null); setRowErrors([]);
      const res = await studentSubmissionService.importCsv(assessmentId, file);
      onImported(res.data);
      onClose();
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.message);
        const details = (err.data as { errors?: unknown }).errors;
        if (Array.isArray(details)) setRowErrors(details.map(String));
      } else {
        setError(err instanceof Error ? err.message : 'Import failed.');
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
      <div className="w-full max-w-lg bg-white dark:bg-[#1C1C1E] rounded-2xl shadow-2xl border border-sage-200 dark:border-[#2C2C2E] overflow-hidden" role="dialog" aria-modal="true" aria-labelledby="import-answers-title">
        <div className="flex items-center justify-between p-4 border-b border-sage-200 dark:border-[#2C2C2E]">
          <h3 id="import-answers-title" className="text-sm font-bold text-sage-800 dark:text-white flex items-center gap-2">
            <FileSpreadsheet className="w-4 h-4" /> Import Student Answers (CSV)
          </h3>
          <button type="button" onClick={onClose} disabled={busy} aria-label="Close" className="p-1 rounded-lg text-sage-500 hover:text-sage-800 dark:hover:text-white">
            <X className="w-4 h-4" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-5 space-y-4 text-xs">
          <div className="p-3 rounded-xl bg-sage-100 dark:bg-[#2C2C2E] border border-sage-200 dark:border-[#3A3A3C] space-y-1">
            <p className="font-semibold text-sage-800 dark:text-white">Required columns</p>
            <pre className="font-mono text-[11px] text-sage-700 dark:text-sage-200 whitespace-pre-wrap">student_identifier,question_number,answer_text{'\n'}STU001,1,"Normalization is..."</pre>
            <p className="text-[11px] text-sage-500">Students must already be registered. Rows are validated together; nothing is imported if any row is invalid.</p>
          </div>

          <div
            role="button"
            tabIndex={0}
            onClick={() => !busy && inputRef.current?.click()}
            onKeyDown={(e) => { if (e.key === 'Enter') inputRef.current?.click(); }}
            onDragOver={(e) => e.preventDefault()}
            onDrop={(e) => { e.preventDefault(); pick(e.dataTransfer.files?.[0]); }}
            className={`flex flex-col items-center justify-center p-6 rounded-xl border-2 border-dashed cursor-pointer ${file ? 'border-sage-700 dark:border-white bg-sage-100 dark:bg-[#2C2C2E]' : 'border-sage-200 dark:border-[#3A3A3C] hover:border-sage-300'}`}
          >
            <input ref={inputRef} type="file" accept=".csv,text/csv" className="hidden" onChange={(e) => pick(e.target.files?.[0])} disabled={busy} data-testid="csv-input" />
            <UploadCloud className="w-5 h-5 text-sage-500 mb-1" />
            {file ? (
              <p className="font-medium text-sage-800 dark:text-white">{file.name} · {(file.size / 1024).toFixed(0)} KB</p>
            ) : (
              <p className="text-sage-700 dark:text-sage-200">Click or drop a CSV file (max 5 MB)</p>
            )}
          </div>

          {error && (
            <div className="p-3 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900 rounded-xl text-red-700 dark:text-red-300 space-y-1" role="alert">
              <div className="flex items-center gap-2"><AlertCircle className="w-4 h-4 shrink-0" /> <span>{error}</span></div>
              {rowErrors.length > 0 && (
                <ul className="list-disc pl-6 text-[11px] max-h-40 overflow-y-auto space-y-0.5">
                  {rowErrors.map((r, i) => <li key={i}>{r}</li>)}
                </ul>
              )}
            </div>
          )}

          <div className="flex items-center justify-end gap-2 pt-2 border-t border-sage-200 dark:border-[#2C2C2E]">
            <Button type="button" variant="ghost" size="sm" onClick={onClose} disabled={busy}>Cancel</Button>
            <Button type="submit" variant="primary" size="sm" isLoading={busy} disabled={!file || busy} leftIcon={<UploadCloud className="w-3.5 h-3.5" />}>
              Import Answers
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
};
