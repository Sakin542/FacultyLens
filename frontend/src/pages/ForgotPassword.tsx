import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { Mail, ArrowLeft, KeyRound } from 'lucide-react';
import { AuthShell, AuthSuccess, authButtonClass, authInputClass, authLinkClass } from '@/components/landing/AuthShell';

export const ForgotPassword: React.FC = () => {
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const [isSubmitted, setIsSubmitted] = useState(false);
  const [isLoading, setIsLoading] = useState(false);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!email) {
      setError('University email is required');
      return;
    }
    if (!/\S+@\S+\.\S+/.test(email)) {
      setError('Please provide a valid email address');
      return;
    }
    setError('');
    setIsLoading(true);

    setTimeout(() => {
      setIsLoading(false);
      setIsSubmitted(true);
    }, 600);
  };

  return (
    <AuthShell
      eyebrow="Account recovery"
      title={isSubmitted ? 'Check your inbox.' : 'Forgot your password?'}
      subtitle={isSubmitted ? 'If an account exists with this email, password reset instructions are on their way.' : 'Enter your university email and we\u2019ll send you a password reset link.'}
      footer={<Link to="/login" className={`${authLinkClass} inline-flex items-center gap-1.5`}><ArrowLeft className="w-3.5 h-3.5" aria-hidden="true" />Back to sign in</Link>}
    >
      {isSubmitted ? (
        <AuthSuccess message={`Reset link sent to ${email}`} />
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <Input label="University Email" type="email" placeholder="faculty@university.edu" value={email} onChange={(e) => setEmail(e.target.value)} error={error} leftIcon={<Mail className="w-4 h-4" />} className={authInputClass} required />
          <Button type="submit" variant="primary" size="md" className={authButtonClass} isLoading={isLoading} rightIcon={<KeyRound className="w-4 h-4" />}>Send reset link</Button>
        </form>
      )}
    </AuthShell>
  );
};
