import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card, CardHeader, CardTitle, CardContent, CardDescription } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { Badge } from '@/components/common/Badge';
import { useAuth } from '@/context/AuthContext';
import { authService } from '@/services/authService';
import { ApiError } from '@/services/api';
import { ConfirmSignOutDialog } from '@/components/common/ConfirmSignOutDialog';
import {
  User,
  LogOut,
  Save,
  CheckCircle2,
  AlertCircle,
  Lock,
  Mail,
  Building,
  Award,
} from 'lucide-react';

const MIN_PASSWORD_LENGTH = 6;

type FieldErrors = Record<string, string>;

const extractApiError = (err: unknown, fallback: string): { message: string; fields: FieldErrors } => {
  const fields: FieldErrors = {};
  if (err instanceof ApiError) {
    Object.entries(err.errors ?? {}).forEach(([key, msgs]) => {
      if (msgs?.[0]) fields[key] = msgs[0];
    });
    return { message: err.message || fallback, fields };
  }
  return { message: err instanceof Error && err.message ? err.message : fallback, fields };
};

const StatusNote: React.FC<{ tone: 'success' | 'error'; children: React.ReactNode }> = ({ tone, children }) => (
  <span
    role={tone === 'error' ? 'alert' : 'status'}
    className={
      tone === 'success'
        ? 'text-xs text-[#166534] font-medium flex items-center gap-1.5 bg-[#F0FDF4] px-3 py-1.5 rounded-lg border border-[#BBF7D0]'
        : 'text-xs text-[#991B1B] font-medium flex items-center gap-1.5 bg-[#FEF2F2] px-3 py-1.5 rounded-lg border border-[#FECACA]'
    }
  >
    {tone === 'success' ? <CheckCircle2 className="w-4 h-4 shrink-0" /> : <AlertCircle className="w-4 h-4 shrink-0" />}
    {children}
  </span>
);

export const Settings: React.FC = () => {
  const navigate = useNavigate();
  const { user, logout, refreshUser } = useAuth();

  const [profile, setProfile] = useState({
    fullName: user?.name || user?.fullName || '',
    department: user?.department || '',
    designation: user?.designation || '',
  });

  useEffect(() => {
    if (user) {
      setProfile({
        fullName: user.name || user.fullName || '',
        department: user.department || '',
        designation: user.designation || '',
      });
    }
  }, [user]);

  const [passwords, setPasswords] = useState({
    currentPassword: '',
    newPassword: '',
    confirmPassword: '',
  });

  const [profileSaving, setProfileSaving] = useState(false);
  const [profileSuccess, setProfileSuccess] = useState<string | null>(null);
  const [profileError, setProfileError] = useState<string | null>(null);
  const [profileFieldErrors, setProfileFieldErrors] = useState<FieldErrors>({});

  const [passwordSaving, setPasswordSaving] = useState(false);
  const [passwordSuccess, setPasswordSuccess] = useState<string | null>(null);
  const [passwordError, setPasswordError] = useState<string | null>(null);
  const [passwordFieldErrors, setPasswordFieldErrors] = useState<FieldErrors>({});

  const profileDirty =
    profile.fullName.trim() !== (user?.name || user?.fullName || '') ||
    profile.department.trim() !== (user?.department || '') ||
    profile.designation.trim() !== (user?.designation || '');

  const handleSaveProfile = async (e: React.FormEvent) => {
    e.preventDefault();
    setProfileSuccess(null);
    setProfileError(null);

    const name = profile.fullName.trim();
    const department = profile.department.trim();
    const designation = profile.designation.trim();
    const fieldErrors: FieldErrors = {};
    if (!name) fieldErrors.name = 'Full name is required.';
    if (!department) fieldErrors.department = 'Department is required.';
    if (!designation) fieldErrors.designation = 'Designation is required.';
    setProfileFieldErrors(fieldErrors);
    if (Object.keys(fieldErrors).length > 0) return;

    setProfileSaving(true);
    try {
      const response = await authService.updateProfile({ name, department, designation });
      await refreshUser();
      setProfileSuccess(response.message || 'Profile details updated successfully');
    } catch (err) {
      const { message, fields } = extractApiError(err, 'Could not update your profile. Please try again.');
      setProfileError(message);
      setProfileFieldErrors(fields);
    } finally {
      setProfileSaving(false);
    }
  };

  const handleChangePassword = async (e: React.FormEvent) => {
    e.preventDefault();
    setPasswordSuccess(null);
    setPasswordError(null);

    const fieldErrors: FieldErrors = {};
    if (!passwords.currentPassword) fieldErrors.current_password = 'Enter your current password.';
    if (passwords.newPassword.length < MIN_PASSWORD_LENGTH) {
      fieldErrors.password = `New password must be at least ${MIN_PASSWORD_LENGTH} characters.`;
    } else if (passwords.currentPassword && passwords.newPassword === passwords.currentPassword) {
      fieldErrors.password = 'New password must be different from the current password.';
    }
    if (passwords.confirmPassword !== passwords.newPassword) {
      fieldErrors.password_confirmation = 'Passwords do not match.';
    }
    setPasswordFieldErrors(fieldErrors);
    if (Object.keys(fieldErrors).length > 0) return;

    setPasswordSaving(true);
    try {
      const response = await authService.changePassword({
        current_password: passwords.currentPassword,
        password: passwords.newPassword,
        password_confirmation: passwords.confirmPassword,
      });
      setPasswords({ currentPassword: '', newPassword: '', confirmPassword: '' });
      setPasswordSuccess(response.message || 'Password updated successfully');
    } catch (err) {
      const { message, fields } = extractApiError(err, 'Could not update your password. Please try again.');
      setPasswordError(message);
      setPasswordFieldErrors(fields);
    } finally {
      setPasswordSaving(false);
    }
  };

  const [signOutOpen, setSignOutOpen] = useState(false);
  const [signingOut, setSigningOut] = useState(false);

  const handleLogout = async () => {
    setSigningOut(true);
    try {
      await logout();
    } finally {
      setSigningOut(false);
      setSignOutOpen(false);
    }
    navigate('/login', { replace: true });
  };

  return (
    <div className="space-y-8 max-w-4xl">
      <ConfirmSignOutDialog open={signOutOpen} busy={signingOut} email={user?.email} onConfirm={handleLogout} onCancel={() => setSignOutOpen(false)} />
      {/* Profile Section */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle>Faculty Profile</CardTitle>
              <CardDescription>Manage your institutional identification and department details</CardDescription>
            </div>
            <Badge variant="outline" className="font-mono">Faculty ID: {user?.id ?? '—'}</Badge>
          </div>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSaveProfile} className="space-y-4" noValidate>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <Input
                label="Full Name"
                name="name"
                autoComplete="name"
                value={profile.fullName}
                onChange={(e) => {
                  setProfile({ ...profile, fullName: e.target.value });
                  setProfileSuccess(null);
                }}
                leftIcon={<User className="w-4 h-4" />}
                error={profileFieldErrors.name}
                required
              />

              <Input
                label="University Email"
                type="email"
                name="email"
                value={user?.email || ''}
                readOnly
                disabled
                leftIcon={<Mail className="w-4 h-4" />}
                helperText="Your email is your institutional identity and cannot be changed."
              />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <Input
                label="Department"
                name="department"
                autoComplete="organization"
                value={profile.department}
                onChange={(e) => {
                  setProfile({ ...profile, department: e.target.value });
                  setProfileSuccess(null);
                }}
                leftIcon={<Building className="w-4 h-4" />}
                error={profileFieldErrors.department}
                required
              />

              <Input
                label="Designation"
                name="designation"
                autoComplete="organization-title"
                value={profile.designation}
                onChange={(e) => {
                  setProfile({ ...profile, designation: e.target.value });
                  setProfileSuccess(null);
                }}
                leftIcon={<Award className="w-4 h-4" />}
                error={profileFieldErrors.designation}
                required
              />
            </div>

            <div className="pt-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              <div className="min-w-0">
                {profileSuccess && <StatusNote tone="success">{profileSuccess}</StatusNote>}
                {profileError && <StatusNote tone="error">{profileError}</StatusNote>}
              </div>

              <Button
                type="submit"
                variant="primary"
                size="sm"
                isLoading={profileSaving}
                disabled={!profileDirty}
                leftIcon={<Save className="w-4 h-4" />}
                className="self-end sm:self-auto"
              >
                {profileSaving ? 'Saving…' : 'Save Changes'}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      {/* Account Security */}
      <Card>
        <CardHeader>
          <CardTitle>Account & Security</CardTitle>
          <CardDescription>Update your institutional password and manage login credentials</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleChangePassword} className="space-y-4" noValidate>
            <Input
              label="Current Password"
              type="password"
              name="current_password"
              autoComplete="current-password"
              placeholder="••••••••"
              value={passwords.currentPassword}
              onChange={(e) => {
                setPasswords({ ...passwords, currentPassword: e.target.value });
                setPasswordSuccess(null);
              }}
              leftIcon={<Lock className="w-4 h-4" />}
              error={passwordFieldErrors.current_password}
              required
            />

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <Input
                label="New Password"
                type="password"
                name="password"
                autoComplete="new-password"
                placeholder="••••••••"
                value={passwords.newPassword}
                onChange={(e) => {
                  setPasswords({ ...passwords, newPassword: e.target.value });
                  setPasswordSuccess(null);
                }}
                leftIcon={<Lock className="w-4 h-4" />}
                error={passwordFieldErrors.password}
                helperText={`At least ${MIN_PASSWORD_LENGTH} characters.`}
                required
              />

              <Input
                label="Confirm New Password"
                type="password"
                name="password_confirmation"
                autoComplete="new-password"
                placeholder="••••••••"
                value={passwords.confirmPassword}
                onChange={(e) => {
                  setPasswords({ ...passwords, confirmPassword: e.target.value });
                  setPasswordSuccess(null);
                }}
                leftIcon={<Lock className="w-4 h-4" />}
                error={passwordFieldErrors.password_confirmation}
                required
              />
            </div>

            <div className="pt-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              <div className="min-w-0">
                {passwordSuccess && <StatusNote tone="success">{passwordSuccess}</StatusNote>}
                {passwordError && <StatusNote tone="error">{passwordError}</StatusNote>}
              </div>

              <Button
                type="submit"
                variant="outline"
                size="sm"
                isLoading={passwordSaving}
                disabled={!passwords.currentPassword || !passwords.newPassword || !passwords.confirmPassword}
                className="self-end sm:self-auto"
              >
                {passwordSaving ? 'Updating…' : 'Update Password'}
              </Button>
            </div>
          </form>
        </CardContent>
      </Card>

      {/* Sign Out Section */}
      <Card className="border-[#FECACA] bg-[#FEF2F2]/20">
        <CardHeader className="border-[#FECACA]">
          <CardTitle className="text-[#991B1B]">Session & Sign Out</CardTitle>
          <CardDescription>Log out of your FacultyLens account on this device</CardDescription>
        </CardHeader>
        <CardContent className="flex items-center justify-between pt-2">
          <p className="text-xs text-sage-500">
            You are signed in as <strong>{user?.email || 'faculty account'}</strong>
          </p>
          <Button
            variant="danger"
            size="sm"
            leftIcon={<LogOut className="w-4 h-4" />}
            onClick={() => setSignOutOpen(true)}
          >
            Sign Out
          </Button>
        </CardContent>
      </Card>
    </div>
  );
};

