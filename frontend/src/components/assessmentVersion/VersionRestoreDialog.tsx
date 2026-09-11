import React, { useState } from 'react';
import { RotateCcw, X } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { AssessmentVersionSummary, RestoreVersionInput } from '@/types/assessmentVersion';

/** STEP 38: "Restore as new version" — never overwrites the current version. */
export const VersionRestoreDialog: React.FC<{
  open: boolean;
  version: AssessmentVersionSummary | null;
  currentLabel?: string | null;
  busy?: boolean;
  error?: string | null;
  onClose: () => void;
  onConfirm: (input: RestoreVersionInput) => void;
}> = ({ open, version, currentLabel, busy, error, onClose, onConfirm }) => {
  const [summary, setSummary] = useState('');
  if (!open || !version) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="version-restore-title" data-testid="version-restore-dialog">
      <form noValidate onSubmit={(e) => { e.preventDefault(); onConfirm({ change_summary: summary.trim() || undefined }); }} className="w-full max-w-md rounded-xl bg-white dark:bg-[#161616] border border-sage-200 dark:border-[#2A2A2A] p-5 space-y-4 shadow-lg">
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-center gap-2"><RotateCcw className="w-4 h-4" aria-hidden="true" /><h2 id="version-restore-title" className="text-lg font-semibold text-sage-800 dark:text-white">Restore {version.version_label} as a new version</h2></div>
          <button type="button" onClick={onClose} aria-label="Close" className="text-sage-500 hover:text-sage-800"><X className="w-4 h-4" /></button>
        </div>
        <p className="text-sm text-sage-600 dark:text-sage-400">
          A new draft version will be created with the questions, marks, blueprint snapshot and rubric references of <strong>{version.version_label}</strong>.
          {currentLabel ? <> The current version <strong>{currentLabel}</strong> is not changed.</> : ' Nothing is overwritten.'}
        </p>
        <label className="block text-xs font-medium uppercase tracking-wider text-sage-700 dark:text-sage-300">Change summary (optional)
          <textarea value={summary} onChange={(e) => setSummary(e.target.value)} rows={2} placeholder={`Restored structure from ${version.version_label}`} className="mt-1 w-full rounded-lg border border-sage-200 bg-white dark:bg-sage-700 px-3 py-2 text-sm font-normal normal-case tracking-normal" />
        </label>
        {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" size="sm" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button type="submit" size="sm" isLoading={busy}>Restore as new version</Button>
        </div>
      </form>
    </div>
  );
};
