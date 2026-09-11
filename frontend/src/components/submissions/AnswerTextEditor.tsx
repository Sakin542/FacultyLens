import React from 'react';

interface AnswerTextEditorProps {
  id?: string;
  label?: string;
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
  placeholder?: string;
  rows?: number;
  maxLength?: number;
  helperText?: string;
}

export const AnswerTextEditor: React.FC<AnswerTextEditorProps> = ({
  id,
  label = 'Student answer',
  value,
  onChange,
  disabled = false,
  placeholder = 'Type or paste the student\'s answer…',
  rows = 5,
  maxLength = 20000,
  helperText,
}) => (
  <div className="space-y-1.5">
    <div className="flex items-center justify-between">
      <label htmlFor={id} className="block text-[10px] font-medium uppercase tracking-wider text-sage-700 dark:text-sage-200">
        {label}
      </label>
      <span className="text-[10px] text-sage-500 font-mono">{value.length}/{maxLength}</span>
    </div>
    <textarea
      id={id}
      rows={rows}
      maxLength={maxLength}
      value={value}
      disabled={disabled}
      placeholder={placeholder}
      onChange={(e) => onChange(e.target.value)}
      className="w-full rounded-lg border border-sage-200 dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] px-3 py-2 text-xs text-sage-800 dark:text-white placeholder:text-sage-400 focus:outline-none focus:ring-2 focus:ring-sage-600 dark:focus:ring-white focus:border-transparent disabled:bg-sage-100 disabled:cursor-not-allowed leading-relaxed"
    />
    {helperText && <p className="text-[11px] text-sage-500">{helperText}</p>}
  </div>
);
