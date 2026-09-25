import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: './tests/visual', workers: 1, retries: 0, timeout: 45000,
  use: { baseURL: process.env.MOBILE_PREVIEW_URL, viewport: { width: 390, height: 844 }, browserName: 'chromium', channel: 'msedge', screenshot: 'only-on-failure', trace: 'off' },
  reporter: 'list',
});
