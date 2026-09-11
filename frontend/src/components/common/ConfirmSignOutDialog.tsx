import React, { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { LogOut, X } from 'lucide-react';
import { Button } from '@/components/common/Button';

interface ConfirmSignOutDialogProps {
  open: boolean;
  busy?: boolean;
  email?: string | null;
  onConfirm: () => void;
  onCancel: () => void;
}

/** Yes / No confirmation shown before ending the session. Rendered in a portal so it escapes clipped layouts. */
export const ConfirmSignOutDialog: React.FC<ConfirmSignOutDialogProps> = ({ open, busy, email, onConfirm, onCancel }) => {
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !busy) onCancel();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, busy, onCancel]);

  if (!open) return null;

  return createPortal(
    <div
      className="fixed inset-0 z-[100] flex items-center justify-center bg-sage-800/40 backdrop-blur-[2px] p-4 page-enter"
      onClick={() => { if (!busy) onCancel(); }}
      role="presentation"
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="sign-out-title"
        aria-describedby="sign-out-desc"
        data-testid="sign-out-dialog"
        onClick={(e) => e.stopPropagation()}
        className="w-full max-w-sm rounded-2xl bg-white border border-sage-200 shadow-2xl shadow-sage-800/20 p-6 space-y-5"
      >
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-center gap-3">
            <span className="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-[#FEF2F2] text-[#991B1B]">
              <LogOut className="w-5 h-5" aria-hidden="true" />
            </span>
            <h2 id="sign-out-title" className="font-serif text-2xl leading-tight text-sage-800">Sign out?</h2>
          </div>
          <button
            type="button"
            onClick={onCancel}
            disabled={busy}
            aria-label="Close"
            className="text-sage-400 hover:text-sage-800 transition-colors disabled:opacity-50"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        <p id="sign-out-desc" className="text-sm text-sage-600 leading-relaxed">
          You will be signed out of FacultyLens on this device
          {email ? <> as <strong className="text-sage-800">{email}</strong></> : null}. Any unsaved work on this page will be lost.
        </p>

        <div className="grid grid-cols-2 gap-3">
          <Button autoFocus type="button" variant="outline" size="md" onClick={onCancel} disabled={busy} className="w-full">
            No, stay
          </Button>
          <Button type="button" variant="danger" size="md" onClick={onConfirm} isLoading={busy} className="w-full">
            {busy ? 'Signing out…' : 'Yes, sign out'}
          </Button>
        </div>
      </div>
    </div>,
    document.body,
  );
};
