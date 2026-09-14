import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { Mail, ArrowLeft, KeyRound } from 'lucide-react';
import { AuthShell, AuthAlert, AuthSuccess, authButtonClass, authInputClass, authLinkClass } from '@/components/landing/AuthShell';
import { authService } from '@/services/authService';
import { ApiError } from '@/services/api';

/** Shown regardless of whether the account exists (account-enumeration protection lives on the server too). */
export const GENERIC_RESET_MESSAGE = 'If an account exists for this email address, a password reset link has been sent.';

export const ForgotPassword: React.FC = () => {
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const [requestError, setRequestError] = useState<string | null>(null);
  const [isSubmitted, setIsSubmitted] = useState(false);
  const [isLoading, setIsLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setRequestError(null);
    const trimmed = email.trim();
    if (!trimmed) {
      setError('University email is required');
      return;
    }
    if (!/^\S+@\S+\.\S+$/.test(trimmed)) {
      setError('Please provide a valid email address');
      return;
    }
    setError('');
    setIsLoading(true);
    try {
      await authService.forgotPassword(trimmed);
      setIsSubmitted(true);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422 && err.errors?.email?.[0]) {
        setError(err.errors.email[0]);
      } else if (err instanceof ApiError && err.status === 429) {
        setRequestError(err.message || 'Too many reset attempts. Please wait a few minutes and try again.');
      } else {
        setRequestError('We could not process your request right now. Please try again in a moment.');
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <AuthShell
      eyebrow="Account recovery"
      title={isSubmitted ? 'Check your inbox.' : 'Forgot your password?'}
      subtitle={isSubmitted ? 'The link in the email expires after a short time and can be used once.' : 'Enter your university email and we\u2019ll send you a password reset link.'}
      footer={<Link to="/login" className={`${authLinkClass} inline-flex items-center gap-1.5`}><ArrowLeft className="w-3.5 h-3.5" aria-hidden="true" />Back to sign in</Link>}
    >
      {isSubmitted ? (
        <div className="space-y-4" data-testid="forgot-password-success">
          <AuthSuccess message={GENERIC_RESET_MESSAGE} />
          <p className="text-xs text-sage-500">Didn&apos;t get it? Check your spam folder, or <button type="button" className={authLinkClass} onClick={() => { setIsSubmitted(false); }}>try again</button> in a few minutes.</p>
        </div>
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4" noValidate data-testid="forgot-password-form">
          {requestError && <AuthAlert title="Request not sent" message={requestError} />}
          <Input label="University Email" type="email" name="email" autoComplete="email" placeholder="faculty@university.edu" value={email} onChange={(e) => { setEmail(e.target.value); if (error) setError(''); }} error={error} leftIcon={<Mail className="w-4 h-4" />} className={authInputClass} required />
          <Button type="submit" variant="primary" size="md" className={authButtonClass} isLoading={isLoading} rightIcon={<KeyRound className="w-4 h-4" />}>Send reset link</Button>
        </form>
      )}
    </AuthShell>
  );
};
