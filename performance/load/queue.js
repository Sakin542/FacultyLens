// §25–§27 Queue (Redis, `queue:work`) throughput + idempotency using the real AI-analysis job:
//   1. dispatch JOBS analyses (async=1) across distinct assessments as fast as the API accepts them
//   2. poll until every one is completed/failed → jobs/minute, per-job wait (accept → processing) and processing time
//   3. idempotency: re-POST the same assessment while it is queued/processing → 202 "already processing", same
//      analysis id, and analysis-history gains exactly ONE version per accepted run (never a duplicate report)
// Run: k6 run performance/load/queue.js -e JOBS=10   (10 / 50 supported by the dataset: sizes 10..75 questions)
import { sleep } from 'k6';
import { api, ensureSession, vuEmail, trend, timed, summarize, json } from './lib/session.js';
import { resultPath } from './lib/profiles.js';

const JOBS = parseInt(__ENV.JOBS || '10', 10);
const PER_VU = 10; // ai-analysis throttle is 30/min per user; 10 dispatches per account keeps the test on the queue, not the limiter
const VUS = Math.max(1, Math.ceil(JOBS / PER_VU));
export const options = { vus: VUS, iterations: VUS, noCookiesReset: true, summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max', 'count'] };
const tAccept = trend('queue_accept');
const tWait = trend('queue_wait_to_processing');
const tProc = trend('queue_processing');
const tTotal = trend('queue_job_total');
const tAll = trend('queue_all_jobs_wall');
const tDup = trend('idempotency_duplicate_reports');

export default function () {
  if (!ensureSession(vuEmail())) return;
  const body = json(api('GET', '/assessments?per_page=100')) || { data: [] };
  const all = Array.isArray(body.data) ? body.data : body.data.data || [];
  const sizes = JOBS <= 10 ? [10] : JOBS <= 20 ? [10, 20] : JOBS <= 30 ? [10, 20, 30] : [10, 20, 30, 50, 75];
  const pool = all.filter((a) => sizes.some((n) => a.title && a.title.includes(`(${n} questions)`))).slice(0, JOBS);
  const targets = pool.filter((_, i) => Math.floor(i / PER_VU) === (__VU - 1));
  console.log(`[queue] VU${__VU} dispatching ${targets.length} analyses`);

  const before = {};
  for (const a of targets) before[a.id] = (json(api('GET', `/assessments/${a.id}/analysis-history`)) || {}).data?.total_versions ?? 0;

  const t0 = Date.now();
  const jobs = {};
  for (const a of targets) {
    const r = timed(tAccept, () => api('POST', `/ai/assessments/${a.id}/analyze?async=1`, {}, { name: 'queue_accept' }));
    jobs[a.id] = { accepted: Date.now(), status: r.status, id: (json(r) || {}).analysis_id, firstProcessing: null, done: null };
    if (r.status !== 202) console.log(`[queue] accept ${a.id} → ${r.status} ${String(r.body).slice(0, 100)}`);
  }
  // idempotency probe: the first target is re-posted while the queue is busy
  const dupTarget = targets[0];
  const dup = api('POST', `/ai/assessments/${dupTarget.id}/analyze?async=1`, {}, { name: 'queue_duplicate_post' });
  const dupBody = json(dup) || {};
  const dupStartedNewRun = dup.status === 202 && dupBody.analysis_id && dupBody.analysis_id !== jobs[dupTarget.id].id;
  console.log(`[queue] duplicate POST → ${dup.status} status=${dupBody.status} same_id=${dupBody.analysis_id === jobs[dupTarget.id].id}${dupStartedNewRun ? ' (first run had already completed → legitimate second run)' : ''}`);

  let pending = targets.length; const deadline = Date.now() + 30 * 60 * 1000;
  while (pending > 0 && Date.now() < deadline) {
    sleep(2);
    pending = 0;
    for (const a of targets) {
      const j = jobs[a.id];
      if (j.done) continue;
      const s = json(api('GET', `/ai/assessments/${a.id}/analysis-status`, undefined, { name: 'queue_poll' })) || {};
      if (s.analysis_status === 'processing' && !j.firstProcessing && s.updated_at) j.firstProcessing = new Date(s.updated_at).getTime();
      if (s.analysis_status === 'completed' || s.analysis_status === 'failed') {
        j.done = Date.now(); j.final = s.analysis_status;
        const analyzed = s.analyzed_at ? new Date(s.analyzed_at).getTime() : j.done;
        if (j.firstProcessing) { tWait.add(Math.max(0, j.firstProcessing - j.accepted)); tProc.add(Math.max(0, analyzed - j.firstProcessing)); }
        tTotal.add(j.done - j.accepted, { result: s.analysis_status });
      } else pending++;
    }
  }
  const wall = Date.now() - t0; tAll.add(wall);
  const failed = Object.values(jobs).filter((j) => j.final === 'failed').length;
  console.log(`[queue] VU${__VU}: ${targets.length} jobs finished in ${(wall / 1000).toFixed(1)}s (${(targets.length / (wall / 60000)).toFixed(1)} jobs/min from this account), failed=${failed}`);

  let dupReports = 0;
  for (const a of targets) {
    const after = (json(api('GET', `/assessments/${a.id}/analysis-history`)) || {}).data?.total_versions ?? 0;
    const delta = after - before[a.id];
    const expected = a.id === dupTarget.id && dupStartedNewRun ? 2 : 1;
    if (delta !== expected) { dupReports += Math.abs(delta - expected); console.log(`[queue] assessment ${a.id}: versions ${before[a.id]} → ${after} (expected +${expected})`); }
  }
  tDup.add(dupReports);
  console.log(`[queue] VU${__VU}: duplicate/missing report versions across ${targets.length} assessments: ${dupReports}`);
}

export function handleSummary(data) {
  const out = summarize(data, 'queue', { jobs: JOBS });
  return { [resultPath(`queue-j${JOBS}`)]: JSON.stringify(out, null, 2), stdout: `\n${JSON.stringify(out.endpoints, null, 2)}\n` };
}
