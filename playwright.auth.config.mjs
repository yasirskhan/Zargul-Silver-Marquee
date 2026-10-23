import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/authenticated-browser',
  timeout: 90_000,
  retries: 0,
  // Real login + TOTP replay/rate limits share one isolated Laravel server.
  // Serialize different authenticated browser identities; never bypass MFA.
  workers: 1,
  fullyParallel: false,
  reporter: [['list']],
  use: {
    baseURL: 'http://127.0.0.1:8000',
    browserName: 'chromium',
    headless: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
});
