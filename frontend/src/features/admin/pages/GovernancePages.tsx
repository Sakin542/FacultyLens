import React, { useEffect, useRef, useState } from 'react';
import { CheckCircle2, CircleSlash, PlayCircle, Save } from 'lucide-react';
import { adminApi } from '../api';
import { useAction, useAdminData } from '../hooks';
import type { AiGovernanceData, FeatureSetting, Finding } from '../types';
import {
  Async, Btn, Card, ConfirmDialog, Field, KeyValue, Kpi, Notice, PageHeader, Pagination, Pill, Segmented, Select, SeverityBadge, StateBadge,
  StatusBadge, Switch, Tabs, fmt, fmtDateTime, humanize, inputCls, timeAgo,
} from '../components/ui';
import { cn } from '@/utils/cn';

// ======================================================================================== data quality

export const DataQualityPage: React.FC = () => {
  const [status, setStatus] = useState('active');
  const [severity, setSeverity] = useState('');
  const [check, setCheck] = useState('');
  const [page, setPage] = useState(1);
  const state = useAdminData(() => adminApi.dataQuality({ status, severity, check, page, per_page: 20 }), [status, severity, check, page]);
  const action = useAction();
  const [closing, setClosing] = useState<{ finding: Finding; to: 'resolved' | 'ignored' } | null>(null);

  const scan = async () => { if (await action.run('scan', () => adminApi.runScan())) void state.reload(); };
  const setFindingStatus = async (f: Finding, to: Finding['status'], note?: string) => {
    const r = await action.run(`f-${f.id}`, () => adminApi.updateFinding(f.id, to, note));
    if (r) void state.reload();
  };

  return (
    <div className="space-y-6">
      <PageHeader title="Data quality" description="Read-only checks across courses, assessments, documents and accounts. The scanner never changes a record: owners fix the data, and the next scan confirms the fix by closing the finding automatically."
        meta={state.data?.meta.last_scan && <>Last scan {timeAgo(state.data.meta.last_scan.completed_at)} ({humanize(state.data.meta.last_scan.status)}) · runs daily at 04:00</>}
        actions={<Btn variant="primary" icon={PlayCircle} loading={action.busy === 'scan'} onClick={() => { void scan(); }}>Run scan now</Btn>} />
      {action.notice && <Notice tone={action.notice.tone} onClose={() => action.setNotice(null)}>{action.notice.text}</Notice>}
      <Async state={state} rows={8}>
        {(res) => {
          const s = res.meta.summary;
          return (
            <>
              <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Kpi label="Open" value={fmt((s.by_status.open ?? 0) + (s.by_status.acknowledged ?? 0))} tone={(s.by_status.open ?? 0) > 0 ? 'amber' : undefined} hint={`${fmt(s.by_status.acknowledged ?? 0)} acknowledged`} />
                <Kpi label="High severity" value={fmt(s.by_severity.high ?? 0)} tone={(s.by_severity.high ?? 0) > 0 ? 'red' : undefined} />
                <Kpi label="Resolved" value={fmt(s.by_status.resolved ?? 0)} />
                <Kpi label="Ignored" value={fmt(s.by_status.ignored ?? 0)} />
              </div>
              <div className="grid gap-6 lg:grid-cols-3">
                <Card title="Checks" description="Open findings per check">
                  <ul className="space-y-1.5 text-sm">
                    {res.meta.checks.map((c) => (
                      <li key={c.key}>
                        <button type="button" onClick={() => { setCheck(check === c.key ? '' : c.key); setPage(1); }} className={cn('flex w-full items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-left hover:bg-adm-warm', check === c.key && 'bg-adm-cream')}>
                          <span className="min-w-0"><span className="block truncate">{c.label}</span><span className="text-[11px] text-adm-muted">{humanize(c.category)}</span></span>
                          {(s.by_check[c.key] ?? 0) > 0 ? <Pill tone="amber">{s.by_check[c.key]}</Pill> : <CheckCircle2 className="h-4 w-4 text-adm-sage" aria-label="No findings" />}
                        </button>
                      </li>
                    ))}
                  </ul>
                </Card>
                <div className="space-y-4 lg:col-span-2">
                  <div className="flex flex-wrap gap-2">
                    <Segmented label="Status" value={status} onChange={(v) => { setStatus(v); setPage(1); }} options={[{ value: 'active', label: 'Open' }, { value: 'resolved', label: 'Resolved' }, { value: 'ignored', label: 'Ignored' }]} />
                    <Select hideLabel label="Severity" value={severity} onChange={(e) => { setSeverity(e.target.value); setPage(1); }} options={[{ value: '', label: 'Any severity' }, { value: 'high', label: 'High' }, { value: 'medium', label: 'Medium' }, { value: 'low', label: 'Low' }]} />
                  </div>
                  {res.data.length === 0 ? <Card><p className="py-6 text-center text-sm text-adm-muted">{res.meta.last_scan ? 'No findings in this view.' : 'No scan has run yet. Run one to check the data.'}</p></Card> : (
                    <ul className="space-y-3">
                      {res.data.map((f) => (
                        <li key={f.id} className="rounded-xl border border-adm-line bg-adm-surface p-4">
                          <div className="flex flex-wrap items-start justify-between gap-3">
                            <div className="min-w-0">
                              <div className="flex flex-wrap items-center gap-2"><SeverityBadge severity={f.severity} /><StatusBadge status={f.status} /><Pill>{humanize(f.check)}</Pill></div>
                              <p className="mt-1.5 font-medium">{f.entity_label ?? `${f.entity_type} #${f.entity_id}`} <span className="text-xs font-normal text-adm-muted">{f.entity_type} #{f.entity_id}</span></p>
                              <p className="text-sm">{f.explanation}</p>
                              <p className="mt-1 text-xs text-adm-muted">Suggested: {f.remediation}</p>
                              <p className="mt-1 text-[11px] text-adm-muted">Detected {fmtDateTime(f.first_detected_at)} · last seen {timeAgo(f.last_detected_at)}{f.resolution_note && ` · ${f.auto_resolved ? 'auto' : f.resolved_by?.name ?? ''}: ${f.resolution_note}`}</p>
                            </div>
                            {(f.status === 'open' || f.status === 'acknowledged') && (
                              <div className="flex flex-wrap gap-1.5">
                                {f.status === 'open' && <Btn size="sm" onClick={() => { void setFindingStatus(f, 'acknowledged'); }}>Acknowledge</Btn>}
                                <Btn size="sm" onClick={() => setClosing({ finding: f, to: 'resolved' })}>Mark resolved</Btn>
                                <Btn size="sm" variant="ghost" onClick={() => setClosing({ finding: f, to: 'ignored' })}>Ignore</Btn>
                              </div>
                            )}
                            {(f.status === 'ignored' || (f.status === 'resolved' && !f.auto_resolved)) && <Btn size="sm" variant="ghost" onClick={() => { void setFindingStatus(f, 'open'); }}>Re-open</Btn>}
                          </div>
                        </li>
                      ))}
                    </ul>
                  )}
                  <Pagination meta={res.meta} onPage={setPage} />
                </div>
              </div>
            </>
          );
        }}
      </Async>
      <ConfirmDialog open={closing !== null} title={closing?.to === 'ignored' ? 'Ignore finding' : 'Mark resolved'} confirmLabel={closing?.to === 'ignored' ? 'Ignore' : 'Mark resolved'}
        reason={{ label: 'Note', required: true }} busy={action.busy?.startsWith('f-')}
        body={closing?.to === 'ignored' ? 'Ignored findings stay ignored on future scans. Use this for accepted exceptions (e.g. a practice quiz with no questions).' : 'The next scan re-checks the record and re-opens the finding if the problem is still there.'}
        onCancel={() => setClosing(null)} onConfirm={(note) => { const c = closing!; setClosing(null); void setFindingStatus(c.finding, c.to, note); }} />
    </div>
  );
};

// ======================================================================================== AI governance

const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${(v * 100).toFixed(1)}%`);

export const AiGovernancePage: React.FC = () => {
  const state = useAdminData(() => adminApi.ai(), []);
  const [tab, setTab] = useState('models');
  return (
    <div className="space-y-6">
      <PageHeader title="AI governance" description="Built only from recorded data: the model registry, completed evaluation runs and faculty review decisions. Accuracy is never inferred from confidence scores or successful requests." />
      <Async state={state} rows={8}>
        {(d) => (
          <>
            <Notice tone="info">{d.principle}</Notice>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <Kpi label="AI service" value={<StateBadge state={d.service.status} />} hint={d.service.checked_at ? `checked ${timeAgo(d.service.checked_at)}` : 'no recorded check'} />
              <Kpi label="Serving model" value={<span className="break-all text-base">{d.service.model ?? 'Unknown'}</span>} hint={d.service.model_loaded === null ? undefined : d.service.model_loaded ? 'loaded' : 'not loaded'} />
              <Kpi label="Evaluation runs" value={fmt(d.evaluation_totals.completed)} hint={`${fmt(d.evaluation_totals.failed)} failed · ${fmt(d.evaluation_totals.gate_failed)} failed quality gate`} />
              <Kpi label="Registered models" value={fmt(d.models.length)} hint={`${d.models.filter((m) => m.is_active).length} active`} />
            </div>
            <Tabs label="AI governance sections" active={tab} onChange={setTab} tabs={[{ key: 'models', label: 'Model registry', count: d.models.length }, { key: 'evaluations', label: 'Evaluations', count: d.evaluations.length }, { key: 'reviews', label: 'Faculty reviews' }, { key: 'providers', label: 'Providers & tools' }, { key: 'config', label: 'Configuration' }]} />
            {tab === 'models' && (
              <Card bodyClassName="p-0">
                {d.models.length === 0 ? <p className="py-8 text-center text-sm text-adm-muted">No models registered. Models are registered when an evaluation run first uses them.</p> : (
                  <ul className="divide-y divide-adm-line">
                    {d.models.map((m) => (
                      <li key={m.id} className="grid gap-2 px-5 py-3 md:grid-cols-[1.4fr_1fr_1fr]">
                        <div><p className="font-medium">{m.model_name}</p><p className="text-xs text-adm-muted">{m.provider} · {humanize(m.model_type)} · {humanize(m.task)} · v{m.version}</p></div>
                        <div className="text-xs">{m.is_active ? <Pill tone="sage">Active</Pill> : <Pill>Inactive</Pill>} <span className="text-adm-muted">{m.evaluation_runs} run(s)</span></div>
                        <div className="text-xs">{m.last_evaluation ? <>{humanize(m.last_evaluation.headline_metric ?? 'metric')}: <span className="font-semibold">{pct(m.last_evaluation.headline_value)}</span> · gate {humanize(m.last_evaluation.gate_status ?? '—')}<span className="block text-adm-muted">{fmtDateTime(m.last_evaluation.completed_at)}</span></> : <span className="text-adm-muted">Never evaluated — no metrics to show</span>}</div>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            )}
            {tab === 'evaluations' && (
              <Card bodyClassName="p-0">
                {d.evaluations.length === 0 ? <p className="py-8 text-center text-sm text-adm-muted">No evaluation runs recorded. Create datasets and runs under AI evaluation.</p> : (
                  <div className="overflow-x-auto"><table className="min-w-full text-sm">
                    <caption className="sr-only">Evaluation runs</caption>
                    <thead><tr className="border-b border-adm-line text-left text-[11px] uppercase tracking-[0.06em] text-adm-muted"><th className="px-5 py-2">Run</th><th className="px-3 py-2">Dataset</th><th className="px-3 py-2">Accuracy</th><th className="px-3 py-2">Macro F1</th><th className="px-3 py-2">Precision / recall</th><th className="px-3 py-2">Gate</th><th className="px-5 py-2">Completed</th></tr></thead>
                    <tbody className="divide-y divide-adm-line">
                      {d.evaluations.map((r) => (
                        <tr key={r.id}>
                          <td className="px-5 py-2"><p className="font-medium">#{r.id} {humanize(r.task)}</p><p className="text-xs text-adm-muted">{r.model?.model_name ?? '—'} · <StatusBadge status={r.status.toLowerCase()} /></p>{r.failure_reason && <p className="text-xs text-adm-red">{r.failure_reason}</p>}</td>
                          <td className="px-3 py-2 text-xs">{r.dataset ? `${r.dataset.name} (${r.dataset.version})` : '—'}<span className="block text-adm-muted">{fmt(r.examples)} examples{r.size_category ? ` · ${r.size_category}` : ''}</span></td>
                          <td className="px-3 py-2 tabular-nums">{pct(r.metrics.accuracy)}</td>
                          <td className="px-3 py-2 tabular-nums">{pct(r.metrics.macro_f1)}</td>
                          <td className="px-3 py-2 tabular-nums text-xs">{pct(r.metrics.macro_precision)} / {pct(r.metrics.macro_recall)}</td>
                          <td className="px-3 py-2">{r.gate_status ? <Pill tone={r.gate_status === 'PASSED' ? 'sage' : r.gate_status === 'FAILED' ? 'red' : 'amber'}>{humanize(r.gate_status)}</Pill> : '—'}{r.regression && <Pill tone="red" className="ml-1">Regression</Pill>}</td>
                          <td className="px-5 py-2 text-xs text-adm-muted">{fmtDateTime(r.completed_at)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table></div>
                )}
              </Card>
            )}
            {tab === 'reviews' && (
              <div className="grid gap-6 lg:grid-cols-3">
                <Card title="Faculty decisions on AI results (90 days)" className="lg:col-span-2" description="Overrides and rejections are quality signals recorded by faculty — the strongest real-world evidence of AI errors.">
                  {d.reviews_90d.length === 0 ? <p className="text-sm text-adm-muted">No reviews recorded.</p> : (
                    <ul className="divide-y divide-adm-line text-sm">{d.reviews_90d.map((r) => (
                      <li key={r.type} className="flex flex-wrap items-center justify-between gap-2 py-2"><span className="font-medium">{humanize(r.type)}</span>
                        <span className="text-xs text-adm-muted">{r.total} reviewed · {r.accepted} accepted · {r.overridden} overridden · {r.rejected} rejected {r.override_rate !== null && <Pill tone={r.override_rate > 30 ? 'amber' : 'neutral'}>{r.override_rate}% changed</Pill>}</span></li>
                    ))}</ul>
                  )}
                </Card>
                <Card title="Override reasons">
                  {d.override_reasons_90d.length === 0 ? <p className="text-sm text-adm-muted">None recorded.</p> : <ul className="space-y-1 text-sm">{d.override_reasons_90d.map((r) => <li key={r.reason} className="flex justify-between"><span>{humanize(r.reason)}</span><span className="tabular-nums">{r.count}</span></li>)}</ul>}
                </Card>
              </div>
            )}
            {tab === 'providers' && <ProvidersPanel p={d.provider} />}
            {tab === 'config' && (
              <div className="grid gap-6 lg:grid-cols-2">
                <Card title="Service configuration" description="Values are never shown; only whether they are set">
                  <KeyValue items={[
                    { label: 'AI service address', value: d.configuration.service_url_configured ? <Pill tone="sage">Configured</Pill> : <Pill icon={CircleSlash}>Missing</Pill> },
                    { label: 'Shared API key', value: d.configuration.api_key_configured ? <Pill tone="sage">Configured</Pill> : <Pill>Not set</Pill> },
                    { label: 'Request timeout', value: `${d.configuration.request_timeout_seconds} s` },
                    { label: 'Connect timeout', value: `${d.configuration.connect_timeout_seconds} s` },
                  ]} />
                  <p className="mt-4 text-xs text-adm-muted">{d.configuration.change_note}</p>
                </Card>
                <Card title="AI feature switches" description="Enforced server-side; change them in Platform settings">
                  <ul className="divide-y divide-adm-line text-sm">{d.configuration.feature_flags.map((f) => <li key={f.key} className="flex justify-between py-2"><span>{f.label}</span>{f.value ? <Pill tone="sage">On</Pill> : <Pill tone="amber">Off</Pill>}</li>)}</ul>
                </Card>
              </div>
            )}
          </>
        )}
      </Async>
    </div>
  );
};

const TOOL_LABELS: Record<string, string> = {
  ocr: 'Handwriting OCR', transcription: 'Lecture transcription', translation: 'Bangla ↔ English translation',
  speech: 'Read aloud (text to speech)', writing: 'Notes & quiz, question checker, simplify, Bloom rewriter', similarity: 'Answer similarity (plagiarism check)',
};

/** Which generation provider is selected, whether each provider is set up, and toolkit model readiness (live from the AI service). */
const ProvidersPanel: React.FC<{ p: AiGovernanceData['provider'] }> = ({ p }) => {
  const gen = p.toolkit.generation;
  return (
    <div className="space-y-6" data-testid="ai-providers">
      {!p.toolkit.available && <Notice tone="warning">{p.toolkit.error ?? 'The AI service did not report its status.'}</Notice>}
      <div className="grid gap-6 lg:grid-cols-2">
        <Card title="Generation provider" description="Used by document chat, question generator, rubric, syllabus and variant drafts. Change it in Platform settings.">
          <KeyValue columns={1} items={[
            { label: 'Selected', value: <Pill tone={p.selected === 'hosted' ? 'amber' : 'sage'}>{p.selected === 'hosted' ? 'Hosted API' : 'Local (private)'}</Pill> },
            { label: 'Local model', value: gen ? (gen.local.configured ? <span className="font-mono text-xs">{gen.local.model} {gen.local.loaded ? '· loaded' : '· loads on first use'}</span> : <Pill>Not configured — template engines are used</Pill>) : '—' },
            { label: 'Hosted provider', value: gen ? (gen.hosted.configured ? <span className="font-mono text-xs" data-testid="hosted-model-chain">{[gen.hosted.model, ...(gen.hosted.fallback_models ?? [])].join(' → ')}</span> : <Pill>Not configured (LLM_HOSTED_* in the AI service)</Pill>) : '—' },
          ]} />
          {p.selected === 'hosted' && gen && !gen.hosted.configured && <Notice tone="warning" className="mt-3">Hosted is selected but not configured, so the local model is used for every request.</Notice>}
        </Card>
        <Card title="AI toolkit models" description="Local Hugging Face models, downloaded and loaded on first use">
          <ul className="divide-y divide-adm-line text-sm">
            {(['translation', 'speech', 'writing', 'ocr', 'transcription', 'similarity'] as const).map((key) => {
              const t = p.toolkit.tools[key];
              const jobs = p.tool_jobs_30d[key];
              return (
                <li key={key} className="py-2.5">
                  <div className="flex items-center justify-between gap-2">
                    <span className="font-medium">{TOOL_LABELS[key]}</span>
                    {!t ? <Pill>Unknown</Pill> : t.ready ? <Pill tone="sage">{t.loaded ? 'Loaded' : 'Ready'}</Pill> : <Pill tone="red">Missing dependency</Pill>}
                  </div>
                  {t && <p className="font-mono text-[11px] text-adm-muted">{t.model}{Object.entries(t.dependencies).filter(([, ok]) => !ok).map(([d]) => ` · needs ${d}`).join('')}</p>}
                  {jobs && <p className="text-[11px] text-adm-muted">30 days: {Object.entries(jobs).map(([s, n]) => `${n} ${s}`).join(' · ')}</p>}
                </li>
              );
            })}
          </ul>
          {p.toolkit.memory && <MemoryLine m={p.toolkit.memory} />}
        </Card>
      </div>
    </div>
  );
};

/** How much memory the AI service's local models use, against the budget, and which are loaded right now. */
const MemoryLine: React.FC<{ m: NonNullable<AiGovernanceData['provider']['toolkit']['memory']> }> = ({ m }) => (
  <div className="mt-3 border-t border-adm-line pt-3 text-[11px] text-adm-muted" data-testid="ai-memory">
    <p>
      <span className="font-medium text-adm-ink">Memory:</span> {m.budget_mb > 0 ? `${(m.process_mb / 1024).toFixed(1)} of ${(m.budget_mb / 1024).toFixed(1)} GB` : `${(m.process_mb / 1024).toFixed(1)} GB (no budget)`}
      {m.idle_unload_seconds > 0 && ` · models unload after ${Math.round(m.idle_unload_seconds / 60)} min idle`}
    </p>
    <p>Loaded now: {m.loaded.length ? m.loaded.map((x) => (x.in_use ? `${x.name} (working)` : x.name)).join(', ') : 'none — each model loads on first use'}</p>
  </div>
);

// ======================================================================================== AI usage

export const AiUsagePage: React.FC = () => {
  const [days, setDays] = useState(30);
  const state = useAdminData(() => adminApi.aiUsage(days), [days]);
  return (
    <div className="space-y-6">
      <PageHeader title="AI usage" description="Every call from Laravel to the AI service, metered as metadata only (no prompts, documents or answers). Token counts are an estimate from payload size; the models run locally, so this measures load, not spend."
        actions={<Segmented label="Window" value={days} onChange={(v) => setDays(Number(v))} options={[{ value: 7, label: '7 d' }, { value: 30, label: '30 d' }, { value: 90, label: '90 d' }]} />} />
      <Async state={state} rows={8}>
        {(u) => (
          <>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
              <Kpi label="Calls" value={fmt(u.totals.calls)} />
              <Kpi label="Failed" value={fmt(u.totals.failed)} tone={u.totals.failed ? 'amber' : undefined} hint={u.totals.calls ? `${((u.totals.failed / u.totals.calls) * 100).toFixed(1)}% error rate` : undefined} />
              <Kpi label="Average latency" value={`${fmt(u.totals.avg_ms)} ms`} />
              <Kpi label="Estimated tokens" value={fmt(u.totals.tokens)} />
            </div>
            <div className="grid gap-6 lg:grid-cols-2">
              <Card title="By operation" bodyClassName="p-0">
                <table className="min-w-full text-sm"><caption className="sr-only">AI usage by operation</caption>
                  <thead><tr className="border-b border-adm-line text-left text-[11px] uppercase tracking-[0.06em] text-adm-muted"><th className="px-5 py-2">Operation</th><th className="px-3 py-2">Calls</th><th className="px-3 py-2">Failed</th><th className="px-5 py-2">Avg ms</th></tr></thead>
                  <tbody className="divide-y divide-adm-line">{u.by_operation.length === 0 ? <tr><td colSpan={4} className="px-5 py-6 text-center text-adm-muted">No calls in this window.</td></tr> : u.by_operation.map((o) => <tr key={o.operation}><td className="px-5 py-2 font-mono text-xs">{o.operation}</td><td className="px-3 py-2 tabular-nums">{fmt(o.calls)}</td><td className="px-3 py-2 tabular-nums">{fmt(o.failed)}</td><td className="px-5 py-2 tabular-nums">{fmt(o.avg_ms)}</td></tr>)}</tbody>
                </table>
              </Card>
              <Card title="By user" description="Aggregate load only; not a measure of faculty performance" bodyClassName="p-0">
                <table className="min-w-full text-sm"><caption className="sr-only">AI usage by user</caption>
                  <thead><tr className="border-b border-adm-line text-left text-[11px] uppercase tracking-[0.06em] text-adm-muted"><th className="px-5 py-2">User</th><th className="px-3 py-2">Calls</th><th className="px-5 py-2">Last used</th></tr></thead>
                  <tbody className="divide-y divide-adm-line">{u.by_user.length === 0 ? <tr><td colSpan={3} className="px-5 py-6 text-center text-adm-muted">No calls in this window.</td></tr> : u.by_user.map((r) => <tr key={r.user_id ?? 'jobs'}><td className="px-5 py-2">{r.name}</td><td className="px-3 py-2 tabular-nums">{fmt(r.calls)}</td><td className="px-5 py-2 text-xs text-adm-muted">{timeAgo(r.last_used_at)}</td></tr>)}</tbody>
                </table>
              </Card>
            </div>
            <Card title="Recent calls" bodyClassName="p-0">
              <ul className="divide-y divide-adm-line text-sm">{u.recent.map((r) => (
                <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-2"><span className="font-mono text-xs">{r.operation}</span>
                  <span className="flex items-center gap-2 text-xs text-adm-muted">{r.user?.name ?? 'Background job'} · {r.duration_ms} ms · {r.success ? <Pill tone="sage">{r.status_code ?? 'OK'}</Pill> : <Pill tone="red">{r.status_code ?? 'No response'}</Pill>} · {timeAgo(r.created_at)}</span></li>
              ))}</ul>
            </Card>
          </>
        )}
      </Async>
    </div>
  );
};

// ======================================================================================== settings

export const SettingsPage: React.FC = () => {
  const state = useAdminData(() => adminApi.settings(), []);
  const action = useAction();
  const [draft, setDraft] = useState<Record<string, boolean | string>>({});
  const [confirming, setConfirming] = useState(false);

  // Switches start from the server values once. Later background refreshes (for the change history) must not reset
  // them, or a switch flipped while a refresh is in flight would silently snap back.
  const initialised = useRef(false);
  useEffect(() => {
    if (state.data && !initialised.current) {
      initialised.current = true;
      setDraft(Object.fromEntries(state.data.features.map((f) => [f.key, f.value])));
    }
  }, [state.data]);

  const changed = (state.data?.features ?? []).filter((f) => draft[f.key] !== undefined && draft[f.key] !== f.value);
  const save = async (reason: string) => {
    const r = await action.run('save', () => adminApi.updateSettings(Object.fromEntries(changed.map((f) => [f.key, draft[f.key]])), reason || undefined));
    setConfirming(false);
    if (r) {
      // Apply the saved values from the response straight away; keep any switch changed meanwhile as unsaved
      const saved = Object.fromEntries(r.data.features.map((f) => [f.key, f.value]));
      const submitted = new Set(changed.map((f) => f.key));
      setDraft((d) => ({ ...d, ...Object.fromEntries(Object.entries(saved).filter(([k]) => submitted.has(k))) }));
      state.setData((prev) => (prev ? { ...prev, features: r.data.features } : prev));
      void state.reload();
    }
  };
  const renderFlag = (f: FeatureSetting) => (
    <li key={f.key} className="flex flex-wrap items-start justify-between gap-3 py-3">
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium">{f.label}{draft[f.key] !== f.value && <Pill tone="amber" className="ml-2">Unsaved</Pill>}</p>
        <p className="text-xs text-adm-muted">{f.description}</p>
        {f.type === 'string' && (
          <Field label={f.label} className="mt-2">{(id) => <input id={id} value={String(draft[f.key] ?? '')} maxLength={500} onChange={(e) => setDraft({ ...draft, [f.key]: e.target.value })} className={inputCls} placeholder="Leave empty to hide" />}</Field>
        )}
      </div>
      {f.type === 'boolean' && <Switch checked={Boolean(draft[f.key])} onChange={(v) => setDraft({ ...draft, [f.key]: v })} label={f.label} testId={`flag-${f.key}`} />}
      {f.type === 'select' && (
        <div className="w-full sm:w-72">
          <Select hideLabel label={f.label} value={String(draft[f.key] ?? f.value)} onChange={(e) => setDraft({ ...draft, [f.key]: e.target.value })}
            options={(f.options ?? []).map((o) => ({ value: o.value, label: o.label }))} data-testid={`flag-${f.key}`} />
          {f.key === 'ai_provider' && draft[f.key] === 'hosted' && (
            <p className="mt-1.5 text-xs text-adm-amber-text">Hosted: academic text (questions, document excerpts, answers) is sent to the external provider configured in the AI service.</p>
          )}
        </div>
      )}
    </li>
  );

  return (
    <div className="space-y-6">
      <PageHeader title="Platform settings" description="Runtime switches enforced on the server (`feature:` middleware). Turning a feature off blocks new work; existing results stay readable. Flags never replace authorization."
        actions={<Btn variant="primary" icon={Save} disabled={changed.length === 0} onClick={() => setConfirming(true)}>Save {changed.length ? `(${changed.length})` : ''}</Btn>} />
      {action.notice && <Notice tone={action.notice.tone} onClose={() => action.setNotice(null)}>{action.notice.text}</Notice>}
      <Async state={state} rows={8}>
        {(d) => (
          <div className="grid gap-6 lg:grid-cols-3">
            <Card title="Features" className="lg:col-span-2">
              <ul className="divide-y divide-adm-line">{d.features.map(renderFlag)}</ul>
              {!d.google_configured && <p className="mt-2 text-xs text-adm-muted">Google sign-in also needs GOOGLE_CLIENT_ID in backend/.env; without it the button stays hidden even when enabled.</p>}
            </Card>
            <Card title="Change history" description="From the audit log">
              {d.history.length === 0 ? <p className="text-sm text-adm-muted">No changes recorded.</p> : (
                <ol className="space-y-3 text-sm">
                  {d.history.map((h) => (
                    <li key={h.id}>
                      <p className="text-xs text-adm-muted">{fmtDateTime(h.created_at)} · {h.actor?.name ?? 'System'}</p>
                      <ul className="mt-0.5 text-xs">{Object.entries(h.changed).map(([k, v]) => <li key={k}><span className="font-medium">{humanize(k)}</span>: {h.previous && k in h.previous ? `${String(h.previous[k])} → ` : ''}{String(v) || '(empty)'}</li>)}</ul>
                      {h.reason && <p className="text-xs text-adm-muted">“{h.reason}”</p>}
                    </li>
                  ))}
                </ol>
              )}
            </Card>
          </div>
        )}
      </Async>
      <ConfirmDialog open={confirming} title="Apply setting changes?" confirmLabel="Apply" busy={action.busy === 'save'} reason={{ label: 'Reason for the change', required: false }}
        body={<ul className="list-disc pl-5">{changed.map((f) => <li key={f.key}>{f.label}: {String(f.value) || '(empty)'} → <strong>{String(draft[f.key]) || '(empty)'}</strong></li>)}</ul>}
        onCancel={() => setConfirming(false)} onConfirm={(reason) => { void save(reason); }} />
    </div>
  );
};

// ======================================================================================== help & coverage

const BUILT: [string, string][] = [
  ['Dashboard', 'Real KPIs with period comparison, configurable widgets (saved per browser), CSV export of KPIs.'],
  ['Users', 'Search/filter/sort, create + invite, profile edits, suspend/reinstate with reason, session revocation, 2FA reset, bulk actions, CSV import with preview, CSV export.'],
  ['Roles & permissions', 'Fixed roles (Administrator, Department head, Faculty) with an enforced-permission matrix, privileged inventory and role history.'],
  ['Security center', 'Failed sign-ins (recorded per attempt), refused admin sign-ins, 2FA adoption, sessions, threshold signals.'],
  ['Audit log', 'Filter, search, inspect and export. Read-only.'],
  ['Academic structure', 'Departments (derived), programs, terms and course metadata gaps.'],
  ['System health', 'Five explicit states per service, response time, recorded history and hourly availability.'],
  ['Queues & jobs', 'Depth, failure trend, scrubbed failure summaries, allow-listed retries and dismissal.'],
  ['E-mail & notifications', 'Delivery status, latency, templates, failure codes and notification volume.'],
  ['Alerts & incidents', 'Threshold rules with dedupe, auto-resolution and maintenance suppression; incidents with timeline and root cause.'],
  ['Task center & exports', 'Every import/export/scan/check with its outcome; formula-safe, expiring CSV downloads.'],
  ['Data quality', 'Twelve read-only checks with review status and automatic revalidation.'],
  ['AI governance & usage', 'Model registry, recorded evaluation metrics, faculty override signals, AI configuration status and usage metering.'],
  ['Backup & recovery', 'Backups with SHA-256 integrity verification and a documented, console-only restore procedure.'],
  ['Calendar & schedule', 'Maintenance windows (suppress alert notifications) and the live scheduled-task list.'],
  ['Settings & environment', 'Feature flags with reasons and history; version, drivers, configuration presence and migration status.'],
];

const NOT_BUILT: [string, string][] = [
  ['Approval workflows, access reviews, delegation, temporary elevation', 'FacultyLens deliberately has a single administrator account (ADMIN_EMAIL). Separating requester from approver needs a second privileged role, which would reverse that decision. Sensitive actions instead require confirmation, a reason and leave an audit trail.'],
  ['Custom roles and a permission editor', 'Roles are fixed in code; the matrix documents enforced behaviour. A permission table would be a new authorization system.'],
  ['Workflow builder / custom automation rules', 'Automation is limited to the built-in scheduled commands (monitoring, scans, backups, pruning). Arbitrary rules or scripts are intentionally not accepted from the UI.'],
  ['Scheduled report delivery', 'Exports are on demand. Recurring delivery needs a recipient model and an approval policy for e-mailing data.'],
  ['Restore from the panel', 'Restore replaces every table in the live database, so it stays a console command with explicit confirmation.'],
  ['Re-sending failed e-mails', 'Message context (including one-time links) is intentionally not stored, so a failed e-mail cannot be rebuilt. Users repeat the action.'],
  ['Data retention policies, legal hold, deletion requests, data inventory', 'Need institutional policy decisions first. Existing retention is fixed in config (notifications, e-mail log, reports, backups).'],
  ['API credential inventory', 'FacultyLens has no managed API clients; the only integration secrets live in .env and are reported as configured / not configured.'],
  ['Dependency vulnerability scanning', 'Not integrated; run composer audit, npm audit and pip-audit in CI.'],
  ['Localization, accessibility audits, release history', 'Not built. The app runs in one locale; the version shown is the git commit or APP_VERSION.'],
];

export const HelpPage: React.FC = () => (
  <div className="space-y-6">
    <PageHeader title="Help & coverage" description="How the control center works, what each state means, and which requested modules are intentionally not built." />
    <div className="grid gap-6 lg:grid-cols-2">
      <Card title="Service states">
        <ul className="space-y-2 text-sm">
          {([['healthy', 'A probe succeeded within its time limit.'], ['degraded', 'Reachable but impaired (model not loaded, backlog, failures, low disk).'], ['unavailable', 'The probe failed or timed out.'], ['unconfigured', 'The service is intentionally not set up (e.g. log mailer, no broadcaster).'], ['unknown', 'Cannot be observed with the current setup, or never checked.']] as const)
            .map(([s, t]) => <li key={s} className="flex items-start gap-3"><span className="w-28 shrink-0"><StateBadge state={s} /></span><span className="text-adm-muted">{t}</span></li>)}
        </ul>
      </Card>
      <Card title="Principles">
        <ul className="list-disc space-y-1.5 pl-5 text-sm">
          <li>Every endpoint under <code>/api/admin</code> is authorized on the server; hidden buttons are not a control.</li>
          <li>AI output is advisory: nothing here approves assessments, finalizes grades or edits official records.</li>
          <li>Numbers come from stored data. When something was not measured, the panel says so.</li>
          <li>Secrets never reach the browser; exports exclude credentials and neutralize spreadsheet formulas.</li>
          <li>Sensitive actions require confirmation, often a reason, and are written to the audit log.</li>
        </ul>
      </Card>
      <Card title="Scheduled automation">
        <ul className="space-y-1 text-sm"><li><code>admin:monitor</code> every 5 min — health snapshot, scheduler heartbeat, alert rules</li><li><code>admin:data-quality-scan</code> daily 04:00</li><li><code>backup:database</code> daily (BACKUP_SCHEDULE_TIME)</li><li><code>admin:prune</code> daily 04:30 — health history and expired exports</li></ul>
        <p className="mt-3 text-xs text-adm-muted">If the scheduler is not running, System health shows the scheduler as Unknown or Unavailable and the dashboard stays empty of recorded checks.</p>
      </Card>
      <Card title="Troubleshooting">
        <ul className="list-disc space-y-1.5 pl-5 text-sm">
          <li><strong>No health data:</strong> start the scheduler container or run <code>php artisan admin:monitor</code>.</li>
          <li><strong>AI service unavailable:</strong> check the ai-service container and AI_SERVICE_URL; open an incident if it persists.</li>
          <li><strong>E-mail Unconfigured:</strong> set MAIL_MAILER and SMTP credentials in backend/.env.</li>
          <li><strong>Locked out of 2FA:</strong> use a recovery code; otherwise reset from the server with tinker.</li>
        </ul>
      </Card>
    </div>
    <Card title="Built modules">
      <ul className="grid gap-2 text-sm md:grid-cols-2">{BUILT.map(([t, d]) => <li key={t} className="rounded-lg border border-adm-line px-3 py-2"><p className="flex items-center gap-1.5 font-medium"><CheckCircle2 className="h-4 w-4 text-adm-sage" aria-hidden="true" />{t}</p><p className="mt-0.5 text-xs text-adm-muted">{d}</p></li>)}</ul>
    </Card>
    <Card title="Not built, and why" description="Listed here instead of shipping empty pages">
      <ul className="space-y-2 text-sm">{NOT_BUILT.map(([t, d]) => <li key={t} className="rounded-lg border border-adm-line bg-adm-warm px-3 py-2"><p className="flex items-center gap-1.5 font-medium"><CircleSlash className="h-4 w-4 text-adm-muted" aria-hidden="true" />{t}</p><p className="mt-0.5 text-xs text-adm-muted">{d}</p></li>)}</ul>
    </Card>
  </div>
);

