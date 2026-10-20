import React, { useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Download, FileUp, ShieldCheck, UserPlus, X } from 'lucide-react';
import { adminApi } from '../api';
import { useAction, useAdminData, useDebounced } from '../hooks';
import type { AdminUser, AssignableRole, ImportPreview } from '../types';
import {
  Async, Btn, Card, Column, ConfirmDialog, DataTable, Dialog, Field, Notice, PageHeader, Pagination, Pill, Select, StatusBadge, Switch,
  fmt, humanize, inputCls, timeAgo,
} from '../components/ui';
import { cn } from '@/utils/cn';

type BulkAction = 'activate' | 'deactivate' | 'set_role' | 'set_department';

export const UsersPage: React.FC = () => {
  const [params, setParams] = useSearchParams();
  const navigate = useNavigate();
  const [search, setSearch] = useState(params.get('search') ?? '');
  const term = useDebounced(search, 300);
  const filters = {
    search: term,
    role: params.get('role') ?? '',
    status: params.get('status') ?? '',
    verified: params.get('verified') ?? '',
    two_factor: params.get('two_factor') ?? '',
    inactive_days: params.get('inactive_days') ?? '',
    sort: params.get('sort') ?? '',
    direction: params.get('direction') ?? 'asc',
    page: Number(params.get('page') ?? 1),
  };
  const setFilter = (key: string, value: string) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value); else next.delete(key);
    if (key !== 'page') next.delete('page');
    setParams(next, { replace: true });
  };

  const state = useAdminData(() => adminApi.users({ ...filters, per_page: 20 }), [JSON.stringify(filters)]);
  const action = useAction();
  const [selected, setSelected] = useState<Set<number | string>>(new Set());
  const [bulk, setBulk] = useState<BulkAction | null>(null);
  const [bulkRole, setBulkRole] = useState<AssignableRole>('DEPARTMENT_HEAD');
  const [bulkDept, setBulkDept] = useState('');
  const [creating, setCreating] = useState(false);
  const [importing, setImporting] = useState(false);

  const sortBy = (col: string) => {
    const next = new URLSearchParams(params);
    const same = filters.sort === col;
    next.set('sort', col);
    next.set('direction', same && filters.direction === 'asc' ? 'desc' : 'asc');
    setParams(next, { replace: true });
  };
  const sortLabel = (col: string, label: string) => (
    <button type="button" onClick={() => sortBy(col)} className="inline-flex items-center gap-1 uppercase hover:text-adm-ink" aria-label={`Sort by ${label}`}>
      {label}{filters.sort === col && <span aria-hidden="true">{filters.direction === 'asc' ? '↑' : '↓'}</span>}
    </button>
  );

  const columns: Column<AdminUser>[] = useMemo(() => [
    {
      key: 'name', header: sortLabel('name', 'Name'), render: (u) => (
        <div className="min-w-[12rem]">
          <Link to={`/admin/users/${u.id}`} className="font-medium text-adm-ink hover:text-adm-sage hover:underline">{u.name}</Link>
          <p className="text-xs text-adm-muted">{u.email}</p>
        </div>
      ),
    },
    { key: 'role', header: 'Role', render: (u) => <Pill tone={u.role === 'ADMIN' ? 'sage' : u.role === 'DEPARTMENT_HEAD' ? 'blue' : 'neutral'} icon={u.is_admin ? ShieldCheck : undefined}>{humanize(u.role)}</Pill> },
    { key: 'department', header: 'Department', render: (u) => <span className={cn(!u.department && 'text-adm-muted')}>{u.department ?? 'Not set'}</span> },
    { key: 'status', header: 'Status', render: (u) => <StatusBadge status={u.is_active ? 'active' : 'failed'} label={u.is_active ? 'Active' : 'Suspended'} /> },
    {
      key: 'security', header: 'Security', render: (u) => (
        <div className="flex flex-wrap gap-1">
          {u.two_factor_enabled ? <Pill tone="sage">2FA</Pill> : <Pill>No 2FA</Pill>}
          {!u.email_verified && <Pill tone="amber">Unverified</Pill>}
        </div>
      ),
    },
    { key: 'courses', header: sortLabel('courses_count', 'Courses'), className: 'tabular-nums', render: (u) => fmt(u.courses_count) },
    { key: 'last_login', header: sortLabel('last_login_at', 'Last sign-in'), render: (u) => <span className="whitespace-nowrap text-xs text-adm-muted">{timeAgo(u.last_login_at)}</span> },
  // eslint-disable-next-line react-hooks/exhaustive-deps
  ], [filters.sort, filters.direction, params]);

  const ids = [...selected].map(Number);
  const runBulk = async (reason: string) => {
    if (!bulk) return;
    const res = await action.run('bulk', () => adminApi.bulkUsers({
      ids, action: bulk, confirm: true, reason: reason || undefined,
      role: bulk === 'set_role' ? bulkRole : undefined, department: bulk === 'set_department' ? bulkDept : undefined,
    }));
    setBulk(null);
    if (res) { setSelected(new Set()); void state.reload(); }
  };

  const exportUsers = async () => {
    const res = await action.run('export', () => adminApi.createExport('users'));
    if (res) window.open(adminApi.operationDownloadUrl(res.data.id), '_blank', 'noopener');
  };

  return (
    <div className="space-y-6">
      <PageHeader title="Users" description="Create, invite, suspend and update faculty accounts. Suspending keeps every course and record the account owns."
        actions={<>
          <Btn icon={Download} loading={action.busy === 'export'} onClick={() => { void exportUsers(); }}>Export CSV</Btn>
          <Btn icon={FileUp} onClick={() => setImporting(true)}>Import CSV</Btn>
          <Btn icon={UserPlus} variant="primary" onClick={() => setCreating(true)}>New account</Btn>
        </>} />

      {action.notice && <Notice tone={action.notice.tone} onClose={() => action.setNotice(null)}>{action.notice.text}</Notice>}

      <Card bodyClassName="p-5 pt-4">
        <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
          <div className="sm:col-span-2">
            <label htmlFor="user-search" className="mb-1 block text-xs font-medium text-adm-ink">Search</label>
            <input id="user-search" type="search" value={search} onChange={(e) => { setSearch(e.target.value); setFilter('search', e.target.value); }} placeholder="Name, e-mail or department" className={inputCls} data-testid="user-search" />
          </div>
          <Select label="Role" value={filters.role} onChange={(e) => setFilter('role', e.target.value)} options={[{ value: '', label: 'All roles' }, { value: 'FACULTY', label: 'Faculty' }, { value: 'DEPARTMENT_HEAD', label: 'Department head' }, { value: 'ADMIN', label: 'Administrator' }]} />
          <Select label="Status" value={filters.status} onChange={(e) => setFilter('status', e.target.value)} options={[{ value: '', label: 'Any status' }, { value: 'active', label: 'Active' }, { value: 'deactivated', label: 'Suspended' }]} />
          <Select label="Verification" value={filters.verified} onChange={(e) => setFilter('verified', e.target.value)} options={[{ value: '', label: 'Any' }, { value: 'yes', label: 'E-mail verified' }, { value: 'no', label: 'Not verified' }]} />
          <Select label="Inactivity" value={filters.inactive_days} onChange={(e) => setFilter('inactive_days', e.target.value)} options={[{ value: '', label: 'Any' }, { value: '30', label: 'No sign-in 30 d' }, { value: '90', label: 'No sign-in 90 d' }, { value: '180', label: 'No sign-in 180 d' }]} />
        </div>

        {selected.size > 0 && (
          <div className="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-adm-line bg-adm-warm px-3 py-2 text-sm" data-testid="bulk-bar">
            <span className="font-medium">{selected.size} selected</span>
            <Btn size="sm" onClick={() => setBulk('activate')}>Reinstate</Btn>
            <Btn size="sm" onClick={() => setBulk('deactivate')}>Suspend</Btn>
            <Btn size="sm" onClick={() => setBulk('set_role')}>Change role</Btn>
            <Btn size="sm" onClick={() => setBulk('set_department')}>Set department</Btn>
            <Btn size="sm" variant="ghost" icon={X} onClick={() => setSelected(new Set())}>Clear</Btn>
          </div>
        )}

        <Async state={state} rows={8}>
          {(res) => (
            <>
              <DataTable caption="User accounts" columns={columns} rows={res.data} rowKey={(u) => u.id} onRowClick={(u) => navigate(`/admin/users/${u.id}`)}
                selection={{
                  selected,
                  disabled: (u) => u.is_admin,
                  toggle: (k) => setSelected((s) => { const n = new Set(s); if (n.has(k)) { n.delete(k); } else { n.add(k); } return n; }),
                  toggleAll: (keys) => setSelected((s) => keys.every((k) => s.has(k)) ? new Set([...s].filter((k) => !keys.includes(k))) : new Set([...s, ...keys])),
                }} />
              <Pagination meta={res.meta} onPage={(p) => setFilter('page', String(p))} />
            </>
          )}
        </Async>
      </Card>

      <ConfirmDialog
        open={bulk !== null}
        title={bulk === 'deactivate' ? `Suspend ${selected.size} account(s)?` : bulk === 'activate' ? `Reinstate ${selected.size} account(s)?` : bulk === 'set_role' ? 'Change role' : 'Set department'}
        tone={bulk === 'deactivate' ? 'danger' : 'primary'}
        confirmLabel="Apply"
        busy={action.busy === 'bulk'}
        reason={bulk === 'deactivate' ? { label: 'Reason for suspension', required: true } : { label: 'Reason', required: false }}
        body={<div className="space-y-3">
          <p>This applies to {selected.size} selected account(s) in one transaction. The administrator account is always skipped. Each change is written to the audit log.</p>
          {bulk === 'deactivate' && <p>Suspended users are signed out immediately. Their courses and records stay intact.</p>}
          {bulk === 'set_role' && <Select label="New role" value={bulkRole} onChange={(e) => setBulkRole(e.target.value as AssignableRole)} options={[{ value: 'FACULTY', label: 'Faculty' }, { value: 'DEPARTMENT_HEAD', label: 'Department head' }]} />}
          {bulk === 'set_department' && <Field label="Department">{(id) => <input id={id} value={bulkDept} onChange={(e) => setBulkDept(e.target.value)} className={inputCls} />}</Field>}
        </div>}
        onCancel={() => setBulk(null)}
        onConfirm={(reason) => { void runBulk(reason); }}
      />

      <CreateUserDialog open={creating} onClose={() => setCreating(false)} onCreated={(msg) => { action.setNotice({ tone: 'success', text: msg }); void state.reload(); }} />
      <ImportDialog open={importing} onClose={() => setImporting(false)} onDone={(msg, ok) => { action.setNotice({ tone: ok ? 'success' : 'error', text: msg }); void state.reload(); }} />
    </div>
  );
};

const CreateUserDialog: React.FC<{ open: boolean; onClose: () => void; onCreated: (message: string) => void }> = ({ open, onClose, onCreated }) => {
  const [form, setForm] = useState({ name: '', email: '', department: '', designation: '', role: 'FACULTY' as AssignableRole, send_invite: true });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setErrors({});
    try {
      const res = await adminApi.createUser(form);
      onCreated(res.message ?? 'Account created.');
      setForm({ name: '', email: '', department: '', designation: '', role: 'FACULTY', send_invite: true });
      onClose();
    } catch (err) {
      const apiErrors = (err as { errors?: Record<string, string[]> }).errors;
      if (apiErrors) setErrors(apiErrors);
      setError(err instanceof Error ? err.message : 'Could not create the account.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} title="New account" onClose={onClose}
      footer={<><Btn onClick={onClose}>Cancel</Btn><Btn variant="primary" type="submit" form="create-user" loading={busy}>Create account</Btn></>}>
      <form id="create-user" onSubmit={(e) => { void submit(e); }} className="space-y-3">
        {error && <Notice tone="error">{error}</Notice>}
        <Field label="Full name" error={errors.name?.[0]}>{(id) => <input id={id} required value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputCls} />}</Field>
        <Field label="E-mail" error={errors.email?.[0]}>{(id) => <input id={id} type="email" required value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className={inputCls} />}</Field>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Department">{(id) => <input id={id} value={form.department} onChange={(e) => setForm({ ...form, department: e.target.value })} className={inputCls} />}</Field>
          <Field label="Designation">{(id) => <input id={id} value={form.designation} onChange={(e) => setForm({ ...form, designation: e.target.value })} className={inputCls} />}</Field>
        </div>
        <Select label="Role" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value as AssignableRole })} options={[{ value: 'FACULTY', label: 'Faculty' }, { value: 'DEPARTMENT_HEAD', label: 'Department head' }]} />
        <div className="flex items-center justify-between gap-3 rounded-lg border border-adm-line bg-adm-warm px-3 py-2">
          <span className="text-xs text-adm-ink">E-mail a link so the user sets their own password<span className="block text-adm-muted">You never see or choose the password.</span></span>
          <Switch checked={form.send_invite} onChange={(v) => setForm({ ...form, send_invite: v })} label="Send set-password link" />
        </div>
      </form>
    </Dialog>
  );
};

const ImportDialog: React.FC<{ open: boolean; onClose: () => void; onDone: (message: string, ok: boolean) => void }> = ({ open, onClose, onDone }) => {
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<ImportPreview | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [invite, setInvite] = useState(true);

  const reset = () => { setFile(null); setPreview(null); setError(null); };
  const runPreview = async (f: File) => {
    setFile(f);
    setBusy(true);
    setError(null);
    try { setPreview((await adminApi.importPreview(f)).data); } catch (e) { setError(e instanceof Error ? e.message : 'Could not read the file.'); } finally { setBusy(false); }
  };
  const commit = async () => {
    if (!file) return;
    setBusy(true);
    try {
      const res = await adminApi.importUsers(file, invite);
      onDone(res.message ?? 'Import finished.', true);
      reset();
      onClose();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'The import failed.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} wide title="Import accounts from CSV" onClose={() => { reset(); onClose(); }}
      footer={<>
        <Btn onClick={() => { reset(); onClose(); }}>Cancel</Btn>
        <Btn variant="primary" disabled={!preview || preview.valid === 0} loading={busy && Boolean(preview)} onClick={() => { void commit(); }} data-testid="import-commit">
          {preview ? `Create ${preview.valid} account(s)` : 'Create accounts'}
        </Btn>
      </>}>
      <div className="space-y-4">
        <p className="text-sm text-adm-muted">Columns: <code className="rounded bg-adm-cream px-1">name, email</code> (required), <code className="rounded bg-adm-cream px-1">department, designation, role</code> (optional; role is FACULTY or DEPARTMENT_HEAD). Nothing is written until you confirm; invalid rows are skipped, never forced in.</p>
        <Field label="CSV file (max 512 KB, 500 rows)">
          {(id) => <input id={id} type="file" accept=".csv,text/csv" onChange={(e) => { const f = e.target.files?.[0]; if (f) void runPreview(f); }} className="block w-full" data-testid="import-file" />}
        </Field>
        {error && <Notice tone="error">{error}</Notice>}
        {preview && preview.missing_columns.length > 0 && <Notice tone="error">Missing required column(s): {preview.missing_columns.join(', ')}.</Notice>}
        {preview && preview.missing_columns.length === 0 && (
          <div data-testid="import-preview">
            <div className="mb-2 flex flex-wrap gap-2 text-xs"><Pill tone="sage">{preview.valid} valid</Pill><Pill tone={preview.invalid ? 'red' : 'neutral'}>{preview.invalid} with errors</Pill>{preview.truncated && <Pill tone="amber">Only the first 500 rows were read</Pill>}</div>
            <div className="max-h-72 overflow-auto rounded-lg border border-adm-line">
              <table className="min-w-full text-xs">
                <thead className="sticky top-0 bg-adm-warm text-left text-adm-muted"><tr><th className="px-3 py-2">Line</th><th className="px-3 py-2">Name</th><th className="px-3 py-2">E-mail</th><th className="px-3 py-2">Role</th><th className="px-3 py-2">Result</th></tr></thead>
                <tbody className="divide-y divide-adm-line">
                  {preview.rows.map((r) => (
                    <tr key={r.line} className={r.errors.length ? 'bg-adm-red-tint/40' : ''}>
                      <td className="px-3 py-1.5 tabular-nums">{r.line}</td><td className="px-3 py-1.5">{r.name}</td><td className="px-3 py-1.5">{r.email}</td><td className="px-3 py-1.5">{humanize(r.role)}</td>
                      <td className="px-3 py-1.5">{r.errors.length ? <span className="text-adm-red">{r.errors.join(' ')}</span> : <span className="text-adm-sage">Ready</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <label className="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" checked={invite} onChange={(e) => setInvite(e.target.checked)} className="h-4 w-4 accent-[#1E6F5C]" />E-mail each new user a set-password link</label>
          </div>
        )}
      </div>
    </Dialog>
  );
};
