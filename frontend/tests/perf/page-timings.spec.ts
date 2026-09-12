// STEP 43 §28/§29 — real-browser page timings and API request inventory against the performance stack.
// Not part of the regular e2e suite (different base URL/data); run with:
//   cd frontend && npx playwright test tests/perf/page-timings.spec.ts --config tests/perf/playwright.perf.config.ts
import { test, expect } from '@playwright/test';
import * as fs from 'fs';

const BASE = process.env.PERF_BASE_URL || 'http://127.0.0.1:8090';
const EMAIL = process.env.PERF_EMAIL || 'perf.faculty@example.com';
const PASSWORD = process.env.PERF_PASSWORD || 'PerfTest#2026';
const OUT = process.env.PERF_OUT || '../performance/results/frontend-pages.json';

test('page load, API wait and request inventory for the main pages', async ({ page }) => {
  test.setTimeout(10 * 60 * 1000);
  const results: Record<string, unknown>[] = [];

  await page.goto(`${BASE}/login`);
  await page.getByLabel('University Email').fill(EMAIL);
  await page.locator('input#password').fill(PASSWORD);
  await page.locator('form').getByRole('button', { name: 'Sign In' }).click();
  await page.waitForURL(/dashboard/, { timeout: 60_000 });

  const assessments = await page.evaluate(async () => (await (await fetch('/api/assessments?per_page=100', { credentials: 'include', headers: { Accept: 'application/json' } })).json()));
  const list = assessments.data;
  const big = list.find((a: any) => a.title.includes('(200 questions)'));
  const course = list[0].course_id;
  const pages = [
    ['dashboard', '/dashboard'], ['courses', '/courses'], ['course-detail', `/courses/${course}`], ['assessments', '/assessments'],
    ['assessment-200q', `/assessments/${big.id}`], ['analysis-200q', `/assessments/${big.id}/analysis`], ['analytics', '/analytics'],
    ['history', '/history'], ['reports', '/reports'], ['question-bank-5000', `/courses/${course}/question-bank`],
  ];

  for (const [name, path] of pages) {
    const apiCalls: { url: string; ms: number; status: number; bytes: number }[] = [];
    const seen = new Map<string, number>();
    const onResp = async (r: any) => {
      const u = r.url();
      if (!u.includes('/api/')) return;
      const key = `${r.request().method()} ${u.replace(BASE, '')}`;
      seen.set(key, (seen.get(key) || 0) + 1);
      const t = r.request().timing();
      let bytes = 0; try { bytes = (await r.body()).length; } catch { /* streamed */ }
      apiCalls.push({ url: key, ms: Math.round(t.responseEnd - t.requestStart), status: r.status(), bytes });
    };
    page.on('response', onResp);
    const t0 = Date.now();
    await page.goto(`${BASE}${path}`, { waitUntil: 'load' });
    // The SPA shows a session splash (auth check, ≥1.5 s reveal) before rendering the route; wait until the page has
    // rendered real content and the API has been quiet for 1.5 s (poll every 250 ms, up to 60 s).
    let lastCount = -1; let quietSince = Date.now(); const deadline = Date.now() + 60_000;
    while (Date.now() < deadline) {
      await page.waitForTimeout(250);
      const splash = await page.locator('[aria-label="Verifying institutional session"]').count();
      if (apiCalls.length !== lastCount) { lastCount = apiCalls.length; quietSince = Date.now(); }
      if (!splash && apiCalls.length > 0 && Date.now() - quietSince > 1500) break;
    }
    const wall = Date.now() - t0 - 1500;
    const nav = await page.evaluate(() => {
      const n = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming;
      const fcp = performance.getEntriesByName('first-contentful-paint')[0];
      return { domContentLoaded: Math.round(n.domContentLoadedEventEnd), load: Math.round(n.loadEventEnd), fcp: fcp ? Math.round(fcp.startTime) : null, transferKB: Math.round(performance.getEntriesByType('resource').reduce((s, r: any) => s + (r.transferSize || 0), 0) / 1024) };
    });
    const dom = await page.evaluate(() => document.querySelectorAll('*').length);
    page.off('response', onResp);
    const duplicates = [...seen.entries()].filter(([, n]) => n > 1).map(([k, n]) => `${k} ×${n}`);
    results.push({ page: name, path, wall_ms: wall, ...nav, api_requests: apiCalls.length, api_wait_ms_total: apiCalls.reduce((s, c) => s + c.ms, 0), api_bytes_kb: Math.round(apiCalls.reduce((s, c) => s + c.bytes, 0) / 1024), duplicate_requests: duplicates, dom_nodes: dom, calls: apiCalls });
    console.log(`${name.padEnd(18)} wall ${String(wall).padStart(5)} ms  fcp ${String(nav.fcp).padStart(5)}  load ${String(nav.load).padStart(5)}  api ${String(apiCalls.length).padStart(2)} req / ${String(results.at(-1)!.api_wait_ms_total).padStart(5)} ms / ${String(results.at(-1)!.api_bytes_kb).padStart(5)} KB  dom ${dom}  dup ${duplicates.length ? duplicates.join('; ') : '-'}`);
    expect(apiCalls.filter((c) => c.status >= 500)).toHaveLength(0);
  }
  fs.writeFileSync(OUT, JSON.stringify({ generated_at: new Date().toISOString(), base_url: BASE, pages: results }, null, 2));
});
