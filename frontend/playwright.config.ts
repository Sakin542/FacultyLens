import { defineConfig, devices } from '@playwright/test';

/**
 * STEP 41 — browser end-to-end journeys against the real stack:
 *   frontend  : production build served by `vite preview` on http://127.0.0.1:4173 (started here)
 *   backend   : Laravel on http://127.0.0.1:8080  (docker compose up -d)
 *   ai-service: FastAPI on http://127.0.0.1:8001  (docker compose up -d)
 *
 * 127.0.0.1 (not localhost) is used on purpose so the Sanctum session cookie set by the API on 127.0.0.1:8080
 * is same-site for the browser. Override with E2E_BASE_URL / E2E_API_URL when needed.
 */
export default defineConfig({
  testDir: './tests/e2e',
  timeout: 120_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:4173',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    ...devices['Desktop Chrome'],
  },
  webServer: {
    command: 'npx vite build && npx vite preview --host 127.0.0.1 --port 4173 --strictPort',
    url: 'http://127.0.0.1:4173',
    reuseExistingServer: true,
    timeout: 240_000,
    env: { VITE_API_BASE_URL: process.env.E2E_API_URL ? `${process.env.E2E_API_URL}/api` : 'http://127.0.0.1:8080/api' },
  },
});
