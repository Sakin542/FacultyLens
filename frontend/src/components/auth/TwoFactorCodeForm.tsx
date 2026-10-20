import React, { useState } from 'react';
import { ShieldCheck, ArrowRight, KeyRound } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { authButtonClass, authInputClass, authLinkClass } from '@/components/landing/AuthShell';

interface Props {
  onSubmit: (code: { code?: string; recovery_code?: string }) => Promise<void>;
  onCancel: () => void;
}

/** Second sign-in step: 6-digit authenticator code, or a single-use recovery code. */
export const TwoFactorCodeForm: React.FC<Props> = ({ onSubmit, onCancel }) => {
  const [useRecovery, setUseRecovery] = useState(false);
  const [value, setValue] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const trimmed = value.trim();
    if (!useRecovery && !/^\d{6}$/.test(trimmed.replace(/\s/g, ''))) {
      setError('Enter the 6-digit code from your authenticator app.');
      return;
    }
    if (useRecovery && trimmed.length < 8) {
      setError('Enter one of your recovery codes (for example ABCDE-12345).');
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await onSubmit(useRecovery ? { recovery_code: trimmed } : { code: trimmed.replace(/\s/g, '') });
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Verification failed.');
      setBusy(false);
    }
  };

  return (
    <form onSubmit={submit} className="space-y-4" noValidate data-testid="two-factor-form">
      <div className="flex items-start gap-3 rounded-lg border border-sage-200 bg-sage-100 p-3 text-sm text-sage-700">
        <ShieldCheck className="w-5 h-5 shrink-0 text-sage-700" aria-hidden="true" />
        <p>{useRecovery ? 'Enter one of the recovery codes you saved when you turned on two-factor authentication. Each code works once.' : 'Your account is protected with two-factor authentication. Open your authenticator app and enter the current code.'}</p>
      </div>

      <Input
        label={useRecovery ? 'Recovery code' : 'Authentication code'}
        inputMode={useRecovery ? 'text' : 'numeric'}
        autoComplete="one-time-code"
        autoFocus
        placeholder={useRecovery ? 'ABCDE-12345' : '123 456'}
        value={value}
        onChange={(e) => { setValue(e.target.value); setError(null); }}
        error={error ?? undefined}
        leftIcon={<KeyRound className="w-4 h-4" />}
        className={authInputClass}
        maxLength={useRecovery ? 32 : 7}
      />

      <Button type="submit" variant="primary" size="md" className={authButtonClass} isLoading={busy} disabled={busy} rightIcon={<ArrowRight className="w-4 h-4" />}>
        {busy ? 'Verifying…' : 'Verify and sign in'}
      </Button>

      <div className="flex items-center justify-between text-xs">
        <button type="button" className={authLinkClass} onClick={() => { setUseRecovery(!useRecovery); setValue(''); setError(null); }}>
          {useRecovery ? 'Use an authenticator code' : 'Use a recovery code'}
        </button>
        <button type="button" className="text-sage-500 hover:text-sage-800" onClick={onCancel}>Back to sign in</button>
      </div>
    </form>
  );
};
