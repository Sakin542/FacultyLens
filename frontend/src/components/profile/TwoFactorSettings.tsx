import React, { useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { ShieldCheck, ShieldOff, Copy, RefreshCw } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { Badge } from '@/components/common/Badge';
import { useAuth } from '@/context/AuthContext';
import { twoFactorService, TwoFactorStatus } from '@/services/twoFactorService';

type Step = 'idle' | 'password' | 'scan' | 'codes' | 'disable' | 'regenerate';

/** Settings card: turn on authenticator-app two-factor sign-in, save recovery codes, regenerate or turn off. */
export const TwoFactorSettings: React.FC = () => {
  const { user, updateUser } = useAuth();
  const [status, setStatus] = useState<TwoFactorStatus | null>(null);
  const [step, setStep] = useState<Step>('idle');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [secret, setSecret] = useState('');
  const [qr, setQr] = useState('');
  const [codes, setCodes] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [copied, setCopied] = useState(false);

  const refresh = () => twoFactorService.status().then(setStatus).catch(() => setStatus(null));
  useEffect(() => { void refresh(); }, []);

  const reset = () => { setStep('idle'); setPassword(''); setCode(''); setError(null); };

  const run = async (fn: () => Promise<void>) => {
    setBusy(true);
    setError(null);
    try { await fn(); } catch (e) { setError(e instanceof Error ? e.message : 'Something went wrong.'); } finally { setBusy(false); }
  };

  const start = () => run(async () => {
    const res = await twoFactorService.enable(password);
    setSecret(res.secret);
    setQr(await QRCode.toDataURL(res.otpauth_url, { margin: 1, width: 192, color: { dark: '#2F3B2F', light: '#FFFFFF' } }));
    setPassword('');
    setStep('scan');
  });

  const confirm = () => run(async () => {
    const res = await twoFactorService.confirm(code.replace(/\s/g, ''));
    setCodes(res.recovery_codes);
    setCode('');
    setStep('codes');
    if (user) updateUser({ ...user, two_factor_enabled: true });
    await refresh();
  });

  const disable = () => run(async () => {
    await twoFactorService.disable(password);
    if (user) updateUser({ ...user, two_factor_enabled: false });
    reset();
    await refresh();
  });

  const regenerate = () => run(async () => {
    const res = await twoFactorService.regenerateRecoveryCodes(password);
    setCodes(res.recovery_codes);
    setPassword('');
    setStep('codes');
    await refresh();
  });

  const copyCodes = async () => {
    try { await navigator.clipboard.writeText(codes.join('\n')); setCopied(true); window.setTimeout(() => setCopied(false), 2000); } catch { /* clipboard blocked */ }
  };

  const enabled = Boolean(status?.enabled);

  return (
    <Card id="two-factor" data-testid="settings-two-factor">
      <CardHeader>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <CardTitle>Two-Factor Authentication</CardTitle>
          <Badge variant={enabled ? 'Good' : 'neutral'} dot={enabled}>{enabled ? 'On' : 'Off'}</Badge>
        </div>
        <CardDescription>Require a 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, Authy, 1Password) when you sign in.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {error && <p role="alert" className="rounded-lg border border-[#FECACA] bg-[#FEF2F2] px-3 py-2 text-xs text-[#991B1B]">{error}</p>}

        {step === 'idle' && (
          enabled ? (
            <div className="flex flex-wrap items-center justify-between gap-3">
              <p className="text-sm text-sage-700">{status?.recovery_codes_remaining ?? 0} recovery code(s) left.</p>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" leftIcon={<RefreshCw className="w-3.5 h-3.5" />} onClick={() => setStep('regenerate')}>New recovery codes</Button>
                <Button variant="danger" size="sm" leftIcon={<ShieldOff className="w-3.5 h-3.5" />} onClick={() => setStep('disable')}>Turn off</Button>
              </div>
            </div>
          ) : (
            <Button size="sm" leftIcon={<ShieldCheck className="w-3.5 h-3.5" />} onClick={() => setStep('password')} data-testid="two-factor-start">Turn on two-factor authentication</Button>
          )
        )}

        {(step === 'password' || step === 'disable' || step === 'regenerate') && (
          <form className="space-y-3 max-w-sm" onSubmit={(e) => { e.preventDefault(); void (step === 'password' ? start() : step === 'disable' ? disable() : regenerate()); }}>
            <Input label="Confirm your password" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required autoFocus />
            <div className="flex gap-2">
              <Button type="submit" size="sm" variant={step === 'disable' ? 'danger' : 'primary'} isLoading={busy} disabled={busy || !password}>
                {step === 'password' ? 'Continue' : step === 'disable' ? 'Turn off 2FA' : 'Generate new codes'}
              </Button>
              <Button type="button" size="sm" variant="ghost" onClick={reset}>Cancel</Button>
            </div>
          </form>
        )}

        {step === 'scan' && (
          <div className="grid gap-4 sm:grid-cols-[auto,1fr] sm:items-start">
            {qr && <img src={qr} alt="QR code for your authenticator app" className="h-48 w-48 rounded-lg border border-sage-200" data-testid="two-factor-qr" />}
            <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); void confirm(); }}>
              <ol className="list-decimal space-y-1 pl-4 text-sm text-sage-700">
                <li>Scan the QR code with your authenticator app.</li>
                <li>Or enter this key manually: <code className="break-all rounded bg-sage-100 px-1.5 py-0.5 text-xs text-sage-800">{secret.replace(/(.{4})/g, '$1 ').trim()}</code></li>
                <li>Enter the 6-digit code the app shows.</li>
              </ol>
              <Input label="Authentication code" inputMode="numeric" autoComplete="one-time-code" maxLength={7} value={code} onChange={(e) => setCode(e.target.value)} placeholder="123 456" />
              <div className="flex gap-2">
                <Button type="submit" size="sm" isLoading={busy} disabled={busy || code.replace(/\s/g, '').length !== 6}>Verify and turn on</Button>
                <Button type="button" size="sm" variant="ghost" onClick={reset}>Cancel</Button>
              </div>
            </form>
          </div>
        )}

        {step === 'codes' && (
          <div className="space-y-3">
            <p className="text-sm text-sage-700">Save these recovery codes somewhere safe. Each one signs you in once if you lose your phone. They are shown only now.</p>
            <ul className="grid grid-cols-2 gap-2 sm:grid-cols-4" data-testid="two-factor-recovery-codes">
              {codes.map((c) => <li key={c} className="rounded-md border border-sage-200 bg-sage-100 px-2 py-1.5 text-center font-mono text-xs text-sage-800">{c}</li>)}
            </ul>
            <div className="flex gap-2">
              <Button size="sm" variant="outline" leftIcon={<Copy className="w-3.5 h-3.5" />} onClick={copyCodes}>{copied ? 'Copied' : 'Copy codes'}</Button>
              <Button size="sm" onClick={reset}>I saved them</Button>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
};
