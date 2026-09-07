import React, { useState, useRef } from 'react';
import { Button } from '@/components/common/Button';
import { X, UploadCloud, FileText, AlertCircle, Loader2, CheckCircle2 } from 'lucide-react';
import { DocumentProcessingType } from '@/types';

interface DocumentUploadModalProps {
  isOpen: boolean;
  onClose: () => void;
  courseId: number | string;
  assessmentId?: number | string | null;
  onUploadSuccess: () => void;
  onSubmitUpload: (formData: FormData) => Promise<void>;
}

export const DocumentUploadModal: React.FC<DocumentUploadModalProps> = ({
  isOpen,
  onClose,
  courseId,
  assessmentId,
  onUploadSuccess,
  onSubmitUpload,
}) => {
  const [documentType, setDocumentType] = useState<DocumentProcessingType>('syllabus');
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [uploadProgress, setUploadProgress] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  if (!isOpen) return null;

  const validateAndSetFile = (selectedFile: File) => {
    // Check file size (max 10MB)
    if (selectedFile.size > 10 * 1024 * 1024) {
      setError('File size must not exceed 10MB.');
      return;
    }

    // Check extension
    const ext = selectedFile.name.split('.').pop()?.toLowerCase();
    if (!ext || !['pdf', 'docx', 'txt'].includes(ext)) {
      setError('Unsupported file type. Please upload a PDF (.pdf), Word Document (.docx), or Text file (.txt).');
      return;
    }

    setFile(selectedFile);
    setError(null);
  };

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files[0]) {
      validateAndSetFile(e.target.files[0]);
    }
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      validateAndSetFile(e.dataTransfer.files[0]);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!file) {
      setError('Please select a document file to upload.');
      return;
    }

    const formData = new FormData();
    formData.append('course_id', String(courseId));
    if (assessmentId) {
      formData.append('assessment_id', String(assessmentId));
    }
    formData.append('document_type', documentType);
    formData.append('file', file);

    try {
      setIsSubmitting(true);
      setUploadProgress('Uploading and extracting text...');
      await onSubmitUpload(formData);
      onUploadSuccess();
      onClose();
      // Reset state
      setFile(null);
      setDocumentType('syllabus');
      setUploadProgress(null);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to upload and process document.');
      }
    } finally {
      setIsSubmitting(false);
      setUploadProgress(null);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4 overflow-y-auto">
      <div className="relative w-full max-w-lg rounded-xl bg-white shadow-xl dark:bg-slate-800 border border-slate-200 dark:border-slate-700 overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between border-b border-slate-100 dark:border-slate-700/60 px-6 py-4">
          <div>
            <h3 className="text-lg font-semibold text-slate-900 dark:text-white">
              Upload Academic Document
            </h3>
            <p className="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
              Extract text from syllabus, question papers, or exam archives
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            disabled={isSubmitting}
            className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-700 dark:hover:text-slate-300 transition-colors"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Body Form */}
        <form onSubmit={handleSubmit} className="p-6 space-y-5">
          {error && (
            <div className="flex items-start gap-2.5 rounded-lg bg-red-50 p-3.5 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-400 border border-red-200 dark:border-red-900/50">
              <AlertCircle className="h-5 w-5 shrink-0 text-red-500 mt-0.5" />
              <div>{error}</div>
            </div>
          )}

          {/* Document Type Selector */}
          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5">
              Document Category <span className="text-red-500">*</span>
            </label>
            <select
              value={documentType}
              onChange={(e) => setDocumentType(e.target.value as DocumentProcessingType)}
              disabled={isSubmitting}
              className="w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-700/50 dark:text-white"
            >
              <option value="syllabus">Course Syllabus / Curriculum</option>
              <option value="question_paper">Question Paper / Assessment Draft</option>
              <option value="assignment">Assignment / Project Prompt</option>
              <option value="previous_exam">Previous Exam / Past Archive</option>
              <option value="other">Other Reference Document</option>
            </select>
          </div>

          {/* Drag & Drop File Upload Area */}
          <div>
            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-700 dark:text-slate-300 mb-1.5">
              Document File (.pdf, .docx, .txt) <span className="text-red-500">*</span>
            </label>

            <div
              onDragOver={(e) => e.preventDefault()}
              onDrop={handleDrop}
              onClick={() => fileInputRef.current?.click()}
              className={`relative flex flex-col items-center justify-center rounded-xl border-2 border-dashed p-6 text-center cursor-pointer transition-colors ${
                file
                  ? 'border-indigo-500 bg-indigo-50/40 dark:bg-indigo-950/20 dark:border-indigo-500/60'
                  : 'border-slate-300 hover:border-indigo-400 bg-slate-50/50 hover:bg-indigo-50/20 dark:border-slate-600 dark:bg-slate-900/30'
              }`}
            >
              <input
                ref={fileInputRef}
                type="file"
                accept=".pdf,.docx,.txt"
                onChange={handleFileChange}
                className="hidden"
                disabled={isSubmitting}
              />

              {file ? (
                <div className="flex flex-col items-center space-y-2">
                  <div className="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-100 text-indigo-600 dark:bg-indigo-900/50 dark:text-indigo-400">
                    <CheckCircle2 className="h-6 w-6" />
                  </div>
                  <div className="text-sm font-medium text-slate-900 dark:text-white max-w-xs truncate">
                    {file.name}
                  </div>
                  <div className="text-xs text-slate-500 dark:text-slate-400">
                    {(file.size / (1024 * 1024)).toFixed(2)} MB • Click or drag to replace
                  </div>
                </div>
              ) : (
                <div className="flex flex-col items-center space-y-2">
                  <div className="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                    <UploadCloud className="h-6 w-6" />
                  </div>
                  <div className="text-sm font-medium text-slate-700 dark:text-slate-200">
                    Click to browse or drag and drop file here
                  </div>
                  <div className="text-xs text-slate-400 dark:text-slate-500">
                    Supports PDF, DOCX, TXT up to 10 MB
                  </div>
                </div>
              )}
            </div>
          </div>

          {/* Processing Information Note */}
          <div className="rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-100 dark:border-blue-900/40 p-3 text-xs text-blue-800 dark:text-blue-300">
            <strong>Automatic Text Extraction:</strong> Uploaded documents are automatically parsed, normalized, and cleaned to extract syllabus units, assessment questions, and academic content.
          </div>

          {/* Footer Actions */}
          <div className="flex items-center justify-end gap-3 pt-2 border-t border-slate-100 dark:border-slate-700/60">
            <Button
              type="button"
              variant="outline"
              onClick={onClose}
              disabled={isSubmitting}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              variant="primary"
              disabled={isSubmitting || !file}
              className="min-w-[140px]"
            >
              {isSubmitting ? (
                <span className="flex items-center gap-2">
                  <Loader2 className="h-4 w-4 animate-spin" />
                  {uploadProgress || 'Processing...'}
                </span>
              ) : (
                <span className="flex items-center gap-2">
                  <FileText className="h-4 w-4" />
                  Upload & Extract
                </span>
              )}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
};

