import React, { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, CalendarPlus, CheckCheck, Database, Download, FileDown, Plus, ShieldCheck, Siren, Trash2 } from 'lucide-react';
import { adminApi } from '../api';
import { useAction, useAdminData } from '../hooks';
import type { AdminAlert, AdminOperation, BackupRecord, Incident, IncidentDetail } from '../types';
import {
  Async, Btn, Card, Column, ConfirmDialog, DataTable, Dialog, Field, KeyValue, Kpi, Notice, PageHeader, Pagination, Pill, Segmented, Select,
  SeverityBadge, StatusBadge, Switch, Tabs, fmt, fmtDateTime, formatBytes, humanize, inputCls, timeAgo,
} from '../components/ui';
import { cn } from '@/utils/cn';

// ======================================================================================== alerts

export const AlertsPage: React.FC = () => {
  const [status, setStatus] = useState('active');
  const [page, setPage] = useState(1);
  const state = useAdminData(() => adminApi.alerts({ status, page, per_page: 20 }), [status, page]);
  const action = useAction();
  const navigate = useNavigate();
  const [resolving, setResolving] = useState<AdminAlert | null>(null);

  const ack = async (a: AdminAlert) => { if (await action.run('ack', () => adminApi.acknowledgeAlert(a.id))) void state.reload(); };
  const escalate = async (a: AdminAlert) => {
    const res = await action.run('escalate', () => adminApi.escalateAlert(a.id));
    if (res) navigate(`/admin/incidents/${res.data.incident_id}`);
  };

  return (
    <div className="space-y-6">
      <PageHeader title="Alerts" description="Raised every five minutes by admin:monitor when a measured value crosses a threshold. A persisting condition updates one alert instead of creating duplicates, and alerts close themselves when the condition clears."
        actions={<Segmented label="Status" value={status} onChange={(v) => { setStatus(v); setPage(1); }} options={[{ value: 'active', label: 'Active' }, { value: 'resolved', label: 'Resolved' }]} />} />
      {action.notice && <Notice tone={action.notice.tone} onClose={() => action.setNotice(null)}>{action.notice.text}</Notice>}
      <Async state={state} rows={6}>
        {(res) => (
          <>
            {res.data.length === 0 ? (
              <Card><p className="py-6 text-center text-sm text-adm-muted">{status === 'active' ? 'No active alerts. All measured conditions are within their thresholds.' : 'No resolved alerts yet.'}</p></Card>
            ) : (
              <ul className="space-y-3">
                {res.data.map((a) => (
                  <li key={a.id} className={cn('rounded-xl border bg-adm-surface p-4', a.severity === 'critical' && a.status !== 'resolved' ? 'border-[#FECACA]' : 'border-adm-line')}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2"><SeverityBadge severity={a.severity} /><StatusBadge status={a.status} />{a.suppressed && <Pill tone="blue">Maintenance: not notified</Pill>}{a.incident_id && <Link to={`/admin/incidents/${a.incident_id}`}><Pill tone="red" icon={Siren}>{`INC-${String(a.incident_id).padStart(4, '0')}`}</Pill></Link>}</div>
                        <p className="mt-1.5 font-medium">{a.title}</p>
                        <p className="text-sm text-adm-muted">{a.message}</p>
                        <p className="mt-1 text-xs text-adm-muted">First seen {fmtDateTime(a.first_seen_at)} · last {timeAgo(a.last_seen_at)} · {a.occurrences} check(s){a.acknowledged_by && ` · acknowledged by ${a.acknowledged_by.name}`}</p>
                        {a.resolution_note && <p className="mt-1 text-xs">Resolution: {a.resolution_note}{a.resolved_by ? ` (${a.resolved_by.name})` : ' (automatic)'}</p>}
                      </div>
                      {a.status !== 'resolved' && (
                        <div className="flex flex-wrap gap-1.5">
                          {a.status === 'open' && <Btn size="sm" onClick={() => { void ack(a); }}>Acknowledge</Btn>}
                          {!a.incident_id && <Btn size="sm" icon={Siren} onClick={() => { void escalate(a); }}>Open incident</Btn>}
                          <Btn size="sm" variant="primary" icon={CheckCheck} onClick={() => setResolving(a)}>Resolve</Btn>
                        </div>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}
            <Pagination meta={res.meta} onPage={setPage} />
            <Card title="Alert rules" description="Thresholds from backend/config/admin.php (ADMIN_ALERT_* variables); 0 disables a rule">
              <KeyValue columns={3} items={[
                { label: 'Service unavailable', value: 'any probe reports Unavailable' },
                ...Object.entries(res.meta.rules ?? {}).map(([k, v]) => ({ label: humanize(k), value: v ? fmt(v) : 'disabled' })),
              ]} />
            </Card>
          </>
        )}
      </Async>
      <ConfirmDialog open={resolving !== null} title="Resolve alert" confirmLabel="Resolve" busy={action.busy === 'resolve'} reason={{ label: 'Resolution note', required: true }}
        body="If the condition is still measured on the next check, the alert re-opens." onCancel={() => setResolving(null)}
        onConfirm={(note) => { const a = resolving!; void action.run('resolve', () => adminApi.resolveAlert(a.id, note)).then((r) => { setResolving(null); if (r) void state.reload(); }); }} />
    </div>
  );
};

// ======================================================================================== incidents

export const IncidentsPage: React.FC = () => {
  const [status, setStatus] = useState('active');
  const [page, setPage] = useState(1);
  const state = useAdminData(() => adminApi.incidents({ status, page, per_page: 20 }), [status, page]);
  const [creating, setCreating] = useState(false);
  const navigate = useNavigate();

  const columns: Column<Incident>[] = [
    { key: 'ref', header: 'Incident', render: (i) => <div><Link to={`/admin/incidents/${i.id}`} className="font-medium hover:underline">{i.reference}</Link><p className="text-sm">{i.title}</p></div> },
    { key: 'sev', header: 'Severity', render: (i) => <SeverityBadge severity={i.severity} /> },
    { key: 'status', header: 'Status', render: (i) => <StatusBadge status={i.status} /> },
    { key: 'cat', header: 'Category', render: (i) => humanize(i.category) },
    { key: 'owner', header: 'Assignee', render: (i) => i.assignee?.name ?? '—' },
    { key: 'opened', header: 'Opened', render: (i) => <span className="whitespace-nowrap text-xs text-adm-muted">{fmtDateTime(i.opened_at)}</span> },
  ];

  return (
    <div className="space-y-6">
      <PageHeader title="Incidents" description="Track an outage or problem from first report to root cause and corrective action. Every change is kept in the incident timeline and the audit log."
        actions={<><Segmented label="Status" value={status} onChange={(v) => { setStatus(v); setPage(1); }} options={[{ value: 'active', label: 'Active' }, { value: 'resolved', label: 'Resolved' }, { value: 'closed', label: 'Closed' }]} /><Btn variant="primary" icon={Plus} onClick={() => setCreating(true)}>New incident</Btn></>} />
      <Async state={state} rows={6}>
        {(res) => (
          <>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <Kpi label="Open" value={fmt(res.meta.counts.open ?? 0)} tone={(res.meta.counts.open ?? 0) > 0 ? 'red' : undefined} />
              <Kpi label="Investigating" value={fmt(res.meta.counts.investigating ?? 0)} />
              <Kpi label="Mitigated" value={fmt(res.meta.counts.mitigated ?? 0)} />
              <Kpi label="Mean time to resolve (90 d)" value={res.meta.mean_minutes_to_resolve_90d === null ? 'No data' : res.meta.mean_minutes_to_resolve_90d < 120 ? `${res.meta.mean_minutes_to_resolve_90d} min` : `${Math.round(res.meta.mean_minutes_to_resolve_90d / 60)} h`} />
            </div>
            <Card><DataTable caption="Incidents" columns={columns} rows={res.data} rowKey={(i) => i.id} onRowClick={(i) => navigate(`/admin/incidents/${i.id}`)} empty={<p className="py-8 text-center text-sm text-adm-muted">No incidents in this view.</p>} /><Pagination meta={res.meta} onPage={setPage} /></Card>
            <NewIncidentDialog open={creating} options={res.meta.options} onClose={() => setCreating(false)} onCreated={(id) => navigate(`/admin/incidents/${id}`)} />
          </>
        )}
      </Async>
    </div>
  );
};

const NewIncidentDialog: React.FC<{ open: boolean; options: { categories: string[]; severities: string[] }; onClose: () => void; onCreated: (id: number) => void }> = ({ open, options, onClose, onCreated }) => {
  const [form, setForm] = useState({ title: '', category: 'availability', severity: 'medium', service: '', summary: '' });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    try { onCreated((await adminApi.createIncident({ ...form, service: form.service || undefined })).data.id); } catch (err) { setError(err instanceof Error ? err.message : 'Could not open the incident.'); } finally { setBusy(false); }
  };
  return (
    <Dialog open={open} title="New incident" onClose={onClose} footer={<><Btn onClick={onClose}>Cancel</Btn><Btn variant="primary" type="submit" form="new-incident" loading={busy}>Open incident</Btn></>}>
      <form id="new-incident" className="space-y-3" onSubmit={(e) => { void submit(e); }}>
        {error && <Notice tone="error">{error}</Notice>}
        <Field label="Title">{(id) => <input id={id} required maxLength={160} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className={inputCls} />}</Field>
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label="Category" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} options={options.categories.map((c) => ({ value: c, label: humanize(c) }))} />
          <Select label="Severity" value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value })} options={options.severities.map((c) => ({ value: c, label: humanize(c) }))} />
        </div>
        <Field label="Affected service (optional)">{(id) => <input id={id} value={form.service} onChange={(e) => setForm({ ...form, service: e.target.value })} className={inputCls} placeholder="e.g. ai_service" />}</Field>
        <Field label="What is happening?">{(id) => <textarea id={id} rows={4} value={form.summary} onChange={(e) => setForm({ ...form, summary: e.target.value })} className={cn(inputCls, 'h-auto py-2')} />}</Field>
      </form>
    </Dialog>
  );
};

export const IncidentDetailPage: React.FC = () => {
  const id = Number(useParams().incidentId);
  const state = useAdminData(() => adminApi.incident(id), [id]);
  const action = useAction();
  const [note, setNote] = useState('');
  const [rca, setRca] = useState<{ root_cause: string; corrective_actions: string } | null>(null);

  const update = async (body: Partial<IncidentDetail>) => { const r = await action.run('update', () => adminApi.updateIncident(id, body)); if (r) state.setData(r.data); };

  return (
    <div className="space-y-6">
      <Link to="/admin/incidents" className="inline-flex items-center gap-1 text-sm text-adm-muted hover:text-adm-ink"><ArrowLeft className="h-4 w-4" aria-hidden="true" />All incidents</Link>
      {action.notice && <Notice tone={action.notice.tone} onClose={() => action.setNotice(null)}>{action.notice.text}</Notice>}
      <Async state={state} rows={8}>
        {(i) => (
          <>
            <PageHeader title={`${i.reference} · ${i.title}`} meta={<span className="flex flex-wrap gap-1.5"><SeverityBadge severity={i.severity} /><StatusBadge status={i.status} /><Pill>{humanize(i.category)}</Pill>{i.service && <Pill tone="blue">{i.service}</Pill>}</span>}
              actions={<>
                <Select hideLabel label="Status" value={i.status} onChange={(e) => { void update({ status: e.target.value as IncidentDetail['status'] }); }} options={['open', 'investigating', 'mitigated', 'resolved', 'closed'].map((s) => ({ value: s, label: humanize(s) }))} />
                <Select hideLabel label="Severity" value={i.severity} onChange={(e) => { void update({ severity: e.target.value as IncidentDetail['severity'] }); }} options={['critical', 'high', 'medium', 'low'].map((s) => ({ value: s, label: humanize(s) }))} />
              </>} />
            <div className="grid gap-6 lg:grid-cols-3">
              <div className="space-y-6 lg:col-span-2">
                <Card title="Summary"><p className="whitespace-pre-wrap text-sm">{i.summary || <span className="text-adm-muted">No summary.</span>}</p></Card>
                <Card title="Root cause & corrective actions" description="Required before the incident can be resolved"
                  actions={rca ? <><Btn size="sm" onClick={() => setRca(null)}>Cancel</Btn><Btn size="sm" variant="primary" loading={action.busy === 'update'} onClick={() => { void update(rca).then(() => setRca(null)); }}>Save</Btn></>
                    : <Btn size="sm" onClick={() => setRca({ root_cause: i.root_cause ?? '', corrective_actions: i.corrective_actions ?? '' })}>Edit</Btn>}>
                  {rca ? (
                    <div className="space-y-3">
                      <Field label="Root cause">{(fid) => <textarea id={fid} rows={4} value={rca.root_cause} onChange={(e) => setRca({ ...rca, root_cause: e.target.value })} className={cn(inputCls, 'h-auto py-2')} />}</Field>
                      <Field label="Corrective actions">{(fid) => <textarea id={fid} rows={4} value={rca.corrective_actions} onChange={(e) => setRca({ ...rca, corrective_actions: e.target.value })} className={cn(inputCls, 'h-auto py-2')} />}</Field>
                    </div>
                  ) : (
                    <KeyValue columns={1} items={[{ label: 'Root cause', value: <span className="whitespace-pre-wrap">{i.root_cause || '—'}</span> }, { label: 'Corrective actions', value: <span className="whitespace-pre-wrap">{i.corrective_actions || '—'}</span> }]} />
                  )}
                </Card>
                <Card title="Timeline">
                  <ol className="relative space-y-4 border-l border-adm-line pl-5">
                    {i.events.map((e) => (
                      <li key={e.id} className="relative">
                        <span className={cn('absolute -left-[25px] top-1.5 h-2 w-2 rounded-full ring-4 ring-adm-surface', e.type === 'note' ? 'bg-adm-blue' : e.type === 'status' ? 'bg-adm-amber' : 'bg-adm-sage')} aria-hidden="true" />
                        <p className="whitespace-pre-wrap text-sm">{e.body}</p>
                        <p className="text-xs text-adm-muted">{humanize(e.type)} · {e.user?.name ?? 'System'} · {fmtDateTime(e.created_at)}</p>
                      </li>
                    ))}
                  </ol>
                  <form className="mt-5 flex flex-col gap-2 sm:flex-row" onSubmit={(e) => { e.preventDefault(); if (!note.trim()) return; void action.run('note', () => adminApi.addIncidentNote(id, note.trim())).then((r) => { if (r) { setNote(''); state.setData(r.data); } }); }}>
                    <label htmlFor="incident-note" className="sr-only">Investigation note</label>
                    <textarea id="incident-note" rows={2} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Add an investigation note…" className={cn(inputCls, 'h-auto flex-1 py-2')} />
                    <Btn type="submit" variant="primary" loading={action.busy === 'note'} disabled={!note.trim()}>Add note</Btn>
                  </form>
                </Card>
              </div>
              <div className="space-y-6">
                <Card title="Details">
                  <KeyValue columns={1} items={[
                    { label: 'Opened', value: `${fmtDateTime(i.opened_at)} by ${i.opened_by?.name ?? 'System'}` },
                    { label: 'Assignee', value: i.assignee?.name ?? '—' },
                    { label: 'Resolved', value: fmtDateTime(i.resolved_at) },
                  ]} />
                </Card>
                <Card title="Linked alerts">
                  {i.alerts.length === 0 ? <p className="text-sm text-adm-muted">None.</p> : <ul className="space-y-2 text-sm">{i.alerts.map((a) => <li key={a.id}><p className="font-medium">{a.title}</p><p className="text-xs text-adm-muted">{humanize(a.status)} · {a.occurrences} check(s)</p></li>)}</ul>}
                </Card>
                <Card title="Related audit events">
                  {i.audit.length === 0 ? <p className="text-sm text-adm-muted">None.</p> : <ul className="space-y-1.5 text-xs">{i.audit.map((a) => <li key={a.id} className="flex justify-between gap-2"><span className="font-mono">{a.action}</span><span className="text-adm-muted">{timeAgo(a.created_at)}</span></li>)}</ul>}
                </Card>
              </div>
            </div>
          </>
        )}
      </Async>
    </div>
  );
};

// ======================================================================================== task center + reports

const OperationRow: React.FC<{ op: AdminOperation }> = ({ op }) => (
  <li className="grid gap-2 px-5 py-3 sm:grid-cols-[1fr_auto] sm:items-center">
    <div className="min-w-0">
      <div className="flex flex-wrap items-center gap-2"><p className="font-medium">{op.label}</p><StatusBadge status={op.status} /><Pill>{humanize(op.type)}</Pill></div>
      <p className="mt-0.5 text-xs text-adm-muted">
        {op.initiated_by?.name ?? 'Scheduler'} · started {fmtDateTime(op.started_at)}{op.duration_ms !== null && ` · ${op.duration_ms < 1000 ? `${op.duration_ms} ms` : `${(op.duration_ms / 1000).toFixed(1)} s`}`}
        {op.total_count !== null && ` · ${fmt(op.total_count)} total`}{op.success_count !== null && ` · ${fmt(op.success_count)} ok`}{op.failure_count ? ` · ${fmt(op.failure_count)} failed/skipped` : ''}
      </p>
      {op.error && <p className="mt-0.5 text-xs text-adm-red">{op.error}</p>}
      {op.summary && Array.isArray((op.summary as { skipped_rows?: unknown[] }).skipped_rows) && (op.summary as { skipped_rows: { line: number; errors: string[] }[] }).skipped_rows.length > 0 && (
        <details className="mt-1 text-xs"><summary className="cursor-pointer text-adm-muted">Skipped rows</summary>
          <ul className="mt-1 space-y-0.5">{(op.summary as { skipped_rows: { line: number; errors: string[] }[] }).skipped_rows.map((r) => <li key={r.line}>Line {r.line}: {r.errors.join(' ')}</li>)}</ul>
        </details>
      )}
    </div>
    {op.result_filename && (op.result_available
      ? <a href={adminApi.operationDownloadUrl(op.id)} className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-adm-line px-2.5 text-xs font-medium hover:bg-adm-warm" target="_blank" rel="noopener noreferrer"><Download className="h-3.5 w-3.5" aria-hidden="true" />Download</a>
      : <span className="text-xs text-adm-muted">File expired</span>)}
  </li>
);

export const TasksPage: React.FC = () => {
  const [type, setType] = useState('');
  const [page, setPage] = useState(1);
  const state = useAdminData(() => adminApi.operations({ type, page, per_page: 20 }), [type, page]);
  return (
    <div className="space-y-6">
      <PageHeader title="Task center" description="Every import, export, scan and health check started from the panel or the scheduler, with the outcome its worker reported. Status changes only when the work itself finishes."
        actions={<Select hideLabel label="Type" value={type} onChange={(e) => { setType(e.target.value); setPage(1); }} options={[{ value: '', label: 'All types' }, ...Object.keys(state.data?.meta.types ?? {}).map((t) => ({ value: t, label: humanize(t) }))]} />} />
      <Card bodyClassName="p-0">
        <Async state={state} rows={6}>
          {(res) => res.data.length === 0 ? <p className="py-10 text-center text-sm text-adm-muted">No operations recorded yet.</p> : <><ul className="divide-y divide-adm-line">{res.data.map((op) => <OperationRow key={op.id} op={op} />)}</ul><div className="px-5 pb-4"><Pagination meta={res.meta} onPage={setPage} /></div></>}
        </Async>
      </Card>
    </div>
  );
};

export const ReportsPage: React.FC = () => {
  const state = useAdminData(() => adminApi.operations({ type: 'export', per_page: 15 }), []);
  const action = useAction();
  const [dataset, setDataset] = useState('users');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const datasets = state.data?.meta.datasets ?? [];

  const create = async () => { const r = await action.run('export', () => adminApi.createExport(dataset, from, to)); if (r) void state.reload(); };

  return (
    <div className="space-y-6">
      <PageHeader title="Reports & exports" description="Fixed, column-controlled CSV exports. No passwords or tokens are ever included, delivery recipients stay masked, and every cell is protected against spreadsheet formula injection." />
      {action.notice && <Notice tone={action.notice.tone} onClose={() => action.setNotice(null)}>{action.notice.text}</Notice>}
      <Card title="New export" description={`Files are stored privately and expire after ${state.data?.meta.ttl_hours ?? 24} h. Creating and downloading are both audited.`}>
        <div className="flex flex-wrap items-end gap-3">
          <Select className="w-64" label="Dataset" value={dataset} onChange={(e) => setDataset(e.target.value)} options={datasets.map((d) => ({ value: d.key, label: d.label }))} />
          <div><label htmlFor="exp-from" className="mb-1 block text-xs font-medium">From (optional)</label><input id="exp-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} className={inputCls} /></div>
          <div><label htmlFor="exp-to" className="mb-1 block text-xs font-medium">To (optional)</label><input id="exp-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} className={inputCls} /></div>
          <Btn variant="primary" icon={FileDown} loading={action.busy === 'export'} onClick={() => { void create(); }}>Generate CSV</Btn>
        </div>
        <p className="mt-3 text-xs text-adm-muted">Date range applies to time-based datasets (audit log, incidents, alerts, failed jobs, e-mail log). Faculty-facing academic reports remain under the faculty Reports module.</p>
      </Card>
      <Card title="Export history" bodyClassName="p-0">
        <Async state={state} rows={4}>
          {(res) => res.data.length === 0 ? <p className="py-8 text-center text-sm text-adm-muted">No exports yet.</p> : <ul className="divide-y divide-adm-line">{res.data.map((op) => <OperationRow key={op.id} op={op} />)}</ul>}
        </Async>
      </Card>
    </div>
  );
};

// ======================================================================================== calendar & schedule

export const SchedulePage: React.FC = () => {
  const windows = useAdminData(() => adminApi.maintenance(), []);
  const schedule = useAdminData(() => adminApi.schedule(), []);
  const action = useAction();
  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState({ title: '', description: '', services: [] as string[], starts_at: '', ends_at: '', suppress_alerts: true });

  const create = async () => {
    const r = await action.run('create', () => adminApi.createMaintenance({ ...form, starts_at: new Date(form.starts_at).toISOString(), ends_at: new Date(form.ends_at).toISOString() }));
    if (r) { setCreating(false); setForm({ title: '', description: '', services: [], starts_at: '', ends_at: '', suppress_alerts: true }); void windows.reload(); }
  };

  return (
    <div className="space-y-6">
      <PageHeader title="Calendar & schedule" description={`Planned maintenance and the scheduled tasks the server runs. Times are shown in your browser's time zone; the server runs on ${schedule.data?.timezone ?? 'its configured time zone'}. Scheduled times are plans — see the Task center for what actually ran.`}
        actions={<Btn variant="primary" icon={CalendarPlus} onClick={() => setCreating(true)}>Plan maintenance</Btn>} />
      {action.notice && <Notice tone={action.notice.tone} onClose={() => action.setNotice(null)}>{action.notice.text}</Notice>}
      <Card title="Maintenance windows" description="Alerts for the listed services are recorded but not notified while a window is in progress">
        <Async state={windows} rows={3}>
          {(res) => res.data.length === 0 ? <p className="text-sm text-adm-muted">No maintenance planned.</p> : (
            <ul className="divide-y divide-adm-line">
              {res.data.map((w) => (
                <li key={w.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
                  <div>
                    <div className="flex flex-wrap items-center gap-2"><p className="font-medium">{w.title}</p><StatusBadge status={w.state} /></div>
                    <p className="text-xs text-adm-muted">{fmtDateTime(w.starts_at)} → {fmtDateTime(w.ends_at)} · {w.services.length ? w.services.join(', ') : 'all services'}{w.suppress_alerts ? ' · alerts suppressed' : ''}</p>
                    {w.description && <p className="mt-1 text-sm">{w.description}</p>}
                  </div>
                  {(w.state === 'scheduled' || w.state === 'in_progress') && <Btn size="sm" onClick={() => { void action.run('cancel', () => adminApi.cancelMaintenance(w.id)).then((r) => { if (r) void windows.reload(); }); }}>Cancel</Btn>}
                </li>
              ))}
            </ul>
          )}
        </Async>
      </Card>
      <Card title="Scheduled tasks" description={schedule.data ? `Scheduler heartbeat ${timeAgo(schedule.data.heartbeat_at)}` : undefined} bodyClassName="p-0">
        <Async state={schedule} rows={6}>
          {(s) => (
            <div className="overflow-x-auto"><table className="min-w-full text-sm">
              <caption className="sr-only">Scheduled tasks</caption>
              <thead><tr className="border-b border-adm-line text-left text-[11px] uppercase tracking-[0.06em] text-adm-muted"><th className="px-5 py-2">Command</th><th className="px-3 py-2">Cron</th><th className="px-3 py-2">Next run</th><th className="px-5 py-2">Overlap guard</th></tr></thead>
              <tbody className="divide-y divide-adm-line">
                {s.tasks.map((t) => (
                  <tr key={t.command}><td className="px-5 py-2 font-mono text-xs">{t.command}</td><td className="px-3 py-2 font-mono text-xs text-adm-muted">{t.expression}</td>
                    <td className="px-3 py-2 text-xs">{fmtDateTime(t.next_run_at)} <span className="text-adm-muted">({timeAgo(t.next_run_at)})</span></td><td className="px-5 py-2">{t.without_overlapping ? <Pill tone="sage">Yes</Pill> : <Pill>No</Pill>}</td></tr>
                ))}
              </tbody>
            </table></div>
          )}
        </Async>
      </Card>
      <Dialog open={creating} title="Plan maintenance" onClose={() => setCreating(false)} footer={<><Btn onClick={() => setCreating(false)}>Cancel</Btn><Btn variant="primary" loading={action.busy === 'create'} disabled={!form.title || !form.starts_at || !form.ends_at} onClick={() => { void create(); }}>Schedule</Btn></>}>
        <div className="space-y-3">
          <Field label="Title">{(id) => <input id={id} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className={inputCls} />}</Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Starts">{(id) => <input id={id} type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} className={inputCls} />}</Field>
            <Field label="Ends (max 72 h later)">{(id) => <input id={id} type="datetime-local" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} className={inputCls} />}</Field>
          </div>
          <fieldset><legend className="mb-1 text-xs font-medium">Affected services (none = all)</legend>
            <div className="flex flex-wrap gap-2">{(windows.data?.meta.services ?? []).map((s) => (
              <label key={s.key} className="inline-flex items-center gap-1.5 rounded-lg border border-adm-line px-2 py-1 text-xs">
                <input type="checkbox" className="accent-[#1E6F5C]" checked={form.services.includes(s.key)} onChange={(e) => setForm({ ...form, services: e.target.checked ? [...form.services, s.key] : form.services.filter((x) => x !== s.key) })} />{s.label}
              </label>
            ))}</div>
          </fieldset>
          <Field label="Notes">{(id) => <textarea id={id} rows={3} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className={cn(inputCls, 'h-auto py-2')} />}</Field>
          <div className="flex items-center justify-between rounded-lg border border-adm-line bg-adm-warm px-3 py-2 text-xs"><span>Suppress alert notifications during the window</span><Switch checked={form.suppress_alerts} onChange={(v) => setForm({ ...form, suppress_alerts: v })} label="Suppress alerts" /></div>
        </div>
      </Dialog>
    </div>
  );
};

// ======================================================================================== backups

export const BackupsPage: React.FC = () => {
  const state = useAdminData(() => adminApi.backups(), []);
  const action = useAction();
  const [deleting, setDeleting] = useState<BackupRecord | null>(null);
  const [tab, setTab] = useState('backups');
  const run = async (key: string, fn: () => Promise<{ message?: string }>) => { const r = await action.run(key, fn); if (r) void state.reload(); };

  const columns: Column<BackupRecord>[] = [
    { key: 'file', header: 'Backup', render: (b) => <div><p className="break-all font-mono text-xs">{b.filename}</p><p className="text-xs text-adm-muted">{humanize(b.trigger)}{b.triggered_by ? ` by ${b.triggered_by.name}` : ''} · {fmtDateTime(b.completed_at ?? b.started_at)}</p>{b.error && <p className="text-xs text-adm-red">{b.error}</p>}</div> },
    { key: 'size', header: 'Size', className: 'tabular-nums whitespace-nowrap', render: (b) => formatBytes(b.size_bytes) },
    { key: 'status', header: 'Status', render: (b) => <StatusBadge status={b.status} /> },
    { key: 'integrity', header: 'Integrity', render: (b) => b.verification_status ? <span><StatusBadge status={b.verification_status === 'verified' ? 'verified' : 'failed'} label={b.verification_status === 'verified' ? 'Verified' : 'Verification failed'} /><span className="block text-[11px] text-adm-muted">{timeAgo(b.verified_at)}</span></span> : <Pill>Not verified</Pill> },
    {
      key: 'actions', header: 'Actions', render: (b) => b.status === 'completed' ? (
        <div className="flex flex-wrap gap-1">
          <Btn size="sm" icon={ShieldCheck} loading={action.busy === `verify-${b.id}`} onClick={() => { void run(`verify-${b.id}`, () => adminApi.verifyBackup(b.id)); }}>Verify</Btn>
          <a href={adminApi.backupDownloadUrl(b.id)} className="inline-flex h-8 items-center gap-1 rounded-lg border border-adm-line px-2.5 text-xs font-medium hover:bg-adm-warm" target="_blank" rel="noopener noreferrer"><Download className="h-3.5 w-3.5" aria-hidden="true" />Download</a>
          <Btn size="sm" variant="ghost" icon={Trash2} onClick={() => setDeleting(b)} aria-label="Delete backup" />
        </div>
      ) : null,
    },
  ];

  return (
    <div className="space-y-6">
      <PageHeader title="Backup & recovery" description="Gzipped database snapshots in private storage with a SHA-256 checksum. Verification re-hashes and fully decompresses the archive; it proves the file is intact, not that a restore has been rehearsed."
        actions={<Btn variant="primary" icon={Database} loading={action.busy === 'create'} onClick={() => { void run('create', () => adminApi.createBackup()); }}>Back up now</Btn>} />
      {action.notice && <Notice tone={action.notice.tone} onClose={() => action.setNotice(null)}>{action.notice.text}</Notice>}
      <Async state={state} rows={6}>
        {(res) => (
          <>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <Kpi label="Last successful" value={res.meta.last_completed ? timeAgo(res.meta.last_completed.completed_at) : 'None'} tone={res.meta.last_completed ? undefined : 'red'} />
              <Kpi label="Schedule" value={`Daily ${res.meta.schedule_time}`} hint={`keeps newest ${res.meta.keep}`} />
              <Kpi label="Failed (30 d)" value={fmt(res.meta.failed_30d)} tone={res.meta.failed_30d ? 'amber' : undefined} />
              <Kpi label="Storage" value={res.meta.disk} hint={`${res.meta.driver} database`} />
            </div>
            <Tabs label="Backup sections" active={tab} onChange={setTab} tabs={[{ key: 'backups', label: 'Backups', count: res.data.length }, { key: 'recovery', label: 'Recovery procedure' }]} />
            {tab === 'backups' ? (
              <Card><DataTable caption="Database backups" columns={columns} rows={res.data} rowKey={(b) => b.id} empty={<p className="py-8 text-center text-sm text-adm-muted">No backups yet. Use “Back up now” or start the scheduler.</p>} /></Card>
            ) : (
              <Card title="Restore checklist" description={res.meta.restore.reason}>
                <ol className="list-decimal space-y-2 pl-5 text-sm">
                  <li>Open an incident and plan a maintenance window covering <em>database</em>.</li>
                  <li>Verify the chosen backup here (checksum + full decompression).</li>
                  <li>Take a fresh backup of the current state so the restore itself can be undone.</li>
                  <li>Stop queue workers (Horizon) and the scheduler so nothing writes during the restore.</li>
                  <li>On the server run <code className="rounded bg-adm-cream px-1">{res.meta.restore.command}</code> and type the confirmation.</li>
                  <li>Restart workers, run a health check and a data-quality scan, then record the outcome in the incident.</li>
                </ol>
                <p className="mt-3 text-xs text-adm-muted">Uploaded files (documents, profile pictures, reports) live in storage/app and are not part of database backups; back that directory up separately.</p>
              </Card>
            )}
          </>
        )}
      </Async>
      <ConfirmDialog open={deleting !== null} tone="danger" title="Delete this backup?" confirmLabel="Delete backup" typeToConfirm="DELETE" busy={action.busy === 'delete'}
        body={<>The file <span className="break-all font-mono text-xs">{deleting?.filename}</span> is removed from storage. This cannot be undone.</>}
        onCancel={() => setDeleting(null)} onConfirm={() => { const b = deleting!; setDeleting(null); void run('delete', () => adminApi.deleteBackup(b.id)); }} />
    </div>
  );
};
