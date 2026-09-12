// §16–§20 AI performance.
//   direct : FastAPI latency by payload size — embeddings 1/10/50/100/200, similarity vs 100/500/1000/5000 previous questions,
//            full analyze-assessment for 10/50/100/200 questions (measures inference without Laravel)
//   app    : Laravel async analysis  POST /ai/assessments/{id}/analyze?async=1 → poll analysis-status → completed;
//            measures acceptance latency, queue wait and total completion; AI_CONCURRENCY parallel VUs (1/2/5/10/20)
// Run:  k6 run performance/load/ai-analysis.js -e MODE=direct
//       k6 run performance/load/ai-analysis.js -e MODE=app -e AI_CONCURRENCY=5
import http from 'k6/http';
import { sleep } from 'k6';
import { api, ensureSession, vuEmail, AI_BASE, AI_KEY, trend, timed, summarize, json } from './lib/session.js';
import { resultPath } from './lib/profiles.js';

const MODE = __ENV.MODE || 'direct';
const CONC = parseInt(__ENV.AI_CONCURRENCY || '1', 10);

export const options = MODE === 'direct'
  ? { vus: 1, iterations: 1, summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max', 'count'] }
  : { noCookiesReset: true, vus: CONC, iterations: CONC * parseInt(__ENV.AI_ROUNDS || '2', 10), summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max', 'count'], thresholds: { http_req_failed: ['rate<0.05'] } };

const tEmbed = { 1: trend('ai_embed_1'), 10: trend('ai_embed_10'), 50: trend('ai_embed_50'), 100: trend('ai_embed_100'), 200: trend('ai_embed_200') };
const tSim = { 100: trend('ai_similarity_prev100'), 500: trend('ai_similarity_prev500'), 1000: trend('ai_similarity_prev1000'), 5000: trend('ai_similarity_prev5000') };
const tFull = { 10: trend('ai_full_analysis_10q'), 50: trend('ai_full_analysis_50q'), 100: trend('ai_full_analysis_100q'), 200: trend('ai_full_analysis_200q') };
const tAccept = trend('app_analysis_accept');
const tQueueWait = trend('app_analysis_queue_wait');
const tTotal = trend('app_analysis_total');
const tPoll = trend('app_analysis_status_poll');

const aiHeaders = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-AI-Service-Key': AI_KEY };

function q(i) {
  const topics = ['normalization', 'indexing', 'transactions', 'query optimisation', 'ER modelling', 'concurrency control', 'recovery', 'distributed databases'];
  return `Explain a B+ tree index for ${topics[i % 8]} and discuss trade-offs under ${10 + (i % 90)} concurrent transactions (variant ${i}).`;
}
function questions(n) { return Array.from({ length: n }, (_, i) => ({ id: i + 1, number: i + 1, text: q(i), marks: 5, question_type: 'descriptive', difficulty: 'medium', cognitive_level: 'Understand', topics: [], learning_outcome_code: `LO${1 + (i % 5)}` })); }
function previous(n) { return Array.from({ length: n }, (_, i) => ({ id: 10000 + i, number: i + 1, text: q(i + 7), assessment_title: 'Old exam', term: 'Spring', year: '2024' })); }
const LOS = Array.from({ length: 5 }, (_, i) => ({ id: i + 1, code: `LO${i + 1}`, description: `Outcome ${i + 1} about relational database ${['design', 'indexing', 'transactions', 'query planning', 'recovery'][i]}.` }));

function direct() {
  for (const n of [1, 10, 50, 100, 200]) {
    for (let r = 0; r < 3; r++) {
      const res = timed(tEmbed[n], () => http.post(`${AI_BASE}/api/v1/embeddings/batch`, JSON.stringify({ texts: questions(n).map((x) => x.text) }), { headers: aiHeaders, timeout: '180s', tags: { name: `ai_embed_${n}` } }));
      if (res.status !== 200 && r === 0) console.log(`[ai] embed ${n} → ${res.status} ${res.body.slice(0, 120)}`);
    }
  }
  for (const n of [100, 500, 1000, 5000]) {
    for (let r = 0; r < 2; r++) {
      const res = timed(tSim[n], () => http.post(`${AI_BASE}/api/v1/analyze-similarity`, JSON.stringify({ course_id: 1, current_questions: questions(20).map((x) => ({ id: x.id, question_number: x.number, text: x.text })), previous_questions: previous(n).map((p) => ({ id: p.id, text: p.text, source_year: 2024, source_assessment: 'Old exam' })) }), { headers: aiHeaders, timeout: '300s', tags: { name: `ai_similarity_prev${n}` } }));
      if (res.status !== 200 && r === 0) console.log(`[ai] similarity ${n} → ${res.status} ${res.body.slice(0, 160)}`);
    }
  }
  for (const n of [10, 50, 100, 200]) {
    for (let r = 0; r < 2; r++) {
      const res = timed(tFull[n], () => http.post(`${AI_BASE}/api/v1/analyze-assessment`, JSON.stringify({ course_id: 1, course_name: 'Perf', assessment: { id: 1, title: 'Perf', total_marks: n * 5, course_code: 'PERF', course_title: 'Perf' }, questions: questions(n), learning_outcomes: LOS, course_topics: ['normalization', 'indexing', 'transactions'], previous_questions: previous(500) }), { headers: aiHeaders, timeout: '600s', tags: { name: `ai_full_analysis_${n}q` } }));
      if (res.status !== 200 && r === 0) console.log(`[ai] full ${n} → ${res.status} ${res.body.slice(0, 160)}`);
    }
  }
}

function app() {
  if (!ensureSession()) { sleep(1); return; }
  const body = json(api('GET', '/assessments?per_page=100', undefined, { name: 'assessments_list' }));
  const all = body && body.data ? (Array.isArray(body.data) ? body.data : body.data.data || []) : [];
  const size = [10, 50, 100, 200][(__VU - 1) % 4];
  const candidates = all.filter((a) => a.title && a.title.includes(`(${size} questions)`));
  const a = candidates[(__VU + __ITER) % Math.max(1, candidates.length)];
  if (!a) { console.log('[ai] no assessment found'); return; }

  const t0 = Date.now();
  const accepted = timed(tAccept, () => api('POST', `/ai/assessments/${a.id}/analyze?async=1`, {}, { name: 'app_analysis_accept' }));
  if (accepted.status !== 202) { console.log(`[ai] accept ${a.id} (${size}q) → ${accepted.status} ${accepted.body.slice(0, 160)}`); sleep(2); return; }

  let firstProcessingSeen = null; let status = 'processing'; let polls = 0;
  const deadline = Date.now() + 15 * 60 * 1000;
  while (Date.now() < deadline) {
    sleep(2);
    const s = json(timed(tPoll, () => api('GET', `/ai/assessments/${a.id}/analysis-status`, undefined, { name: 'app_analysis_status_poll' })));
    polls++;
    status = s ? s.analysis_status : 'unknown';
    if (s && s.updated_at && !firstProcessingSeen && status === 'processing') firstProcessingSeen = Date.now();
    if (status === 'completed' || status === 'failed') break;
  }
  const total = Date.now() - t0;
  tTotal.add(total, { size: String(size), result: status });
  if (firstProcessingSeen) tQueueWait.add(firstProcessingSeen - t0);
  console.log(`[ai] VU${__VU} ${size}q assessment ${a.id}: ${status} in ${(total / 1000).toFixed(1)}s (${polls} polls)`);
}

export default function () { MODE === 'direct' ? direct() : app(); }

export function handleSummary(data) {
  const out = summarize(data, `ai-analysis-${MODE}`, { concurrency: CONC });
  const name = MODE === 'direct' ? 'ai-analysis-direct' : `ai-analysis-app-c${CONC}`;
  return { [resultPath(name)]: JSON.stringify(out, null, 2), stdout: `\n${JSON.stringify(out.endpoints, null, 2)}\n` };
}
