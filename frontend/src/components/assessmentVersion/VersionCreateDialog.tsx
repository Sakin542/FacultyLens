import React, { useState } from 'react';
import { X } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { AssessmentVersionSummary, CreateVersionInput, VersionType } from '@/types/assessmentVersion';

/**
 * STEP 38: create the next version. The server assigns the number/label; faculty choose the source,
 * MAJOR/MINOR and write the change summary (required once a version exists — never auto-generated).
 */
export const VersionCreateDialog: React.FC<{
  open: boolean;
  versions: AssessmentVersionSummary[];
  defaultBasedOnId?: number | null;
  busy?: boolean;
  error?: string | null;
  onClose: () => void;
  onSubmit: (input: CreateVersionInput) => void;
}> = ({ open, versions, defaultBasedOnId, busy, error, onClose, onSubmit }) => {
  const [basedOn, setBasedOn] = useState<string>(defaultBasedOnId ? String(defaultBasedOnId) : '');
  const [type, setType] = useState<VersionType>('MAJOR');
  const [summary, setSummary] = useState('');
  const [touched, setTouched] = useState(false);
  if (!open) return null;
  const first = versions.length === 0;
  const summaryMissing = !first && summary.trim().length < 3;

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    setTouched(true);
    if (summaryMissing) return;
    onSubmit({ based_on_version_id: basedOn ? Number(basedOn) : null, version_type: type, change_summary: summary.trim() || undefined });
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="version-create-title" data-testid="version-create-dialog">
      <form noValidate onSubmit={submit} className="w-full max-w-lg rounded-xl bg-white dark:bg-[#161616] border border-[#E5E5E5] dark:border-[#2A2A2A] p-5 space-y-4 shadow-lg">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h2 id="version-create-title" className="text-lg font-semibold text-[#111111] dark:text-white">{first ? 'Create version 1.0' : 'Create new version'}</h2>
            <p className="text-xs text-[#737373] mt-0.5">{first ? 'Snapshots the current questions, blueprint and approved rubrics of this assessment.' : 'Clones the selected version into a new draft. The source version is preserved unchanged.'}</p>
          </div>
          <button type="button" onClick={onClose} aria-label="Close" className="text-[#737373] hover:text-[#111111]"><X className="w-4 h-4" /></button>
        </div>
        {!first && (
          <label className="block text-xs font-medium uppercase tracking-wider text-[#262626] dark:text-[#D4D4D4]">Based on
            <select value={basedOn} onChange={(e) => setBasedOn(e.target.value)} className="mt-1 w-full rounded-lg border border-[#E5E5E5] bg-white dark:bg-[#111111] px-3 py-2 text-sm font-normal normal-case tracking-normal">
              <option value="">Latest version</option>
              {versions.map((v) => <option key={v.id} value={v.id}>{v.version_label} · {v.status.replace('_', ' ').toLowerCase()}</option>)}
            </select>
          </label>
        )}
        <fieldset className="space-y-1">
          <legend className="text-xs font-medium uppercase tracking-wider text-[#262626] dark:text-[#D4D4D4]">Version type</legend>
          <div className="flex gap-4 text-sm">
            {(['MAJOR', 'MINOR'] as VersionType[]).map((t) => (
              <label key={t} className="inline-flex items-center gap-1.5"><input type="radio" name="version_type" value={t} checked={type === t} onChange={() => setType(t)} disabled={first} />{t === 'MAJOR' ? 'Major — questions / marks / structure change' : 'Minor — wording or instruction changes'}</label>
            ))}
          </div>
          <p className="text-[11px] text-[#737373]">Labels follow deterministic rules: major → next vN.0, minor → next vM.n within the source's major line.</p>
        </fieldset>
        <label className="block text-xs font-medium uppercase tracking-wider text-[#262626] dark:text-[#D4D4D4]">Change summary{!first && <span className="text-[#DC2626] ml-1">*</span>}
          <textarea value={summary} onChange={(e) => setSummary(e.target.value)} onBlur={() => setTouched(true)} rows={3} placeholder={first ? 'Optional note for the initial snapshot' : 'e.g. Updated Q3 and Q6 to improve CO2 coverage. Adjusted hard-question distribution.'}
            className="mt-1 w-full rounded-lg border border-[#E5E5E5] bg-white dark:bg-[#111111] px-3 py-2 text-sm font-normal normal-case tracking-normal" aria-invalid={touched && summaryMissing} />
          {touched && summaryMissing && <span className="block text-xs text-[#DC2626] font-normal normal-case tracking-normal mt-1">Describe what will change in this version (at least 3 characters).</span>}
        </label>
        {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" size="sm" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button type="submit" size="sm" isLoading={busy}>{first ? 'Create v1.0' : 'Create draft version'}</Button>
        </div>
      </form>
    </div>
  );
};
