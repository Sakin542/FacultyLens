import React, { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Mail, Lock, ArrowRight, Eye, EyeOff, ShieldCheck } from 'lucide-react';
import { Button } from '@/components/common/Button';
import { Input } from '@/components/common/Input';
import { useAuth, isTwoFactorPending } from '@/context/AuthContext';
import { AuthAlert, AuthShell, AuthSuccess, authButtonClass, authInputClass, authLinkClass } from '@/components/landing/AuthShell';
import { TwoFactorCodeForm } from '@/components/auth/TwoFactorCodeForm';
import { isValidEmail } from '@/utils/validation';

/** /admin/login: the administrator's sign-in. The server refuses every other account without starting a session. */
export const AdminLogin: React.FC = () => {
  const navigate = useNavigate();
  const { adminLogin, completeTwoFactor, isAuthenticated, loading, user, logout } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [challengeToken, setChallengeToken] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  useEffect(() => {
    if (!loading && isAuthenticated && user?.is_admin && !done) navigate('/admin', { replace: true });
  }, [loading, isAuthenticated, user?.is_admin, navigate, done]);

  const finish = () => {
    setDone(true);
    setTimeout(() => navigate('/admin', { replace: true }), 400);
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    if (!isValidEmail(email.trim()) || password.length < 6) {
      setError('Enter the administrator e-mail and password.');
      return;
    }
    setBusy(true);
    try {
      const result = await adminLogin({ email: email.trim(), password });
      if (isTwoFactorPending(result)) {
        setChallengeToken(result.challengeToken);
        setBusy(false);
        return;
      }
      finish();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign-in failed.');
      setBusy(false);
    }
  };

  const signedInAsFaculty = !loading && isAuthenticated && !user?.is_admin;
  // localhost and 127.0.0.1 keep separate cookies, so the other spelling gives an independent session in this browser
  const altSessionUrl = (() => {
    const { hostname, port, protocol, pathname } = window.location;
    const other = hostname === 'localhost' ? '127.0.0.1' : hostname === '127.0.0.1' ? 'localhost' : null;
    return other ? `${protocol}//${other}${port ? `:${port}` : ''}${pathname}` : null;
  })();

  return (
    <AuthShell
      eyebrow="Restricted · Administrator access"
      title="Admin Sign In"
      subtitle="This area manages users, system health, backups and feature settings. Only the FacultyLens administrator can sign in here."
      footer={<>Faculty member? <Link to="/login" className={authLinkClass}>Go to the faculty sign-in</Link></>}
    >
      {error && <AuthAlert title="Access denied" message={error} />}

      {signedInAsFaculty ? (
        <div className="space-y-4" data-testid="admin-login-faculty-notice">
          <AuthAlert title="Not an administrator" message={`You are signed in as ${user?.email}, which does not have administrator access.`} />
          <div className="flex gap-2">
            <Button variant="outline" size="md" className="flex-1 justify-center" onClick={() => navigate('/dashboard')}>Back to dashboard</Button>
            <Button variant="primary" size="md" className="flex-1 justify-center" onClick={() => { void logout(); }}>Sign out</Button>
          </div>
          {altSessionUrl && (
            <p className="text-xs text-sage-600" data-testid="admin-login-alt-session">
              Need both accounts open at once? Every port on the same host shares one sign-in, but{' '}
              <a href={altSessionUrl} className={authLinkClass}>{altSessionUrl.replace(/^https?:\/\//, '')}</a>{' '}
              is a separate session in this browser (or use a private window or another browser).
            </p>
          )}
        </div>
      ) : done ? (
        <AuthSuccess message="Signed in. Opening the admin panel…" />
      ) : challengeToken ? (
        <TwoFactorCodeForm
          onSubmit={async (code) => { await completeTwoFactor(challengeToken, code); finish(); }}
          onCancel={() => { setChallengeToken(null); setPassword(''); }}
        />
      ) : (
        <form onSubmit={submit} className="space-y-4" noValidate data-testid="admin-login-form">
          <Input label="Administrator email" type="email" autoComplete="username" value={email} onChange={(e) => { setEmail(e.target.value); setError(null); }}
            leftIcon={<Mail className="w-4 h-4" />} className={authInputClass} required />
          <Input label="Password" type={showPassword ? 'text' : 'password'} autoComplete="current-password" value={password} onChange={(e) => { setPassword(e.target.value); setError(null); }}
            leftIcon={<Lock className="w-4 h-4" />} className={authInputClass} required
            rightIcon={<button type="button" tabIndex={-1} onClick={() => setShowPassword(!showPassword)} className="text-sage-400 hover:text-sage-800" aria-label={showPassword ? 'Hide password' : 'Show password'}>{showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}</button>} />
          <Button type="submit" variant="primary" size="md" className={authButtonClass} isLoading={busy} disabled={busy} leftIcon={<ShieldCheck className="w-4 h-4" />} rightIcon={<ArrowRight className="w-4 h-4" />}>
            {busy ? 'Verifying…' : 'Sign in to admin panel'}
          </Button>
        </form>
      )}
    </AuthShell>
  );
};
