import React, { useState } from 'react';
import { CoPoMatrix, MappingLevel } from '@/types/coPo';
import { MappingLevelSelector } from './MappingLevelSelector';

interface CoPoMappingMatrixProps {
  matrix: CoPoMatrix;
  editable?: boolean;
  onChangeLevel?: (learningOutcomeId: number, programOutcomeId: number, mappingId: number | null, level: MappingLevel) => Promise<void>;
}

/**
 * CO x PO matrix (rows = COs, columns = POs). Read-only cells show 1/2/3 or —; in edit mode each
 * cell becomes a level selector. Only faculty edits change mappings.
 */
export const CoPoMappingMatrix: React.FC<CoPoMappingMatrixProps> = ({ matrix, editable = false, onChangeLevel }) => {
  const [busyKey, setBusyKey] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  if (matrix.program_outcomes.length === 0 || matrix.rows.length === 0) {
    return <p className="text-xs text-[#737373] italic" data-testid="matrix-empty">The matrix needs at least one course outcome and one program outcome.</p>;
  }

  const change = async (loId: number, poId: number, mappingId: number | null, level: MappingLevel) => {
    if (!onChangeLevel) return;
    const key = `${loId}:${poId}`;
    try { setBusyKey(key); setError(null); await onChangeLevel(loId, poId, mappingId, level); }
    catch (err) { setError(err instanceof Error ? err.message : 'The mapping could not be saved.'); }
    finally { setBusyKey(null); }
  };

  return (
    <div className="space-y-2" data-testid="co-po-matrix">
      <div className="overflow-x-auto rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C]">
        <table className="w-full text-xs">
          <thead className="bg-[#F7F7F5] dark:bg-[#2C2C2E] text-[10px] uppercase tracking-wider text-[#737373]">
            <tr>
              <th className="text-left px-3 py-2">CO \ PO</th>
              {matrix.program_outcomes.map((po) => (
                <th key={po.id} className="px-2 py-2 text-center" title={po.title}>{po.code}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-[#E5E5E5] dark:divide-[#2C2C2E]">
            {matrix.rows.map((row) => (
              <tr key={row.learning_outcome_id} data-testid="matrix-row">
                <td className="px-3 py-2 font-mono font-semibold text-[#111111] dark:text-white whitespace-nowrap" title={row.description}>{row.code}</td>
                {row.cells.map((cell) => (
                  <td key={cell.program_outcome_id} className="px-2 py-1.5 text-center" data-testid="matrix-cell">
                    {editable ? (
                      <MappingLevelSelector
                        compact
                        value={cell.level}
                        disabled={busyKey === `${row.learning_outcome_id}:${cell.program_outcome_id}`}
                        onChange={(lvl) => change(row.learning_outcome_id, cell.program_outcome_id, cell.mapping_id, lvl)}
                      />
                    ) : (
                      <span className={`inline-flex w-7 h-7 items-center justify-center rounded-md font-mono ${cell.level > 0 ? 'bg-[#111111] text-white dark:bg-white dark:text-[#111111]' : 'text-[#A3A3A3]'}`} title={cell.justification ?? undefined}>
                        {cell.level > 0 ? cell.level : '—'}
                      </span>
                    )}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {error && <p className="text-xs text-red-600" role="alert">{error}</p>}
      <div className="flex flex-wrap items-center gap-3 text-[10px] text-[#737373]" data-testid="matrix-legend">
        <span>— No contribution</span><span>1 Low</span><span>2 Medium</span><span>3 High</span>
        <span className="ml-auto font-mono">{matrix.active_mappings} / {matrix.possible_mappings} mappings · density {matrix.density_percent}%</span>
      </div>
    </div>
  );
};
