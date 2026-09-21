import { test, expect, Page } from '@playwright/test';

/**
 * The negative test: before asserting anything a control *does*, assert that
 * every admin screen the plugin registers renders cleanly on its own — no PHP
 * notice, warning, deprecation or fatal leaking into the HTML.
 *
 * This is the check that catches a fatal on activation, and it is the cheapest
 * catastrophic regression to ship unnoticed. Everest Forms registers six
 * screens under one menu (includes/admin/class-evf-admin-menus.php:155), and a
 * fatal in shared admin bootstrap — includes/admin/class-evf-admin-assets.php
 * has 20 modify commits in the last 400 — only surfaces on whichever screen
 * happens to load the broken path. So all of them, every run.
 *
 * Deliberately asserts on rendered text rather than the response body: a PHP
 * notice emitted inside an output buffer that WordPress later flushes still
 * reaches the DOM, and a body-only regex on a 200 response would miss it.
 */

const PHP_ERROR = /(^|\s)(Warning|Notice|Deprecated|Fatal error|Parse error)\s*:/;

/**
 * @area    activation
 * @tier    fresh
 * @source  knowledge-init 2026-09-07
 * @why     A screen that 500s or prints a notice is a release blocker, and the
 *          six screens share enough bootstrap that testing one proves little.
 */
const SCREENS: Array<{ name: string; page: string; title: RegExp }> = [
  { name: 'All Forms (builder)', page: 'evf-builder', title: /Everest Forms Builder/ },
  { name: 'Entries', page: 'evf-entries', title: /Everest Forms Entries/ },
  { name: 'Settings', page: 'evf-settings', title: /Everest Forms settings/ },
  { name: 'Tools', page: 'evf-tools', title: /Everest Forms tools/ },
];

for (const screen of SCREENS) {
  test(`${screen.name} renders with no PHP error @fresh @activation`, async ({ page }) => {
    await assertCleanAdminScreen(page, screen.page, screen.name, screen.title);
  });
}

/**
 * @area    activation
 * @tier    fresh
 * @source  knowledge-init 2026-09-07
 * @why     Everest Forms is a frontend plugin as much as an admin one: it
 *          enqueues assets and registers a shortcode on every request. A fatal
 *          in that path takes the whole site down, not just wp-admin, so the
 *          front page is checked even though no form is on it yet.
 */
test('front end still renders with the plugin active @fresh @activation', async ({ page }) => {
  const response = await page.goto('/');
  expect(response?.status(), 'front page did not return 200 with Everest Forms active').toBe(200);

  const body = await page.locator('body').innerText();
  expect(
    body,
    'A PHP error reached the front end. Everest Forms enqueues assets and registers ' +
      'its shortcode on every request, so a fatal here is site-wide, not admin-only.',
  ).not.toMatch(PHP_ERROR);
});

async function assertCleanAdminScreen(
  page: Page,
  adminPage: string,
  label: string,
  expectedTitle: RegExp,
) {
  const response = await page.goto(`/wp-admin/admin.php?page=${adminPage}`, {
    waitUntil: 'domcontentloaded',
  });

  expect(response?.status(), `${label} did not return 200`).toBe(200);

  // The title proves WordPress routed to *this* screen rather than bouncing to
  // the dashboard or a capability error, which a 200 alone does not.
  await expect(page, `${label} loaded but is not the screen we asked for`).toHaveTitle(
    expectedTitle,
  );

  // These screens are React-rendered; give the app a beat to mount so a notice
  // printed during an admin-ajax bootstrap is in the DOM when we look.
  await page.waitForLoadState('networkidle').catch(() => {
    /* networkidle is best-effort: some screens poll and never go quiet */
  });

  const body = await page.locator('body').innerText();
  const match = body.match(PHP_ERROR);
  expect(
    match,
    `${label} rendered a PHP error: ${match?.[0] ?? ''}. ` +
      'Look at the trace — the surrounding text names the file and line.',
  ).toBeNull();
}
