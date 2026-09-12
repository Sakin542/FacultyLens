// Load profiles shared by every scenario. Select with PROFILE=baseline|ramp|spike|soak|fixed (default baseline).
//   baseline : 1 VU, N iterations           – latency of the application work itself, no contention
//   ramp     : 1 → 5 → 10 → 25 → 50 → 100 VUs – capacity discovery (§31)
//   spike    : 10 → 100 → 10 VUs             – sudden burst (§32)
//   soak     : 20 VUs for SOAK_MINUTES       – leak / drift detection (§33)
//   fixed    : VUS for DURATION              – ad-hoc
export function profileOptions(overrides = {}) {
  const profile = __ENV.PROFILE || 'baseline';
  const iterations = parseInt(__ENV.ITERATIONS || '30', 10);
  const step = __ENV.STEP || '60s';
  const soak = `${parseInt(__ENV.SOAK_MINUTES || '30', 10)}m`;
  // noCookiesReset: a VU keeps its Sanctum session across iterations (k6 clears the jar per iteration by default)
  const base = { noCookiesReset: true, discardResponseBodies: false, summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max', 'count'], thresholds: { http_req_failed: ['rate<0.05'] } };
  const profiles = {
    baseline: { vus: 1, iterations },
    fixed: { vus: parseInt(__ENV.VUS || '10', 10), duration: __ENV.DURATION || '60s' },
    ramp: { stages: [{ duration: step, target: 1 }, { duration: step, target: 5 }, { duration: step, target: 10 }, { duration: step, target: 25 }, { duration: step, target: 50 }, { duration: step, target: 100 }, { duration: '20s', target: 0 }] },
    spike: { stages: [{ duration: '60s', target: 10 }, { duration: '10s', target: 100 }, { duration: '60s', target: 100 }, { duration: '10s', target: 10 }, { duration: '60s', target: 10 }, { duration: '10s', target: 0 }] },
    soak: { stages: [{ duration: '60s', target: 20 }, { duration: soak, target: 20 }, { duration: '20s', target: 0 }] },
  };
  return Object.assign(base, profiles[profile] || profiles.baseline, overrides);
}

export function resultPath(name) {
  const profile = __ENV.PROFILE || 'baseline';
  const tag = __ENV.RUN_TAG ? `-${__ENV.RUN_TAG}` : '';
  return `performance/results/${name}-${profile}${tag}.json`;
}
