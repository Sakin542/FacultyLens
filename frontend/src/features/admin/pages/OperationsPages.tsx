import React, { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Activity, ExternalLink, PlayCircle, RotateCcw, Trash2 } from 'lucide-react';
import { adminApi } from '../api';
import { useAction, useAdminData, useDebounced } from '../hooks';
import type { EmailDeliveryRow, FailedJob, InstitutionCourse } from '../types';
import { BACKEND_URL } from '@/services/api';
import {
  Async, Btn, Card, Column, ConfirmDialog, DataTable, KeyValue, Kpi, Notice, PageHeader, Pagination, Pill, Segmented, Select, StateBadge,
  StatusBadge, fmt, fmtDateTime, humanize, inputCls, timeAgo,
} from '../components/ui';
import { AvailabilityStrip, CHART, DailyBars, ShareBar } from '../components/charts';

// ======================================================================================== institution

export const InstitutionPage: React.FC = () => {
  const [params, setParams] = useSearchParams();
  const [search, setSearch] = useState(params.get('search') ?? '');
  const term = useDebounced(search, 300);
  const filters = { search: term, department: params.get('department') ?? '', gap: params.get('gap') ?? '', status: params.get('status') ?? '', page: Number(params.get('page') ?? 1) };
  const setFilter = (k: string, v: string) => { const n = new URLSearchParams(params); if (v) n.set(k, v); else n.delete(k); if (k !== 'page') n.delete('page'); setParams(n, { replace: true }); };
  const overview = useAdminData(() => adminApi.institution(), []);
  const courses = useAdminData(() => adminApi.institutionCourses({ ...filters, per_page: 15 }), [JSON.stringify(filters)]);

  const columns: Column<InstitutionCourse>[] = [
    { key: 'course', header: 'Course', render: (c) => <div><p className="font-medium">{c.course_code}</p><p className="text-xs text-adm-muted">{c.course_name}</p></div> },
    { key: 'term', header: 'Term', render: (c) => <span className="whitespace-nowrap text-xs">{c.semester} {c.academic_year}</span> },
    { key: 'owner', header: 'Owner', render: (c) => <div><p>{c.owner.name}</p><p className="text-xs text-adm-muted">{c.department ?? 'No department'}</p></div> },
    { key: 'program', header: 'Program', render: (c) => c.program_code ?? <span className="text-adm-muted">—</span> },
    { key: 'counts', header: 'COs · assessments', render: (c) => <span className="tabular-nums">{c.outcomes_count} · {c.assessments_count}</span> },
    { key: 'status', header: 'Status', render: (c) => <StatusBadge status={c.status} /> },
    { key: 'gaps', header: 'Metadata gaps', render: (c) => c.gaps.length ? <div className="flex flex-wrap gap-1">{c.gaps.map((g) => <Pill key={g} tone="amber">{g}</Pill>)}</div> : <Pill tone="sage">Complete</Pill> },
  ];

  return (
    <div className="space-y-6">
      <PageHeader title="Academic structure" description="Read-only oversight of departments, programs, terms and course metadata. Course content, grades and outcome decisions stay with faculty." />
      <Async state={overview} rows={6}>
        {(d) => (
          <>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <Kpi label="Departments" value={fmt(d.totals.departments)} hint={`${fmt(d.totals.users_without_department)} account(s) without one`} />
              <Kpi label="Programs" value={fmt(d.totals.programs)} />
              <Kpi label="Courses" value={fmt(d.totals.courses)} hint={`${fmt(d.totals.active_courses)} active`} />
              <Kpi label="Learning outcomes · assessments" value={`${fmt(d.totals.learning_outcomes)} · ${fmt(d.totals.assessments)}`} />
            </div>
            <div className="grid gap-6 lg:grid-cols-3">
              <Card title="Departments" description="Derived from accounts and programs" className="lg:col-span-2" bodyClassName="p-0">
                <div className="overflow-x-auto"><table className="min-w-full text-sm">
                  <caption className="sr-only">Departments</caption>
                  <thead><tr className="border-b border-adm-line text-left text-[11px] uppercase tracking-[0.06em] text-adm-muted"><th className="px-5 py-2">Department</th><th className="px-3 py-2">Members</th><th className="px-3 py-2">Heads</th><th className="px-3 py-2">Courses</th><th className="px-3 py-2">Programs</th><th className="px-5 py-2" /></tr></thead>
                  <tbody className="divide-y divide-adm-line">
                    {d.departments.length === 0 && <tr><td colSpan={6} className="px-5 py-6 text-center text-adm-muted">No departments recorded.</td></tr>}
                    {d.departments.map((dep) => (
                      <tr key={dep.name}>
                        <td className="px-5 py-2"><button type="button" className="font-medium hover:underline" onClick={() => setFilter('department', dep.name)}>{dep.name}</button></td>
                        <td className="px-3 py-2 tabular-nums">{dep.members}</td><td className="px-3 py-2 tabular-nums">{dep.heads}</td><td className="px-3 py-2 tabular-nums">{dep.courses}</td><td className="px-3 py-2 tabular-nums">{dep.programs}</td>
                        <td className="px-5 py-2">{dep.without_head && <Pill tone="amber">No department head</Pill>}</td>
                      </tr>
                    ))}
                  </tbody>
                </table></div>
              </Card>
              <Card title="Terms">
                <ul className="divide-y divide-adm-line text-sm">
                  {d.terms.length === 0 && <li className="py-2 text-adm-muted">No courses yet.</li>}
                  {d.terms.map((t) => <li key={`${t.academic_year}-${t.semester}`} className="flex justify-between py-1.5"><span>{t.semester} {t.academic_year}</span><span className="text-xs text-adm-muted">{t.courses} course(s) · {t.active} active</span></li>)}
                </ul>
              </Card>
            </div>
            <Card title="Programs" bodyClassName="p-0">
              <div className="overflow-x-auto"><table className="min-w-full text-sm">
                <caption className="sr-only">Programs</caption>
                <thead><tr className="border-b border-adm-line text-left text-[11px] uppercase tracking-[0.06em] text-adm-muted"><th className="px-5 py-2">Program</th><th className="px-3 py-2">Department</th><th className="px-3 py-2">Program outcomes</th><th className="px-3 py-2">Courses</th><th className="px-5 py-2">Status</th></tr></thead>
                <tbody className="divide-y divide-adm-line">
                  {d.programs.length === 0 && <tr><td colSpan={5} className="px-5 py-6 text-center text-adm-muted">No programs defined.</td></tr>}
                  {d.programs.map((p) => (
                    <tr key={p.id}><td className="px-5 py-2"><span className="font-medium">{p.code}</span> <span className="text-adm-muted">{p.name}</span></td><td className="px-3 py-2">{p.department ?? '—'}</td>
                      <td className="px-3 py-2">{p.outcomes === 0 ? <Pill tone="amber">None</Pill> : p.outcomes}</td><td className="px-3 py-2 tabular-nums">{p.courses}</td><td className="px-5 py-2"><StatusBadge status={p.status} /></td></tr>
                  ))}
                </tbody>
              </table></div>
            </Card>
          </>
        )}
      </Async>
      <Card title="Course metadata" description="Filter for missing outcomes, programs or assessments">
        <div className="mb-4 grid gap-3 sm:grid-cols-4">
          <div className="sm:col-span-2"><label htmlFor="course-search" className="mb-1 block text-xs font-medium">Search</label><input id="course-search" type="search" value={search} onChange={(e) => { setSearch(e.target.value); setFilter('search', e.target.value); }} placeholder="Code, name or owner" className={inputCls} /></div>
          <Select label="Gap" value={filters.gap} onChange={(e) => setFilter('gap', e.target.value)} options={[{ value: '', label: 'Any' }, { value: 'no_outcomes', label: 'No learning outcomes' }, { value: 'no_program', label: 'No program' }, { value: 'no_assessments', label: 'No assessments' }]} />
          <Select label="Status" value={filters.status} onChange={(e) => setFilter('status', e.target.value)} options={[{ value: '', label: 'Any' }, { value: 'active', label: 'Active' }, { value: 'archived', label: 'Archived' }]} />
        </div>
        {filters.department && <p className="mb-3 text-xs">Department: <Pill tone="blue">{filters.department}</Pill> <button type="button" className="ml-1 text-adm-sage hover:underline" onClick={() => setFilter('department', '')}>clear</button></p>}
        <Async state={courses} rows={6}>
          {(res) => <><DataTable caption="Courses" columns={columns} rows={res.data} rowKey={(c) => c.id} /><Pagination meta={res.meta} onPage={(p) => setFilter('page', String(p))} /></>}
        </Async>
      </Card>
    </div>
  );
};

// ======================================================================================== system health

export const HealthPage: React.FC = () => {
  const [hours, setHours] = useState(24);
  const [live, setLive] = useState(false);
  const state = useAdminData(() => adminApi.health(hours, live), [hours, live]);
  const action = useAction();

  const runCheck = async () => {
    const res = await action.run('check', () => adminApi.runHealthCheck());
    if (res) { setLive(false); void state.reload(); }
  };

  return (
    <div className="space-y-6">
      <PageHeader title="System health" description="Bounded probes (≤ 3 s) of every service. A service shows Healthy only after a probe succeeded; services that are not set up show Unconfigured, and services that cannot be observed show Unknown."
        meta={state.data && <>{state.data.live ? 'Live probe' : `Last recorded check ${timeAgo(state.data.checked_at)}`} · scheduler heartbeat {timeAgo(state.data.scheduler_heartbeat_at)}</>}
        actions={<>
          <Segmented label="History window" value={hours} onChange={(v) => setHours(Number(v))} options={[{ value: 6, label: '6 h' }, { value: 24, label: '24 h' }, { value: 72, label: '3 d' }, { value: 168, label: '7 d' }]} />
          <Btn icon={Activity} onClick={() => setLive(true)} loading={state.loading && live}>Probe now</Btn>
          <Btn icon={PlayCircle} variant="primary" loading={action.busy === 'check'} onClick={() => { void runCheck(); }}>Record check & evaluate alerts</Btn>
        </>} />
      {action.notice && <Notice tone={action.notice.tone} onClose={() => action.setNotice(null)}>{action.notice.text}</Notice>}
      <Async state={state} rows={10}>
        {(d) => {
          const groups = [...new Set(d.services.map((s) => s.group))];
          return (
            <div className="space-y-6">
              {groups.map((g) => (
                <Card key={g} title={g} bodyClassName="p-0">
                  <ul className="divide-y divide-adm-line">
                    {d.services.filter((s) => s.group === g).map((s) => {
                      const h = d.history[s.service];
                      return (
                        <li key={s.service} className="grid gap-3 px-5 py-4 lg:grid-cols-[1.2fr_1fr_1.4fr]" data-testid={`service-${s.service}`}>
                          <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2"><p className="font-medium">{s.label}</p><StateBadge state={s.status} /></div>
                            <p className="mt-0.5 text-xs text-adm-muted">{s.summary ?? describeDetail(s.detail)}</p>
                          </div>
                          <KeyValue columns={2} items={[
                            { label: 'Response', value: s.response_ms === null ? '—' : `${s.response_ms} ms` },
                            { label: 'Avg response', value: h?.avg_response_ms === null || !h ? '—' : `${h.avg_response_ms} ms` },
                            { label: 'Last success', value: timeAgo(h?.last_success_at) },
                            { label: `Failures (${d.hours} h)`, value: h ? `${h.failures} / ${h.checks}` : '—' },
                          ]} />
                          {h && <AvailabilityStrip series={h.series} label={`${s.label} availability`} />}
                        </li>
                      );
                    })}
                  </ul>
                </Card>
              ))}
            </div>
          );
        }}
      </Async>
    </div>
  );
};

const describeDetail = (detail: Record<string, unknown>): string => {
  const parts = Object.entries(detail ?? {}).filter(([, v]) => v !== null && typeof v !== 'object').map(([k, v]) => `${humanize(k)}: ${String(v)}`);
  return parts.join(' · ') || 'No details.';
};

// ======================================================================================== environment

export const EnvironmentPage: React.FC = () => {
  const state = useAdminData(() => adminApi.environment(), []);
  return (
    <div className="space-y-6">
      <PageHeader title="Environment" description="Operational metadata only. Values of secrets are never sent to the browser — configuration rows show whether a value is present, not what it is." />
      <Async state={state} rows={8}>
        {(d) => (
          <>
            {d.application.debug_warning && <Notice tone="error">Debug mode is enabled in production. Set APP_DEBUG=false.</Notice>}
            <div className="grid gap-6 lg:grid-cols-2">
              <Card title="Application">
                <KeyValue items={[
                  { label: 'Environment', value: <Pill tone={d.application.environment === 'production' ? 'sage' : 'amber'}>{d.application.environment}</Pill> },
                  { label: 'Version', value: <span className="font-mono text-xs">{d.application.version}</span> },
                  { label: 'Debug', value: d.application.debug ? 'On' : 'Off' },
                  { label: 'Time zone', value: d.application.timezone },
                  { label: 'PHP · Laravel', value: `${d.application.php} · ${d.application.laravel}` },
                  { label: 'Locale', value: d.application.locale },
                ]} />
              </Card>
              <Card title="Drivers">
                <KeyValue items={Object.entries(d.drivers).map(([k, v]) => ({ label: humanize(k), value: <span className="font-mono text-xs">{v}</span> }))} />
              </Card>
              <Card title="Configuration status">
                <ul className="divide-y divide-adm-line text-sm">
                  {d.configuration.map((c) => (
                    <li key={c.key} className="flex items-center justify-between gap-3 py-2">
                      <span>{c.label}<span className="block font-mono text-[11px] text-adm-muted">{c.key}</span></span>
                      {c.configured ? <Pill tone="sage">Configured</Pill> : <Pill>Not configured</Pill>}
                    </li>
                  ))}
                </ul>
              </Card>
              <Card title="Database migrations">
                {d.migrations.error ? <Notice tone="error">Could not read migration status ({d.migrations.error}).</Notice> : (
                  <>
                    <KeyValue items={[
                      { label: 'Applied', value: `${fmt(d.migrations.ran)} of ${fmt(d.migrations.files)}` },
                      { label: 'Last batch', value: fmt(d.migrations.last_batch) },
                      { label: 'Latest', value: <span className="break-all font-mono text-[11px]">{d.migrations.latest}</span> },
                      { label: 'AI timeouts', value: `${d.ai.connect_timeout_seconds} s connect · ${d.ai.request_timeout_seconds} s request` },
                    ]} />
                    {(d.migrations.pending?.length ?? 0) > 0
                      ? <Notice tone="warning" className="mt-3">{d.migrations.pending!.length} pending migration(s). Run <code>php artisan migrate</code> during a maintenance window; the panel never runs migrations.</Notice>
                      : <p className="mt-3 text-xs text-adm-sage">All migrations applied.</p>}
                  </>
                )}
              </Card>
            </div>
          </>
        )}
      </Async>
    </div>
  );
};

// ======================================================================================== queues

export const QueuesPage: React.FC = () => {
  const summary = useAdminData(() => adminApi.queues(), []);
  const [page, setPage] = useState(1);
  const failed = useAdminData(() => adminApi.failedJobs({ page, per_page: 15 }), [page]);
  const action = useAction();
  const [confirm, setConfirm] = useState<{ job: FailedJob; kind: 'retry' | 'forget' } | null>(null);

  const act = async () => {
    if (!confirm) return;
    const { job, kind } = confirm;
    const res = await action.run(kind, () => kind === 'retry' ? adminApi.retryJob(job.uuid) : adminApi.forgetJob(job.uuid));
    setConfirm(null);
    if (res) { void failed.reload(); void summary.reload(); }
  };

  const columns: Column<FailedJob>[] = [
    { key: 'job', header: 'Job', render: (j) => <div><p className="font-medium">{j.job_name}</p><p className="text-xs text-adm-muted">{j.queue} · {j.connection}</p></div> },
    { key: 'exception', header: 'Failure (scrubbed)', className: 'max-w-md', render: (j) => <p className="break-words font-mono text-[11px] text-adm-ink">{j.exception}</p> },
    { key: 'when', header: 'Failed', render: (j) => <span className="whitespace-nowrap text-xs text-adm-muted" title={fmtDateTime(j.failed_at)}>{timeAgo(j.failed_at)}</span> },
    {
      key: 'actions', header: 'Actions', render: (j) => (
        <div className="flex flex-wrap gap-1">
          <Btn size="sm" icon={RotateCcw} disabled={!j.retryable} title={j.retry_policy} onClick={() => setConfirm({ job: j, kind: 'retry' })}>Retry</Btn>
          <Btn size="sm" variant="ghost" icon={Trash2} onClick={() => setConfirm({ job: j, kind: 'forget' })}>Dismiss</Btn>
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-6">
      <PageHeader title="Queues & jobs" description="Queue depth and failed jobs. Job payloads are never shown, jobs cannot be created from here, and only job types that are safe to repeat can be retried."
        actions={summary.data?.horizon_available && <a href={`${BACKEND_URL}${summary.data.horizon_path}`} target="_blank" rel="noopener noreferrer" className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-adm-line bg-adm-surface px-3.5 text-sm font-medium hover:bg-adm-warm">Open Horizon<ExternalLink className="h-3.5 w-3.5" aria-hidden="true" /></a>} />
      {action.notice && <Notice tone={action.notice.tone} onClose={() => action.setNotice(null)}>{action.notice.text}</Notice>}
      <Async state={summary} rows={5}>
        {(s) => (
          <>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <Kpi label="Queue driver" value={s.driver} hint={s.horizon_available ? 'Horizon available' : 'Horizon not in use'} />
              <Kpi label="Pending" value={fmt(s.queues.reduce((a, q) => a + (q.pending ?? 0), 0))} hint={`${s.queues.length} queue(s)`} />
              <Kpi label="Failed (24 h)" value={fmt(s.failed_24h)} tone={s.failed_24h ? 'amber' : undefined} />
              <Kpi label="Failed (all kept)" value={fmt(s.failed_total)} />
            </div>
            <div className="grid gap-6 lg:grid-cols-3">
              <Card title="Failed jobs per day" description="Last 15 days" className="lg:col-span-2"><DailyBars data={s.failed_trend} label="Failed jobs per day" /></Card>
              <Card title="Queue depth">
                <ul className="divide-y divide-adm-line text-sm">
                  {s.queues.map((q) => <li key={q.queue} className="flex justify-between py-1.5"><span className="font-mono text-xs">{q.queue}</span><span className="tabular-nums">{q.pending === null ? <Pill tone="blue">{q.error ?? 'unknown'}</Pill> : fmt(q.pending)}</span></li>)}
                </ul>
                {s.failed_by_job.length > 0 && <>
                  <p className="mt-4 text-xs font-medium">Most failing (30 d)</p>
                  <ul className="mt-1 text-xs text-adm-muted">{s.failed_by_job.map((f) => <li key={f.job} className="flex justify-between py-0.5"><span>{f.job}</span><span className="tabular-nums">{f.count}</span></li>)}</ul>
                </>}
              </Card>
            </div>
          </>
        )}
      </Async>
      <Card title="Failed jobs">
        <Async state={failed} rows={5}>
          {(res) => <><DataTable caption="Failed jobs" columns={columns} rows={res.data} rowKey={(j) => j.uuid} empty={<p className="py-8 text-center text-sm text-adm-muted">No failed jobs.</p>} /><Pagination meta={res.meta} onPage={setPage} /></>}
        </Async>
      </Card>
      {summary.data && (
        <Card title="Retry policy" description="Why each job type is or is not retryable">
          <ul className="grid gap-2 text-sm md:grid-cols-2">
            {summary.data.retry_policy.map((p) => <li key={p.job} className="rounded-lg border border-adm-line px-3 py-2"><div className="flex items-center justify-between"><span className="font-medium">{p.job}</span>{p.retryable ? <Pill tone="sage">Retryable</Pill> : <Pill>Not retryable</Pill>}</div><p className="mt-0.5 text-xs text-adm-muted">{p.reason}</p></li>)}
          </ul>
        </Card>
      )}
      <ConfirmDialog open={confirm !== null} title={confirm?.kind === 'retry' ? `Retry ${confirm.job.job_name}?` : 'Dismiss this failed job?'}
        tone={confirm?.kind === 'forget' ? 'danger' : 'primary'} confirmLabel={confirm?.kind === 'retry' ? 'Retry job' : 'Dismiss'} busy={action.busy !== null}
        body={confirm?.kind === 'retry' ? confirm.job.retry_policy : 'The failure record is removed from the list (the dismissal is audited). The job is not run again.'}
        onCancel={() => setConfirm(null)} onConfirm={() => { void act(); }} />
    </div>
  );
};

// ======================================================================================== e-mail

export const EmailPage: React.FC = () => {
  const [days, setDays] = useState(30);
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const summary = useAdminData(() => adminApi.email(days), [days]);
  const rows = useAdminData(() => adminApi.emailDeliveries({ status, page, per_page: 15 }), [status, page]);

  const columns: Column<EmailDeliveryRow>[] = [
    { key: 'when', header: 'Queued', render: (r) => <span className="whitespace-nowrap text-xs text-adm-muted">{fmtDateTime(r.queued_at ?? r.created_at)}</span> },
    { key: 'template', header: 'Template', render: (r) => <span className="font-mono text-xs">{r.template}</span> },
    { key: 'to', header: 'Recipient', render: (r) => <span className="font-mono text-xs">{r.recipient}</span> },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'attempts', header: 'Attempts', className: 'tabular-nums', render: (r) => r.attempts },
    { key: 'error', header: 'Error', render: (r) => r.error_code ? <Pill tone="red">{r.error_code}</Pill> : '—' },
  ];

  return (
    <div className="space-y-6">
      <PageHeader title="E-mail & notifications" description="The existing delivery pipeline (queue → worker → SMTP) and in-app notifications. Recipients are masked; message bodies are not stored."
        actions={<Segmented label="Window" value={days} onChange={(v) => setDays(Number(v))} options={[{ value: 7, label: '7 d' }, { value: 30, label: '30 d' }, { value: 90, label: '90 d' }]} />} />
      <Async state={summary} rows={6}>
        {(s) => (
          <>
            {!s.delivering && <Notice tone="warning">The mailer is “{s.mailer}”: e-mails are recorded but not delivered. Configure MAIL_* to send.</Notice>}
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <Kpi label="Sent" value={fmt(s.by_status.SENT)} />
              <Kpi label="Failed" value={fmt(s.by_status.FAILED)} tone={s.by_status.FAILED ? 'red' : undefined} />
              <Kpi label="Stuck > 30 min" value={fmt(s.stuck)} tone={s.stuck ? 'amber' : undefined} hint={`${fmt(s.by_status.PENDING + s.by_status.PROCESSING)} pending now`} />
              <Kpi label="Delivery latency" value={s.latency_seconds ? `${s.latency_seconds.median} s` : 'Not measured'} hint={s.latency_seconds ? `p95 ${s.latency_seconds.p95} s · ${s.latency_seconds.sample} sends` : 'no sent mail in window'} />
            </div>
            <div className="grid gap-6 lg:grid-cols-3">
              <Card title="Deliveries per day" className="lg:col-span-2"><DailyBars data={s.trend} label="E-mail deliveries per day" split okLabel="Sent" /></Card>
              <Card title="In-app notifications" description={`Broadcasting: ${s.broadcasting}`}>
                <KeyValue columns={2} items={[{ label: 'Created', value: fmt(s.notifications.created) }, { label: 'Unread', value: fmt(s.notifications.unread) }]} />
                <ul className="mt-3 text-xs text-adm-muted">{s.notifications.by_type.map((t) => <li key={t.type} className="flex justify-between py-0.5"><span>{t.type}</span><span>{t.count}</span></li>)}</ul>
                <p className="mt-3 text-[11px] text-adm-muted">Database notifications keep working when real-time broadcasting is down.</p>
              </Card>
            </div>
            <div className="grid gap-6 lg:grid-cols-2">
              <Card title="By template">
                {s.by_template.length === 0 ? <p className="text-sm text-adm-muted">No e-mails in this window.</p> :
                  <ul className="space-y-3">{s.by_template.map((t) => <li key={t.template}><p className="mb-1 font-mono text-xs">{t.template}</p><ShareBar label={t.template} parts={[{ label: 'Delivered or pending', value: t.total - t.failed, color: CHART.ok }, { label: 'Failed', value: t.failed, color: CHART.failed }]} /></li>)}</ul>}
              </Card>
              <Card title="Failure reasons">
                {s.errors.length === 0 ? <p className="text-sm text-adm-muted">No failures.</p> : <ul className="divide-y divide-adm-line text-sm">{s.errors.map((e) => <li key={e.error_code} className="flex justify-between py-1.5"><span className="font-mono text-xs">{e.error_code}</span><span className="tabular-nums">{e.count}</span></li>)}</ul>}
                <p className="mt-3 text-xs text-adm-muted">{s.retry_note}</p>
              </Card>
            </div>
          </>
        )}
      </Async>
      <Card title="Delivery log" actions={<Select hideLabel label="Status filter" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} options={[{ value: '', label: 'All statuses' }, ...['SENT', 'FAILED', 'PENDING', 'PROCESSING', 'CANCELLED'].map((v) => ({ value: v, label: humanize(v) }))]} />}>
        <Async state={rows} rows={5}>{(res) => <><DataTable caption="E-mail deliveries" columns={columns} rows={res.data} rowKey={(r) => r.id} dense /><Pagination meta={res.meta} onPage={setPage} /></>}</Async>
      </Card>
    </div>
  );
};
