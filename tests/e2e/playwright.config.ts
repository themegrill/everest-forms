import { defineConfig, devices } from '@playwright/test';
import { baseUrl, STORAGE_STATE, targetEnv } from './env';

/**
 * Playwright config for Everest Forms' E2E regression suite.
 *
 * Three callers drive this suite and the config has to serve all three
 * without any of them editing it:
 *
 *   a developer, locally   → `npm run test:e2e`, no env vars, hits the Local site
 *   CI, on every PR        → `--grep @fresh` via .github/workflows/qa-suite.yml
 *   themegrill-qa skills   → run-suite.mjs, which exports TGQA_* and greps a tier
 *
 * Nothing here hardcodes a host: `env.ts` owns that decision and its
 * precedence chain. Every spec navigates with a relative path so `baseURL`
 * governs, without exception.
 *
 * Tiering is by tag in the test *title*, because that is what `--grep`
 * matches. `@fresh` runs anywhere; `@demo` needs seeded content.
 */
export default defineConfig({
  testDir: './specs',
  outputDir: '../../test-results',
  // Serial, deliberately.
  //
  // These specs mutate site-global state that WordPress stores in single rows:
  // the `everest_forms_*` option set, and the `everest_form` post type. Two
  // workers saving the Settings screen concurrently overwrite each other's
  // values by construction, and the assertion that reads the value back then
  // sees the other worker's write. That is a property of the shared option
  // row, not of this machine, so it applies on a CI runner too.
  //
  // If the suite grows enough for serial to hurt, the fix is to isolate state
  // per worker (a site each), NOT to raise the worker count and accept an
  // occasionally-wrong required check.
  // 60s rather than Playwright's 30s default. Specs here routinely create a
  // form through the builder UI and publish a page before they assert
  // anything, and that setup alone can spend 20s against a real WordPress —
  // leaving a 30s budget that fails on setup cost rather than on the product.
  timeout: 60_000,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [
    ['list'],
    ['html', { outputFolder: '../../playwright-report', open: 'never' }],
    ['json', { outputFile: '../../test-results/results.json' }],
  ],

  use: {
    baseURL: baseUrl(),
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },

  projects: [
    {
      // A setup project rather than a step inside globalSetup, so a failed
      // login is a reported test with a trace instead of an opaque crash.
      name: 'setup',
      testDir: '.',
      testMatch: /auth\.setup\.ts/,
    },
    {
      name: 'everest-forms',
      dependencies: ['setup'],
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1440, height: 900 },
        storageState: STORAGE_STATE,
      },
      metadata: { env: targetEnv() },
    },
  ],
});
