import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { Mail, ArrowLeft, CheckCircle2, KeyRound } from 'lucide-react';

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
    <div className="min-h-[calc(100vh-4rem)] flex items-center justify-center p-4 sm:p-6 lg:p-8">
      <div className="w-full max-w-md bg-white rounded-2xl border border-[#E5E5E5] shadow-card p-8 sm:p-10">
        <div className="w-12 h-12 rounded-xl bg-[#F7F7F5] border border-[#E5E5E5] flex items-center justify-center text-[#111111] mb-6">
          <KeyRound className="w-6 h-6" />
        </div>

        {isSubmitted ? (
          <div className="space-y-6 text-left">
            <div className="flex items-center gap-2 text-[#166534] bg-[#F0FDF4] border border-[#BBF7D0] p-3 rounded-lg text-xs">
              <CheckCircle2 className="w-4 h-4 shrink-0" />
              <span>Reset link sent to <strong>{email}</strong></span>
            </div>

            <div className="space-y-2">
              <h2 className="text-xl font-bold text-[#111111]">Check Your University Inbox</h2>
              <p className="text-xs text-[#737373] leading-relaxed">
                If an account exists with this email, you will receive password reset instructions shortly.
              </p>
            </div>

            <div className="pt-2">
              <Link to="/login">
                <Button variant="outline" size="md" className="w-full justify-center" leftIcon={<ArrowLeft className="w-4 h-4" />}>
                  Back to Login
                </Button>
              </Link>
            </div>
          </div>
        ) : (
          <div className="space-y-6">
            <div className="space-y-1">
              <h2 className="text-2xl font-bold text-[#111111] tracking-tight">Forgot Password?</h2>
              <p className="text-xs text-[#737373] leading-relaxed">
                Enter your university email and we&apos;ll send you a password reset link.
              </p>
            </div>

            <form onSubmit={handleSubmit} className="space-y-4" noValidate>
              <Input
                label="Email"
                type="email"
                placeholder="faculty@university.edu"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                error={error}
                leftIcon={<Mail className="w-4 h-4" />}
                required
              />

              <Button
                type="submit"
                variant="primary"
                size="md"
                className="w-full justify-center"
                isLoading={isLoading}
              >
                Send Reset Link
              </Button>
            </form>

            <div className="pt-4 border-t border-[#E5E5E5] text-center">
              <Link
                to="/login"
                className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#111111] hover:underline"
              >
                <ArrowLeft className="w-3.5 h-3.5" />
                <span>Back to Login</span>
              </Link>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

