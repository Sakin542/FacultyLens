import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { Card } from '@/components/common/Card';
import {
  Mail,
  Lock,
  User,
  Building,
  Award,
  ArrowRight,
  Eye,
  EyeOff,
  CheckCircle2,
} from 'lucide-react';

export const Register: React.FC = () => {
  const navigate = useNavigate();
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
  const [isLoading, setIsLoading] = useState(false);
  const [registerSuccess, setRegisterSuccess] = useState(false);

  const calculatePasswordStrength = (pass: string): { label: string; percent: number; color: string } => {
    if (!pass) return { label: '', percent: 0, color: 'bg-[#E5E5E5]' };
    let score = 0;
    if (pass.length >= 6) score += 25;
    if (pass.length >= 8) score += 25;
    if (/[A-Z]/.test(pass)) score += 25;
    if (/[0-9!@#$%^&*]/.test(pass)) score += 25;

    if (score <= 25) return { label: 'Weak', percent: 25, color: 'bg-[#DC2626]' };
    if (score <= 50) return { label: 'Fair', percent: 50, color: 'bg-[#D97706]' };
    if (score <= 75) return { label: 'Good', percent: 75, color: 'bg-[#111111]' };
    return { label: 'Strong', percent: 100, color: 'bg-[#16A34A]' };
  };

  const strength = calculatePasswordStrength(formData.password);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    const { name, value, type } = e.target;
    const val = type === 'checkbox' ? (e.target as HTMLInputElement).checked : value;
    setFormData({ ...formData, [name]: val });
    if (errors[name]) {
      setErrors({ ...errors, [name]: '' });
    }
  };

  const validate = () => {
    const errs: Record<string, string> = {};
    if (!formData.fullName.trim()) errs.fullName = 'Full Name is required';
    if (!formData.email.trim()) {
      errs.email = 'University email is required';
    } else if (!/\S+@\S+\.\S+/.test(formData.email)) {
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

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!validate()) return;

    setIsLoading(true);
    // Simulate account provisioning
    setTimeout(() => {
      localStorage.setItem('facultylens_token', 'mock-new-faculty-session-token');
      localStorage.setItem('facultylens_user_name', formData.fullName);
      localStorage.setItem('facultylens_user_email', formData.email);
      setIsLoading(false);
      setRegisterSuccess(true);
      setTimeout(() => {
        navigate('/dashboard');
      }, 600);
    }, 700);
  };

  return (
    <div className="min-h-[calc(100vh-14rem)] flex items-center justify-center p-4 sm:p-6 lg:p-12">
      <Card className="w-full max-w-xl bg-white rounded-2xl border border-[#E5E5E5] shadow-card p-8 sm:p-10 space-y-6">
        {/* Header without internal logo/name */}
        <div className="space-y-1 text-left">
          <h2 className="text-2xl sm:text-3xl font-extrabold text-[#111111] tracking-tight">Create Faculty Account</h2>
          <p className="text-xs text-[#737373]">
            Fill in your university department details to register
          </p>
        </div>

        {registerSuccess ? (
          <div className="p-4 rounded-xl bg-[#F0FDF4] border border-[#BBF7D0] flex items-center gap-3 text-xs text-[#166534] font-medium animate-pulse">
            <CheckCircle2 className="w-5 h-5 shrink-0 text-[#16A34A]" />
            <span>Account created successfully! Redirecting to your dashboard...</span>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4" noValidate>
            <Input
              label="Full Name"
              name="fullName"
              placeholder="Dr. Tariqul Islam"
              value={formData.fullName}
              onChange={handleChange}
              error={errors.fullName}
              leftIcon={<User className="w-4 h-4" />}
              required
            />

            <Input
              label="University Email"
              name="email"
              type="email"
              placeholder="faculty@university.edu"
              value={formData.email}
              onChange={handleChange}
              error={errors.email}
              leftIcon={<Mail className="w-4 h-4" />}
              required
            />

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
              <Input
                label="Department"
                name="department"
                placeholder="Computer Science"
                value={formData.department}
                onChange={handleChange}
                error={errors.department}
                leftIcon={<Building className="w-4 h-4" />}
                required
              />

              <Input
                label="Designation"
                name="designation"
                placeholder="Associate Professor"
                value={formData.designation}
                onChange={handleChange}
                error={errors.designation}
                leftIcon={<Award className="w-4 h-4" />}
                required
              />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
              <Input
                label="Password"
                name="password"
                type={showPassword ? 'text' : 'password'}
                placeholder="••••••••"
                value={formData.password}
                onChange={handleChange}
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

              <Input
                label="Confirm Password"
                name="confirmPassword"
                type={showConfirmPassword ? 'text' : 'password'}
                placeholder="••••••••"
                value={formData.confirmPassword}
                onChange={handleChange}
                error={errors.confirmPassword}
                leftIcon={<Lock className="w-4 h-4" />}
                rightIcon={
                  <button
                    type="button"
                    tabIndex={-1}
                    onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                    className="text-[#737373] hover:text-[#111111] focus:outline-none"
                    aria-label={showConfirmPassword ? 'Hide confirm password' : 'Show confirm password'}
                  >
                    {showConfirmPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                }
                required
              />
            </div>

            {/* Password strength visualizer */}
            {formData.password && (
              <div className="space-y-1 pt-0.5">
                <div className="flex justify-between text-[11px]">
                  <span className="text-[#737373]">Password Strength:</span>
                  <span className="font-semibold text-[#111111]">{strength.label}</span>
                </div>
                <div className="w-full bg-[#E5E5E5] h-1.5 rounded-full overflow-hidden">
                  <div
                    className={`h-full ${strength.color} transition-all duration-300`}
                    style={{ width: `${strength.percent}%` }}
                  />
                </div>
              </div>
            )}

            {/* Terms Checkbox */}
            <div className="pt-1">
              <label className="flex items-start gap-2.5 cursor-pointer text-xs text-[#737373]">
                <input
                  type="checkbox"
                  name="agreeTerms"
                  checked={formData.agreeTerms}
                  onChange={handleChange}
                  className="mt-0.5 rounded border-[#E5E5E5] text-[#111111] focus:ring-[#111111]"
                />
                <span>
                  I agree to the University Academic Governance & Faculty AI Usage Guidelines.
                </span>
              </label>
              {errors.agreeTerms && (
                <p className="text-[11px] text-[#DC2626] font-medium mt-1">{errors.agreeTerms}</p>
              )}
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
                Create Account
              </Button>
            </div>
          </form>
        )}

        <div className="pt-4 border-t border-[#E5E5E5] text-center">
          <p className="text-xs text-[#737373]">
            Already have an account?{' '}
            <Link to="/login" className="font-bold text-[#111111] hover:underline">
              Sign In
            </Link>
          </p>
        </div>
      </Card>
    </div>
  );
};
