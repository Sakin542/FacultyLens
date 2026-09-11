import React, { useState, useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { useAuth } from '@/context/AuthContext';
import { ApiError } from '@/services/api';
import { isValidEmail } from '@/utils/validation';
import { AuthAlert, AuthShell, AuthSuccess, authButtonClass, authInputClass, authLinkClass } from '@/components/landing/AuthShell';
import { Mail, Lock, ArrowRight, Eye, EyeOff, ShieldCheck } from 'lucide-react';

export const Login: React.FC = () => {
  const navigate = useNavigate();
  const { login, isAuthenticated, loading } = useAuth();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [rememberMe, setRememberMe] = useState(true);
  const [errors, setErrors] = useState<{ email?: string; password?: string }>({});
  const [authError, setAuthError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [loginSuccess, setLoginSuccess] = useState(false);

  // If already authenticated, redirect to dashboard
  useEffect(() => {
    if (!loading && isAuthenticated) {
      navigate('/dashboard', { replace: true });
    }
  }, [isAuthenticated, loading, navigate]);

  const validate = () => {
    const newErrors: { email?: string; password?: string } = {};
    if (!email.trim()) {
      newErrors.email = 'University email is required';
    } else if (!isValidEmail(email)) {
      newErrors.email = 'Please provide a valid university email address';
    }
    if (!password) {
      newErrors.password = 'Password is required';
    } else if (password.length < 6) {
      newErrors.password = 'Password must be at least 6 characters';
    }
    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setAuthError(null);
    if (!validate()) return;

    setIsLoading(true);

    try {
      await login({
        email: email.trim(),
        password,
        remember: rememberMe,
      });

      setLoginSuccess(true);
      setTimeout(() => {
        navigate('/dashboard');
      }, 400);
    } catch (err: unknown) {
      setIsLoading(false);
      // 422: show the server's field messages on the inputs instead of a generic banner
      if (err instanceof ApiError && err.status === 422 && err.errors) {
        setErrors({ email: err.errors.email?.[0], password: err.errors.password?.[0] });
        return;
      }
      const errorMessage = err instanceof Error ? err.message : 'Invalid email or password.';
      setAuthError(errorMessage);
    }
  };

  return (
    <AuthShell
      eyebrow="Institutional access · Welcome back"
      title="Sign In"
      subtitle="Sign in with your registered university email to open your faculty workspace."
      footer={<>Don&apos;t have an account? <Link to="/register" className={authLinkClass}>Create a faculty account</Link></>}
    >
      {authError && <AuthAlert title="Authentication failed" message={authError} />}

      {loginSuccess ? (
        <AuthSuccess message="Signed in. Taking you to your dashboard…" />
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <Input
            label="University Email"
            type="email"
            placeholder="faculty@example.com"
            value={email}
            onChange={(e) => {
              setEmail(e.target.value);
              if (errors.email) setErrors({ ...errors, email: '' });
              if (authError) setAuthError(null);
            }}
            error={errors.email}
            leftIcon={<Mail className="w-4 h-4" />}
            className={authInputClass}
            required
          />

          <Input
            label="Password"
            type={showPassword ? 'text' : 'password'}
            placeholder="••••••••"
            value={password}
            onChange={(e) => {
              setPassword(e.target.value);
              if (errors.password) setErrors({ ...errors, password: '' });
              if (authError) setAuthError(null);
            }}
            error={errors.password}
            leftIcon={<Lock className="w-4 h-4" />}
            rightIcon={
              <button type="button" tabIndex={-1} onClick={() => setShowPassword(!showPassword)} className="text-sage-400 hover:text-sage-800 focus:outline-none" aria-label={showPassword ? 'Hide password' : 'Show password'}>
                {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
              </button>
            }
            className={authInputClass}
            required
          />

          <div className="flex items-center justify-between text-xs pt-1">
            <label className="flex items-center gap-2 cursor-pointer text-sage-700">
              <input type="checkbox" checked={rememberMe} onChange={(e) => setRememberMe(e.target.checked)} className="rounded border-sage-300 text-sage-700 focus:ring-sage-600" />
              <span>Remember me</span>
            </label>
            <Link to="/forgot-password" className={authLinkClass}>Forgot password?</Link>
          </div>

          <Button type="submit" variant="primary" size="md" className={`${authButtonClass} mt-2`} isLoading={isLoading} disabled={isLoading} rightIcon={<ArrowRight className="w-4 h-4" />}>
            {isLoading ? 'Signing in…' : 'Sign In'}
          </Button>

          <p className="flex items-center justify-center gap-1.5 text-[11px] text-sage-400 pt-1"><ShieldCheck className="w-3.5 h-3.5" aria-hidden="true" />Secure, cookie-based session · your course data stays private</p>
        </form>
      )}
    </AuthShell>
  );
};
