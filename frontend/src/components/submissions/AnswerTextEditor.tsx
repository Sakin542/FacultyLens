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
      <label htmlFor={id} className="block text-[10px] font-medium uppercase tracking-wider text-[#262626] dark:text-[#E5E5E5]">
        {label}
      </label>
      <span className="text-[10px] text-[#737373] font-mono">{value.length}/{maxLength}</span>
    </div>
    <textarea
      id={id}
      rows={rows}
      maxLength={maxLength}
      value={value}
      disabled={disabled}
      placeholder={placeholder}
      onChange={(e) => onChange(e.target.value)}
      className="w-full rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] bg-white dark:bg-[#2C2C2E] px-3 py-2 text-xs text-[#111111] dark:text-white placeholder:text-[#A3A3A3] focus:outline-none focus:ring-2 focus:ring-[#111111] dark:focus:ring-white focus:border-transparent disabled:bg-[#F7F7F5] disabled:cursor-not-allowed leading-relaxed"
    />
    {helperText && <p className="text-[11px] text-[#737373]">{helperText}</p>}
  </div>
);
