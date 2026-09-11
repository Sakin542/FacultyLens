import React, { useState } from 'react';
import { Pencil, Save, X } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { CoPoMatrix, MappingLevel } from '@/types/coPo';
import { CoPoMappingMatrix } from './CoPoMappingMatrix';

interface CoPoMappingEditorProps {
  matrix: CoPoMatrix;
  canEdit: boolean;
  onChangeLevel: (learningOutcomeId: number, programOutcomeId: number, mappingId: number | null, level: MappingLevel) => Promise<void>;
}

/** Matrix with an explicit edit toggle so faculty consciously enter editing mode. */
export const CoPoMappingEditor: React.FC<CoPoMappingEditorProps> = ({ matrix, canEdit, onChangeLevel }) => {
  const [editing, setEditing] = useState(false);
  return (
    <div className="space-y-2" data-testid="co-po-editor">
      <div className="flex items-center justify-between">
        <h4 className="text-[10px] uppercase tracking-wider font-semibold text-sage-500">CO → PO Mapping Matrix</h4>
        {canEdit && (
          editing ? (
            <Button variant="outline" size="sm" leftIcon={<Save className="w-3.5 h-3.5" />} onClick={() => setEditing(false)} data-testid="done-editing">Done</Button>
          ) : (
            <Button variant="ghost" size="sm" leftIcon={<Pencil className="w-3.5 h-3.5" />} onClick={() => setEditing(true)} data-testid="edit-matrix">Edit Mappings</Button>
          )
        )}
      </div>
      <CoPoMappingMatrix matrix={matrix} editable={editing} onChangeLevel={onChangeLevel} />
      {editing && <p className="text-[11px] text-sage-500 flex items-center gap-1"><X className="w-3 h-3" /> Each change is saved immediately and recorded in the audit log. Analyses become stale until regenerated.</p>}
    </div>
  );
};
