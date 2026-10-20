import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import {
  Activity, AlertTriangle, ArrowDown, ArrowUp, BellRing, BookOpen, Bot, Building2, Database, Download, Eye, EyeOff, FileCheck2,
  Mail, RefreshCw, Settings2, ShieldCheck, Siren, UserX, Users, Workflow,
} from 'lucide-react';
import { adminApi } from '../api';
import { useAdminData } from '../hooks';
import type { DashboardData } from '../types';
import { Async, Btn, Card, Kpi, PageHeader, Pill, Segmented, SeverityBadge, StateBadge, fmt, fmtDateTime, humanize, timeAgo } from '../components/ui';
import { CHART, DailyBars, ShareBar } from '../components/charts';
import { cn } from '@/utils/cn';

const WIDGETS = [
  { key: 'kpi-people', label: 'People KPIs' },
  { key: 'kpi-ops', label: 'Operations KPIs' },
  { key: 'kpi-academic', label: 'Academic & AI KPIs' },
  { key: 'alerts', label: 'Critical alerts & services' },
  { key: 'trends', label: 'Activity trends' },
  { key: 'distribution', label: 'Accounts & documents' },
  { key: 'activity', label: 'Recent admin activity' },
] as const;
type WidgetKey = typeof WIDGETS[number]['key'];
const VIEW_KEY = 'facultylens.admin.dashboard.view';

interface View { order: WidgetKey[]; hidden: WidgetKey[]; days: number }
const DEFAULT_VIEW: View = { order: WIDGETS.map((w) => w.key), hidden: [], days: 30 };

const loadView = (): View => {
  try {
    const v = JSON.parse(localStorage.getItem(VIEW_KEY) || 'null') as View | null;
    if (!v) return DEFAULT_VIEW;
    const known = WIDGETS.map((w) => w.key) as WidgetKey[];
    const order = [...v.order.filter((k) => known.includes(k)), ...known.filter((k) => !v.order.includes(k))];
    return { order, hidden: v.hidden.filter((k) => known.includes(k)), days: [7, 30, 90].includes(v.days) ? v.days : 30 };
  } catch {
    return DEFAULT_VIEW;
  }
};

const csvCell = (v: unknown) => {
  const s = v === null || v === undefined ? '' : String(v);
  const safe = /^[=+\-@\t\r]/.test(s) && Number.isNaN(Number(s)) ? `'${s}` : s;
  return /[",\n]/.test(safe) ? `"${safe.replace(/"/g, '""')}"` : safe;
};

const downloadKpis = (d: DashboardData) => {
  const k = d.kpis;
  const rows: [string, unknown, unknown][] = [
    ['Total users', k.users_total.value, ''], ['Active faculty', k.active_faculty.value, ''], ['Suspended accounts', k.suspended_accounts.value, ''],
    ['New users', k.new_users.current, k.new_users.previous], ['Departments', k.departments.value, ''], ['Courses', k.courses.value, ''],
    ['Assessments', k.assessments.value, ''], ['AI analyses completed', k.analyses_completed.current, k.analyses_completed.previous],
    ['AI analyses failed', k.analyses_failed.current, k.analyses_failed.previous], ['AI calls', k.ai_calls.current, k.ai_calls.previous],
    ['AI availability %', k.ai_availability.value ?? 'not measured', ''], ['Failed jobs', k.failed_jobs.current, k.failed_jobs.previous],
    ['E-mail failures', k.email_failures.current, k.email_failures.previous], ['Open alerts', k.open_alerts.value, ''],
    ['Open incidents', k.open_incidents.value, ''], ['Open data-quality findings', k.data_quality_open.value, ''], ['Last successful backup', k.last_backup.value ?? 'none', ''],
  ];
  const csv = [['metric', `last ${d.range.days} days / current`, `previous ${d.range.days} days`], ...rows].map((r) => r.map(csvCell).join(',')).join('\n');
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  const a = Object.assign(document.createElement('a'), { href: url, download: `facultylens-dashboard-${d.range.days}d.csv` });
  a.click();
  URL.revokeObjectURL(url);
};

export const DashboardPage: React.FC = () => {
  const [view, setView] = useState<View>(loadView);
  const [customizing, setCustomizing] = useState(false);
  const [refreshTick, setRefreshTick] = useState(0);
  const state = useAdminData(() => adminApi.dashboard(view.days, refreshTick > 0), [view.days, refreshTick]);

  const save = (next: View) => {
    setView(next);
    try { localStorage.setItem(VIEW_KEY, JSON.stringify(next)); } catch { /* per-browser convenience only */ }
  };
  const move = (key: WidgetKey, dir: -1 | 1) => {
    const order = [...view.order];
    const i = order.indexOf(key);
    const j = i + dir;
    if (j < 0 || j >= order.length) return;
    [order[i], order[j]] = [order[j], order[i]];
    save({ ...view, order });
  };

  return (
    <div className="space-y-6" data-testid="admin-dashboard">
      <PageHeader
        title="Dashboard"
        description="Every figure below is counted from the database or a recorded health check. Nothing is estimated."
        meta={state.data && <>Generated {timeAgo(state.data.freshness.generated_at)} · health snapshot {timeAgo(state.data.freshness.health_snapshot_at)} · scheduler heartbeat {timeAgo(state.data.freshness.scheduler_heartbeat_at)}</>}
        actions={<>
          <Segmented label="Date range" value={view.days} onChange={(v) => save({ ...view, days: Number(v) })}
            options={[{ value: 7, label: '7 days' }, { value: 30, label: '30 days' }, { value: 90, label: '90 days' }]} />
          <Btn icon={RefreshCw} loading={state.loading && Boolean(state.data)} onClick={() => setRefreshTick((t) => t + 1)}>Refresh</Btn>
          <Btn icon={Download} disabled={!state.data} onClick={() => state.data && downloadKpis(state.data)}>Export</Btn>
          <Btn icon={Settings2} variant={customizing ? 'primary' : 'secondary'} onClick={() => setCustomizing((c) => !c)} aria-pressed={customizing}>Customize</Btn>
        </>}
      />

      {customizing && (
        <Card title="Customize this dashboard" description="Show, hide and reorder widgets. Saved in this browser only." actions={<Btn size="sm" variant="ghost" onClick={() => save(DEFAULT_VIEW)}>Reset</Btn>}>
          <ul className="divide-y divide-adm-line">
            {view.order.map((key, i) => {
              const hidden = view.hidden.includes(key);
              return (
                <li key={key} className="flex items-center justify-between gap-3 py-2 text-sm">
                  <span className={cn(hidden && 'text-adm-muted line-through')}>{WIDGETS.find((w) => w.key === key)?.label}</span>
                  <span className="flex gap-1">
                    <Btn size="sm" variant="ghost" icon={ArrowUp} disabled={i === 0} onClick={() => move(key, -1)} aria-label="Move up" />
                    <Btn size="sm" variant="ghost" icon={ArrowDown} disabled={i === view.order.length - 1} onClick={() => move(key, 1)} aria-label="Move down" />
                    <Btn size="sm" variant="ghost" icon={hidden ? EyeOff : Eye} onClick={() => save({ ...view, hidden: hidden ? view.hidden.filter((h) => h !== key) : [...view.hidden, key] })}
                      aria-label={hidden ? 'Show widget' : 'Hide widget'}>{hidden ? 'Hidden' : 'Shown'}</Btn>
                  </span>
                </li>
              );
            })}
          </ul>
        </Card>
      )}

      <Async state={state} rows={10}>
        {(d) => (
          <div className="space-y-6">
            {view.order.filter((k) => !view.hidden.includes(k)).map((key) => <Widget key={key} id={key} d={d} />)}
          </div>
        )}
      </Async>
    </div>
  );
};

const Widget: React.FC<{ id: WidgetKey; d: DashboardData }> = ({ id, d }) => {
  const k = d.kpis;
  const days = d.range.days;
  const docs = k.documents.by_status;

  switch (id) {
    case 'kpi-people':
      return (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="People">
          <Kpi label="Total users" value={fmt(k.users_total.value)} icon={Users} to="/admin/users" testId="kpi-users" delta={{ ...k.new_users, days }} hint={`${fmt(k.new_users.current)} joined`} />
          <Kpi label="Active faculty" value={fmt(k.active_faculty.value)} icon={ShieldCheck} to="/admin/users?status=active" hint={`${fmt(k.unverified_accounts.value)} unverified e-mail`} />
          <Kpi label="Suspended" value={fmt(k.suspended_accounts.value)} icon={UserX} to="/admin/users?status=deactivated" tone={k.suspended_accounts.value ? 'amber' : undefined} />
          <Kpi label="Departments" value={fmt(k.departments.value)} icon={Building2} to="/admin/institution" hint="from accounts and programs" />
        </div>
      );
    case 'kpi-ops':
      return (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Operations">
          <Kpi label="Open alerts" value={fmt(k.open_alerts.value)} icon={BellRing} to="/admin/alerts" tone={k.open_alerts.value ? 'red' : undefined} hint={`${fmt(k.unread_alerts.value)} unread notifications`} />
          <Kpi label="Open incidents" value={fmt(k.open_incidents.value)} icon={Siren} to="/admin/incidents" tone={k.open_incidents.value ? 'red' : undefined} />
          <Kpi label="Failed jobs" value={fmt(k.failed_jobs.current)} icon={Workflow} to="/admin/queues" delta={{ ...k.failed_jobs, invert: true, days }}
            hint={k.pending_jobs.value === null ? 'queue depth not measured yet' : `${fmt(k.pending_jobs.value)} pending`} tone={k.failed_jobs.current ? 'amber' : undefined} />
          <Kpi label="Last backup" value={k.last_backup.value ? timeAgo(k.last_backup.value) : 'None'} icon={Database} to="/admin/backups"
            tone={!k.last_backup.value ? 'red' : undefined} hint={k.last_backup.verification_status ? `integrity ${k.last_backup.verification_status}` : 'integrity not verified'} />
        </div>
      );
    case 'kpi-academic':
      return (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Academic and AI">
          <Kpi label="Courses · assessments" value={`${fmt(k.courses.value)} · ${fmt(k.assessments.value)}`} icon={BookOpen} to="/admin/institution" hint={`${fmt(k.assessments.new.current)} assessments created`} />
          <Kpi label="AI analyses" value={fmt(k.analyses_completed.current)} icon={FileCheck2} delta={{ ...k.analyses_completed, days }}
            hint={`${fmt(k.analyses_failed.current)} failed`} tone={k.analyses_failed.current > k.analyses_completed.current ? 'amber' : undefined} />
          <Kpi label="AI availability" value={k.ai_availability.value === null ? 'Not measured' : `${k.ai_availability.value}%`} icon={Bot} to="/admin/health"
            hint={k.ai_availability.checks ? `${fmt(k.ai_availability.checks)} recorded checks` : 'no recorded checks in this period'}
            tone={k.ai_availability.value !== null && k.ai_availability.value < 95 ? 'amber' : undefined} />
          <Kpi label="Data-quality findings" value={fmt(k.data_quality_open.value)} icon={AlertTriangle} to="/admin/data-quality"
            hint={k.data_quality_open.last_scan_at ? `scanned ${timeAgo(k.data_quality_open.last_scan_at)}` : 'never scanned'} tone={k.data_quality_open.value ? 'amber' : undefined} />
        </div>
      );
    case 'alerts':
      return (
        <div className="grid gap-6 lg:grid-cols-5">
          <Card title="Critical alerts" description="Open and acknowledged, most severe first" className="lg:col-span-3"
            actions={<Link to="/admin/alerts" className="text-xs font-medium text-adm-sage hover:underline">All alerts →</Link>}>
            {d.critical_alerts.length === 0 ? <p className="text-sm text-adm-muted">No open alerts.</p> : (
              <ul className="divide-y divide-adm-line">
                {d.critical_alerts.map((a) => (
                  <li key={a.id} className="flex items-start justify-between gap-3 py-2.5">
                    <div className="min-w-0">
                      <p className="text-sm font-medium text-adm-ink">{a.title}</p>
                      <p className="text-xs text-adm-muted">{a.message}</p>
                    </div>
                    <div className="flex shrink-0 flex-col items-end gap-1"><SeverityBadge severity={a.severity} /><span className="text-[11px] text-adm-muted">{timeAgo(a.last_seen_at)}</span></div>
                  </li>
                ))}
              </ul>
            )}
          </Card>
          <Card title="Services" description={d.services.length ? 'Latest recorded check' : undefined} className="lg:col-span-2"
            actions={<Link to="/admin/health" className="text-xs font-medium text-adm-sage hover:underline">Health →</Link>} testId="dashboard-services">
            {d.services.length === 0 ? (
              <p className="text-sm text-adm-muted">No health check recorded yet. Start the scheduler or run a check from System health.</p>
            ) : (
              <ul className="divide-y divide-adm-line">
                {d.services.map((s) => (
                  <li key={s.service} className="flex items-center justify-between gap-2 py-1.5 text-sm">
                    <span className="truncate">{s.label}</span>
                    <span className="flex items-center gap-2">{s.response_ms !== null && <span className="text-[11px] tabular-nums text-adm-muted">{s.response_ms} ms</span>}<StateBadge state={s.status} /></span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      );
    case 'trends':
      return (
        <div className="grid gap-6 lg:grid-cols-2">
          <Card title="AI service calls" description={`Per day, last ${days} days`}><DailyBars data={d.series.ai_calls} label="AI calls per day" split testId="chart-ai-calls" /></Card>
          <Card title="AI analyses" description="Completed vs failed per day"><DailyBars data={d.series.analyses} label="AI analyses per day" split okLabel="Completed" /></Card>
          <Card title="E-mail delivery" description="Sent vs permanently failed"><DailyBars data={d.series.emails} label="E-mails per day" split okLabel="Sent" /></Card>
          <Card title="New accounts" description="Accounts created per day"><DailyBars data={d.series.new_users} label="New accounts per day" /></Card>
          <Card title="Failed background jobs" description="Jobs that exhausted their retries"><DailyBars data={d.series.failed_jobs} label="Failed jobs per day" /></Card>
          <Card title="Administrative activity" description="Admin actions recorded in the audit log"><DailyBars data={d.series.admin_activity} label="Admin actions per day" /></Card>
        </div>
      );
    case 'distribution':
      return (
        <div className="grid gap-6 lg:grid-cols-2">
          <Card title="Account status by role">
            <ul className="space-y-4">
              {d.account_status.map((r) => (
                <li key={r.role}>
                  <p className="mb-1.5 text-xs font-medium text-adm-ink">{humanize(r.role)}</p>
                  <ShareBar label={`${humanize(r.role)} accounts`} parts={[{ label: 'Active', value: r.active, color: CHART.ok }, { label: 'Suspended', value: r.deactivated, color: CHART.failed }]} />
                </li>
              ))}
            </ul>
          </Card>
          <Card title="Document processing" description="All uploaded documents by extraction status" actions={<Link to="/admin/data-quality" className="text-xs font-medium text-adm-sage hover:underline">Findings →</Link>}>
            {docs.completed + docs.processing + docs.uploaded + docs.failed === 0 ? <p className="text-sm text-adm-muted">No documents have been uploaded yet.</p> : <ShareBar label="Documents by status" parts={[
              { label: 'Completed', value: docs.completed, color: CHART.ok },
              { label: 'Processing', value: docs.processing, color: '#3F5F7A' },
              { label: 'Uploaded', value: docs.uploaded, color: '#A9B4AE' },
              { label: 'Failed', value: docs.failed, color: CHART.failed },
            ]} />}
            <p className="mt-4 flex items-center gap-1.5 text-xs text-adm-muted"><Activity className="h-3.5 w-3.5" aria-hidden="true" />
              Integrations healthy: {k.integrations_healthy.of ? `${k.integrations_healthy.value} of ${k.integrations_healthy.of}` : 'not checked yet'}</p>
          </Card>
        </div>
      );
    case 'activity':
      return (
        <Card title="Recent administrative activity" actions={<Link to="/admin/audit?scope=admin" className="text-xs font-medium text-adm-sage hover:underline">Audit log →</Link>}>
          {d.recent_activity.length === 0 ? <p className="text-sm text-adm-muted">No administrative actions recorded yet.</p> : (
            <ul className="divide-y divide-adm-line">
              {d.recent_activity.map((a) => (
                <li key={a.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                  <span className="min-w-0">
                    <span className="block truncate text-adm-ink">{humanize(a.action.replace(/^ADMIN_/, ''))}</span>
                    <span className="block text-xs text-adm-muted">{a.user?.name ?? 'System'}{a.entity_id ? ` · ${a.entity_type} #${a.entity_id}` : ''}</span>
                  </span>
                  <span className="shrink-0 text-xs text-adm-muted" title={fmtDateTime(a.created_at)}>{timeAgo(a.created_at)}</span>
                </li>
              ))}
            </ul>
          )}
          <p className="mt-3 flex items-center gap-1.5 text-xs text-adm-muted"><Mail className="h-3.5 w-3.5" aria-hidden="true" />{fmt(k.email_failures.current)} e-mail failure(s) in the last {days} days <Pill tone="neutral">vs {fmt(k.email_failures.previous)} before</Pill></p>
        </Card>
      );
    default:
      return null;
  }
};
