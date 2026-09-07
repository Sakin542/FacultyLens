import React, { useState, useRef } from 'react';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { X, UploadCloud, FileText, AlertCircle, Loader2 } from 'lucide-react';

interface MaterialUploadModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (formData: FormData) => Promise<void>;
}

export const MaterialUploadModal: React.FC<MaterialUploadModalProps> = ({
  isOpen,
  onClose,
  onSubmit,
}) => {
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  if (!isOpen) return null;

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files[0]) {
      const selectedFile = e.target.files[0];
      // Max 20MB
      if (selectedFile.size > 20 * 1024 * 1024) {
        setError('File size must not exceed 20MB.');
        return;
      }
      setFile(selectedFile);
      if (!title) {
        // Automatically default title to clean file name without extension
        const cleanName = selectedFile.name.replace(/\.[^/.]+$/, '');
        setTitle(cleanName);
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
      if (!title) {
        const cleanName = droppedFile.name.replace(/\.[^/.]+$/, '');
        setTitle(cleanName);
      }
      setError(null);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!title.trim()) {
      setError('Material title is required');
      return;
    }

    if (!file) {
      setError('Please select a file to upload');
      return;
    }

    const formData = new FormData();
    formData.append('title', title.trim());
    if (description.trim()) {
      formData.append('description', description.trim());
    }
    formData.append('file', file);

    try {
      setIsSubmitting(true);
      await onSubmit(formData);
      onClose();
      // Reset
      setTitle('');
      setDescription('');
      setFile(null);
    } catch (err: unknown) {
      if (err instanceof Error) {
        setError(err.message);
      } else {
        setError('Failed to upload material');
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
      <div className="bg-white dark:bg-[#1C1C1E] rounded-2xl border border-[#E5E5E5] dark:border-[#2C2C2E] shadow-xl max-w-lg w-full p-6 space-y-5 animate-in fade-in zoom-in duration-150">
        <div className="flex items-center justify-between pb-3 border-b border-[#E5E5E5] dark:border-[#2C2C2E]">
          <div className="flex items-center gap-2">
            <div className="w-8 h-8 rounded-lg bg-[#F7F7F5] dark:bg-[#2C2C2E] border border-[#E5E5E5] dark:border-[#3A3A3C] flex items-center justify-center text-[#111111] dark:text-white">
              <UploadCloud className="w-4 h-4" />
            </div>
            <h3 className="text-lg font-bold text-[#111111] dark:text-white">Upload Course Material</h3>
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
          {/* File Picker Zone */}
          <div
            onDragOver={(e) => e.preventDefault()}
            onDrop={handleDrop}
            onClick={() => fileInputRef.current?.click()}
            className="border-2 border-dashed border-[#D4D4D4] dark:border-[#3A3A3C] hover:border-[#111111] dark:hover:border-white rounded-xl p-6 text-center cursor-pointer transition-colors bg-[#FAFAFA] dark:bg-[#2C2C2E]/50"
          >
            <input
              type="file"
              ref={fileInputRef}
              onChange={handleFileChange}
              accept=".pdf,.doc,.docx,.txt,.ppt,.pptx"
              className="hidden"
            />
            {file ? (
              <div className="flex items-center justify-center gap-3">
                <div className="w-10 h-10 rounded-lg bg-emerald-50 dark:bg-emerald-950/50 border border-emerald-200 dark:border-emerald-800 flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                  <FileText className="w-5 h-5" />
                </div>
                <div className="text-left">
                  <p className="text-sm font-semibold text-[#111111] dark:text-white truncate max-w-xs">
                    {file.name}
                  </p>
                  <p className="text-xs text-[#737373]">{formatFileSize(file.size)}</p>
                </div>
              </div>
            ) : (
              <div className="space-y-2">
                <UploadCloud className="w-8 h-8 text-[#737373] mx-auto" />
                <p className="text-xs font-semibold text-[#111111] dark:text-white">
                  Click to select file or drag & drop here
                </p>
                <p className="text-[11px] text-[#737373]">
                  PDF, DOCX, DOC, PPTX, TXT (up to 20MB)
                </p>
              </div>
            )}
          </div>

          <Input
            label="Material Title"
            placeholder="e.g. Course Syllabus & Policy Guide"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            required
            disabled={isSubmitting}
          />

          <div>
            <label className="block text-xs font-medium uppercase tracking-wider text-[#262626] dark:text-[#E5E5E5] mb-1.5">
              Description (Optional)
            </label>
            <textarea
              rows={3}
              placeholder="Provide context or notes on this document..."
              value={description}
              onChange={(e) => setDescription(e.target.value)}
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
              disabled={isSubmitting || !file}
              leftIcon={isSubmitting ? <Loader2 className="w-4 h-4 animate-spin" /> : undefined}
            >
              {isSubmitting ? 'Uploading...' : 'Upload Material'}
            </Button>
          </div>
        </form>
      </div>
    </div>
  );
};

