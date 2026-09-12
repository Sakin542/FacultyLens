// §36 Rate limits: from ONE client IP / ONE account, burst each throttled endpoint past its configured limit and
// verify the transition Allowed → 429 (and that a different account is unaffected).
//   auth        : 20 logins / min / IP
//   api         : 120 requests / min / user
//   ai-analysis : 30 / min / user     (POST /ai/assessments/{id}/analyze?async=1 — accepted or 409/202, never 5xx)
//   uploads     : 25 / min / user     (POST /assessments/{id}/submissions/import with an empty CSV → 422 counts)
// Run: k6 run performance/load/rate-limits.js
import http from 'k6/http';
import { check } from 'k6';
import { api, csrf, login, vuEmail, BASE, PASSWORD, json, summarize, trend } from './lib/session.js';
import { resultPath } from './lib/profiles.js';

export const options = { vus: 1, iterations: 1, noCookiesReset: true, thresholds: {} };
const first429 = { login: trend('first429_login'), api: trend('first429_api'), ai: trend('first429_ai'), uploads: trend('first429_uploads'), other: trend('other_user_status') };

function burst(label, n, fn, t) {
  const codes = {};
  let first = null;
  for (let i = 1; i <= n; i++) {
    const s = fn(i);
    codes[s] = (codes[s] || 0) + 1;
    if (s === 429 && first === null) first = i;
    if (s >= 500) console.log(`[rate] ${label} request ${i} → ${s}`);
  }
  console.log(`[rate] ${label}: first 429 at request #${first} · statuses ${JSON.stringify(codes)}`);
  if (t) t.add(first === null ? -1 : first);
  return { first429: first, codes };
}

export default function () {
  const out = {};
  // Sessions for the per-user tests are opened from distinct IPs first (the per-IP login bucket is exhausted below).
  const jarA = http.cookieJar(); const jarB = new http.CookieJar();
  login(vuEmail(2), undefined, '10.99.0.2');
  const sessA = jarA.cookiesForURL(BASE);
  jarA.clear(BASE);
  login(vuEmail(3), undefined, '10.99.0.3');
  const sessB = jarA.cookiesForURL(BASE);
  jarA.clear(BASE);
  const use = (sess) => { jarA.clear(BASE); for (const [k, v] of Object.entries(sess)) jarA.set(BASE, k, v[0]); };

  // 1. auth: 20 / min / IP (single IP; wrong password so no session churn)
  csrf();
  out.auth = burst('login (20/min/IP)', 30, () => api('POST', '/auth/login', { email: vuEmail(), password: 'wrong-password' }, { name: 'rl_login' }).status, first429.login);
  check(out.auth, { 'login throttled after 20': (r) => r.first429 !== null && r.first429 <= 21 && r.first429 >= 20 });

  // 2. api: 120 / min / user — cheap endpoint
  use(sessA);
  out.api = burst('GET /auth/user (120/min/user)', 130, () => api('GET', '/auth/user', undefined, { name: 'rl_api' }).status, first429.api);
  check(out.api, { 'api throttled after 120': (r) => r.first429 !== null && r.first429 <= 122 });

  // a different account is not affected by the first account's exhausted bucket
  use(sessB);
  const other = api('GET', '/auth/user', undefined, { name: 'rl_api_other' }).status;
  check(other, { 'other user still 200': (s) => s === 200 });
  first429.other.add(other);
  void jarB;

  // 3. ai-analysis: 30 / min / user
  const all = json(api('GET', '/assessments?per_page=100')) || { data: [] };
  const a = (Array.isArray(all.data) ? all.data : all.data.data || []).find((x) => x.title && x.title.includes('(10 questions)'));
  if (a) {
    out.ai = burst('POST analyze?async=1 (30/min/user)', 35, () => api('POST', `/ai/assessments/${a.id}/analyze?async=1`, {}, { name: 'rl_ai' }).status, first429.ai);
    check(out.ai, { 'ai throttled after 30': (r) => r.first429 !== null && r.first429 <= 32 });
    // 4. uploads: 25 / min / user — invalid CSV → 422 each, still counted by the limiter
    const jar = http.cookieJar(); const c = jar.cookiesForURL(BASE);
    const xsrf = decodeURIComponent(c['XSRF-TOKEN'] ? c['XSRF-TOKEN'][0] : '');
    out.uploads = burst('POST submissions/import (25/min/user)', 30, () => {
      const fd = { file: http.file('not,a,valid\n', 'x.csv', 'text/csv') };
      return http.post(`${BASE}/api/assessments/${a.id}/submissions/import`, fd, { headers: { Accept: 'application/json', Origin: BASE, Referer: `${BASE}/`, 'X-XSRF-TOKEN': xsrf, 'X-Forwarded-For': '10.99.0.1' }, tags: { name: 'rl_uploads' } }).status;
    }, first429.uploads);
    check(out.uploads, { 'uploads throttled after 25': (r) => r.first429 !== null && r.first429 <= 27 });
  }
}

export function handleSummary(data) {
  const out = summarize(data, 'rate-limits');
  return { [resultPath('rate-limits')]: JSON.stringify(out, null, 2), stdout: `\n${JSON.stringify(out.endpoints, null, 2)}\n` };
}
