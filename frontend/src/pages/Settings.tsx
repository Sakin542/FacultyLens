import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card, CardHeader, CardTitle, CardContent, CardDescription } from '@/components/common/Card';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { Badge } from '@/components/common/Badge';
import { useTheme } from '@/context/ThemeContext';
import { mockCurrentUser } from '@/utils/mockData';
import { getCurrentUser, logoutUser } from '@/utils/auth';
import {
  User,
  LogOut,
  Save,
  CheckCircle2,
  Lock,
  Mail,
  Building,
  Award,
  Sun,
  Moon,
} from 'lucide-react';

export const Settings: React.FC = () => {
  const navigate = useNavigate();
  const { theme, setTheme } = useTheme();
  const currentUser = getCurrentUser() || mockCurrentUser;

  const [profile, setProfile] = useState({
    fullName: currentUser.fullName,
    email: currentUser.email,
    department: currentUser.department,
    designation: currentUser.designation,
  });

  const [passwords, setPasswords] = useState({
    currentPassword: '',
    newPassword: '',
    confirmPassword: '',
  });

  const [saveSuccess, setSaveSuccess] = useState(false);
  const [passwordSuccess, setPasswordSuccess] = useState(false);

  const handleSaveProfile = (e: React.FormEvent) => {
    e.preventDefault();
    setSaveSuccess(true);
    setTimeout(() => setSaveSuccess(false), 3000);
  };

  const handleChangePassword = (e: React.FormEvent) => {
    e.preventDefault();
    if (!passwords.newPassword || passwords.newPassword !== passwords.confirmPassword) {
      alert('Please check your password inputs.');
      return;
    }
    setPasswordSuccess(true);
    setPasswords({ currentPassword: '', newPassword: '', confirmPassword: '' });
    setTimeout(() => setPasswordSuccess(false), 3000);
  };

  const handleLogout = () => {
    logoutUser();
    navigate('/login');
  };

  return (
    <div className="space-y-8 max-w-4xl">
      {/* Appearance Section */}
      <Card>
        <CardHeader>
          <CardTitle>Appearance & Theme</CardTitle>
          <CardDescription>Customize the interface look and feel for optimal academic workflow comfort</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <button
              type="button"
              onClick={() => setTheme('light')}
              className={`p-4 rounded-xl border flex items-center gap-4 transition-all text-left ${
                theme === 'light'
                  ? 'border-[#111111] dark:border-white bg-[#F7F7F5] dark:bg-[#262626] ring-2 ring-[#111111] dark:ring-white'
                  : 'border-[#E5E5E5] dark:border-[#262626] bg-white dark:bg-[#1A1A1A] hover:border-[#111111] dark:hover:border-white'
              }`}
            >
              <div className="w-10 h-10 rounded-lg bg-white border border-[#E5E5E5] flex items-center justify-center text-[#111111] shrink-0 shadow-xs">
                <Sun className="w-5 h-5" />
              </div>
              <div>
                <h4 className="text-sm font-bold text-[#111111] dark:text-white">Academic Light</h4>
                <p className="text-xs text-[#737373] dark:text-[#A3A3A3]">Crisp high-contrast black on off-white</p>
              </div>
            </button>

            <button
              type="button"
              onClick={() => setTheme('dark')}
              className={`p-4 rounded-xl border flex items-center gap-4 transition-all text-left ${
                theme === 'dark'
                  ? 'border-white dark:border-white bg-[#F7F7F5] dark:bg-[#262626] ring-2 ring-[#111111] dark:ring-white'
                  : 'border-[#E5E5E5] dark:border-[#262626] bg-white dark:bg-[#1A1A1A] hover:border-[#111111] dark:hover:border-white'
              }`}
            >
              <div className="w-10 h-10 rounded-lg bg-[#111111] border border-[#262626] flex items-center justify-center text-white shrink-0 shadow-xs">
                <Moon className="w-5 h-5" />
              </div>
              <div>
                <h4 className="text-sm font-bold text-[#111111] dark:text-white">Obsidian Dark</h4>
                <p className="text-xs text-[#737373] dark:text-[#A3A3A3]">Deep carbon background with white typography</p>
              </div>
            </button>
          </div>
        </CardContent>
      </Card>

      {/* Profile Section */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div>
              <CardTitle>Faculty Profile</CardTitle>
              <CardDescription>Manage your institutional identification and department details</CardDescription>
            </div>
            <Badge variant="outline" className="font-mono">Faculty ID: {currentUser.id || mockCurrentUser.id}</Badge>
          </div>
        </CardHeader>
        <CardContent>
          <form onSubmit={handleSaveProfile} className="space-y-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <Input
                label="Full Name"
                value={profile.fullName}
                onChange={(e) => setProfile({ ...profile, fullName: e.target.value })}
                leftIcon={<User className="w-4 h-4" />}
                required
              />

              <Input
                label="University Email"
                type="email"
                value={profile.email}
                onChange={(e) => setProfile({ ...profile, email: e.target.value })}
                leftIcon={<Mail className="w-4 h-4" />}
                required
              />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <Input
                label="Department"
                value={profile.department}
                onChange={(e) => setProfile({ ...profile, department: e.target.value })}
                leftIcon={<Building className="w-4 h-4" />}
                required
              />

              <Input
                label="Designation"
                value={profile.designation}
                onChange={(e) => setProfile({ ...profile, designation: e.target.value })}
                leftIcon={<Award className="w-4 h-4" />}
                required
              />
            </div>

            <div className="pt-3 flex items-center justify-between">
              {saveSuccess ? (
                <span className="text-xs text-[#166534] font-medium flex items-center gap-1.5 bg-[#F0FDF4] px-3 py-1.5 rounded-lg border border-[#BBF7D0]">
                  <CheckCircle2 className="w-4 h-4" /> Profile details updated successfully
                </span>
              ) : <div />}

              <Button
                type="submit"
                variant="primary"
                size="sm"
                leftIcon={<Save className="w-4 h-4" />}
              >
                Save Changes
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
          <form onSubmit={handleChangePassword} className="space-y-4">
            <Input
              label="Current Password"
              type="password"
              placeholder="••••••••"
              value={passwords.currentPassword}
              onChange={(e) => setPasswords({ ...passwords, currentPassword: e.target.value })}
              leftIcon={<Lock className="w-4 h-4" />}
            />

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <Input
                label="New Password"
                type="password"
                placeholder="••••••••"
                value={passwords.newPassword}
                onChange={(e) => setPasswords({ ...passwords, newPassword: e.target.value })}
                leftIcon={<Lock className="w-4 h-4" />}
              />

              <Input
                label="Confirm New Password"
                type="password"
                placeholder="••••••••"
                value={passwords.confirmPassword}
                onChange={(e) => setPasswords({ ...passwords, confirmPassword: e.target.value })}
                leftIcon={<Lock className="w-4 h-4" />}
              />
            </div>

            <div className="pt-3 flex items-center justify-between">
              {passwordSuccess ? (
                <span className="text-xs text-[#166534] font-medium flex items-center gap-1.5 bg-[#F0FDF4] px-3 py-1.5 rounded-lg border border-[#BBF7D0]">
                  <CheckCircle2 className="w-4 h-4" /> Password updated successfully
                </span>
              ) : <div />}

              <Button
                type="submit"
                variant="outline"
                size="sm"
              >
                Update Password
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
          <p className="text-xs text-[#737373]">
            You are signed in as <strong>{mockCurrentUser.email}</strong>
          </p>
          <Button
            variant="danger"
            size="sm"
            leftIcon={<LogOut className="w-4 h-4" />}
            onClick={handleLogout}
          >
            Sign Out
          </Button>
        </CardContent>
      </Card>
    </div>
  );
};

