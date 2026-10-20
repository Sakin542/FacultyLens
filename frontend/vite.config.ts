import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'path';

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 3000,
    // Listen on IPv4 loopback so http://127.0.0.1:3000 works as well as http://localhost:3000 (Vite's default bound
    // only to localhost's IPv6 address). Browsers fall back from ::1 to 127.0.0.1 for "localhost". Not exposed to
    // the network.
    host: '127.0.0.1',
    open: 'http://localhost:3000/',
    // Vite only transforms a file when the browser first asks for it. Pre-transform what every visit needs (the entry,
    // which pulls in the public pages and layouts, plus both dashboards) as soon as the server starts. Warming every
    // page competed with the first request and made the first load slower.
    warmup: {
      clientFiles: ['./src/main.tsx', './src/pages/Dashboard.tsx', './src/features/admin/pages/DashboardPage.tsx'],
    },
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8080',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://127.0.0.1:8080',
        changeOrigin: true,
      },
    },
  },
  // Same origin as the dev server so the backend CORS / Sanctum stateful domains stay valid for `vite preview`
  preview: {
    port: 3000,
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: './src/tests/setup.ts',
    // Browser journeys live in tests/e2e (Playwright, `npm run test:e2e`); tests/perf holds the STEP 43 Playwright
    // page-timing probe that runs against the performance stack (see performance/README.md)
    exclude: ['node_modules/**', 'dist/**', 'tests/e2e/**', 'tests/perf/**'],
  },
});

