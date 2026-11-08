import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: '.', timeout: 180_000, workers: 1,
  use: { baseURL: 'http://127.0.0.1:8099', ignoreHTTPSErrors: true,
    viewport: { width: 1440, height: 900 }, screenshot: 'only-on-failure' },
  webServer: {
    cwd: '../..',
    command: 'php -S 127.0.0.1:8099 central/tests/browser_router.php',
    url: 'http://127.0.0.1:8099/staff/login',
    reuseExistingServer: false,
    timeout: 120_000,
    stdout: 'pipe', stderr: 'pipe',
  },
  reporter: [['list']],
});