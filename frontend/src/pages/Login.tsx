import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { Card } from '@/components/common/Card';
import { authenticateUser } from '@/utils/auth';
import {
  Mail,
  Lock,
  ArrowRight,
  Eye,
  EyeOff,
  CheckCircle2,
  AlertCircle,
  ShieldCheck,
} from 'lucide-react';

export const Login: React.FC = () => {
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [rememberMe, setRememberMe] = useState(true);
  const [errors, setErrors] = useState<{ email?: string; password?: string }>({});
  const [authError, setAuthError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [loginSuccess, setLoginSuccess] = useState(false);

  const validate = () => {
    const newErrors: { email?: string; password?: string } = {};
    if (!email.trim()) {
      newErrors.email = 'University email is required';
    } else if (!/\S+@\S+\.\S+/.test(email)) {
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

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setAuthError(null);
    if (!validate()) return;

    setIsLoading(true);

    setTimeout(() => {
      const result = authenticateUser(email, password);

      if (!result.success) {
        setIsLoading(false);
        setAuthError(result.message || 'Authentication failed. Please check your credentials.');
        return;
      }

      if (rememberMe) {
        localStorage.setItem('facultylens_remember_me', 'true');
      }

      setIsLoading(false);
      setLoginSuccess(true);
      setTimeout(() => {
        navigate('/dashboard');
      }, 500);
    }, 600);
  };

  return (
    <div className="min-h-[calc(100vh-14rem)] flex items-center justify-center p-4 sm:p-6 lg:p-12">
      <Card className="w-full max-w-md bg-white rounded-2xl border border-[#E5E5E5] shadow-card p-8 sm:p-10 space-y-6">
        {/* Header without internal logo/name */}
        <div className="space-y-1 text-left">
          <div className="flex items-center gap-2 mb-1">
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-mono font-semibold uppercase tracking-wider bg-[#F7F7F5] text-[#737373] border border-[#E5E5E5]">
              <ShieldCheck className="w-3.5 h-3.5 text-[#111111]" /> Institutional Access
            </span>
          </div>
          <h2 className="text-2xl sm:text-3xl font-extrabold text-[#111111] tracking-tight">Sign In</h2>
          <p className="text-xs text-[#737373]">
            Enter your registered university email to access your faculty portal
          </p>
        </div>

        {authError && (
          <div className="p-3.5 rounded-xl bg-[#FEF2F2] border border-[#FECACA] flex items-start gap-2.5 text-xs text-[#991B1B] animate-in fade-in duration-200">
            <AlertCircle className="w-4 h-4 shrink-0 text-[#DC2626] mt-0.5" />
            <div className="space-y-1">
              <span className="font-semibold block">Access Restricted</span>
              <span className="text-[11px] leading-relaxed block">{authError}</span>
            </div>
          </div>
        )}

        {loginSuccess ? (
          <div className="p-4 rounded-xl bg-[#F0FDF4] border border-[#BBF7D0] flex items-center gap-3 text-xs text-[#166534] font-medium animate-pulse">
            <CheckCircle2 className="w-5 h-5 shrink-0 text-[#16A34A]" />
            <span>Authentication successful. Redirecting to your dashboard...</span>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4" noValidate>
            <Input
              label="University Email"
              type="email"
              placeholder="faculty@university.edu"
              value={email}
              onChange={(e) => {
                setEmail(e.target.value);
                if (errors.email) setErrors({ ...errors, email: '' });
                if (authError) setAuthError(null);
              }}
              error={errors.email}
              leftIcon={<Mail className="w-4 h-4" />}
              required
            />

            <div className="relative">
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
                  <button
                    type="button"
                    tabIndex={-1}
                    onClick={() => setShowPassword(!showPassword)}
                    className="text-[#737373] hover:text-[#111111] focus:outline-none"
                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                  >
                    {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                }
                required
              />
            </div>

            <div className="flex items-center justify-between text-xs pt-1">
              <label className="flex items-center gap-2 cursor-pointer text-[#737373]">
                <input
                  type="checkbox"
                  checked={rememberMe}
                  onChange={(e) => setRememberMe(e.target.checked)}
                  className="rounded border-[#E5E5E5] text-[#111111] focus:ring-[#111111]"
                />
                <span className="font-medium text-[#262626]">Remember me</span>
              </label>

              <Link
                to="/forgot-password"
                className="font-semibold text-[#111111] hover:underline"
              >
                Forgot Password?
              </Link>
            </div>

            <div className="pt-2">
              <Button
                type="submit"
                variant="primary"
                size="md"
                className="w-full justify-center text-sm font-semibold tracking-wide"
                isLoading={isLoading}
                rightIcon={<ArrowRight className="w-4 h-4" />}
              >
                Sign In
              </Button>
            </div>
          </form>
        )}

        <div className="pt-4 border-t border-[#E5E5E5] text-center">
          <p className="text-xs text-[#737373]">
            Don&apos;t have an account?{' '}
            <Link to="/register" className="font-bold text-[#111111] hover:underline">
              Create Account
            </Link>
          </p>
        </div>
      </Card>
    </div>
  );
};
