import { defineConfig, devices } from '@playwright/test';

// STEP 43 — browser timing probe against the LOCAL performance stack (nginx-served production build on :8090).
export default defineConfig({
  testDir: '.',
  timeout: 10 * 60 * 1000,
  retries: 0,
  workers: 1,
  reporter: [['list']],
  use: { ...devices['Desktop Chrome'], baseURL: process.env.PERF_BASE_URL || 'http://127.0.0.1:8090', trace: 'off', video: 'off' },
});
