import { defineConfig, devices } from '@playwright/test';

/**
 * Browser tests for the Laundo dashboard.
 *
 * These drive the real dashboard against the development MySQL database, which
 * already holds the fixtures seeded by DevFixturesSeeder, CatalogSeeder,
 * GeoSeeder and TimeSlotSeeder. They therefore READ far more than they write,
 * and anything they do create is cleaned up or clearly named as a test artifact.
 *
 * Run:  npx playwright test
 *       npx playwright test --headed          (watch it happen)
 *       npx playwright show-report
 *
 * The PHP feature suite covers logic and isolation; these cover what the logic
 * cannot: that the pages actually render, the RTL/Arabic layout holds, the AJAX
 * search and status toggles work in a real browser, and the sidebar shows each
 * role only what it should.
 */
export default defineConfig({
  testDir: './tests/Browser',
  // The dashboard mutates shared rows (status toggles), so parallel workers
  // would race each other.
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'storage/playwright-report' }]],

  use: {
    baseURL: process.env.APP_TEST_URL || 'http://127.0.0.1:8800',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    // Wide enough that the sidebar is expanded rather than collapsed into a
    // hamburger, which is what the assertions expect to find.
    viewport: { width: 1440, height: 900 },
  },

  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],

  // Boots the app itself so a run needs no manual setup. Reuses an already
  // running server locally.
  webServer: {
    command: 'php artisan serve --port=8800',
    url: 'http://127.0.0.1:8800/login',
    reuseExistingServer: true,
    timeout: 60_000,
  },
});
