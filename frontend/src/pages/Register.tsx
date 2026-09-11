import React, { useState, useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { useAuth } from '@/context/AuthContext';
import { ApiError } from '@/services/api';
import { isValidEmail } from '@/utils/validation';
import { AuthAlert, AuthShell, AuthSuccess, authButtonClass, authInputClass, authLinkClass } from '@/components/landing/AuthShell';
import { Mail, Lock, User as UserIcon, Building, Award, ArrowRight, Eye, EyeOff, ShieldCheck } from 'lucide-react';

export const Register: React.FC = () => {
  const navigate = useNavigate();
  const { register, isAuthenticated, loading } = useAuth();

  const [formData, setFormData] = useState({
    fullName: '',
    email: '',
    department: 'Computer Science & Engineering',
    designation: 'Assistant Professor',
    password: '',
    confirmPassword: '',
    agreeTerms: true,
  });

  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [registerError, setRegisterError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [registerSuccess, setRegisterSuccess] = useState(false);

  // If already authenticated, redirect to dashboard
  useEffect(() => {
    if (!loading && isAuthenticated) {
      navigate('/dashboard', { replace: true });
    }
  }, [isAuthenticated, loading, navigate]);

  const calculatePasswordStrength = (pass: string): { label: string; percent: number; color: string } => {
    if (!pass) return { label: '', percent: 0, color: 'bg-sage-200' };
    let score = 0;
    if (pass.length >= 6) score += 25;
    if (pass.length >= 8) score += 25;
    if (/[A-Z]/.test(pass)) score += 25;
    if (/[0-9!@#$%^&*]/.test(pass)) score += 25;

    if (score <= 25) return { label: 'Weak', percent: 25, color: 'bg-[#DC2626]' };
    if (score <= 50) return { label: 'Fair', percent: 50, color: 'bg-[#D97706]' };
    if (score <= 75) return { label: 'Good', percent: 75, color: 'bg-sage-500' };
    return { label: 'Strong', percent: 100, color: 'bg-sage-700' };
  };

  const strength = calculatePasswordStrength(formData.password);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value, type } = e.target;
    const val = type === 'checkbox' ? (e.target as HTMLInputElement).checked : value;
    setFormData({ ...formData, [name]: val });
    if (errors[name]) {
      setErrors({ ...errors, [name]: '' });
    }
    if (registerError) {
      setRegisterError(null);
    }
  };

  const validate = () => {
    const errs: Record<string, string> = {};
    if (!formData.fullName.trim()) errs.fullName = 'Full Name is required';
    if (!formData.email.trim()) {
      errs.email = 'University email is required';
    } else if (!isValidEmail(formData.email)) {
      errs.email = 'Valid university email is required';
    }
    if (!formData.department.trim()) errs.department = 'Department is required';
    if (!formData.designation.trim()) errs.designation = 'Designation is required';
    if (!formData.password) {
      errs.password = 'Password is required';
    } else if (formData.password.length < 6) {
      errs.password = 'Password must be at least 6 characters';
    }
    if (formData.password !== formData.confirmPassword) {
      errs.confirmPassword = 'Passwords do not match';
    }
    if (!formData.agreeTerms) {
      errs.agreeTerms = 'You must accept the academic governance terms';
    }
    setErrors(errs);
    return Object.keys(errs).length === 0;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setRegisterError(null);
    if (!validate()) return;

    setIsLoading(true);

    try {
      await register({
        name: formData.fullName.trim(),
        email: formData.email.trim(),
        department: formData.department.trim(),
        designation: formData.designation.trim(),
        password: formData.password,
        password_confirmation: formData.confirmPassword,
      });

      setRegisterSuccess(true);
      setTimeout(() => {
        navigate('/dashboard');
      }, 500);
    } catch (err: unknown) {
      setIsLoading(false);
      // 422: map Laravel field names back onto the form fields
      if (err instanceof ApiError && err.status === 422 && err.errors) {
        const map: Record<string, string> = { name: 'fullName', email: 'email', department: 'department', designation: 'designation', password: 'password', password_confirmation: 'confirmPassword' };
        const fieldErrors: Record<string, string> = {};
        Object.entries(err.errors).forEach(([k, v]) => { fieldErrors[map[k] ?? k] = v[0]; });
        setErrors(fieldErrors);
        return;
      }
      const errorMessage = err instanceof Error ? err.message : 'Registration failed. Please check your details.';
      setRegisterError(errorMessage);
    }
  };

  return (
    <AuthShell
      wide
      eyebrow="Faculty registration"
      title="Create Faculty Account"
      subtitle="Add your department details to provision a private academic workspace for your courses."
      footer={<>Already have an account? <Link to="/login" className={authLinkClass}>Sign in</Link></>}
    >
      {registerError && <AuthAlert title="Registration issue" message={registerError} />}

      {registerSuccess ? (
        <AuthSuccess message="Account created. Setting up your dashboard…" />
      ) : (
        <form onSubmit={handleSubmit} className="space-y-4" noValidate>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            <Input label="Full Name" name="fullName" placeholder="Dr. Tariqul Islam" value={formData.fullName} onChange={handleChange} error={errors.fullName} leftIcon={<UserIcon className="w-4 h-4" />} className={authInputClass} required />
            <Input label="University Email" name="email" type="email" placeholder="faculty@university.edu" value={formData.email} onChange={handleChange} error={errors.email} leftIcon={<Mail className="w-4 h-4" />} className={authInputClass} required />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            <Input label="Department" name="department" placeholder="Computer Science" value={formData.department} onChange={handleChange} error={errors.department} leftIcon={<Building className="w-4 h-4" />} className={authInputClass} required />
            <Input label="Designation" name="designation" placeholder="Associate Professor" value={formData.designation} onChange={handleChange} error={errors.designation} leftIcon={<Award className="w-4 h-4" />} className={authInputClass} required />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
            <Input
              label="Password" name="password" type={showPassword ? 'text' : 'password'} placeholder="••••••••" value={formData.password} onChange={handleChange} error={errors.password}
              leftIcon={<Lock className="w-4 h-4" />} className={authInputClass} required
              rightIcon={<button type="button" tabIndex={-1} onClick={() => setShowPassword(!showPassword)} className="text-sage-400 hover:text-sage-800 focus:outline-none" aria-label={showPassword ? 'Hide password' : 'Show password'}>{showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}</button>}
            />
            <Input
              label="Confirm Password" name="confirmPassword" type={showConfirmPassword ? 'text' : 'password'} placeholder="••••••••" value={formData.confirmPassword} onChange={handleChange} error={errors.confirmPassword}
              leftIcon={<Lock className="w-4 h-4" />} className={authInputClass} required
              rightIcon={<button type="button" tabIndex={-1} onClick={() => setShowConfirmPassword(!showConfirmPassword)} className="text-sage-400 hover:text-sage-800 focus:outline-none" aria-label={showConfirmPassword ? 'Hide confirm password' : 'Show confirm password'}>{showConfirmPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}</button>}
            />
          </div>

          {formData.password && (
            <div className="space-y-1 pt-0.5" aria-live="polite">
              <div className="flex justify-between text-[11px]">
                <span className="text-sage-500">Password strength</span>
                <span className="font-semibold text-sage-800">{strength.label}</span>
              </div>
              <div className="w-full bg-sage-100 h-1.5 rounded-full overflow-hidden">
                <div className={`h-full ${strength.color} transition-all duration-500`} style={{ width: `${strength.percent}%` }} />
              </div>
            </div>
          )}

          <div className="pt-1">
            <label className="flex items-start gap-2.5 cursor-pointer text-xs text-sage-600">
              <input type="checkbox" name="agreeTerms" checked={formData.agreeTerms} onChange={handleChange} className="mt-0.5 rounded border-sage-300 text-sage-700 focus:ring-sage-600" />
              <span>I agree to the University Academic Governance &amp; Faculty AI Usage Guidelines.</span>
            </label>
            {errors.agreeTerms && <p className="text-[11px] text-[#DC2626] font-medium mt-1">{errors.agreeTerms}</p>}
          </div>

          <Button type="submit" variant="primary" size="md" className={`${authButtonClass} mt-2`} isLoading={isLoading} disabled={isLoading} rightIcon={<ArrowRight className="w-4 h-4" />}>
            {isLoading ? 'Creating account…' : 'Create Account'}
          </Button>

          <p className="flex items-center justify-center gap-1.5 text-[11px] text-sage-400 pt-1"><ShieldCheck className="w-3.5 h-3.5" aria-hidden="true" />AI assists, faculty decides — nothing in your courses changes without you</p>
        </form>
      )}
    </AuthShell>
  );
};
