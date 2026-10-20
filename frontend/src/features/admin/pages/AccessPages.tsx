import React, { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { AlertTriangle, Download, Info, Lock, ShieldAlert, ShieldCheck } from 'lucide-react';
import { adminApi } from '../api';
import { useAction, useAdminData, useDebounced } from '../hooks';
import type { AuditEntry } from '../types';
import {
  Async, Btn, Card, Column, DataTable, Dialog, Kpi, Notice, PageHeader, Pagination, Pill, Segmented, Select, StatusBadge,
  fmt, fmtDateTime, humanize, inputCls, timeAgo,
} from '../components/ui';
import { DailyBars } from '../components/charts';
import { cn } from '@/utils/cn';

const LEVEL_TONE = (v: string) => (v === 'none' || v === 'never' ? 'neutral' : v === 'full' || v.startsWith('all') ? 'sage' : 'blue');

// ======================================================================================== roles & permissions

export const AccessPage: React.FC = () => {
  const state = useAdminData(() => adminApi.access(), []);
  return (
    <div className="space-y-6">
      <PageHeader title="Roles & permissions" description="FacultyLens has three fixed roles. This matrix documents what the backend enforces and where; hiding a menu item is never the control." />
      <Async state={state} rows={10}>
        {(d) => (
          <>
            <Notice tone="info"><span className="inline-flex items-start gap-1.5"><Lock className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{d.admin_lock.description}{!d.admin_lock.email_configured && ' ADMIN_EMAIL is not set, so the role alone currently decides.'}</span></Notice>
            <div className="grid gap-3 md:grid-cols-3">
              {d.roles.map((r) => (
                <div key={r.key} className="rounded-xl border border-adm-line bg-adm-surface p-4">
                  <div className="flex items-center justify-between gap-2">
                    <p className="font-semibold text-adm-ink">{r.label}</p>
                    {r.protected ? <Pill tone="sage" icon={Lock}>Protected</Pill> : <Pill>Assignable</Pill>}
                  </div>
                  <p className="mt-1 text-xs text-adm-muted">{r.description}</p>
                  <p className="mt-3 text-2xl font-semibold tabular-nums">{fmt(r.members)} <span className="text-xs font-normal text-adm-muted">account(s)</span></p>
                </div>
              ))}
            </div>

            <Card title="Permission matrix" description="Effective access per role, with the enforcing code" bodyClassName="p-0">
              <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                  <caption className="sr-only">Permission matrix</caption>
                  <thead><tr className="border-b border-adm-line text-left text-[11px] uppercase tracking-[0.06em] text-adm-muted">
                    <th className="px-5 py-2 font-medium">Capability</th>{d.roles.map((r) => <th key={r.key} className="px-3 py-2 font-medium">{r.label}</th>)}<th className="px-5 py-2 font-medium">Enforced by</th>
                  </tr></thead>
                  {d.modules.map((m) => (
                    <tbody key={m.module} className="divide-y divide-adm-line border-b border-adm-line">
                      <tr className="bg-adm-warm"><th colSpan={d.roles.length + 2} scope="colgroup" className="px-5 py-1.5 text-left text-xs font-semibold text-adm-ink">{m.module}</th></tr>
                      {m.capabilities.map((c) => (
                        <tr key={c.key}>
                          <td className="px-5 py-2">{c.label}</td>
                          {d.roles.map((r) => <td key={r.key} className="px-3 py-2"><Pill tone={LEVEL_TONE(c.roles[r.key] ?? 'none')}>{c.roles[r.key] ?? 'none'}</Pill></td>)}
                          <td className="px-5 py-2 font-mono text-[11px] text-adm-muted">{c.enforced_by}</td>
                        </tr>
                      ))}
                    </tbody>
                  ))}
                </table>
              </div>
            </Card>

            <div className="grid gap-6 lg:grid-cols-2">
              <Card title="Privileged access inventory" description="Accounts with access beyond their own courses">
                <ul className="divide-y divide-adm-line">
                  {d.privileged.map((p) => (
                    <li key={p.id} className="py-2.5">
                      <div className="flex items-center justify-between gap-2">
                        <Link to={`/admin/users/${p.id}`} className="font-medium text-adm-ink hover:underline">{p.name}</Link>
                        <span className="flex gap-1">{p.two_factor_enabled ? <Pill tone="sage">2FA</Pill> : <Pill tone="amber">No 2FA</Pill>}<Pill tone={p.role === 'ADMIN' ? 'sage' : 'blue'}>{humanize(p.role)}</Pill></span>
                      </div>
                      <p className="text-xs text-adm-muted">{p.department ?? 'No department'} · last sign-in {timeAgo(p.last_login_at)}{!p.is_active && ' · suspended'}</p>
                      {p.note && <p className="mt-1 text-xs text-adm-red">{p.note}</p>}
                    </li>
                  ))}
                </ul>
              </Card>
              <Card title="Role change history" description="From the audit log">
                {d.history.length === 0 ? <p className="text-sm text-adm-muted">No role changes recorded.</p> : (
                  <ul className="divide-y divide-adm-line">
                    {d.history.map((h) => (
                      <li key={h.id} className="py-2 text-sm">
                        <p><span className="font-medium">{h.target_name ?? `User #${h.target_id}`}</span> {h.from ? <>{humanize(h.from)} → </> : 'created as '}{humanize(h.to ?? '')}</p>
                        <p className="text-xs text-adm-muted">{h.actor?.name ?? 'System'} · {fmtDateTime(h.created_at)}{h.reason ? ` · “${h.reason}”` : ''}</p>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            </div>
          </>
        )}
      </Async>
    </div>
  );
};

// ======================================================================================== audit log

export const AuditPage: React.FC = () => {
  const [params, setParams] = useSearchParams();
  const [search, setSearch] = useState(params.get('search') ?? '');
  const term = useDebounced(search, 300);
  const filters = { action: params.get('action') ?? '', scope: params.get('scope') ?? 'all', entity_type: params.get('entity_type') ?? '', from: params.get('from') ?? '', to: params.get('to') ?? '', page: Number(params.get('page') ?? 1), search: term };
  const setFilter = (k: string, v: string) => { const n = new URLSearchParams(params); if (v) n.set(k, v); else n.delete(k); if (k !== 'page') n.delete('page'); setParams(n, { replace: true }); };

  const state = useAdminData(() => adminApi.auditLogs({ ...filters, per_page: 25 }), [JSON.stringify(filters)]);
  const facets = useAdminData(() => adminApi.auditFacets(), []);
  const [detail, setDetail] = useState<AuditEntry | null>(null);
  const action = useAction();

  const exportAudit = async () => {
    const res = await action.run('export', () => adminApi.createExport('audit_logs', filters.from, filters.to));
    if (res) window.open(adminApi.operationDownloadUrl(res.data.id), '_blank', 'noopener');
  };

  const columns: Column<AuditEntry>[] = [
    { key: 'time', header: 'Time', render: (e) => <span className="whitespace-nowrap text-xs text-adm-muted" title={fmtDateTime(e.created_at)}>{fmtDateTime(e.created_at)}</span> },
    { key: 'action', header: 'Action', render: (e) => <span className="font-mono text-xs">{e.action}</span> },
    { key: 'actor', header: 'Actor', render: (e) => e.actor ? <Link to={`/admin/users/${e.actor.id}`} className="hover:underline">{e.actor.name}</Link> : <span className="text-adm-muted">System / anonymous</span> },
    { key: 'resource', header: 'Resource', render: (e) => <span className="text-xs">{e.entity_type}{e.entity_id ? ` #${e.entity_id}` : ''}</span> },
    { key: 'outcome', header: 'Outcome', render: (e) => <StatusBadge status={e.outcome === 'failure' ? 'failed' : 'completed'} label={e.outcome === 'failure' ? 'Failure' : 'Success'} /> },
    { key: 'ip', header: 'Source IP', render: (e) => <span className="font-mono text-xs text-adm-muted">{e.ip_address ?? '—'}</span> },
  ];

  return (
    <div className="space-y-6">
      <PageHeader title="Audit log" description="Append-only record of administrative, security and academic events. Entries cannot be edited or deleted from the panel; secrets and private academic text are redacted before they are stored."
        actions={<Btn icon={Download} loading={action.busy === 'export'} onClick={() => { void exportAudit(); }}>Export CSV</Btn>} />
      {action.notice && <Notice tone={action.notice.tone} onClose={() => action.setNotice(null)}>{action.notice.text}</Notice>}
      <Card>
        <div className="mb-4 flex flex-wrap items-end gap-3">
          <Segmented label="Scope" value={filters.scope} onChange={(v) => setFilter('scope', v === 'all' ? '' : v)} options={[{ value: 'all', label: 'All' }, { value: 'admin', label: 'Admin actions' }, { value: 'security', label: 'Security' }]} />
          <div className="w-56"><label htmlFor="audit-search" className="mb-1 block text-xs font-medium">Search actions</label><input id="audit-search" type="search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="e.g. role changed" className={inputCls} /></div>
          <Select className="w-56" label="Action" value={filters.action} onChange={(e) => setFilter('action', e.target.value)} options={[{ value: '', label: 'Any action' }, ...(facets.data?.actions ?? []).map((a) => ({ value: a, label: a }))]} />
          <Select className="w-44" label="Resource type" value={filters.entity_type} onChange={(e) => setFilter('entity_type', e.target.value)} options={[{ value: '', label: 'Any' }, ...(facets.data?.entity_types ?? []).map((a) => ({ value: a, label: a }))]} />
          <div><label htmlFor="audit-from" className="mb-1 block text-xs font-medium">From</label><input id="audit-from" type="date" value={filters.from} onChange={(e) => setFilter('from', e.target.value)} className={inputCls} /></div>
          <div><label htmlFor="audit-to" className="mb-1 block text-xs font-medium">To</label><input id="audit-to" type="date" value={filters.to} onChange={(e) => setFilter('to', e.target.value)} className={inputCls} /></div>
        </div>
        <Async state={state} rows={10}>
          {(res) => <>
            <DataTable caption="Audit events" columns={columns} rows={res.data} rowKey={(e) => e.id} onRowClick={setDetail} dense />
            <Pagination meta={res.meta} onPage={(p) => setFilter('page', String(p))} />
          </>}
        </Async>
      </Card>
      <Dialog open={detail !== null} title={detail?.action ?? ''} onClose={() => setDetail(null)} wide footer={<Btn onClick={() => setDetail(null)}>Close</Btn>}>
        {detail && (
          <div className="space-y-3 text-sm">
            <p className="text-adm-muted">{fmtDateTime(detail.created_at)} · {detail.actor?.name ?? 'System / anonymous'} · {detail.entity_type}{detail.entity_id ? ` #${detail.entity_id}` : ''}{detail.course_id ? ` · course #${detail.course_id}` : ''}</p>
            <pre className="max-h-80 overflow-auto rounded-lg bg-adm-warm p-3 text-xs">{JSON.stringify(detail.metadata ?? {}, null, 2)}</pre>
          </div>
        )}
      </Dialog>
    </div>
  );
};

// ======================================================================================== security center

export const SecurityPage: React.FC = () => {
  const [days, setDays] = useState(7);
  const state = useAdminData(() => adminApi.security(days), [days]);
  return (
    <div className="space-y-6">
      <PageHeader title="Security center" description="Facts are audit records exactly as stored. Signals are threshold observations for a human to review — they never label an account or address as malicious."
        actions={<Segmented label="Window" value={days} onChange={(v) => setDays(Number(v))} options={[{ value: 1, label: '24 h' }, { value: 7, label: '7 days' }, { value: 30, label: '30 days' }, { value: 90, label: '90 days' }]} />} />
      <Async state={state} rows={10}>
        {(d) => (
          <>
            {d.signals.length > 0 && (
              <Card title="Signals to review" testId="security-signals">
                <ul className="space-y-2">
                  {d.signals.map((s, i) => (
                    <li key={i} className={cn('flex items-start gap-2 rounded-lg border px-3 py-2 text-sm', s.severity === 'critical' ? 'border-[#FECACA] bg-adm-red-tint' : s.severity === 'warning' ? 'border-[#FDE68A] bg-adm-amber-tint' : 'border-[#D4DEE8] bg-adm-blue-tint')}>
                      {s.severity === 'info' ? <Info className="mt-0.5 h-4 w-4 shrink-0 text-adm-blue" aria-hidden="true" /> : <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-adm-red" aria-hidden="true" />}
                      <span><span className="font-medium">{s.title}</span><span className="block text-xs text-adm-muted">{s.detail}</span></span>
                    </li>
                  ))}
                </ul>
              </Card>
            )}
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <Kpi label="Failed sign-ins" value={fmt(d.facts.failed_sign_ins)} icon={ShieldAlert} to="/admin/audit?action=AUTH_LOGIN_FAILED" tone={d.facts.failed_sign_ins ? 'amber' : undefined} />
              <Kpi label="Refused admin sign-ins" value={fmt(d.facts.refused_admin_sign_ins)} icon={Lock} to="/admin/audit?action=ADMIN_LOGIN_REFUSED" />
              <Kpi label="Two-factor adoption" value={d.posture.two_factor_percent === null ? '—' : `${d.posture.two_factor_percent}%`} icon={ShieldCheck} hint={`${fmt(d.posture.two_factor_accounts)} of ${fmt(d.posture.active_accounts)} active accounts`} />
              <Kpi label="Active sessions (2 h)" value={d.posture.active_sessions_2h === null ? 'Not measurable' : fmt(d.posture.active_sessions_2h)} hint={`session driver: ${d.posture.session_driver}`} />
            </div>
            <div className="grid gap-6 lg:grid-cols-3">
              <Card title="Failed sign-ins per day" className="lg:col-span-2"><DailyBars data={d.failed_trend} label="Failed sign-ins per day" /></Card>
              <Card title="Recorded facts" description={`Last ${d.days} day(s)`}>
                <ul className="divide-y divide-adm-line text-sm">
                  {Object.entries(d.facts).map(([k, v]) => <li key={k} className="flex justify-between py-1.5"><span>{humanize(k)}</span><span className="tabular-nums">{fmt(v)}</span></li>)}
                </ul>
              </Card>
            </div>
            <div className="grid gap-6 lg:grid-cols-2">
              <Card title="Accounts with failed sign-ins">
                {d.targeted_accounts.length === 0 ? <p className="text-sm text-adm-muted">None in this window.</p> : (
                  <ul className="divide-y divide-adm-line text-sm">
                    {d.targeted_accounts.map((t) => (
                      <li key={t.user_id} className="flex items-center justify-between gap-2 py-2">
                        <span><Link to={`/admin/users/${t.user_id}`} className="font-medium hover:underline">{t.name ?? `User #${t.user_id}`}</Link><span className="block text-xs text-adm-muted">{t.addresses} address(es) · last {timeAgo(t.last_at)}</span></span>
                        <Pill tone={t.failures >= 5 ? 'amber' : 'neutral'}>{t.failures} failed</Pill>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
              <Card title="Source addresses" description="Failed attempts grouped by IP (includes attempts on unknown e-mails)">
                {d.sources.length === 0 ? <p className="text-sm text-adm-muted">None in this window.</p> : (
                  <ul className="divide-y divide-adm-line text-sm">
                    {d.sources.map((s) => <li key={s.ip_address} className="flex justify-between py-2"><span className="font-mono text-xs">{s.ip_address}</span><span className="text-xs text-adm-muted">{s.failures} attempts · {s.accounts} known account(s)</span></li>)}
                  </ul>
                )}
              </Card>
            </div>
            <Card title="Security events" actions={<Link to="/admin/audit?scope=security" className="text-xs font-medium text-adm-sage hover:underline">Full log →</Link>}>
              {d.events.length === 0 ? <p className="text-sm text-adm-muted">No security events recorded.</p> : (
                <ul className="divide-y divide-adm-line text-sm">
                  {d.events.map((e) => <li key={e.id} className="flex justify-between gap-3 py-1.5"><span className="font-mono text-xs">{e.action}</span><span className="text-xs text-adm-muted">{e.actor?.name ?? 'anonymous'} · {timeAgo(e.created_at)}</span></li>)}
                </ul>
              )}
              <p className="mt-4 text-xs text-adm-muted">Dependency vulnerability scanning: {d.dependency_scanning}</p>
            </Card>
          </>
        )}
      </Async>
    </div>
  );
};
