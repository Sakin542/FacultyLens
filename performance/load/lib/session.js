// Shared helpers for FacultyLens k6 scenarios (Sanctum cookie session, CSRF, per-VU identity).
import http from 'k6/http';
import { check } from 'k6';
import { Trend, Counter } from 'k6/metrics';

export const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8090';
export const AI_BASE = __ENV.AI_BASE_URL || 'http://127.0.0.1:8011';
export const AI_KEY = __ENV.AI_KEY || 'perf-ai-key-not-secret';
export const PASSWORD = __ENV.PERF_PASSWORD || 'PerfTest#2026';
export const OWNER_EMAIL = 'perf.faculty@example.com';
export const LIST_EMAIL = 'perf.courses@example.com';
export const USER_COUNT = parseInt(__ENV.PERF_USERS || '100', 10);

export const errors = new Counter('app_errors');
export const rateLimited = new Counter('rate_limited_429');

/** perf.user.001 … perf.user.100 — one account (and one rate-limit bucket) per virtual user. */
export function vuEmail(vu = __VU) {
  const n = ((vu - 1) % USER_COUNT) + 1;
  return `perf.user.${String(n).padStart(3, '0')}@example.com`;
}

/**
 * Each VU presents a distinct client IP through X-Forwarded-For (nginx runs inside Laravel's TRUSTED_PROXIES range),
 * so per-IP throttles behave as they would for distinct faculty machines. The second octet changes per run so
 * consecutive scenarios do not inherit each other's throttle buckets. rate-limits.js sets SINGLE_IP=1 to test the limiter.
 */
const RUN_OCTET = Math.floor(Date.now() / 1000) % 200 + 10;
let iterIp = 0;
function clientIp() {
  if (__ENV.SINGLE_IP === '1') return '10.99.0.1';
  const n = __ENV.IP_PER_ITER === '1' ? (__VU * 1000 + iterIp) : __VU;
  return `10.${RUN_OCTET}.${Math.floor(n / 256) % 256}.${n % 256}`;
}
export function rotateIp() { iterIp++; }

function baseHeaders(extra = {}) {
  return Object.assign({
    Accept: 'application/json',
    Origin: BASE,
    Referer: `${BASE}/`,
    'X-Forwarded-For': clientIp(),
  }, extra);
}

function xsrf() {
  const jar = http.cookieJar();
  const c = jar.cookiesForURL(BASE);
  const raw = c['XSRF-TOKEN'] ? c['XSRF-TOKEN'][0] : '';
  return decodeURIComponent(raw);
}

export function csrf() {
  http.get(`${BASE}/sanctum/csrf-cookie`, { headers: baseHeaders(), tags: { name: 'csrf' } });
}

export function api(method, path, body, tags = {}, extraHeaders = {}) {
  const url = path.startsWith('http') ? path : `${BASE}/api${path}`;
  const headers = baseHeaders(extraHeaders);
  if (forcedIp) headers['X-Forwarded-For'] = forcedIp;
  const params = { headers, tags, timeout: __ENV.HTTP_TIMEOUT || '180s' };
  let res;
  if (method === 'GET') {
    res = http.get(url, params);
  } else {
    headers['Content-Type'] = 'application/json';
    headers['X-XSRF-TOKEN'] = xsrf();
    res = http.request(method, url, body === undefined ? null : JSON.stringify(body), params);
  }
  if (res.status === 429) rateLimited.add(1);
  if (res.status >= 500 || res.status === 0) errors.add(1);
  if (__ENV.DEBUG_STATUS === '1' && res.status >= 400) console.log(`[debug] VU${__VU} it${__ITER} ${method} ${path} → ${res.status} ${String(res.body).slice(0, 120)}`);
  return res;
}

let forcedIp = null;
export function login(email, trend, fromIp = null) {
  forcedIp = fromIp;
  csrf();
  const t0 = Date.now();
  const res = api('POST', '/auth/login', { email, password: PASSWORD }, { name: 'login' });
  forcedIp = null;
  if (trend) trend.add(Date.now() - t0);
  check(res, { 'login 200': (r) => r.status === 200 });
  if (res.status !== 200) console.log(`[session] login ${email} → ${res.status} ${String(res.body).slice(0, 80)}`);
  authed = res.status === 200;
  return authed;
}

let authed = false;
/** Log in once per VU; retried on later iterations if the first attempt failed (e.g. throttled). */
export function ensureSession(email, trend) {
  if (authed) return true;
  return login(email || vuEmail(), trend);
}

export function logout() {
  authed = false;
  return api('POST', '/auth/logout', {}, { name: 'logout' });
}

export function json(res) {
  try { return res.json(); } catch (e) { return null; }
}

/** Percentile helper for handleSummary (k6 Trend values are exposed via metrics.values). */
export function pick(metrics, name) {
  const m = metrics[name];
  if (!m || !m.values) return null;
  const v = m.values;
  const r = (x) => (x === undefined ? null : Math.round(x * 10) / 10);
  return { avg: r(v.avg), min: r(v.min), med: r(v.med), p90: r(v['p(90)']), p95: r(v['p(95)']), p99: r(v['p(99)']), max: r(v.max), count: v.count };
}

export function summarize(data, scenario, extra = {}) {
  const out = { scenario, profile: __ENV.PROFILE || 'baseline', generated_at: new Date().toISOString(), base_url: BASE, vus_max: data.metrics.vus_max ? data.metrics.vus_max.values.max : null, iterations: data.metrics.iterations ? data.metrics.iterations.values.count : null, http_reqs: data.metrics.http_reqs ? data.metrics.http_reqs.values.count : null, http_req_failed_rate: data.metrics.http_req_failed ? data.metrics.http_req_failed.values.rate : null, app_errors: data.metrics.app_errors ? data.metrics.app_errors.values.count : 0, rate_limited_429: data.metrics.rate_limited_429 ? data.metrics.rate_limited_429.values.count : 0, endpoints: {}, extra };
  for (const k of Object.keys(data.metrics)) {
    if (k.startsWith('t_')) out.endpoints[k.slice(2)] = pick(data.metrics, k);
  }
  return out;
}

export function trend(name) {
  return new Trend(`t_${name}`, true);
}

export function timed(t, fn) {
  const t0 = Date.now();
  const res = fn();
  t.add(Date.now() - t0);
  return res;
}
