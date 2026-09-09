import React, { useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { CourseOutcome, ProgramOutcome } from '@/types/coPo';

/** Existing learning outcomes displayed as COs (LO2 → CO2). Read-only here; edit in Course Details. */
export const CourseOutcomeList: React.FC<{ outcomes: CourseOutcome[] }> = ({ outcomes }) => (
  <div className="space-y-1.5" data-testid="course-outcome-list">
    <h4 className="text-[10px] uppercase tracking-wider font-semibold text-[#737373]">Course Outcomes ({outcomes.length})</h4>
    {outcomes.length === 0 ? (
      <p className="text-xs text-[#737373] italic">No learning outcomes yet. Add them in Course Details.</p>
    ) : (
      <ul className="divide-y divide-[#E5E5E5] dark:divide-[#2C2C2E] text-xs">
        {outcomes.map((co) => (
          <li key={co.id} className="py-1.5 flex items-start gap-2">
            <span className="font-mono font-bold text-[#111111] dark:text-white w-12 shrink-0">{co.display_code}</span>
            <span className="text-[#262626] dark:text-[#E5E5E5] flex-1">{co.description}</span>
            {co.cognitive_level && <span className="text-[10px] text-[#737373] shrink-0">{co.cognitive_level}</span>}
          </li>
        ))}
      </ul>
    )}
  </div>
);

interface ProgramOutcomeListProps {
  outcomes: ProgramOutcome[];
  canEdit?: boolean;
  onAdd?: (data: { code: string; title: string; description?: string }) => Promise<void>;
  onDelete?: (id: number) => Promise<void>;
}

/** Program outcomes with institution-configured wording; optional inline add/remove. */
export const ProgramOutcomeList: React.FC<ProgramOutcomeListProps> = ({ outcomes, canEdit = false, onAdd, onDelete }) => {
  const [adding, setAdding] = useState(false);
  const [code, setCode] = useState('');
  const [title, setTitle] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    if (!code.trim() || !title.trim()) { setError('Code and title are required.'); return; }
    try { setBusy(true); setError(null); await onAdd?.({ code: code.trim(), title: title.trim() }); setCode(''); setTitle(''); setAdding(false); }
    catch (err) { setError(err instanceof Error ? err.message : 'The program outcome could not be saved.'); }
    finally { setBusy(false); }
  };

  return (
    <div className="space-y-1.5" data-testid="program-outcome-list">
      <div className="flex items-center justify-between">
        <h4 className="text-[10px] uppercase tracking-wider font-semibold text-[#737373]">Program Outcomes ({outcomes.length})</h4>
        {canEdit && onAdd && !adding && <Button variant="ghost" size="sm" leftIcon={<Plus className="w-3.5 h-3.5" />} onClick={() => setAdding(true)} data-testid="add-po">Add PO</Button>}
      </div>
      {outcomes.length === 0 && !adding && <p className="text-xs text-[#737373] italic">No program outcomes configured. Add the outcomes your institution defines.</p>}
      <ul className="divide-y divide-[#E5E5E5] dark:divide-[#2C2C2E] text-xs">
        {outcomes.map((po) => (
          <li key={po.id} className="py-1.5 flex items-start gap-2" data-testid="po-row">
            <span className="font-mono font-bold text-[#111111] dark:text-white w-12 shrink-0">{po.code}</span>
            <span className="text-[#262626] dark:text-[#E5E5E5] flex-1">{po.title}{po.description ? <span className="text-[#737373]"> — {po.description}</span> : null}</span>
            {canEdit && onDelete && (
              <button type="button" onClick={() => onDelete(po.id)} className="text-[#737373] hover:text-red-600" aria-label={`Remove ${po.code}`}><Trash2 className="w-3.5 h-3.5" /></button>
            )}
          </li>
        ))}
      </ul>
      {adding && (
        <div className="p-3 rounded-lg border border-[#E5E5E5] dark:border-[#3A3A3C] space-y-2" data-testid="po-form">
          <div className="grid grid-cols-[100px_1fr] gap-2">
            <Input id="po-code" label="Code" value={code} onChange={(e) => setCode(e.target.value)} placeholder="PO1" disabled={busy} />
            <Input id="po-title" label="Title" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Engineering Knowledge" disabled={busy} />
          </div>
          {error && <p className="text-xs text-red-600" role="alert">{error}</p>}
          <div className="flex justify-end gap-2">
            <Button variant="ghost" size="sm" onClick={() => { setAdding(false); setError(null); }} disabled={busy}>Cancel</Button>
            <Button variant="primary" size="sm" onClick={submit} isLoading={busy} data-testid="save-po">Save PO</Button>
          </div>
        </div>
      )}
    </div>
  );
};
