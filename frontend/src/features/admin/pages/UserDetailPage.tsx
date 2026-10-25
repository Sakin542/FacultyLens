import React, { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, KeyRound, LogOut, ShieldOff, Trash2, UserCheck, UserX } from 'lucide-react';
import { adminApi } from '../api';
import { useAction, useAdminData } from '../hooks';
import type { AssignableRole } from '../types';
import { Async, Btn, Card, ConfirmDialog, Field, KeyValue, Notice, PageHeader, Pill, Select, StatusBadge, fmt, fmtDateTime, humanize, inputCls, timeAgo } from '../components/ui';

type Pending = 'suspend' | 'reinstate' | 'revoke' | 'reset' | '2fa' | 'role' | 'delete' | null;

export const UserDetailPage: React.FC = () => {
  const id = Number(useParams().userId);
  const navigate = useNavigate();
  const state = useAdminData(() => adminApi.user(id), [id]);
  const action = useAction();
  const [pending, setPending] = useState<Pending>(null);
  const [role, setRole] = useState<AssignableRole>('FACULTY');
  const [profile, setProfile] = useState<{ name: string; department: string; designation: string } | null>(null);

  const done = async <T,>(fn: () => Promise<T & { message?: string }>) => {
    const res = await action.run(pending ?? 'x', fn);
    setPending(null);
    if (res) void state.reload();
  };

  return (
    <div className="space-y-6">
      <Link to="/admin/users" className="inline-flex items-center gap-1 text-sm text-adm-muted hover:text-adm-ink"><ArrowLeft className="h-4 w-4" aria-hidden="true" />All users</Link>
      {action.notice && <Notice tone={action.notice.tone} onClose={() => action.setNotice(null)}>{action.notice.text}</Notice>}

      <Async state={state} rows={8}>
        {(u) => {
          const locked = u.is_admin;
          return (
            <>
              <PageHeader title={u.name} description={u.email}
                meta={<span className="flex flex-wrap gap-1.5"><Pill tone={u.role === 'DEPARTMENT_HEAD' ? 'blue' : u.role === 'ADMIN' ? 'sage' : 'neutral'}>{humanize(u.role)}</Pill>
                  <StatusBadge status={u.is_active ? 'active' : 'failed'} label={u.is_active ? 'Active' : 'Suspended'} />
                  {u.two_factor_enabled ? <Pill tone="sage">2FA on</Pill> : <Pill>2FA off</Pill>}{!u.email_verified && <Pill tone="amber">E-mail unverified</Pill>}</span>}
                actions={locked ? <Pill tone="sage">Protected administrator account</Pill> : <>
                  <Btn icon={KeyRound} onClick={() => setPending('reset')}>Send password link</Btn>
                  <Btn icon={LogOut} onClick={() => setPending('revoke')}>End sessions</Btn>
                  {u.two_factor_enabled && <Btn icon={ShieldOff} onClick={() => setPending('2fa')}>Reset 2FA</Btn>}
                  {u.is_active ? <Btn icon={UserX} variant="danger" onClick={() => setPending('suspend')}>Suspend</Btn> : <Btn icon={UserCheck} variant="primary" onClick={() => setPending('reinstate')}>Reinstate</Btn>}
                  {u.courses_count === 0 && <Btn icon={Trash2} variant="danger" onClick={() => setPending('delete')}>Delete</Btn>}
                </>} />

              <div className="grid gap-6 lg:grid-cols-3">
                <Card title="Profile" className="lg:col-span-2"
                  actions={!locked && (profile
                    ? <><Btn size="sm" onClick={() => setProfile(null)}>Cancel</Btn><Btn size="sm" variant="primary" loading={action.busy === 'profile'} onClick={() => { void action.run('profile', () => adminApi.updateUser(u.id, profile)).then((r) => { if (r) { setProfile(null); void state.reload(); } }); }}>Save</Btn></>
                    : <Btn size="sm" onClick={() => setProfile({ name: u.name, department: u.department ?? '', designation: u.designation ?? '' })}>Edit</Btn>)}>
                  {profile ? (
                    <div className="grid gap-3 sm:grid-cols-3">
                      <Field label="Name">{(fid) => <input id={fid} value={profile.name} onChange={(e) => setProfile({ ...profile, name: e.target.value })} className={inputCls} />}</Field>
                      <Field label="Department">{(fid) => <input id={fid} value={profile.department} onChange={(e) => setProfile({ ...profile, department: e.target.value })} className={inputCls} />}</Field>
                      <Field label="Designation">{(fid) => <input id={fid} value={profile.designation} onChange={(e) => setProfile({ ...profile, designation: e.target.value })} className={inputCls} />}</Field>
                    </div>
                  ) : (
                    <KeyValue columns={3} items={[
                      { label: 'Department', value: u.department ?? '—' },
                      { label: 'Designation', value: u.designation ?? '—' },
                      { label: 'Role', value: <span className="flex items-center gap-2">{humanize(u.role)}{!locked && <button type="button" className="text-xs text-adm-sage hover:underline" onClick={() => { setRole(u.role === 'DEPARTMENT_HEAD' ? 'FACULTY' : 'DEPARTMENT_HEAD'); setPending('role'); }}>Change</button>}</span> },
                      { label: 'Courses owned', value: fmt(u.courses_count) },
                      { label: 'Collaborations', value: fmt(u.collaborations_count) },
                      { label: 'Google sign-in', value: u.google_linked ? 'Linked' : 'Not linked' },
                      { label: 'Account created', value: fmtDateTime(u.created_at) },
                      { label: 'Last sign-in', value: u.last_login_at ? `${fmtDateTime(u.last_login_at)} (${timeAgo(u.last_login_at)})` : 'Never' },
                      { label: 'E-mail verified', value: u.email_verified_at ? fmtDateTime(u.email_verified_at) : 'No' },
                    ]} />
                  )}
                </Card>
                <Card title="Sessions" description={u.sessions.tracked ? `Session driver: ${u.sessions.driver}` : `Session driver "${u.sessions.driver}" does not expose per-user sessions`}>
                  <KeyValue columns={1} items={[
                    { label: 'Browser sessions', value: u.sessions.tracked ? fmt(u.sessions.active) : 'Not measurable' },
                    { label: 'Last activity', value: u.sessions.tracked ? fmtDateTime(u.sessions.last_activity_at) : '—' },
                    { label: 'API tokens', value: fmt(u.sessions.api_tokens) },
                  ]} />
                </Card>
              </div>

              <Card title="Account history" description="Administrative changes and sign-in events for this account (latest 50)">
                {u.history.length === 0 ? <p className="text-sm text-adm-muted">No recorded changes.</p> : (
                  <ol className="relative space-y-4 border-l border-adm-line pl-5">
                    {u.history.map((h) => (
                      <li key={h.id} className="relative">
                        <span className="absolute -left-[25px] top-1.5 h-2 w-2 rounded-full bg-adm-sage ring-4 ring-adm-surface" aria-hidden="true" />
                        <p className="text-sm text-adm-ink">{humanize(h.action.replace(/^ADMIN_/, ''))}</p>
                        <p className="text-xs text-adm-muted">{h.actor?.name ?? 'System'} · {fmtDateTime(h.created_at)}</p>
                        {h.metadata && typeof h.metadata.reason === 'string' && <p className="mt-1 text-xs text-adm-ink">Reason: {h.metadata.reason}</p>}
                        {h.metadata && 'changes' in h.metadata && (
                          <p className="mt-1 text-xs text-adm-muted">{Object.entries(h.metadata.changes as Record<string, { from: unknown; to: unknown }>).map(([k, v]) => `${humanize(k)}: ${String(v.from ?? '—')} → ${String(v.to ?? '—')}`).join(' · ')}</p>
                        )}
                      </li>
                    ))}
                  </ol>
                )}
              </Card>

              <ConfirmDialog open={pending === 'suspend'} tone="danger" title={`Suspend ${u.name}?`} confirmLabel="Suspend account" busy={action.busy === 'suspend'}
                reason={{ label: 'Reason for suspension', required: true }}
                body="They are signed out everywhere and cannot sign in until reinstated. Courses, assessments and grades they own are kept unchanged."
                onCancel={() => setPending(null)} onConfirm={(reason) => { void done(() => adminApi.updateUser(u.id, { is_active: false, reason })); }} />
              <ConfirmDialog open={pending === 'delete'} tone="danger" title={`Delete ${u.name}?`} confirmLabel="Delete account" busy={action.busy === 'delete'}
                typeToConfirm={u.email} reason={{ label: 'Reason for deletion', required: true }}
                body="The account, its sessions, notifications and preferences are removed permanently. This cannot be undone; the audit log keeps a record of the deletion. Accounts that own courses cannot be deleted — suspend them instead."
                onCancel={() => setPending(null)}
                onConfirm={(reason) => { void action.run('delete', () => adminApi.deleteUser(u.id, { confirm_email: u.email, reason })).then((r) => { setPending(null); if (r) navigate('/admin/users'); }); }} />
              <ConfirmDialog open={pending === 'reinstate'} title={`Reinstate ${u.name}?`} confirmLabel="Reinstate" busy={action.busy === 'reinstate'} reason={{ label: 'Reason', required: false }}
                body="The account can sign in again immediately." onCancel={() => setPending(null)} onConfirm={(reason) => { void done(() => adminApi.updateUser(u.id, { is_active: true, reason: reason || undefined })); }} />
              <ConfirmDialog open={pending === 'revoke'} title="End all sessions?" confirmLabel="End sessions" busy={action.busy === 'revoke'}
                body="Signs the user out of every browser and revokes API tokens. They can sign in again with their password." onCancel={() => setPending(null)} onConfirm={() => { void done(() => adminApi.revokeSessions(u.id)); }} />
              <ConfirmDialog open={pending === 'reset'} title="Send a password reset link?" confirmLabel="Send link" busy={action.busy === 'reset'}
                body={`A standard reset link goes to ${u.email}. You never see the password.`} onCancel={() => setPending(null)} onConfirm={() => { void done(() => adminApi.sendPasswordReset(u.id)); }} />
              <ConfirmDialog open={pending === '2fa'} tone="danger" title="Reset two-factor authentication?" confirmLabel="Reset 2FA" busy={action.busy === '2fa'} typeToConfirm="RESET"
                body="Use only after verifying the user's identity (lost phone and recovery codes). They can sign in with a password alone until they enable 2FA again."
                onCancel={() => setPending(null)} onConfirm={() => { void done(() => adminApi.resetTwoFactor(u.id)); }} />
              <ConfirmDialog open={pending === 'role'} title="Change role" confirmLabel="Change role" busy={action.busy === 'role'} reason={{ label: 'Reason', required: false }}
                body={<div className="space-y-3"><p>Department heads get read-only access to every course in their department. The administrator role cannot be assigned here.</p>
                  <Select label="New role" value={role} onChange={(e) => setRole(e.target.value as AssignableRole)} options={[{ value: 'FACULTY', label: 'Faculty' }, { value: 'DEPARTMENT_HEAD', label: 'Department head' }]} /></div>}
                onCancel={() => setPending(null)} onConfirm={(reason) => { void done(() => adminApi.updateUser(u.id, { role, reason: reason || undefined })); }} />
            </>
          );
        }}
      </Async>
    </div>
  );
};
