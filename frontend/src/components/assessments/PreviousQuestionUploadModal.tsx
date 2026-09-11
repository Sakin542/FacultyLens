import React, { useState, useRef } from 'react';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { X, UploadCloud, FileText, AlertCircle, Loader2 } from 'lucide-react';

interface PreviousQuestionUploadModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (formData: FormData) => Promise<void>;
}

export const PreviousQuestionUploadModal: React.FC<PreviousQuestionUploadModalProps> = ({
  isOpen,
  onClose,
  onSubmit,
}) => {
  const [file, setFile] = useState<File | null>(null);
  const [questionText, setQuestionText] = useState('');
  const [sourceYear, setSourceYear] = useState(String(new Date().getFullYear() - 1));
  const [sourceAssessment, setSourceAssessment] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  if (!isOpen) return null;

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files[0]) {
      const selectedFile = e.target.files[0];
      if (selectedFile.size > 20 * 1024 * 1024) {
        setError('File size must not exceed 20MB.');
        return;
      }
      setFile(selectedFile);
      if (!questionText) {
        setQuestionText(`Past Exam Document: ${selectedFile.name}`);
      }
      if (!sourceAssessment) {
        const cleanName = selectedFile.name.replace(/\.[^/.]+$/, '');
        setSourceAssessment(cleanName);
      }
      setError(null);
    }
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      const droppedFile = e.dataTransfer.files[0];
      if (droppedFile.size > 20 * 1024 * 1024) {
        setError('File size must not exceed 20MB.');
        return;
      }
      setFile(droppedFile);
      if (!questionText) {
        setQuestionText(`Past Exam Document: ${droppedFile.name}`);
      }
      if (!sourceAssessment) {
        const cleanName = droppedFile.name.replace(/\.[^/.]+$/, '');
        setSourceAssessment(cleanName);
      }
      setError(null);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!file) {
      setError('Please select a file to upload');
      return;
    }

    const formData = new FormData();
    formData.append('file', file);
    formData.append('question_text', questionText.trim() || `Uploaded Document: ${file.name}`);
    if (sourceYear.trim()) formData.append('source_year', sourceYear.trim());
    if (sourceAssessment.trim()) formData.append('source_assessment', sourceAssessment.trim());
    formData.append('source', 'uploaded_document');

    try {
      setIsSubmitting(true);
      await onSubmit(formData);
      onClose();
      setFile(null);
      setQuestionText('');
      setSourceAssessment('');
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to upload historical question document.');
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  const formatFileSize = (bytes: number) => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-xs">
      <div className="bg-white dark:bg-[#1C1C1E] rounded-2xl border border-sage-200 dark:border-[#2C2C2E] shadow-xl max-w-lg w-full p-6 space-y-5 animate-in fade-in zoom-in duration-150">
        <div className="flex items-center justify-between pb-3 border-b border-sage-200 dark:border-[#2C2C2E]">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-sage-100 dark:bg-[#2C2C2E] border border-sage-200 dark:border-[#3A3A3C] flex items-center justify-center text-sage-800 dark:text-white">
              <UploadCloud className="w-4 h-4" />
            </div>
            <h3 className="text-lg font-bold text-sage-800 dark:text-white">
              Upload Previous Question Paper
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
          <div
            onDragOver={(e) => e.preventDefault()}
            onDrop={handleDrop}
            onClick={() => fileInputRef.current?.click()}
            className="border-2 border-dashed border-sage-300 dark:border-[#3A3A3C] hover:border-sage-700 dark:hover:border-white rounded-xl p-6 text-center cursor-pointer transition-colors bg-sage-50 dark:bg-[#2C2C2E]/50"
          >
            <input
              type="file"
              ref={fileInputRef}
              onChange={handleFileChange}
              accept=".pdf,.doc,.docx,.txt"
              className="hidden"
            />
            {file ? (
              <div className="flex items-center justify-center gap-3">
                <div className="w-10 h-10 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200 dark:border-emerald-800 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                  <FileText className="w-5 h-5" />
                </div>
                <div className="text-left">
                  <p className="text-sm font-semibold text-sage-800 dark:text-white truncate max-w-xs">
                    {file.name}
                  </p>
                  <p className="text-xs text-sage-500">{formatFileSize(file.size)}</p>
                </div>
              </div>
            ) : (
              <div className="space-y-2">
                <UploadCloud className="w-8 h-8 text-sage-500 mx-auto" />
                <p className="text-xs font-semibold text-sage-800 dark:text-white">
                  Click to select previous exam paper or drag & drop here
                </p>
                <p className="text-[11px] text-sage-500">
                  PDF, DOC, DOCX, TXT (up to 20MB)
                </p>
              </div>
            )}
          </div>

          <Input
            label="Document / Question Summary"
            placeholder="e.g. 2024 Midterm Examination Paper"
            value={questionText}
            onChange={(e) => setQuestionText(e.target.value)}
            disabled={isSubmitting}
          />

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Input
              label="Exam Year"
              placeholder="e.g. 2024"
              value={sourceYear}
              onChange={(e) => setSourceYear(e.target.value)}
              disabled={isSubmitting}
            />
            <Input
              label="Exam Name / Session"
              placeholder="e.g. Spring 2024 Final"
              value={sourceAssessment}
              onChange={(e) => setSourceAssessment(e.target.value)}
              disabled={isSubmitting}
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
              disabled={isSubmitting || !file}
              leftIcon={isSubmitting ? <Loader2 className="w-4 h-4 animate-spin" /> : undefined}
            >
              {isSubmitting ? 'Uploading...' : 'Upload Document'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
};

