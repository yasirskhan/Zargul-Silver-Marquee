import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: '.', timeout: 180_000, workers: 1,
  use: { baseURL: 'https://127.0.0.1:8443', ignoreHTTPSErrors: true,
    viewport: { width: 1440, height: 900 }, screenshot: 'only-on-failure' },
  reporter: [['list']],
});