import React from 'react';
import { FileText, Table2, Sheet } from 'lucide-react';
import { cn } from '@/utils/cn';
import { ExportFormat } from '@/types/report';

const FORMATS: { key: ExportFormat; label: string; hint: string; icon: React.ElementType }[] = [
  { key: 'PDF', label: 'PDF', hint: 'Formatted document with metadata, tables and footer', icon: FileText },
  { key: 'CSV', label: 'CSV', hint: 'UTF-8 structured rows for analysis tools', icon: Table2 },
  { key: 'XLSX', label: 'XLSX', hint: 'Workbook with one sheet per table', icon: Sheet },
];

interface ExportFormatSelectorProps {
  value: ExportFormat;
  onChange: (format: ExportFormat) => void;
  allowed?: ExportFormat[];
  disabled?: boolean;
  largeDataset?: boolean;
}

export const ExportFormatSelector: React.FC<ExportFormatSelectorProps> = ({ value, onChange, allowed, disabled, largeDataset }) => (
  <div>
    <p className="block text-xs font-medium text-sage-700 mb-1.5">Format</p>
    <div role="radiogroup" aria-label="Export format" className="grid grid-cols-3 gap-2">
      {FORMATS.filter((f) => !allowed || allowed.includes(f.key)).map((f) => {
        const active = value === f.key;
        const Icon = f.icon;
        return (
          <button
            key={f.key}
            type="button"
            role="radio"
            aria-checked={active}
            disabled={disabled}
            onClick={() => onChange(f.key)}
            className={cn('rounded-lg border px-3 py-2.5 text-left transition-colors disabled:opacity-60',
              active ? 'border-sage-600 bg-sage-50 ring-1 ring-sage-600' : 'border-sage-200 bg-white hover:border-sage-300')}
          >
            <span className="flex items-center gap-1.5 text-sm font-semibold text-sage-800"><Icon className="w-4 h-4 text-sage-600" />{f.label}</span>
            <span className="block text-[11px] text-sage-500 mt-0.5 leading-snug">{f.hint}</span>
          </button>
        );
      })}
    </div>
    {largeDataset && value === 'PDF' && (
      <p className="mt-1.5 text-[11px] text-amber-800">Large dataset: PDF tables are truncated. CSV or XLSX keeps every row.</p>
    )}
  </div>
);
