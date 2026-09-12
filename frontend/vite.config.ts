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
    open: true,
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

