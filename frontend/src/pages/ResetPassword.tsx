import React, { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ArrowLeft, ArrowRight, Eye, EyeOff, KeyRound, ShieldAlert } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { AuthShell, AuthAlert, AuthSuccess, authButtonClass, authInputClass, authLinkClass } from '@/components/landing/AuthShell';
import { authService } from '@/services/authService';
import { ApiError } from '@/services/api';

export const INVALID_LINK_MESSAGE = 'This password reset link is invalid or has expired.';

/** Mirrors the server policy (Password::min(8)->letters()->numbers()). */
export const validateNewPassword = (password: string): string | null => {
  if (!password) return 'New password is required';
  if (password.length < 8) return 'Password must be at least 8 characters';
  if (!/[a-zA-Z]/.test(password) || !/\d/.test(password)) return 'Password must contain at least one letter and one number';
  return null;
};

type State = 'form' | 'submitting' | 'success' | 'invalid';

/**
 * /reset-password?token=…&email=… — the token and e-mail arrive only through the link from the reset e-mail.
 * The page never stores them anywhere but component state and sends them once, together with the new password.
 */
export const ResetPassword: React.FC = () => {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const email = params.get('email') ?? '';
  const linkUsable = useMemo(() => token.length > 0 && /^\S+@\S+\.\S+$/.test(email), [token, email]);

  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [show, setShow] = useState(false);
  const [errors, setErrors] = useState<{ password?: string; confirm?: string }>({});
  const [requestError, setRequestError] = useState<string | null>(null);
  const [state, setState] = useState<State>(linkUsable ? 'form' : 'invalid');

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setRequestError(null);
    const next: { password?: string; confirm?: string } = {};
    const pw = validateNewPassword(password);
    if (pw) next.password = pw;
    if (!confirm) next.confirm = 'Please confirm your new password';
    else if (confirm !== password) next.confirm = 'Passwords do not match';
    setErrors(next);
    if (Object.keys(next).length > 0) return;

    setState('submitting');
    try {
      await authService.resetPassword({ token, email, password, password_confirmation: confirm });
      setPassword('');
      setConfirm('');
      setState('success');
    } catch (err) {
      if (err instanceof ApiError && err.status === 422 && err.errors && (err.errors.password || err.errors.password_confirmation)) {
        setErrors({ password: err.errors.password?.[0], confirm: err.errors.password_confirmation?.[0] });
        setState('form');
        return;
      }
      if (err instanceof ApiError && err.status === 422) {
        setState('invalid');
        return;
      }
      setRequestError(err instanceof ApiError && err.status === 429 ? 'Too many attempts. Please wait a few minutes and try again.' : 'We could not reset your password right now. Please check your connection and try again.');
      setState('form');
    }
  };

  if (state === 'invalid') {
    return (
      <AuthShell eyebrow="Account recovery" title="Link not valid" subtitle="Reset links expire after a short time and can only be used once."
        footer={<Link to="/login" className={`${authLinkClass} inline-flex items-center gap-1.5`}><ArrowLeft className="w-3.5 h-3.5" aria-hidden="true" />Back to sign in</Link>}>
        <div className="space-y-4" data-testid="reset-password-invalid">
          <div role="alert" className="p-4 rounded-xl bg-[#FEF2F2] border border-[#FECACA] flex items-start gap-3 text-sm text-[#991B1B]">
            <ShieldAlert className="w-5 h-5 shrink-0" aria-hidden="true" />
            <span>{INVALID_LINK_MESSAGE}</span>
          </div>
          <Link to="/forgot-password" className="block">
            <Button type="button" variant="primary" size="md" className={authButtonClass} rightIcon={<KeyRound className="w-4 h-4" />}>Request a New Link</Button>
          </Link>
        </div>
      </AuthShell>
    );
  }

  if (state === 'success') {
    return (
      <AuthShell eyebrow="Account recovery" title="Password updated." subtitle="Your other sessions have been signed out for your security.">
        <div className="space-y-4" data-testid="reset-password-success">
          <AuthSuccess message="Password reset successfully. You can now sign in with your new password." />
          <Link to="/login" className="block">
            <Button type="button" variant="primary" size="md" className={authButtonClass} rightIcon={<ArrowRight className="w-4 h-4" />}>Go to Login</Button>
          </Link>
        </div>
      </AuthShell>
    );
  }

  return (
    <AuthShell eyebrow="Account recovery" title="Choose a new password" subtitle={`Resetting the password for ${email}.`}
      footer={<Link to="/login" className={`${authLinkClass} inline-flex items-center gap-1.5`}><ArrowLeft className="w-3.5 h-3.5" aria-hidden="true" />Back to sign in</Link>}>
      <form onSubmit={handleSubmit} className="space-y-4" noValidate data-testid="reset-password-form">
        {requestError && <AuthAlert title="Reset not completed" message={requestError} />}
        <Input
          label="New Password"
          type={show ? 'text' : 'password'}
          name="password"
          autoComplete="new-password"
          placeholder="At least 8 characters, letters and numbers"
          value={password}
          onChange={(e) => { setPassword(e.target.value); if (errors.password) setErrors({ ...errors, password: undefined }); }}
          error={errors.password}
          rightIcon={<button type="button" onClick={() => setShow(!show)} className="text-sage-500" aria-label={show ? 'Hide password' : 'Show password'}>{show ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}</button>}
          className={authInputClass}
          required
        />
        <Input
          label="Confirm New Password"
          type={show ? 'text' : 'password'}
          name="password_confirmation"
          autoComplete="new-password"
          placeholder="Repeat the new password"
          value={confirm}
          onChange={(e) => { setConfirm(e.target.value); if (errors.confirm) setErrors({ ...errors, confirm: undefined }); }}
          error={errors.confirm}
          className={authInputClass}
          required
        />
        <Button type="submit" variant="primary" size="md" className={authButtonClass} isLoading={state === 'submitting'} rightIcon={<KeyRound className="w-4 h-4" />}>Reset Password</Button>
      </form>
    </AuthShell>
  );
};
