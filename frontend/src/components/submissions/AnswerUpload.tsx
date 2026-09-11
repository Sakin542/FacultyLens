import React, { useRef, useState } from 'react';
import { CheckCircle2, UploadCloud } from 'lucide-react';

export const ANSWER_FILE_EXTENSIONS = ['pdf', 'docx', 'txt', 'png', 'jpg', 'jpeg'];
export const ANSWER_FILE_MAX_MB = 10;

export function validateAnswerFile(file: File): string | null {
  if (file.size > ANSWER_FILE_MAX_MB * 1024 * 1024) {
    return `File size must not exceed ${ANSWER_FILE_MAX_MB}MB.`;
  }
  const ext = file.name.split('.').pop()?.toLowerCase();
  if (!ext || !ANSWER_FILE_EXTENSIONS.includes(ext)) {
    return 'Unsupported file type. Allowed: PDF, DOCX, TXT, PNG, JPG.';
  }
  return null;
}

interface AnswerUploadProps {
  file: File | null;
  onChange: (file: File | null) => void;
  onError: (message: string | null) => void;
  disabled?: boolean;
  compact?: boolean;
}

/**
 * Drag & drop / click picker for a student's answer file. Files are stored privately server-side.
 */
export const AnswerUpload: React.FC<AnswerUploadProps> = ({ file, onChange, onError, disabled = false, compact = false }) => {
  const inputRef = useRef<HTMLInputElement>(null);
  const [dragging, setDragging] = useState(false);

  const pick = (f: File | undefined) => {
    if (!f) return;
    const err = validateAnswerFile(f);
    if (err) {
      onError(err);
      onChange(null);
      return;
    }
    onError(null);
    onChange(f);
  };

  return (
    <div
      role="button"
      tabIndex={0}
      aria-label="Upload answer file"
      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') inputRef.current?.click(); }}
      onClick={() => !disabled && inputRef.current?.click()}
      onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
      onDragLeave={() => setDragging(false)}
      onDrop={(e) => { e.preventDefault(); setDragging(false); if (!disabled) pick(e.dataTransfer.files?.[0]); }}
      className={`flex flex-col items-center justify-center rounded-xl border-2 border-dashed text-center cursor-pointer transition-colors ${compact ? 'p-3' : 'p-6'} ${
        file || dragging
          ? 'border-sage-700 dark:border-white bg-sage-100 dark:bg-[#2C2C2E]'
          : 'border-sage-200 dark:border-[#3A3A3C] hover:border-sage-300'
      } ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
      data-testid="answer-upload"
    >
      <input
        ref={inputRef}
        type="file"
        accept=".pdf,.docx,.txt,.png,.jpg,.jpeg"
        className="hidden"
        disabled={disabled}
        data-testid="answer-file-input"
        onChange={(e) => pick(e.target.files?.[0])}
      />
      {file ? (
        <div className="flex items-center gap-2 text-xs">
          <CheckCircle2 className="w-4 h-4 text-emerald-600" />
          <span className="font-medium text-sage-800 dark:text-white truncate max-w-[220px]">{file.name}</span>
          <span className="text-sage-500">{(file.size / 1024).toFixed(0)} KB</span>
        </div>
      ) : (
        <div className="space-y-1">
          <UploadCloud className="w-5 h-5 text-sage-500 mx-auto" />
          <p className="text-xs font-medium text-sage-700 dark:text-sage-200">Click or drop an answer file</p>
          <p className="text-[10px] text-sage-500">PDF, DOCX, TXT, PNG, JPG · up to {ANSWER_FILE_MAX_MB} MB</p>
        </div>
      )}
    </div>
  );
};
