import React from 'react';
import { MAPPING_LEVEL_LABELS, MappingLevel } from '@/types/coPo';

interface MappingLevelSelectorProps {
  value: MappingLevel;
  onChange: (level: MappingLevel) => void;
  disabled?: boolean;
  id?: string;
  compact?: boolean;
}

/** 0–3 mapping level control; labels come from a single shared table. */
export const MappingLevelSelector: React.FC<MappingLevelSelectorProps> = ({ value, onChange, disabled = false, id, compact = false }) => (
  <div className="inline-flex rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] overflow-hidden" role="radiogroup" aria-label="Mapping level" id={id} data-testid="mapping-level-selector">
    {([0, 1, 2, 3] as MappingLevel[]).map((lvl) => (
      <button
        key={lvl}
        type="button"
        role="radio"
        aria-checked={value === lvl}
        disabled={disabled}
        onClick={() => onChange(lvl)}
        title={MAPPING_LEVEL_LABELS[lvl]}
        className={`${compact ? 'px-2 py-1 text-[11px]' : 'px-3 py-1.5 text-xs'} font-mono border-r last:border-r-0 border-[#E5E5E5] dark:border-[#3A3A3C] ${value === lvl ? 'bg-[#111111] text-white dark:bg-white dark:text-[#111111]' : 'bg-white dark:bg-[#1C1C1E] text-[#737373] hover:text-[#111111] dark:hover:text-white'} disabled:opacity-50`}
        data-testid={`level-${lvl}`}
      >
        {lvl === 0 ? '—' : lvl}
      </button>
    ))}
  </div>
);
